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

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Exception\DirectAnswerException;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Model\LLM\ProviderInterface;
use Meetanshi\AIReporting\Model\LLM\ProviderPool;
use Meetanshi\AIReporting\Model\Query\ExampleFinder;
use Meetanshi\AIReporting\Model\Query\NlpToSql;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use Meetanshi\AIReporting\Model\Schema\SchemaCatalog;
use Meetanshi\AIReporting\Test\Unit\Model\Report\ReportContextTrait;
use Meetanshi\AIReporting\Test\Unit\Model\Schema\SchemaCatalogTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NlpToSqlTest extends TestCase
{
    use ReportContextTrait;
    use SchemaCatalogTrait;

    private NlpToSql $nlpToSql;
    private ProviderPool|MockObject $providerPool;
    private ProviderInterface|MockObject $provider;

    protected function setUp(): void
    {
        $this->providerPool = $this->createMock(ProviderPool::class);
        $this->provider     = $this->createMock(ProviderInterface::class);

        $this->providerPool->method('getActiveProvider')
            ->willReturn($this->provider);

        $this->nlpToSql = $this->createNlpToSql(
            $this->createStub(SchemaCatalog::class),
            $this->createStub(ModuleCatalog::class)
        );
    }

    private function createNlpToSql(
        SchemaCatalog $schemaCatalog,
        ModuleCatalog $moduleCatalog,
        ?ExampleFinder $exampleFinder = null
    ): NlpToSql {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->createStub(AdapterInterface::class));

        return new NlpToSql(
            $this->providerPool,
            $resourceConnection,
            $this->createReportContext($this->createMock(AdapterInterface::class), 'America/Chicago'),
            new SqlGuard($this->createMock(DeploymentConfig::class)),
            $schemaCatalog,
            $moduleCatalog,
            $exampleFinder ?? $this->createStub(ExampleFinder::class)
        );
    }

    /**
     * NlpToSql over the fixture store schema; the question mentioning "size chart" names Acme_SizeChart,
     * and Meetanshi_Eattachment adds a column to sales_order.
     */
    private function createNlpToSqlForStore(): NlpToSql
    {
        $moduleCatalog = $this->createStub(ModuleCatalog::class);
        $moduleCatalog->method('detect')->willReturnCallback(
            static fn (string $question) => str_contains(strtolower($question), 'size chart') ? ['Acme_SizeChart'] : []
        );
        $moduleCatalog->method('getOwnedTables')->willReturn(['meetanshi_sizechart']);
        $moduleCatalog->method('getTableOwner')->willReturnCallback(
            static fn (string $table) => $table === 'meetanshi_sizechart' ? 'Acme_SizeChart' : 'Magento_Sales'
        );
        $moduleCatalog->method('isThirdParty')->willReturnCallback(static fn (string $module) => !str_starts_with($module, 'Magento_'));
        $moduleCatalog->method('getThirdPartyColumns')->willReturn([
            'sales_order' => ['eattachment_email_sent' => 'Meetanshi_Eattachment'],
        ]);

        return $this->createNlpToSql($this->createSchemaCatalog(self::storeSchema()), $moduleCatalog);
    }

    /**
     * System prompt and question prompt as sent to the provider, joined.
     *
     * @param array<int, array{question: string, sql: string}> $history
     */
    private function capturePrompt(
        NlpToSql $nlpToSql,
        string $question,
        string $reply = 'SELECT 1',
        array $history = []
    ): string {
        $prompt = '';
        $this->provider->method('complete')->willReturnCallback(
            function (string $text, string $system = '') use (&$prompt, $reply) {
                $prompt = $system . "\n" . $text;
                return $reply;
            }
        );
        $nlpToSql->convert($question, $history);

        return $prompt;
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

    #[DataProvider('forbiddenStatementProvider')]
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

    public function testPromptIncludesStoreTimezoneCurrencyAndSalesRules(): void
    {
        $this->provider->expects($this->once())
            ->method('complete')
            ->with($this->callback(function (string $prompt) {
                return str_contains($prompt, 'STORE FACTS')
                    && str_contains($prompt, 'Store timezone: America/Chicago')
                    && str_contains($prompt, 'base currency USD')
                    && str_contains($prompt, "state IN ('canceled','pending_payment')")
                    && !str_contains($prompt, 'price attribute_id = 75');
            }))
            ->willReturn('SELECT 1');

        $this->nlpToSql->convert('Revenue today');
    }

    // ── Plain-text answers and replies wrapped in prose ─────────────────

    public function testDirectAnswerIsRaisedForNonDataQuestions(): void
    {
        $this->provider->method('complete')
            ->willReturn("ANSWER: Go to System > Index Management, or run bin/magento indexer:reindex.");

        try {
            $this->nlpToSql->convert('How do I reindex?');
            $this->fail('A direct answer was expected.');
        } catch (DirectAnswerException $e) {
            $this->assertSame('Go to System > Index Management, or run bin/magento indexer:reindex.', $e->getAnswer());
        }
    }

    #[DataProvider('wrappedQueryProvider')]
    public function testQueryIsTakenOutOfSurroundingProse(string $reply): void
    {
        $this->provider->method('complete')->willReturn($reply);

        $this->assertSame('SELECT COUNT(*) AS orders FROM sales_order', $this->nlpToSql->convert('How many orders?'));
    }

    public static function wrappedQueryProvider(): array
    {
        return [
            'intro line'        => ["Here is the query:\nSELECT COUNT(*) AS orders FROM sales_order"],
            'intro on one line' => ["SQL: SELECT COUNT(*) AS orders FROM sales_order"],
            'explanation after' => ["SELECT COUNT(*) AS orders FROM sales_order;\n\nThis query counts every order."],
            'fenced in prose'   => ["Sure!\n```sql\nSELECT COUNT(*) AS orders FROM sales_order\n```\nHope it helps."],
        ];
    }

    public function testSecondStatementAfterTheQueryIsStillRejected(): void
    {
        $this->provider->method('complete')->willReturn("SELECT entity_id FROM sales_order;\nDROP TABLE sales_order");

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Security violation');

        $this->nlpToSql->convert('orders');
    }

    public function testWriteStatementBeforeASelectIsStillRejected(): void
    {
        $this->provider->method('complete')->willReturn("DELETE FROM sales_order\nWHERE entity_id IN (\nSELECT 1)");

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Security violation');

        $this->nlpToSql->convert('orders');
    }

    // ── Live schema: any table of the store, third-party modules ─────────

    public function testPromptIncludesTablesOfTheModuleTheQuestionNames(): void
    {
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'How many size charts are enabled?');

        $this->assertStringContainsString('═══ MORE TABLES FROM THIS STORE', $prompt);
        $this->assertStringContainsString(
            '- meetanshi_sizechart (sizechart_id, title, status, created_at) -- Size Chart [module Acme_SizeChart]',
            $prompt
        );
    }

    public function testPromptIncludesCoreTablesOutsideTheStaticSchema(): void
    {
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'Which products have back in stock alerts?');

        $this->assertStringContainsString('- product_alert_stock (', $prompt);
    }

    public function testPromptPointsOutColumnsModulesAddToCoreTables(): void
    {
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'How many orders had the email attachment sent?');

        $this->assertStringContainsString(
            '- sales_order also has: eattachment_email_sent [added by module Meetanshi_Eattachment]',
            $prompt
        );
    }

    public function testPromptForCoreQuestionsAddsNoExtraTables(): void
    {
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'What is today revenue?');

        $this->assertStringNotContainsString('═══ MORE TABLES FROM THIS STORE', $prompt);
    }

    public function testStaticSchemaKeepsOnlyColumnsAndTablesThisStoreHas(): void
    {
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'Revenue today');

        // Adobe Commerce: EAV value tables use row_id, not entity_id
        $this->assertStringContainsString('- catalog_product_entity_varchar (value_id, attribute_id, store_id, value)', $prompt);
        // Tables missing from the store are not offered
        $this->assertStringNotContainsString('- wishlist_item (', $prompt);
        $this->assertStringContainsString('- sales_order (entity_id, increment_id', $prompt);
    }

    public function testRepairShowsRealColumnsAndSimilarTables(): void
    {
        $nlpToSql = $this->createNlpToSqlForStore();
        $prompt   = $this->capturePrompt($nlpToSql, 'warm up');

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->expects($this->once())->method('complete')
            ->with($this->callback(function (string $text) use (&$prompt) {
                $prompt = $text;
                return true;
            }))
            ->willReturn('SELECT track_number FROM sales_shipment_track');
        $this->providerPool = $this->createStub(ProviderPool::class);
        $this->providerPool->method('getActiveProvider')->willReturn($this->provider);
        $nlpToSql = $this->createNlpToSqlForStore();

        $nlpToSql->repair(
            'tracking numbers',
            'SELECT o.tracking FROM sales_order o JOIN sales_shipment_tracking t ON t.order_id = o.entity_id',
            "Table 'm249.sales_shipment_tracking' doesn't exist"
        );

        $this->assertStringContainsString('Actual columns of the tables involved', $prompt);
        $this->assertStringContainsString('- sales_order (entity_id, increment_id,', $prompt);
        $this->assertStringContainsString(
            '- sales_shipment_tracking does NOT exist in this database. Existing tables with similar names: sales_shipment_track',
            $prompt
        );
    }

    // ── System prompt, assumptions, unknown words, examples, follow-ups ──

    public function testSchemaAndRulesGoInAStableSystemPrompt(): void
    {
        $nlpToSql = $this->createNlpToSqlForStore();
        $calls    = [];
        $this->provider->method('complete')->willReturnCallback(
            function (string $prompt, string $system = '') use (&$calls) {
                $calls[] = [$prompt, $system];
                return 'SELECT 1';
            }
        );

        $nlpToSql->convert('Orders today');
        $nlpToSql->convert('Size chart entries');

        $this->assertSame($calls[0][1], $calls[1][1], 'The system prompt must not change between questions');
        $this->assertStringContainsString('- sales_order (entity_id, increment_id', $calls[0][1]);
        $this->assertStringContainsString('Rules:', $calls[0][1]);
        $this->assertStringNotContainsString('Orders today', $calls[0][1]);
        $this->assertStringContainsString('Question: Orders today', $calls[0][0]);
        $this->assertStringNotContainsString('- sales_order (entity_id, increment_id', $calls[0][0]);
    }

    public function testAssumptionLineIsReturnedSeparatelyFromTheSql(): void
    {
        $this->provider->method('complete')->willReturn(
            "ASSUMPTION: 'returns' read as credit memos.\nSELECT COUNT(*) FROM sales_creditmemo"
        );

        $this->assertSame('SELECT COUNT(*) FROM sales_creditmemo', $this->nlpToSql->convert('How many returns?'));
        $this->assertSame("'returns' read as credit memos.", $this->nlpToSql->getLastAssumption());
    }

    public function testAssumptionIsResetForTheNextQuestion(): void
    {
        $this->provider->method('complete')->willReturnOnConsecutiveCalls(
            "ASSUMPTION: something\nSELECT 1",
            'SELECT 2'
        );

        $this->nlpToSql->convert('first');
        $this->nlpToSql->convert('second');

        $this->assertSame('', $this->nlpToSql->getLastAssumption());
    }

    public function testWordsMatchingNothingInTheSchemaAreFlagged(): void
    {
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'How many gift registries were created?');

        $this->assertStringContainsString('No table, column or table comment matches: registries.', $prompt);
    }

    public function testLoyaltyIsFlaggedOnAStoreWithoutRewardTablesDespiteTheRuleExample(): void
    {
        $schema = self::storeSchema();
        unset($schema['amasty_rewards_history']);
        $nlpToSql = $this->createNlpToSql($this->createSchemaCatalog($schema), $this->createStub(ModuleCatalog::class));

        $prompt = $this->capturePrompt($nlpToSql, 'How many customers have loyalty cards?');

        $this->assertStringContainsString('No table, column or table comment matches: loyalty, cards.', $prompt);
    }

    public function testWordsWithAMatchingTableOrMeasureWordsAreNotFlagged(): void
    {
        // "loyalty" → the rewards table; "spend" describes a measure
        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'Top customers by loyalty points and spend');

        $this->assertStringNotContainsString('WORDS WITH NO MATCH', $prompt);
    }

    public function testSimilarSavedReportsAreIncludedAsExamples(): void
    {
        $examples = $this->createStub(ExampleFinder::class);
        $examples->method('find')->willReturn([
            ['question' => 'Top 10 best selling products', 'sql' => "SELECT oi.sku\nFROM sales_order_item oi LIMIT 10"],
        ]);
        $nlpToSql = $this->createNlpToSql(
            $this->createSchemaCatalog(self::storeSchema()),
            $this->createStub(ModuleCatalog::class),
            $examples
        );

        $prompt = $this->capturePrompt($nlpToSql, 'Best selling products this month');

        $this->assertStringContainsString('SAVED REPORTS SIMILAR TO THIS QUESTION', $prompt);
        $this->assertStringContainsString(
            "Q: Top 10 best selling products\nSQL: SELECT oi.sku FROM sales_order_item oi LIMIT 10",
            $prompt
        );
    }

    public function testFollowUpGetsEarlierTurnsAndTheirTables(): void
    {
        $history = [['question' => 'How many size chart entries are active?', 'sql' => 'SELECT COUNT(*) FROM meetanshi_sizechart']];

        $prompt = $this->capturePrompt($this->createNlpToSqlForStore(), 'And by month?', 'SELECT 1', $history);

        $this->assertStringContainsString('EARLIER IN THIS CONVERSATION', $prompt);
        $this->assertStringContainsString("Q: How many size chart entries are active?\nSQL: SELECT COUNT(*) FROM meetanshi_sizechart", $prompt);
        // The follow-up names no table: tables are matched on the earlier question too
        $this->assertStringContainsString('- meetanshi_sizechart (', $prompt);
        $this->assertStringContainsString('Question: And by month?', $prompt);
    }

    public function testRepairKeepsTheAssumptionOfTheFirstAttempt(): void
    {
        $this->provider->method('complete')->willReturnOnConsecutiveCalls(
            "ASSUMPTION: 'returns' read as credit memos.\nSELECT COUNT(*) FROM sales_creditmemos",
            'SELECT COUNT(*) FROM sales_creditmemo'
        );

        $this->nlpToSql->convert('How many returns?');
        $this->nlpToSql->repair('How many returns?', 'SELECT COUNT(*) FROM sales_creditmemos', "Table doesn't exist");

        $this->assertSame("'returns' read as credit memos.", $this->nlpToSql->getLastAssumption());
    }
}
