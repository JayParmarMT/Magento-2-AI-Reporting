<?php
/**
 * Meetanshi AIReporting — audit log of AI questions (Ask AI and Chat)
 *
 * Writes to meetanshi_aireporting_query_log when "Log All Queries" is enabled. A failed write
 * never breaks the request.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model;

use Meetanshi\AIReporting\Model\ResourceModel\QueryLog as QueryLogResource;
use Psr\Log\LoggerInterface;

class QueryLogger
{
    public function __construct(
        private readonly Config $config,
        private readonly QueryLogFactory $queryLogFactory,
        private readonly QueryLogResource $queryLogResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(
        string $nlpQuery,
        string $sqlQuery,
        bool $success,
        string $errorMessage,
        int $executionTimeMs,
        int $rowsReturned,
        int $adminUserId
    ): void {
        if (!$this->config->isQueryLoggingEnabled()) {
            return;
        }

        try {
            $log = $this->queryLogFactory->create();
            $log->setData([
                'nlp_query'         => $nlpQuery,
                'sql_query'         => $sqlQuery !== '' ? $sqlQuery : null,
                'llm_provider'      => $this->config->getLlmProvider(),
                'status'            => $success ? 'success' : 'error',
                'error_message'     => $errorMessage !== '' ? $errorMessage : null,
                'execution_time_ms' => max(0, $executionTimeMs),
                'rows_returned'     => max(0, $rowsReturned),
                'admin_user_id'     => $adminUserId,
            ]);
            $this->queryLogResource->save($log);
        } catch (\Exception $e) {
            $this->logger->warning('Meetanshi AIReporting: Failed to save query log', ['error' => $e->getMessage()]);
        }
    }
}
