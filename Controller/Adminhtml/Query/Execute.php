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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\NlpToSql;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\QueryLogFactory;
use Meetanshi\AIReporting\Model\ResourceModel\QueryLog as QueryLogResource;
use Psr\Log\LoggerInterface;

class Execute extends Action
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly NlpToSql $nlpToSql,
        private readonly QueryExecutor $queryExecutor,
        private readonly Config $config,
        private readonly QueryLogFactory $queryLogFactory,
        private readonly QueryLogResource $queryLogResource,
        private readonly LoggerInterface $logger
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
        $directSql = trim((string) $this->getRequest()->getParam('sql', ''));

        if (empty($nlpQuery) && empty($directSql)) {
            return $resultJson->setData(['success' => false, 'message' => __('Please enter a query.')]);
        }

        $startTime = microtime(true);
        $sqlQuery  = '';
        $status    = 'success';
        $errorMsg  = '';

        try {
            // If direct SQL is provided (from saved report), use it directly
            if (!empty($directSql)) {
                $sqlQuery = $directSql;
            } else {
                // Step 1: Convert NLP to SQL via LLM
                $sqlQuery = $this->nlpToSql->convert($nlpQuery);
            }

            // Step 2: Execute the SQL
            $queryResult = $this->queryExecutor->execute($sqlQuery);

            $response = [
                'success'           => true,
                'sql_query'         => $sqlQuery,
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
                'admin_user_id'     => (int) $this->_auth->getUser()->getId()
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
