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

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Exception\QueryException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\Query\ReadOnlyConnectionProvider;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueryExecutorTest extends TestCase
{
    private QueryExecutor $executor;
    private AdapterInterface|MockObject $connection;
    private array $statements = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('fetchOne')->willReturn('11.4.12-MariaDB');
        $this->connection->method('query')->willReturnCallback(function (string $sql) {
            $this->statements[] = $sql;
            return null;
        });

        $provider = $this->createMock(ReadOnlyConnectionProvider::class);
        $provider->method('getConnection')->willReturn($this->connection);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('');

        $config = $this->createMock(Config::class);
        $config->method('getMaxRows')->willReturn(500);
        $config->method('getQueryTimeout')->willReturn(30);

        $this->executor = new QueryExecutor(
            $provider,
            new SqlGuard($deploymentConfig),
            $config,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testExecuteReturnsColumnsRowsAndMetadata(): void
    {
        $rows = [
            ['email' => 'john@test.com', 'total' => '150.00'],
            ['email' => 'sarah@test.com', 'total' => '250.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $result = $this->executor->execute('SELECT customer_email AS email, grand_total AS total FROM sales_order');

        $this->assertSame(['email', 'total'], $result['columns']);
        $this->assertSame($rows, $result['rows']);
        $this->assertSame(2, $result['row_count']);
        $this->assertIsInt($result['execution_time_ms']);
    }

    public function testExecuteReturnsEmptyColumnsForNoResults(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $result = $this->executor->execute('SELECT entity_id FROM sales_order');

        $this->assertSame([], $result['columns']);
        $this->assertSame(0, $result['row_count']);
    }

    public function testRunsInsideReadOnlyTransactionWithTimeoutAndRollsBack(): void
    {
        $executed = null;
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$executed) {
            $executed = $sql;
            return [];
        });

        $this->executor->execute('SELECT entity_id FROM sales_order');

        $this->assertSame(
            ['SET SESSION max_statement_time = 30', 'START TRANSACTION READ ONLY', 'ROLLBACK'],
            $this->statements
        );
        $this->assertSame('SELECT entity_id FROM sales_order LIMIT 500', $executed);
    }

    public function testRollsBackEvenWhenQueryFails(): void
    {
        $this->connection->method('fetchAll')->willThrowException(new \Exception('Unknown column'));

        try {
            $this->executor->execute('SELECT nope FROM sales_order');
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Query execution failed', $e->getMessage());
        }

        $this->assertSame('ROLLBACK', end($this->statements));
    }

    public function testReadOnlyViolationGetsFriendlyMessage(): void
    {
        $this->connection->method('fetchAll')->willThrowException(
            new \Exception('SQLSTATE[25006]: 1792 Cannot execute statement in a READ ONLY transaction')
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('not allowed');

        $this->executor->execute('SELECT entity_id FROM sales_order');
    }

    public function testUnsafeSqlIsRejectedBeforeReachingTheDatabase(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->expectException(QueryException::class);

        $this->executor->execute("UPDATE sales_order SET status = 'hacked' LIMIT 500");
    }

    public function testLargeLimitIsCapped(): void
    {
        $executed = null;
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$executed) {
            $executed = $sql;
            return [];
        });

        $this->executor->execute('SELECT entity_id FROM sales_order LIMIT 1000000');

        $this->assertSame('SELECT entity_id FROM sales_order LIMIT 500', $executed);
    }

    public function testSecretsAreRedactedFromResults(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['path' => 'payment/stripe/api_key', 'value' => 'sk_live_123'],
        ]);

        $result = $this->executor->execute("SELECT path, value FROM core_config_data WHERE path LIKE 'payment/%'");

        $this->assertSame(SqlGuard::REDACTED, $result['rows'][0]['value']);
    }
}
