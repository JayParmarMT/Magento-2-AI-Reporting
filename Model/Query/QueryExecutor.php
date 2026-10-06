<?php
/**
 * Meetanshi AIReporting
 *
 * Runs AI-generated and saved-report SQL safely:
 *  1. SqlGuard::sanitize() — single SELECT/WITH statement, no writes, no secret tables/columns;
 *  2. a top-level LIMIT of at most "Max Result Rows";
 *  3. an isolated connection (ideally a SELECT-only DB user) inside START TRANSACTION READ ONLY,
 *     with a server-side statement timeout ("Query Timeout");
 *  4. secret values are redacted from the result.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Query;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Exception\QueryException;
use Psr\Log\LoggerInterface;

class QueryExecutor
{
    private ?bool $isMariaDb = null;

    public function __construct(
        private readonly ReadOnlyConnectionProvider $connectionProvider,
        private readonly SqlGuard $sqlGuard,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute a SELECT SQL query and return results.
     *
     * @param string $sql
     * @return array{columns: string[], rows: array[], row_count: int, execution_time_ms: int}
     * @throws QueryException
     */
    public function execute(string $sql): array
    {
        $sql = $this->sqlGuard->sanitize($sql);
        $sql = $this->sqlGuard->applyRowLimit($sql, $this->config->getMaxRows());

        $startTime  = microtime(true);
        $connection = $this->connectionProvider->getConnection();

        try {
            $this->beginReadOnly($connection);
            try {
                $result = $connection->fetchAll($sql);
            } finally {
                $connection->query('ROLLBACK');
            }
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting query execution error', [
                'sql'   => $sql,
                'error' => $e->getMessage()
            ]);
            throw new QueryException(
                __('Query execution failed: %1', $this->describeError($e))
            );
        }

        $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);
        $result = $this->sqlGuard->redactRows($result);

        return [
            'columns'           => !empty($result) ? array_keys($result[0]) : [],
            'rows'              => $result,
            'row_count'         => count($result),
            'execution_time_ms' => $executionTimeMs
        ];
    }

    /**
     * Apply the statement timeout and open a READ ONLY transaction on the isolated connection.
     */
    private function beginReadOnly(AdapterInterface $connection): void
    {
        $timeout = max(1, $this->config->getQueryTimeout());

        if ($this->isMariaDb === null) {
            $this->isMariaDb = stripos((string) $connection->fetchOne('SELECT VERSION()'), 'mariadb') !== false;
        }

        $connection->query($this->isMariaDb
            ? sprintf('SET SESSION max_statement_time = %d', $timeout)
            : sprintf('SET SESSION MAX_EXECUTION_TIME = %d', $timeout * 1000));
        $connection->query('START TRANSACTION READ ONLY');
    }

    /**
     * Friendly messages for the errors a read-only query is expected to hit.
     */
    private function describeError(\Exception $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '1792') || stripos($message, 'READ ONLY transaction') !== false) {
            return (string) __('the query tried to modify data, which is not allowed.');
        }
        if (str_contains($message, '1969') || str_contains($message, '3024')
            || stripos($message, 'max_statement_time') !== false || stripos($message, 'maximum statement execution time') !== false
        ) {
            return (string) __('the query took longer than the configured timeout.');
        }
        if (str_contains($message, '1142') || str_contains($message, '1143')) {
            return (string) __('the reporting database user is not allowed to read this table.');
        }

        // Drop the echoed statement ("…, query was: SELECT …") — the SQL is shown separately
        $cut = stripos($message, ', query was:');

        return $cut === false ? $message : substr($message, 0, $cut);
    }
}
