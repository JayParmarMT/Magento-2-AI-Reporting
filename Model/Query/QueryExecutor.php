<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Exception\QueryException;
use Psr\Log\LoggerInterface;

class QueryExecutor
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute a validated SELECT SQL query and return results.
     *
     * @param string $sql
     * @return array{columns: string[], rows: array[], row_count: int, execution_time_ms: int}
     * @throws QueryException
     */
    public function execute(string $sql): array
    {
        $startTime  = microtime(true);
        $connection = $this->resourceConnection->getConnection();

        // Enforce LIMIT to prevent runaway queries
        $sql = $this->enforceLimitClause($sql);

        try {
            $result = $connection->fetchAll($sql);
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting query execution error', [
                'sql'   => $sql,
                'error' => $e->getMessage()
            ]);
            throw new QueryException(
                __('Query execution failed: %1', $e->getMessage())
            );
        }

        $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        $columns = !empty($result) ? array_keys($result[0]) : [];

        return [
            'columns'          => $columns,
            'rows'             => $result,
            'row_count'        => count($result),
            'execution_time_ms' => $executionTimeMs
        ];
    }

    /**
     * Ensure the query has a LIMIT clause to cap result size.
     */
    private function enforceLimitClause(string $sql): string
    {
        $maxRows    = $this->config->getMaxRows();
        $upperSql   = strtoupper($sql);

        // Skip LIMIT enforcement for aggregate-only queries
        if (
            !str_contains($upperSql, 'LIMIT') &&
            !preg_match('/^\s*SELECT\s+(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $sql)
        ) {
            $sql .= ' LIMIT ' . $maxRows;
        }

        return $sql;
    }
}
