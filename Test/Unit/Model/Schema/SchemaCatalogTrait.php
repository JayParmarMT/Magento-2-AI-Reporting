<?php
/**
 * Meetanshi AIReporting — builds a SchemaCatalog over a fixture database schema
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Schema;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\Schema\SchemaCatalog;

trait SchemaCatalogTrait
{
    /**
     * @param array<string, array{0: string, 1: string[]}> $tables table => [comment, columns]
     */
    private function createSchemaCatalog(array $tables, ?CacheInterface $cache = null): SchemaCatalog
    {
        $comments = [];
        $rows     = [];
        foreach ($tables as $table => [$comment, $columns]) {
            $comments[$table] = $comment;
            foreach ($columns as $column) {
                $rows[] = ['table_name' => $table, 'column_name' => $column];
            }
        }

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchPairs')->willReturn($comments);
        $connection->method('fetchAll')->willReturn($rows);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        if ($cache === null) {
            $cache = $this->createStub(CacheInterface::class);
            $cache->method('load')->willReturn(false);
        }

        return new SchemaCatalog(
            $resourceConnection,
            $cache,
            new Json(),
            new SqlGuard($this->createStub(DeploymentConfig::class))
        );
    }

    /**
     * A small store schema: core tables, a table with Adobe Commerce row_id, and third-party tables.
     *
     * @return array<string, array{0: string, 1: string[]}>
     */
    private static function storeSchema(): array
    {
        return [
            'sales_order' => ['Sales Flat Order', [
                'entity_id', 'increment_id', 'state', 'status', 'customer_id', 'customer_email', 'base_grand_total',
                'created_at', 'gift_message_id', 'eattachment_email_sent',
            ]],
            'sales_order_item' => ['Sales Flat Order Item', ['item_id', 'order_id', 'sku', 'name', 'qty_ordered']],
            'sales_shipment_track' => ['Sales Flat Shipment Track', ['entity_id', 'parent_id', 'track_number', 'carrier_code', 'created_at']],
            'customer_entity' => ['Customer Entity', ['entity_id', 'email', 'password_hash', 'created_at']],
            'customer_address_entity' => ['Customer Address Entity', ['entity_id', 'parent_id', 'city', 'country_id']],
            'catalog_product_entity_varchar' => ['Catalog Product Varchar Attribute Backend Table', [
                'value_id', 'attribute_id', 'store_id', 'row_id', 'value',
            ]],
            'catalogrule' => ['CatalogRule', ['rule_id', 'name', 'is_active', 'from_date', 'to_date']],
            'catalogrule_product_price' => ['CatalogRule Product Price', ['rule_product_price_id', 'product_id', 'rule_price']],
            'product_alert_stock' => ['Product Alert Stock', ['alert_stock_id', 'customer_id', 'product_id', 'add_date', 'status']],
            'product_alert_price' => ['Product Alert Price', ['alert_price_id', 'customer_id', 'product_id', 'price']],
            'meetanshi_sizechart' => ['Size Chart', ['sizechart_id', 'title', 'status', 'created_at']],
            'amasty_rewards_history' => ['', ['id', 'customer_id', 'amount', 'action', 'created_at']],
            'catalog_product_index_price_tmp' => ['Temporary index', ['entity_id', 'price']],
            'catalogrule_product_cl' => ['Changelog', ['version_id', 'entity_id']],
            'admin_user' => ['Admin User Table', ['user_id', 'username', 'password']],
        ];
    }
}
