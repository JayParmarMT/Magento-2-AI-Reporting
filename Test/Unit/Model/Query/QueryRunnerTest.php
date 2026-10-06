<?php
/**
 * Meetanshi AIReporting — QueryRunner Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Query;

use Meetanshi\AIReporting\Exception\QueryException;
use Meetanshi\AIReporting\Model\Query\NlpToSql;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\Query\QueryRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueryRunnerTest extends TestCase
{
    private QueryRunner $runner;
    private NlpToSql|MockObject $nlpToSql;
    private QueryExecutor|MockObject $executor;

    private const RESULT = ['columns' => ['n'], 'rows' => [['n' => '25']], 'row_count' => 1, 'execution_time_ms' => 3];

    protected function setUp(): void
    {
        $this->nlpToSql = $this->createMock(NlpToSql::class);
        $this->executor = $this->createMock(QueryExecutor::class);
        $this->runner   = new QueryRunner($this->nlpToSql, $this->executor);
    }

    public function testReturnsResultWithoutRepairWhenSqlWorks(): void
    {
        $this->nlpToSql->method('convert')->willReturn('SELECT COUNT(*) AS n FROM sales_order');
        $this->executor->method('execute')->willReturn(self::RESULT);
        $this->nlpToSql->expects($this->never())->method('repair');

        $run = $this->runner->run('How many orders?');

        $this->assertFalse($run['repaired']);
        $this->assertSame(self::RESULT, $run['result']);
    }

    public function testRepairsOnceWhenDatabaseRejectsTheSql(): void
    {
        $this->nlpToSql->method('convert')->willReturn('SELECT COUNT(order_id) FROM sales_order');
        $this->executor->method('execute')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'order_id')) {
                throw new QueryException(__(
                    "Query execution failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'order_id' in 'SELECT', query was: SELECT COUNT(order_id) FROM sales_order"
                ));
            }
            return self::RESULT;
        });

        $this->nlpToSql->expects($this->once())
            ->method('repair')
            ->with(
                'How many orders?',
                'SELECT COUNT(order_id) FROM sales_order',
                "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'order_id' in 'SELECT'"
            )
            ->willReturn('SELECT COUNT(entity_id) AS n FROM sales_order');

        $run = $this->runner->run('How many orders?');

        $this->assertTrue($run['repaired']);
        $this->assertSame('SELECT COUNT(entity_id) AS n FROM sales_order', $run['sql']);
    }

    public function testSecurityRejectionIsNeverRetried(): void
    {
        $this->nlpToSql->method('convert')->willReturn('SELECT username FROM admin_user');
        $this->executor->method('execute')->willThrowException(
            new QueryException(__('Security violation: table "admin_user" contains credentials and cannot be queried.'))
        );
        $this->nlpToSql->expects($this->never())->method('repair');

        $this->expectException(QueryException::class);
        $this->runner->run('List admins');
    }

    public function testTimeoutIsNeverRetried(): void
    {
        $this->nlpToSql->method('convert')->willReturn('SELECT 1');
        $this->executor->method('execute')->willThrowException(
            new QueryException(__('Query execution failed: the query took longer than the configured timeout.'))
        );
        $this->nlpToSql->expects($this->never())->method('repair');

        $this->expectException(QueryException::class);
        $this->runner->run('Heavy question');
    }

    public function testSecondFailureIsReported(): void
    {
        $this->nlpToSql->method('convert')->willReturn('SELECT a FROM sales_order');
        $this->nlpToSql->method('repair')->willReturn('SELECT b FROM sales_order');
        $this->executor->expects($this->exactly(3))->method('execute')->willThrowException(
            new QueryException(__("Query execution failed: 1054 Unknown column 'x' in 'SELECT'"))
        );

        $this->expectException(QueryException::class);
        $this->runner->run('Question');
    }
}
