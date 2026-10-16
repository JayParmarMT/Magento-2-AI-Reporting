<?php
/**
 * Meetanshi AIReporting — removes the volume test data created by Test/perf-data/generate.php
 *
 * Only tagged rows are deleted; real orders, customers, products and rules are not touched:
 *   products   sku LIKE 'PT-%'
 *   customers  email LIKE '%@perftest.example' (and carts with that email)
 *   orders     ext_order_id = 'aireporting-perf' AND increment_id LIKE 'PT%', with their invoices,
 *              shipments, credit memos, grid rows and MSI reservations
 *   rules      name LIKE 'PerfTest:%'
 * Everything is deleted in one transaction.
 *
 * --scope=multistore deletes only what multistore.php added: orders PTM…, customers "ms.…@perftest.example"
 * or on a perf_* website, their carts and the reservations of those orders. multistore.php --remove runs it.
 *
 * Usage (from the Magento root):
 *   php app/code/Meetanshi/AIReporting/Test/perf-data/cleanup.php [--dry-run] [--scope=multistore]
 * then rebuild the indexes:
 *   bin/magento indexer:reindex customer_grid catalog_category_product catalog_product_category \
 *     catalog_product_attribute catalog_product_price cataloginventory_stock inventory catalogsearch_fulltext
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

$options = getopt('', ['dry-run', 'scope::']);
$dryRun = isset($options['dry-run']);
$multistore = ($options['scope'] ?? 'all') === 'multistore';
$root = dirname(__DIR__, 6);
require $root . '/app/bootstrap.php';

$resource = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(ResourceConnection::class);
$db = $resource->getConnection();
$t = static fn (string $table): string => $resource->getTableName($table);

$col = static fn (string $sql): array => array_map('intval', $db->fetchCol($sql));
$orders = $col("SELECT entity_id FROM {$t('sales_order')} WHERE ext_order_id = 'aireporting-perf' AND increment_id LIKE '"
    . ($multistore ? 'PTM' : 'PT') . "%'");
$in = static function (array $ids) use ($db): string {
    return $ids ? $db->quoteInto('IN (?)', $ids) : 'IN (NULL)';
};
$byOrder = static fn (string $table) => $orders
    ? array_merge(...array_map(
        static fn (array $chunk) => $col("SELECT entity_id FROM {$t($table)} WHERE order_id " . $in($chunk)),
        array_chunk($orders, 1000)
    ))
    : [];
$ids = [
    'orders'      => $orders,
    'invoices'    => $byOrder('sales_invoice'),
    'shipments'   => $byOrder('sales_shipment'),
    'creditmemos' => $byOrder('sales_creditmemo'),
    'customers'   => $col($multistore
        ? "SELECT c.entity_id FROM {$t('customer_entity')} c LEFT JOIN {$t('store_website')} w ON w.website_id = c.website_id
           WHERE c.email LIKE '%@perftest.example' AND (c.email LIKE 'ms.%' OR w.code IN ('perf_eu', 'perf_uk'))"
        : "SELECT entity_id FROM {$t('customer_entity')} WHERE email LIKE '%@perftest.example'"),
    'products'    => $multistore ? [] : $col("SELECT entity_id FROM {$t('catalog_product_entity')} WHERE sku LIKE 'PT-%'"),
    'quotes'      => $col("SELECT entity_id FROM {$t('quote')} WHERE customer_email LIKE '"
        . ($multistore ? 'ms.' : '') . "%@perftest.example'"),
    'rules'       => $multistore ? [] : $col("SELECT rule_id FROM {$t('salesrule')} WHERE name LIKE 'PerfTest:%'"),
];
$ids['super_attributes'] = $ids['products']
    ? $col("SELECT product_super_attribute_id FROM {$t('catalog_product_super_attribute')} WHERE product_id " . $in($ids['products']))
    : [];

// [table, column, id list] — children before parents
$plan = [
    ['sales_order_grid', 'entity_id', 'orders'], ['sales_invoice_grid', 'order_id', 'orders'],
    ['sales_shipment_grid', 'order_id', 'orders'], ['sales_creditmemo_grid', 'order_id', 'orders'],
    ['sales_invoice_item', 'parent_id', 'invoices'], ['sales_invoice_comment', 'parent_id', 'invoices'],
    ['sales_invoice', 'entity_id', 'invoices'],
    ['sales_shipment_track', 'parent_id', 'shipments'], ['sales_shipment_item', 'parent_id', 'shipments'],
    ['sales_shipment_comment', 'parent_id', 'shipments'], ['sales_shipment', 'entity_id', 'shipments'],
    ['sales_creditmemo_item', 'parent_id', 'creditmemos'], ['sales_creditmemo_comment', 'parent_id', 'creditmemos'],
    ['sales_creditmemo', 'entity_id', 'creditmemos'],
    ['sales_order_status_history', 'parent_id', 'orders'], ['sales_order_payment', 'parent_id', 'orders'],
    ['sales_order_address', 'parent_id', 'orders'], ['sales_order_tax', 'order_id', 'orders'],
    ['sales_payment_transaction', 'order_id', 'orders'], ['sales_order_item', 'order_id', 'orders'],
    ['sales_order', 'entity_id', 'orders'],
    ['quote_item', 'quote_id', 'quotes'], ['quote_address', 'quote_id', 'quotes'], ['quote_payment', 'quote_id', 'quotes'],
    ['quote', 'entity_id', 'quotes'], ['quote_item', 'product_id', 'products'],
    ['customer_grid_flat', 'entity_id', 'customers'], ['customer_log', 'customer_id', 'customers'],
    ['customer_address_entity', 'parent_id', 'customers'], ['customer_entity', 'entity_id', 'customers'],
    ['catalog_product_entity_varchar', 'entity_id', 'products'], ['catalog_product_entity_int', 'entity_id', 'products'],
    ['catalog_product_entity_decimal', 'entity_id', 'products'], ['catalog_product_entity_text', 'entity_id', 'products'],
    ['catalog_product_entity_datetime', 'entity_id', 'products'], ['catalog_product_website', 'product_id', 'products'],
    ['catalog_category_product', 'product_id', 'products'],
    ['catalog_product_super_attribute_label', 'product_super_attribute_id', 'super_attributes'],
    ['catalog_product_super_attribute', 'product_id', 'products'], ['catalog_product_super_link', 'product_id', 'products'],
    ['catalog_product_super_link', 'parent_id', 'products'], ['catalog_product_relation', 'parent_id', 'products'],
    ['catalog_product_relation', 'child_id', 'products'], ['cataloginventory_stock_item', 'product_id', 'products'],
    ['cataloginventory_stock_status', 'product_id', 'products'], ['catalog_product_entity', 'entity_id', 'products'],
    ['salesrule_coupon', 'rule_id', 'rules'], ['salesrule_website', 'rule_id', 'rules'],
    ['salesrule_customer_group', 'rule_id', 'rules'], ['salesrule_label', 'rule_id', 'rules'],
    ['salesrule_product_attribute', 'rule_id', 'rules'], ['salesrule', 'rule_id', 'rules'],
];
$extra = $multistore
    ? ['inventory_reservation' => "metadata LIKE '%\"object_increment_id\":\"PTM%'"]
    : [
        'inventory_reservation' => "sku LIKE 'PT-%' OR metadata LIKE '%\"object_increment_id\":\"PT%'",
        'inventory_source_item' => "sku LIKE 'PT-%'",
        'url_rewrite'           => "entity_type = 'product' AND entity_id " . $in($ids['products']),
    ];

echo ($dryRun ? 'Dry run — would delete' : 'Deleting') . ': ' . implode(', ', array_map(
    static fn ($key) => count($ids[$key]) . ' ' . $key,
    ['orders', 'invoices', 'shipments', 'creditmemos', 'customers', 'products', 'quotes', 'rules']
)) . "\n";

$counts = [];
$db->beginTransaction();
try {
    foreach ($extra as $table => $where) {
        if ($db->isTableExists($t($table))) {
            $counts[$table] = $dryRun
                ? (int) $db->fetchOne("SELECT COUNT(*) FROM {$t($table)} WHERE {$where}")
                : $db->delete($t($table), $where);
        }
    }
    foreach ($plan as [$table, $column, $key]) {
        if (!$ids[$key] || !$db->isTableExists($t($table))) {
            continue;
        }
        foreach (array_chunk($ids[$key], 1000) as $chunk) {
            $where = $column . ' ' . $in($chunk);
            $counts[$table] = ($counts[$table] ?? 0) + ($dryRun
                ? (int) $db->fetchOne("SELECT COUNT(*) FROM {$t($table)} WHERE {$where}")
                : $db->delete($t($table), $where));
        }
    }
    $dryRun ? $db->rollBack() : $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Failed, nothing was deleted: {$e->getMessage()}\n");
    exit(1);
}

foreach (array_filter($counts) as $table => $count) {
    printf("  %-40s %8d\n", $table, $count);
}
echo $dryRun ? "Dry run: nothing was deleted. (Each step is counted on its own, so tables cleaned in two passes,"
    . " such as quote_item and catalog_product_relation, show twice their real count.)\n" : "Done. Now run: bin/magento indexer:reindex customer_grid catalog_category_product"
    . " catalog_product_category catalog_product_attribute catalog_product_price cataloginventory_stock inventory catalogsearch_fulltext\n";
