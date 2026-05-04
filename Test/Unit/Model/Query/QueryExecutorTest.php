<?php
/**
 * Meetanshi AIReporting — QueryExecutor Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Query;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Exception\QueryException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueryExecutorTest extends TestCase
{
    private QueryExecutor $executor;
    private ResourceConnection|MockObject $resourceConnection;
    private AdapterInterface|MockObject $connection;
    private Config|MockObject $config;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection         = $this->createMock(AdapterInterface::class);
        $this->config             = $this->createMock(Config::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->config->method('getMaxRows')
            ->willReturn(500);

        $this->executor = new QueryExecutor(
            $this->resourceConnection,
            $this->config,
            $this->logger
        );
    }

    // ── Successful execution ─────────────────────────────────────────────

    public function testExecuteReturnsColumnsRowsAndMetadata(): void
    {
        $rows = [
            ['email' => 'john@test.com', 'total' => '150.00'],
            ['email' => 'sarah@test.com', 'total' => '250.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->executor->execute("SELECT email, SUM(grand_total) as total FROM sales_order GROUP BY email LIMIT 10");

        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('row_count', $result);
        $this->assertArrayHasKey('execution_time_ms', $result);

        $this->assertSame(['email', 'total'], $result['columns']);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame($rows, $result['rows']);
        $this->assertIsInt($result['execution_time_ms']);
    }

    public function testExecuteReturnsEmptyColumnsForNoResults(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([]);

        $result = $this->executor->execute("SELECT * FROM sales_order WHERE entity_id = -1");

        $this->assertSame([], $result['columns']);
        $this->assertSame(0, $result['row_count']);
    }

    // ── LIMIT enforcement ────────────────────────────────────────────────

    public function testExecuteAddsLimitWhenMissing(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function (string $sql) {
                return str_contains(strtoupper($sql), 'LIMIT 500');
            }))
            ->willReturn([]);

        $this->executor->execute("SELECT * FROM sales_order");
    }

    public function testExecuteDoesNotAddLimitWhenAlreadyPresent(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function (string $sql) {
                // Should not have double LIMIT
                return substr_count(strtoupper($sql), 'LIMIT') === 1;
            }))
            ->willReturn([]);

        $this->executor->execute("SELECT * FROM sales_order LIMIT 100");
    }

    public function testExecuteSkipsLimitForAggregateCountQuery(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function (string $sql) {
                return !str_contains(strtoupper($sql), 'LIMIT');
            }))
            ->willReturn([['cnt' => '42']]);

        $this->executor->execute("SELECT COUNT(*) as cnt FROM sales_order");
    }

    public function testExecuteSkipsLimitForAggregateSumQuery(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function (string $sql) {
                return !str_contains(strtoupper($sql), 'LIMIT');
            }))
            ->willReturn([['total' => '9999.99']]);

        $this->executor->execute("SELECT SUM(grand_total) as total FROM sales_order");
    }

    public function testExecuteSkipsLimitForAggregateAvgQuery(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->callback(function (string $sql) {
                return !str_contains(strtoupper($sql), 'LIMIT');
            }))
            ->willReturn([['avg' => '125.50']]);

        $this->executor->execute("SELECT AVG(grand_total) as avg FROM sales_order");
    }

    // ── Error handling ───────────────────────────────────────────────────

    public function testExecuteThrowsQueryExceptionOnDbError(): void
    {
        $this->connection->method('fetchAll')
            ->willThrowException(new \Exception('Table not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Meetanshi AIReporting query execution error',
                $this->callback(function (array $context) {
                    return isset($context['sql']) && isset($context['error']);
                })
            );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Query execution failed');

        $this->executor->execute("SELECT * FROM nonexistent_table");
    }

    public function testExecuteTracksExecutionTime(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([['id' => 1]]);

        $result = $this->executor->execute("SELECT 1 as id");

        $this->assertGreaterThanOrEqual(0, $result['execution_time_ms']);
    }
}
