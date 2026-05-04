<?php
/**
 * Meetanshi AIReporting — NlpToSql Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Query;

use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Model\LLM\ProviderInterface;
use Meetanshi\AIReporting\Model\LLM\ProviderPool;
use Meetanshi\AIReporting\Model\Query\NlpToSql;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NlpToSqlTest extends TestCase
{
    private NlpToSql $nlpToSql;
    private ProviderPool|MockObject $providerPool;
    private ProviderInterface|MockObject $provider;

    protected function setUp(): void
    {
        $this->providerPool = $this->createMock(ProviderPool::class);
        $this->provider     = $this->createMock(ProviderInterface::class);

        $this->providerPool->method('getActiveProvider')
            ->willReturn($this->provider);

        $this->nlpToSql = new NlpToSql($this->providerPool);
    }

    // ── Successful conversions ───────────────────────────────────────────

    public function testConvertReturnsCleanSelectQuery(): void
    {
        $this->provider->method('complete')
            ->willReturn("SELECT COUNT(*) as total FROM sales_order WHERE status = 'complete'");

        $result = $this->nlpToSql->convert('How many completed orders do we have?');

        $this->assertStringStartsWith('SELECT', strtoupper($result));
        $this->assertStringContainsString('sales_order', $result);
    }

    public function testConvertStripsMarkdownCodeFences(): void
    {
        $this->provider->method('complete')
            ->willReturn("```sql\nSELECT COUNT(*) FROM customer_entity\n```");

        $result = $this->nlpToSql->convert('How many customers?');

        $this->assertStringNotContainsString('```', $result);
        $this->assertStringStartsWith('SELECT', strtoupper(trim($result)));
    }

    public function testConvertStripsTrailingSemicolon(): void
    {
        $this->provider->method('complete')
            ->willReturn("SELECT * FROM sales_order LIMIT 10;");

        $result = $this->nlpToSql->convert('Show me recent orders');

        $this->assertStringEndsNotWith(';', $result);
    }

    public function testConvertAcceptsWithCteQuery(): void
    {
        $cte = "WITH monthly AS (SELECT DATE_FORMAT(created_at, '%Y-%m') as m, SUM(grand_total) as rev FROM sales_order GROUP BY m) SELECT * FROM monthly";

        $this->provider->method('complete')
            ->willReturn($cte);

        $result = $this->nlpToSql->convert('Monthly revenue with CTE');

        $this->assertStringStartsWith('WITH', strtoupper(trim($result)));
    }

    // ── Security: blocked statements ─────────────────────────────────────

    /**
     * @dataProvider forbiddenStatementProvider
     */
    public function testConvertBlocksForbiddenStatements(string $sql, string $keyword): void
    {
        $this->provider->method('complete')
            ->willReturn($sql);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Security violation');

        $this->nlpToSql->convert('malicious query');
    }

    public static function forbiddenStatementProvider(): array
    {
        return [
            'INSERT'   => ["INSERT INTO sales_order (status) VALUES ('hacked')", 'INSERT'],
            'UPDATE'   => ["UPDATE sales_order SET status = 'hacked'", 'UPDATE'],
            'DELETE'   => ["DELETE FROM sales_order", 'DELETE'],
            'DROP'     => ["DROP TABLE sales_order", 'DROP'],
            'ALTER'    => ["ALTER TABLE sales_order ADD COLUMN hacked INT", 'ALTER'],
            'TRUNCATE' => ["TRUNCATE TABLE sales_order", 'TRUNCATE'],
            'CREATE'   => ["CREATE TABLE hacked (id INT)", 'CREATE'],
            'REPLACE'  => ["REPLACE INTO sales_order (entity_id) VALUES (1)", 'REPLACE'],
            'GRANT'    => ["GRANT ALL ON *.* TO 'hacker'@'%'", 'GRANT'],
            'REVOKE'   => ["REVOKE ALL ON *.* FROM 'admin'@'%'", 'REVOKE'],
        ];
    }

    public function testConvertBlocksNonSelectNonWithQuery(): void
    {
        $this->provider->method('complete')
            ->willReturn("SHOW TABLES");

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('valid SELECT query');

        $this->nlpToSql->convert('show tables');
    }

    // ── Edge cases ───────────────────────────────────────────────────────

    public function testConvertHandlesLeadingWhitespace(): void
    {
        $this->provider->method('complete')
            ->willReturn("   SELECT COUNT(*) FROM customer_entity");

        $result = $this->nlpToSql->convert('count customers');

        $this->assertStringStartsWith('SELECT', strtoupper(trim($result)));
    }

    public function testConvertHandlesCodeFenceWithoutLanguage(): void
    {
        $this->provider->method('complete')
            ->willReturn("```\nSELECT * FROM sales_order LIMIT 5\n```");

        $result = $this->nlpToSql->convert('show orders');

        $this->assertStringNotContainsString('```', $result);
    }

    public function testConvertPassesNlpQueryToProvider(): void
    {
        $this->provider->expects($this->once())
            ->method('complete')
            ->with($this->callback(function (string $prompt) {
                return str_contains($prompt, 'What are the top selling products?')
                    && str_contains($prompt, 'sales_order')
                    && str_contains($prompt, 'customer_entity');
            }))
            ->willReturn("SELECT sku, SUM(qty_ordered) as qty FROM sales_order_item GROUP BY sku ORDER BY qty DESC LIMIT 10");

        $this->nlpToSql->convert('What are the top selling products?');
    }
}
