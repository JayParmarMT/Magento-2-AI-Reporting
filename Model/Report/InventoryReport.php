<?php
/**
 * Meetanshi AIReporting — Inventory Report Model
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

use Magento\Framework\App\ResourceConnection;

class InventoryReport
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    private function getConnection()
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * Inventory KPIs
     */
    public function getKpiCards(): array
    {
        $conn = $this->getConnection();

        return [
            'total_skus'        => $conn->fetchOne("SELECT COUNT(*) FROM cataloginventory_stock_item"),
            'in_stock'          => $conn->fetchOne("SELECT COUNT(*) FROM cataloginventory_stock_item WHERE is_in_stock = 1"),
            'out_of_stock'      => $conn->fetchOne("SELECT COUNT(*) FROM cataloginventory_stock_item WHERE is_in_stock = 0"),
            'low_stock'         => $conn->fetchOne("SELECT COUNT(*) FROM cataloginventory_stock_item WHERE qty > 0 AND qty <= 10 AND is_in_stock = 1"),
            'total_stock_value' => $conn->fetchOne("
                SELECT COALESCE(ROUND(SUM(si.qty * pp.value), 2), 0)
                FROM cataloginventory_stock_item si
                JOIN catalog_product_entity_decimal pp
                  ON pp.entity_id = si.product_id
                  AND pp.attribute_id = (
                      SELECT attribute_id FROM eav_attribute
                      WHERE attribute_code = 'price'
                      AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
                  )
                  AND pp.store_id = 0
                WHERE si.qty > 0
            "),
        ];
    }

    /**
     * Low Stock Products (qty <= threshold)
     */
    public function getLowStockProducts(int $threshold = 10, int $limit = 30): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                e.entity_id,
                e.sku,
                COALESCE(pv.value, e.sku) as product_name,
                si.qty,
                si.min_qty,
                si.is_in_stock,
                COALESCE(pp.value, 0) as price
            FROM cataloginventory_stock_item si
            JOIN catalog_product_entity e ON e.entity_id = si.product_id
            LEFT JOIN catalog_product_entity_varchar pv
                ON pv.entity_id = e.entity_id
                AND pv.attribute_id = (
                    SELECT attribute_id FROM eav_attribute
                    WHERE attribute_code = 'name'
                    AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
                )
                AND pv.store_id = 0
            LEFT JOIN catalog_product_entity_decimal pp
                ON pp.entity_id = e.entity_id
                AND pp.attribute_id = (
                    SELECT attribute_id FROM eav_attribute
                    WHERE attribute_code = 'price'
                    AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
                )
                AND pp.store_id = 0
            WHERE si.qty <= {$threshold} AND si.qty >= 0
            ORDER BY si.qty ASC
            LIMIT {$limit}
        ");
    }

    /**
     * Out of Stock Products
     */
    public function getOutOfStockProducts(int $limit = 30): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                e.entity_id,
                e.sku,
                COALESCE(pv.value, e.sku) as product_name,
                si.qty,
                COALESCE(pp.value, 0) as price
            FROM cataloginventory_stock_item si
            JOIN catalog_product_entity e ON e.entity_id = si.product_id
            LEFT JOIN catalog_product_entity_varchar pv
                ON pv.entity_id = e.entity_id
                AND pv.attribute_id = (
                    SELECT attribute_id FROM eav_attribute
                    WHERE attribute_code = 'name'
                    AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
                )
                AND pv.store_id = 0
            LEFT JOIN catalog_product_entity_decimal pp
                ON pp.entity_id = e.entity_id
                AND pp.attribute_id = (
                    SELECT attribute_id FROM eav_attribute
                    WHERE attribute_code = 'price'
                    AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
                )
                AND pp.store_id = 0
            WHERE si.is_in_stock = 0
            ORDER BY pp.value DESC
            LIMIT {$limit}
        ");
    }

    /**
     * Stock Distribution (qty ranges)
     */
    public function getStockDistribution(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                CASE
                    WHEN qty = 0 THEN 'Out of Stock'
                    WHEN qty <= 5 THEN '1-5 (Critical)'
                    WHEN qty <= 10 THEN '6-10 (Low)'
                    WHEN qty <= 50 THEN '11-50 (Medium)'
                    WHEN qty <= 100 THEN '51-100 (Good)'
                    ELSE '100+ (High)'
                END as stock_range,
                COUNT(*) as product_count
            FROM cataloginventory_stock_item
            GROUP BY stock_range
            ORDER BY FIELD(stock_range,
                'Out of Stock','1-5 (Critical)','6-10 (Low)',
                '11-50 (Medium)','51-100 (Good)','100+ (High)')
        ");
    }

    /**
     * Top Selling Products vs Current Stock (demand vs supply)
     */
    public function getDemandVsSupply(int $limit = 15): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                oi.sku,
                oi.name as product_name,
                SUM(oi.qty_ordered) as qty_sold_30d,
                COALESCE(si.qty, 0) as current_stock,
                COALESCE(si.is_in_stock, 0) as is_in_stock,
                CASE
                    WHEN COALESCE(si.qty, 0) = 0 THEN 'OUT OF STOCK'
                    WHEN COALESCE(si.qty, 0) < SUM(oi.qty_ordered) / 30 * 7 THEN 'CRITICAL'
                    WHEN COALESCE(si.qty, 0) < SUM(oi.qty_ordered) / 30 * 14 THEN 'LOW'
                    ELSE 'OK'
                END as stock_status
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            LEFT JOIN catalog_product_entity e ON e.sku = oi.sku
            LEFT JOIN cataloginventory_stock_item si ON si.product_id = e.entity_id
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY oi.sku, oi.name, si.qty, si.is_in_stock
            ORDER BY qty_sold_30d DESC
            LIMIT {$limit}
        ");
    }

    /**
     * Inventory Turnover Rate (last 30 days)
     */
    public function getInventoryTurnover(int $limit = 20): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                oi.sku,
                oi.name as product_name,
                SUM(oi.qty_ordered) as qty_sold,
                COALESCE(si.qty, 0) as current_stock,
                CASE
                    WHEN COALESCE(si.qty, 0) > 0
                    THEN ROUND(SUM(oi.qty_ordered) / si.qty, 2)
                    ELSE NULL
                END as turnover_rate
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            LEFT JOIN catalog_product_entity e ON e.sku = oi.sku
            LEFT JOIN cataloginventory_stock_item si ON si.product_id = e.entity_id
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY oi.sku, oi.name, si.qty
            HAVING turnover_rate IS NOT NULL
            ORDER BY turnover_rate DESC
            LIMIT {$limit}
        ");
    }
}
