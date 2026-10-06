<?php
/**
 * Meetanshi AIReporting — Chat with AI Controller
 *
 * Accepts a natural language question, queries the database via LLM-generated SQL,
 * then produces a human-readable answer. Result rows are sent to the LLM only when
 * "Send Query Results to AI Provider" is enabled; otherwise the answer is built locally.
 * Handles sales, customer, product, AND system/config/module questions — including any table
 * of the store database and questions about specific third-party modules.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Meetanshi\AIReporting\Exception\DirectAnswerException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\LLM\ProviderPool;
use Meetanshi\AIReporting\Model\ModuleInfoResponder;
use Meetanshi\AIReporting\Model\Query\QueryRunner;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use Meetanshi\AIReporting\Model\SystemInfoResponder;
use Psr\Log\LoggerInterface;

class Send extends Action
{
    private readonly SystemInfoResponder $systemInfoResponder;
    private readonly ModuleInfoResponder $moduleInfoResponder;
    private readonly ModuleCatalog $moduleCatalog;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly QueryRunner $queryRunner,
        private readonly ProviderPool $providerPool,
        private readonly Config $config,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly LoggerInterface $logger,
        ?SystemInfoResponder $systemInfoResponder = null,
        ?ModuleInfoResponder $moduleInfoResponder = null,
        ?ModuleCatalog $moduleCatalog = null
    ) {
        parent::__construct($context);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->systemInfoResponder = $systemInfoResponder ?? $objectManager->get(SystemInfoResponder::class);
        $this->moduleInfoResponder = $moduleInfoResponder ?? $objectManager->get(ModuleInfoResponder::class);
        $this->moduleCatalog       = $moduleCatalog ?? $objectManager->get(ModuleCatalog::class);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->getRequest()->isAjax() || !$this->getRequest()->isPost()) {
            return $resultJson->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        if (!$this->config->isEnabled()) {
            return $resultJson->setData(['success' => false, 'message' => __('AI Reporting is disabled.')]);
        }

        $question = trim((string) $this->getRequest()->getParam('message', ''));
        if (empty($question)) {
            return $resultJson->setData(['success' => false, 'message' => __('Please enter a message.')]);
        }

        try {
            // Step 0: Questions about a specific module or vendor ("is the Size Chart extension enabled?",
            // "which Amasty modules are installed?") and platform/environment questions (version, PHP,
            // deploy mode, caches, Redis/Varnish/search/queue backends) are answered directly from
            // live Magento APIs and module files — not via SQL.
            $directAnswer = $this->moduleInfoResponder->answer($question)
                ?? $this->systemInfoResponder->answer($question);
            if ($directAnswer !== null) {
                return $resultJson->setData($this->textAnswer($directAnswer));
            }

            // Steps 1–2: Convert question to SQL and execute it (with automatic correction)
            $run         = $this->queryRunner->run($question);
            $sql         = $run['sql'];
            $queryResult = $run['result'];

            // Step 3: Conversational answer — by the LLM only if sharing results is allowed
            $answer = $this->config->isResultSharingEnabled()
                ? $this->generateAnswer($question, $sql, $queryResult)
                : $this->buildLocalAnswer($queryResult);

            return $resultJson->setData([
                'success'  => true,
                'answer'   => $answer,
                'sql'      => $sql,
                'rows'     => $queryResult['row_count'],
                'time_ms'  => $queryResult['execution_time_ms'],
            ]);

        } catch (DirectAnswerException $e) {
            // Not a data question (greeting, general advice, data the store does not keep): the AI's own reply
            return $resultJson->setData($this->textAnswer($e->getAnswer()));
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting Chat error', [
                'question' => $question,
                'error'    => $e->getMessage(),
            ]);

            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Response for an answer that did not run a query.
     */
    private function textAnswer(string $answer): array
    {
        return [
            'success' => true,
            'answer'  => $answer,
            'sql'     => '',
            'rows'    => 0,
            'time_ms' => 0,
        ];
    }

    /**
     * Send the raw query results to the LLM and ask it to produce a
     * friendly, conversational answer — like ChatGPT would.
     */
    private function generateAnswer(string $question, string $sql, array $queryResult): string
    {
        $rows     = $queryResult['rows'];
        $rowCount = $queryResult['row_count'];

        // Limit data sent to LLM to avoid token overflow
        $maxRowsForContext = 80;
        $truncated = false;
        if ($rowCount > $maxRowsForContext) {
            $rows = array_slice($rows, 0, $maxRowsForContext);
            $truncated = true;
        }

        $dataJson = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Gather live system context (plus the modules the question names)
        $systemContext = $this->getSystemContext($question);

        $prompt = <<<PROMPT
You are a Magento 2 expert assistant helping a store admin. You answer questions about their store's sales data, customers, products, inventory, AND system configuration, installed modules, performance settings, and infrastructure.

Current Magento System Info:
{$systemContext}

Rules:
- Answer in a friendly, professional tone — like a senior Magento consultant.
- Use bullet points, numbered lists, or short paragraphs for readability.
- Format numbers nicely (e.g. \$1,234.56 for currency, 1,234 for counts).
- Highlight key insights, recommendations, or warnings.
- For configuration questions (CDN, Redis, minification, caching, etc.):
  * Clearly state whether the feature is ENABLED or DISABLED.
  * If a value is "1" it means enabled/yes, "0" means disabled/no.
  * If the config path is not found in the data (empty result), it means the default value is being used (not explicitly set in DB).
  * Provide recommendations if something important is disabled in production.
- For module/extension questions:
  * Distinguish between Magento core modules (Magento_*) and third-party/custom modules.
  * Show version numbers when available.
- If the data is empty, explain what that means (e.g. "not configured" or "using default").
- Keep the answer concise but complete.
- Do NOT include SQL queries or technical database details in your answer.
- Use markdown formatting: **bold** for emphasis, bullet points, etc.

Admin's Question: {$question}

Total Rows Returned: {$rowCount}
PROMPT;

        if ($truncated) {
            $prompt .= "\n(Showing first {$maxRowsForContext} of {$rowCount} rows)\n";
        }

        $prompt .= "\nQuery Result Data:\n{$dataJson}\n\nProvide your answer:";

        $provider = $this->providerPool->getActiveProvider();

        return trim($provider->complete($prompt));
    }

    /**
     * Summary built on the server (no data leaves it): row count plus the first rows.
     */
    private function buildLocalAnswer(array $queryResult): string
    {
        $rowCount = (int) $queryResult['row_count'];
        if ($rowCount === 0) {
            return (string) __('No matching data was found.');
        }

        $maxRows = 10;
        $lines   = [(string) __('**%1 row(s) found.**', $rowCount)];
        foreach (array_slice($queryResult['rows'], 0, $maxRows) as $row) {
            $parts = [];
            foreach ($row as $column => $value) {
                $parts[] = $column . ': ' . ($value === null ? '—' : (string) $value);
            }
            $lines[] = '- ' . implode(', ', $parts);
        }
        if ($rowCount > $maxRows) {
            $lines[] = (string) __('…and %1 more. Use "Ask AI" above to see every row as a table or chart.', $rowCount - $maxRows);
        }

        return implode("\n", $lines);
    }

    /**
     * Gather live Magento system information to enrich the LLM context.
     */
    private function getSystemContext(string $question): string
    {
        try {
            $version = $this->productMetadata->getVersion();
            $edition = $this->productMetadata->getEdition();
            $name    = $this->productMetadata->getName();

            $lines = [
                "- Platform: {$name} {$edition} {$version}",
                "- PHP Version: " . PHP_VERSION,
                "- Server: " . ($this->getRequest()->getServer('SERVER_SOFTWARE') ?: 'Unknown'),
            ];
            foreach (array_slice($this->moduleCatalog->detect($question), 0, 3) as $module) {
                $info    = $this->moduleCatalog->getInfo($module);
                $lines[] = "- Module {$module}" . ($info['description'] !== '' ? " ({$info['description']})" : '')
                    . ': ' . ($info['enabled'] ? 'enabled' : 'disabled')
                    . ($info['composer_version'] !== '' ? ', version ' . $info['composer_version'] : '');
            }

            return implode("\n", $lines);
        } catch (\Exception $e) {
            return "- Platform info unavailable";
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::query');
    }
}
