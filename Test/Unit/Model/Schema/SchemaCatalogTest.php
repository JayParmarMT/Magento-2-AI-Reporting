<?php
/**
 * Meetanshi AIReporting — SchemaCatalog Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Schema;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;

class SchemaCatalogTest extends TestCase
{
    use SchemaCatalogTrait;

    public function testSkipsCredentialTablesSecretColumnsAndIndexTables(): void
    {
        $tables = $this->createSchemaCatalog(self::storeSchema())->getTables();

        $this->assertArrayHasKey('meetanshi_sizechart', $tables);
        $this->assertArrayNotHasKey('admin_user', $tables);
        $this->assertArrayNotHasKey('catalog_product_index_price_tmp', $tables);
        $this->assertArrayNotHasKey('catalogrule_product_cl', $tables);
        $this->assertNotContains('password_hash', $tables['customer_entity']['columns']);
        $this->assertSame('Size Chart', $tables['meetanshi_sizechart']['comment']);
    }

    public function testCachedSchemaIsUsedWithoutQueryingTheDatabase(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode([
            'cached_table' => ['comment' => '', 'columns' => ['id']],
        ]));
        $cache->expects($this->never())->method('save');

        $catalog = $this->createSchemaCatalog(self::storeSchema(), $cache);

        $this->assertSame(['cached_table'], array_keys($catalog->getTables()));
    }

    public function testSchemaIsCachedAfterTheFirstLoad(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')
            ->with($this->stringContains('meetanshi_sizechart'), 'meetanshi_aireporting_schema_catalog');

        $catalog = $this->createSchemaCatalog(self::storeSchema(), $cache);
        $catalog->getTables();
        $catalog->getTables();
    }

    public function testFindsThirdPartyTableByCompoundName(): void
    {
        $tables = $this->createSchemaCatalog(self::storeSchema())->findRelevantTables('How many size charts are enabled?');

        $this->assertSame('meetanshi_sizechart', $tables[0]);
    }

    public function testFindsCoreTablesOutsideTheStaticSchema(): void
    {
        $catalog = $this->createSchemaCatalog(self::storeSchema());

        $this->assertSame('product_alert_stock', $catalog->findRelevantTables('Which products have back in stock alerts?')[0]);
        $this->assertContains('sales_shipment_track', $catalog->findRelevantTables('Show tracking numbers of shipments'));
        $this->assertContains('catalogrule', $catalog->findRelevantTables('Which catalog price rules are active?'));
    }

    public function testSynonymsReachExtensionTables(): void
    {
        $tables = $this->createSchemaCatalog(self::storeSchema())->findRelevantTables('Top customers by loyalty points');

        $this->assertContains('amasty_rewards_history', $tables);
    }

    public function testWordsDoNotMatchInsideOtherWords(): void
    {
        // "sent" must not match addre-ss_ent-ity
        $tables = $this->createSchemaCatalog(self::storeSchema())->findRelevantTables('emails sent yesterday');

        $this->assertNotContains('customer_address_entity', $tables);
    }

    public function testPriorityTablesComeFirst(): void
    {
        $tables = $this->createSchemaCatalog(self::storeSchema())
            ->findRelevantTables('stock alerts', ['amasty_rewards_history']);

        $this->assertSame('amasty_rewards_history', $tables[0]);
        $this->assertContains('product_alert_stock', $tables);
    }

    public function testUnrelatedQuestionMatchesNothing(): void
    {
        $this->assertSame([], $this->createSchemaCatalog(self::storeSchema())->findRelevantTables('What is today revenue?'));
    }

    public function testDescribeTableListsMatchingColumnsFirst(): void
    {
        $line = $this->createSchemaCatalog(self::storeSchema())
            ->describeTable('sales_order', 'orders with an attachment', 'module Meetanshi_Eattachment');

        $this->assertStringStartsWith('- sales_order (eattachment_email_sent, entity_id,', $line);
        $this->assertStringEndsWith('-- Sales Flat Order [module Meetanshi_Eattachment]', $line);
    }

    public function testDescribeTableCapsColumns(): void
    {
        $line = $this->createSchemaCatalog(self::storeSchema())->describeTable('sales_order', '', '', 2);

        $this->assertSame('- sales_order (entity_id, increment_id, …) -- Sales Flat Order', $line);
    }

    public function testSuggestsSimilarTablesForAMissingOne(): void
    {
        $suggestions = $this->createSchemaCatalog(self::storeSchema())->suggestTables('sales_shipment_tracking');

        $this->assertSame('sales_shipment_track', $suggestions[0]);
    }

    public function testQuestionTermsIncludeSingularsCompoundsAndSynonyms(): void
    {
        $terms = $this->createSchemaCatalog([])->getQuestionTerms('Which gift cards and categories had returns?');

        $this->assertContains('giftcard', $terms);
        $this->assertContains('category', $terms);
        $this->assertContains('rma', $terms);
        $this->assertNotContains('which', $terms);
    }
}
