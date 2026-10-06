<?php
/**
 * Meetanshi AIReporting — SqlGuard Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Query;

use Magento\Framework\App\DeploymentConfig;
use Meetanshi\AIReporting\Exception\QueryException;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlGuardTest extends TestCase
{
    private SqlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = $this->createGuard('');
    }

    private function createGuard(string $tablePrefix): SqlGuard
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            ['db/connection/default/dbname', null, 'magento'],
            ['db/table_prefix', null, $tablePrefix],
        ]);

        return new SqlGuard($deploymentConfig);
    }

    // ── Allowed queries ──────────────────────────────────────────────────

    #[DataProvider('allowedQueries')]
    public function testAllowsReadOnlyReportQueries(string $sql): void
    {
        $this->assertNotSame('', $this->guard->sanitize($sql));
    }

    public static function allowedQueries(): array
    {
        return [
            'simple select'        => ["SELECT COUNT(*) FROM sales_order WHERE status = 'complete'"],
            'cte'                  => ["WITH m AS (SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(base_grand_total) AS r FROM sales_order GROUP BY m) SELECT * FROM m"],
            'keywords in strings'  => ["SELECT increment_id FROM sales_order WHERE status = 'update; delete from x' AND customer_email = 'a@b.com'"],
            'string functions'     => ["SELECT REPLACE(sku, '-', ''), INSERT(sku, 1, 2, 'X'), TRUNCATE(price, 2) FROM sales_order_item"],
            'extract from alias'   => ["SELECT EXTRACT(MONTH FROM o.created_at) AS m, COUNT(*) FROM sales_order o GROUP BY m"],
            'arithmetic star'      => ["SELECT o.entity_id, oi.qty_ordered * oi.price AS total FROM sales_order o JOIN sales_order_item oi ON oi.order_id = o.entity_id"],
            'current db qualified' => ['SELECT entity_id FROM magento.sales_order'],
            'updated_at column'    => ['SELECT updated_at, created_at FROM customer_entity'],
            'config paths'         => ["SELECT path, value FROM core_config_data WHERE path LIKE 'dev/js/%'"],
            'backticks'            => ['SELECT `entity_id` FROM `sales_order`'],
            'trailing semicolons'  => ['SELECT 1;;  '],
        ];
    }

    public function testStripsCommentsAndFencesFromExecutedSql(): void
    {
        $sql = "```sql\n-- top customers\nSELECT entity_id /* inline */ FROM sales_order # trailing\n```";

        $clean = $this->guard->sanitize($sql);

        $this->assertStringNotContainsString('--', $clean);
        $this->assertStringNotContainsString('/*', $clean);
        $this->assertStringNotContainsString('#', $clean);
        $this->assertStringNotContainsString('```', $clean);
        $this->assertMatchesRegularExpression('/^SELECT entity_id\s+FROM sales_order$/', $clean);
    }

    public function testExecutableCommentsAreRemovedNotRun(): void
    {
        $clean = $this->guard->sanitize('SELECT 1 /*!50000 , SLEEP(10) */');

        $this->assertSame('SELECT 1', $clean);
    }

    // ── Blocked queries ──────────────────────────────────────────────────

    #[DataProvider('blockedQueries')]
    public function testBlocksUnsafeQueries(string $sql): void
    {
        $this->expectException(QueryException::class);

        $this->guard->sanitize($sql);
    }

    public static function blockedQueries(): array
    {
        return [
            'update'              => ["UPDATE sales_order SET status = 'x'"],
            'delete'              => ['DELETE FROM sales_order'],
            'drop'                => ['DROP TABLE sales_order'],
            'truncate'            => ['TRUNCATE TABLE sales_order'],
            'with delete'         => ['WITH x AS (SELECT 1) DELETE FROM sales_order'],
            'with update'         => ["WITH x AS (SELECT 1) UPDATE sales_order SET status = 'x'"],
            'stacked'             => ['SELECT 1; DROP TABLE sales_order'],
            'into outfile'        => ["SELECT * FROM sales_order INTO OUTFILE '/tmp/x'"],
            'into variable'       => ['SELECT entity_id INTO @x FROM sales_order'],
            'for update'          => ['SELECT entity_id FROM sales_order FOR UPDATE'],
            'lock share'          => ['SELECT entity_id FROM sales_order LOCK IN SHARE MODE'],
            'for share'           => ['SELECT entity_id FROM sales_order FOR SHARE'],
            'sleep'               => ['SELECT SLEEP(100)'],
            'benchmark'           => ["SELECT BENCHMARK(100000000, MD5('x'))"],
            'load file'           => ["SELECT LOAD_FILE('/etc/passwd')"],
            'system variable'     => ['SELECT @@datadir'],
            'mysql schema'        => ['SELECT user, authentication_string FROM mysql.user'],
            'info schema'         => ['SELECT table_name FROM information_schema.tables'],
            'other database'      => ['SELECT * FROM otherdb.customers'],
            'admin users'         => ['SELECT username, email FROM admin_user'],
            'oauth tokens'        => ['SELECT consumer_id FROM oauth_token'],
            'integration'         => ['SELECT name FROM integration'],
            'password column'     => ['SELECT email, password_hash FROM customer_entity'],
            'card column'         => ['SELECT cc_number_enc FROM sales_order_payment'],
            'wildcard customers'  => ['SELECT * FROM customer_entity'],
            'alias wildcard'      => ['SELECT c.* FROM customer_entity c'],
            'secret config path'  => ["SELECT value FROM core_config_data WHERE path = 'payment/braintree/private_key'"],
            'not a select'        => ['SHOW TABLES'],
            'unterminated quote'  => ["SELECT 'abc FROM sales_order"],
            'unterminated comment'=> ['SELECT 1 /* never closed'],
            'empty'               => ['   '],
            'aireporting reports' => ['SELECT sql_query FROM meetanshi_aireporting_saved_report'],
        ];
    }

    public function testDeniedTablesAreDetectedWithTablePrefix(): void
    {
        $guard = $this->createGuard('m2_');

        $this->expectException(QueryException::class);
        $guard->sanitize('SELECT username FROM m2_admin_user');
    }

    public function testNonSelectStatementsReportSecurityViolation(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Security violation');

        $this->guard->sanitize("REPLACE INTO sales_order (entity_id) VALUES (1)");
    }

    // ── Row limit ────────────────────────────────────────────────────────

    public function testAppendsLimitWhenMissing(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM sales_order LIMIT 500',
            $this->guard->applyRowLimit('SELECT entity_id FROM sales_order', 500)
        );
    }

    public function testAppendsLimitToAggregatesToo(): void
    {
        $this->assertSame(
            'SELECT COUNT(*) FROM sales_order LIMIT 500',
            $this->guard->applyRowLimit('SELECT COUNT(*) FROM sales_order', 500)
        );
    }

    public function testKeepsSmallerTopLevelLimit(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM sales_order LIMIT 10',
            $this->guard->applyRowLimit('SELECT entity_id FROM sales_order LIMIT 10', 500)
        );
    }

    public function testLowersLargerTopLevelLimit(): void
    {
        $this->assertSame(
            'SELECT entity_id FROM sales_order LIMIT 500',
            $this->guard->applyRowLimit('SELECT entity_id FROM sales_order LIMIT 100000', 500)
        );
        $this->assertSame(
            'SELECT entity_id FROM sales_order LIMIT 20, 500',
            $this->guard->applyRowLimit('SELECT entity_id FROM sales_order LIMIT 20, 99999', 500)
        );
        $this->assertSame(
            'SELECT entity_id FROM sales_order LIMIT 500 OFFSET 20',
            $this->guard->applyRowLimit('SELECT entity_id FROM sales_order LIMIT 99999 OFFSET 20', 500)
        );
    }

    public function testLimitInsideSubqueryDoesNotCountAsTopLevel(): void
    {
        $this->assertSame(
            'SELECT * FROM (SELECT entity_id FROM sales_order LIMIT 5) t LIMIT 500',
            $this->guard->applyRowLimit('SELECT * FROM (SELECT entity_id FROM sales_order LIMIT 5) t', 500)
        );
    }

    public function testLimitWordInStringIsIgnored(): void
    {
        $this->assertSame(
            "SELECT entity_id FROM sales_order WHERE coupon_code = 'LIMIT 5' LIMIT 500",
            $this->guard->applyRowLimit("SELECT entity_id FROM sales_order WHERE coupon_code = 'LIMIT 5'", 500)
        );
    }

    // ── Redaction ────────────────────────────────────────────────────────

    public function testRedactsSecretsFromResults(): void
    {
        $rows = $this->guard->redactRows([
            ['path' => 'payment/braintree/private_key', 'value' => 'abc123'],
            ['path' => 'dev/js/minify_files', 'value' => '1'],
            ['path' => 'carriers/ups/password', 'value' => '0:3:ZmFrZWVuY3J5cHRlZHZhbHVlMTIz'],
            ['email' => 'a@b.com', 'password_hash' => 'x'],
            ['note' => '0:3:ZmFrZWVuY3J5cHRlZHZhbHVlMTIz'],
        ]);

        $this->assertSame(SqlGuard::REDACTED, $rows[0]['value']);
        $this->assertSame('payment/braintree/private_key', $rows[0]['path']);
        $this->assertSame('1', $rows[1]['value']);
        $this->assertSame(SqlGuard::REDACTED, $rows[2]['value']);
        $this->assertSame('a@b.com', $rows[3]['email']);
        $this->assertSame(SqlGuard::REDACTED, $rows[3]['password_hash']);
        $this->assertSame(SqlGuard::REDACTED, $rows[4]['note']);
    }
}
