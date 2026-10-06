<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Query;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\Query\QueryRunner;
use Meetanshi\AIReporting\Model\Query\QueryTokenStorage;
use Meetanshi\AIReporting\Model\QueryLogFactory;
use Meetanshi\AIReporting\Model\ResourceModel\QueryLog as QueryLogResource;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use Psr\Log\LoggerInterface;

/**
 * Runs either a natural-language question (converted to SQL by the LLM) or one of the
 * current admin's saved reports (by report_id). Raw SQL from the browser is never accepted.
 */
class Execute extends Action
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly QueryRunner $queryRunner,
        private readonly QueryExecutor $queryExecutor,
        private readonly Config $config,
        private readonly QueryLogFactory $queryLogFactory,
        private readonly QueryLogResource $queryLogResource,
        private readonly LoggerInterface $logger,
        private readonly SavedReportFactory $savedReportFactory,
        private readonly SavedReportResource $savedReportResource,
        private readonly QueryTokenStorage $queryTokenStorage
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->getRequest()->isAjax() || !$this->getRequest()->isPost()) {
            return $resultJson->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        if (!$this->config->isEnabled()) {
            return $resultJson->setData(['success' => false, 'message' => __('Meetanshi AI Reporting is disabled.')]);
        }

        $nlpQuery = trim((string) $this->getRequest()->getParam('query', ''));
        $reportId = (int) $this->getRequest()->getParam('report_id', 0);

        if ($nlpQuery === '' && $reportId <= 0) {
            return $resultJson->setData(['success' => false, 'message' => __('Please enter a query.')]);
        }

        $startTime = microtime(true);
        $sqlQuery  = '';
        $status    = 'success';
        $errorMsg  = '';

        try {
            if ($reportId > 0) {
                // Saved report: SQL comes from the database, and only the owner may run it
                $report = $this->savedReportFactory->create();
                $this->savedReportResource->load($report, $reportId);
                if (!$report->getId() || $report->getAdminUserId() !== $this->getAdminUserId()) {
                    throw new LocalizedException(__('Saved report not found.'));
                }
                $nlpQuery    = $report->getNlpQuery();
                $sqlQuery    = $report->getSqlQuery();
                $queryResult = $this->queryExecutor->execute($sqlQuery);
            } else {
                // Convert NLP to SQL via LLM and execute it (validated, row-limited, read-only,
                // with one automatic correction if the database rejects the SQL)
                $run         = $this->queryRunner->run($nlpQuery);
                $sqlQuery    = $run['sql'];
                $queryResult = $run['result'];
            }

            $response = [
                'success'           => true,
                'sql_query'         => $sqlQuery,
                'query_token'       => $this->queryTokenStorage->remember($nlpQuery, $sqlQuery),
                'columns'           => $queryResult['columns'],
                'rows'              => $queryResult['rows'],
                'row_count'         => $queryResult['row_count'],
                'execution_time_ms' => $queryResult['execution_time_ms'],
                'provider'          => $this->config->getLlmProvider()
            ];

        } catch (\Exception $e) {
            $status   = 'error';
            $errorMsg = $e->getMessage();
            $this->logger->error('Meetanshi AIReporting Execute error', [
                'query' => $nlpQuery,
                'error' => $errorMsg
            ]);
            $response = ['success' => false, 'message' => $errorMsg];
        }

        // Step 3: Log the query if logging is enabled
        if ($this->config->isQueryLoggingEnabled()) {
            $this->logQuery(
                $nlpQuery,
                $sqlQuery,
                $status,
                $errorMsg,
                (int) round((microtime(true) - $startTime) * 1000),
                $response['row_count'] ?? 0
            );
        }

        return $resultJson->setData($response);
    }

    private function getAdminUserId(): int
    {
        $user = $this->_auth ? $this->_auth->getUser() : null;

        return $user ? (int) $user->getId() : 0;
    }

    private function logQuery(
        string $nlpQuery,
        string $sqlQuery,
        string $status,
        string $errorMsg,
        int $executionTimeMs,
        int $rowsReturned
    ): void {
        try {
            $log = $this->queryLogFactory->create();
            $log->setData([
                'nlp_query'         => $nlpQuery,
                'sql_query'         => $sqlQuery,
                'llm_provider'      => $this->config->getLlmProvider(),
                'status'            => $status,
                'error_message'     => $errorMsg ?: null,
                'execution_time_ms' => $executionTimeMs,
                'rows_returned'     => $rowsReturned,
                'admin_user_id'     => $this->getAdminUserId()
            ]);
            $this->queryLogResource->save($log);
        } catch (\Exception $e) {
            $this->logger->warning('Meetanshi AIReporting: Failed to save query log', ['error' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::query');
    }
}
