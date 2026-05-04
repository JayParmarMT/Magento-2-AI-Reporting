<?php
/**
 * Meetanshi AIReporting — Product Performance Report Model
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

use Magento\Framework\App\ResourceConnection;

class ProductReport
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
     * Product KPIs
     */
    public function getKpiCards(): array
    {
        $conn = $this->getConnection();

        return [
            'total_products'    => $conn->fetchOne("SELECT COUNT(*) FROM catalog_product_entity"),
            'simple_products'   => $conn->fetchOne("SELECT COUNT(*) FROM catalog_product_entity WHERE type_id='simple'"),
            'configurable'      => $conn->fetchOne("SELECT COUNT(*) FROM catalog_product_entity WHERE type_id='configurable'"),
            'total_skus_sold'   => $conn->fetchOne("SELECT COUNT(DISTINCT sku) FROM sales_order_item"),
            'total_qty_sold'    => $conn->fetchOne("SELECT COALESCE(SUM(qty_ordered),0) FROM sales_order_item oi JOIN sales_order o ON o.entity_id=oi.order_id WHERE o.status NOT IN ('canceled')"),
        ];
    }

    /**
     * Best Selling Products by Revenue
     */
    public function getBestSellersByRevenue(int $limit = 20): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                oi.product_id,
                oi.sku,
                oi.name as product_name,
                SUM(oi.qty_ordered) as total_qty_sold,
                ROUND(SUM(oi.row_total), 2) as total_revenue,
                ROUND(AVG(oi.price), 2) as avg_price,
                COUNT(DISTINCT oi.order_id) as order_count
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            WHERE o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY oi.product_id, oi.sku, oi.name
            ORDER BY total_revenue DESC
            LIMIT {$limit}
        ");
    }

    /**
     * Best Selling Products by Quantity
     */
    public function getBestSellersByQty(int $limit = 20): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                oi.product_id,
                oi.sku,
                oi.name as product_name,
                SUM(oi.qty_ordered) as total_qty_sold,
                ROUND(SUM(oi.row_total), 2) as total_revenue,
                COUNT(DISTINCT oi.order_id) as order_count
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            WHERE o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY oi.product_id, oi.sku, oi.name
            ORDER BY total_qty_sold DESC
            LIMIT {$limit}
        ");
    }

    /**
     * Worst Performing Products (least revenue)
     */
    public function getWorstSellers(int $limit = 10): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                oi.sku,
                oi.name as product_name,
                SUM(oi.qty_ordered) as total_qty_sold,
                ROUND(SUM(oi.row_total), 2) as total_revenue
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            WHERE o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY oi.sku, oi.name
            ORDER BY total_revenue ASC
            LIMIT {$limit}
        ");
    }

    /**
     * Revenue by Product Type
     */
    public function getRevenueByProductType(): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                COALESCE(oi.product_type, 'unknown') as product_type,
                COUNT(DISTINCT oi.order_id) as order_count,
                SUM(oi.qty_ordered) as total_qty,
                ROUND(SUM(oi.row_total), 2) as total_revenue
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            WHERE o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY oi.product_type
            ORDER BY total_revenue DESC
        ");
    }

    /**
     * Product Sales Trend (last 12 months) — top 5 products
     */
    public function getTopProductsTrend(): array
    {
        // Get top 5 product SKUs first
        $topSkus = $this->getConnection()->fetchCol("
            SELECT oi.sku
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            WHERE o.status NOT IN ('canceled') AND oi.parent_item_id IS NULL
            GROUP BY oi.sku
            ORDER BY SUM(oi.row_total) DESC
            LIMIT 5
        ");

        if (empty($topSkus)) {
            return [];
        }

        $skuList = implode("','", array_map('addslashes', $topSkus));

        return $this->getConnection()->fetchAll("
            SELECT
                oi.sku,
                oi.name as product_name,
                DATE_FORMAT(o.created_at, '%Y-%m') as month,
                DATE_FORMAT(o.created_at, '%b %Y') as label,
                SUM(oi.qty_ordered) as qty_sold,
                ROUND(SUM(oi.row_total), 2) as revenue
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            WHERE o.status NOT IN ('canceled')
              AND oi.sku IN ('{$skuList}')
              AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY oi.sku, oi.name, DATE_FORMAT(o.created_at, '%Y-%m')
            ORDER BY month ASC, revenue DESC
        ");
    }

    /**
     * Products Never Sold
     */
    public function getProductsNeverSold(int $limit = 20): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                e.entity_id,
                e.sku,
                e.type_id
            FROM catalog_product_entity e
            WHERE e.entity_id NOT IN (
                SELECT DISTINCT product_id FROM sales_order_item WHERE product_id IS NOT NULL
            )
            ORDER BY e.entity_id DESC
            LIMIT {$limit}
        ");
    }

    /**
     * Average Order Value by Product Category (via catalog_category_product)
     */
    public function getRevenueByCategory(int $limit = 15): array
    {
        return $this->getConnection()->fetchAll("
            SELECT
                ccev.value as category_name,
                COUNT(DISTINCT oi.order_id) as order_count,
                SUM(oi.qty_ordered) as total_qty,
                ROUND(SUM(oi.row_total), 2) as total_revenue
            FROM sales_order_item oi
            JOIN sales_order o ON o.entity_id = oi.order_id
            JOIN catalog_category_product ccp ON ccp.product_id = oi.product_id
            JOIN catalog_category_entity_varchar ccev
                ON ccev.entity_id = ccp.category_id
                AND ccev.attribute_id = (
                    SELECT attribute_id FROM eav_attribute
                    WHERE attribute_code = 'name'
                    AND entity_type_id = (
                        SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category'
                    )
                )
                AND ccev.store_id = 0
            WHERE o.status NOT IN ('canceled')
              AND oi.parent_item_id IS NULL
            GROUP BY ccev.value
            ORDER BY total_revenue DESC
            LIMIT {$limit}
        ");
    }
}
