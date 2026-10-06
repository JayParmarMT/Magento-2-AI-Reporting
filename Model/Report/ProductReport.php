<?php
/**
 * Meetanshi AIReporting — Product Performance Report
 *
 * Product figures use top-level order items only (a configurable/bundle line is
 * counted once, not again for its child rows). Revenue is net of discounts and
 * refunds, in the global base currency; quantity is net of refunds/cancellations.
 * Cancelled and unpaid (pending_payment) orders are ignored.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

class ProductReport
{
    public function __construct(
        private readonly ReportContext $context
    ) {
    }

    /**
     * Product KPI cards.
     */
    public function getKpiCards(): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            $result[$period] = $this->getKpiTotals($period);
        }

        return $result;
    }

    /**
     * Product KPI totals for a single period.
     * total_products is the current catalog size (not time-bound);
     * sold metrics and never-sold are computed within the period.
     */
    private function getKpiTotals(string $period): array
    {
        $conn = $this->context->getConnection();
        [$start, $end] = $this->periodRange($period);
        [$from, $to]   = $this->context->utcRange($start, $end);

        $catalog = $conn->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*) AS total_products,
                COALESCE(SUM(type_id = 'simple'), 0) AS simple_products,
                COALESCE(SUM(type_id = 'configurable'), 0) AS configurable
            FROM {{catalog_product_entity}}
        ")) ?: [];

        $sales = $conn->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(DISTINCT oi.sku) AS total_skus_sold,
                COALESCE(SUM({$this->context->itemQtyExpr()}), 0) AS total_qty_sold
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]) ?: [];

        // Products never sold within this period
        $neverSold = (int) $conn->fetchOne($this->context->resolveTables("
            SELECT COUNT(*)
            FROM {{catalog_product_entity}} e
            WHERE NOT EXISTS (
                SELECT 1
                FROM {{sales_order_item}} oi
                JOIN {{sales_order}} o ON o.entity_id = oi.order_id
                WHERE oi.product_id = e.entity_id
                  AND {$this->context->validOrderCondition()}
                  AND o.created_at >= ? AND o.created_at < ?
            )
        "), [$from, $to]);

        return [
            'total_products'  => (int) ($catalog['total_products'] ?? 0),
            'simple_products' => (int) ($catalog['simple_products'] ?? 0),
            'configurable'    => (int) ($catalog['configurable'] ?? 0),
            'total_skus_sold' => (int) ($sales['total_skus_sold'] ?? 0),
            'total_qty_sold'  => (int) ($sales['total_qty_sold'] ?? 0),
            'never_sold'      => $neverSold,
        ];
    }

    /**
     * Return [start, end) local DateTimes for a named period.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function periodRange(string $period): array
    {
        $today    = $this->context->today();
        $tomorrow = $today->modify('+1 day');

        switch ($period) {
            case 'today':
                return [$today, $tomorrow];
            case 'week':
                return [$today->modify('monday this week'), $tomorrow];
            case 'year':
                return [$today->setDate((int) $today->format('Y'), 1, 1), $tomorrow];
            case 'month':
            default:
                return [$today->modify('first day of this month'), $tomorrow];
        }
    }

    /**
     * Top products by net revenue for each period (for the Top-10 bar chart).
     *
     * @return array<string, array>
     */
    public function getBestSellersByRevenuePerPeriod(int $limit = 10): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            [$start, $end] = $this->periodRange($period);
            $result[$period] = $this->getProductSalesForRange('total_revenue DESC', $limit, $start, $end);
        }
        return $result;
    }

    /**
     * Net revenue by product type for each period (for the pie chart).
     *
     * @return array<string, array>
     */
    public function getRevenueByProductTypePerPeriod(): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            [$start, $end] = $this->periodRange($period);
            [$from, $to]   = $this->context->utcRange($start, $end);

            $result[$period] = $this->context->getConnection()->fetchAll($this->context->resolveTables("
                SELECT
                    COALESCE(oi.product_type, 'unknown') AS product_type,
                    COUNT(DISTINCT oi.order_id) AS order_count,
                    SUM({$this->context->itemQtyExpr()}) AS total_qty,
                    ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS total_revenue
                FROM {{sales_order_item}} oi
                JOIN {{sales_order}} o ON o.entity_id = oi.order_id
                WHERE {$this->context->validOrderCondition()}
                  AND oi.parent_item_id IS NULL
                  AND o.created_at >= ? AND o.created_at < ?
                GROUP BY oi.product_type
                ORDER BY total_revenue DESC
            "), [$from, $to]);
        }
        return $result;
    }

    /**
     * Product sales aggregated over an explicit local [start, end) range.
     */
    private function getProductSalesForRange(string $orderBy, int $limit, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                oi.product_id,
                oi.sku,
                MAX(oi.name) AS product_name,
                SUM({$this->context->itemQtyExpr()}) AS total_qty_sold,
                COUNT(DISTINCT oi.order_id) AS order_count,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS total_revenue
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY oi.product_id, oi.sku
            ORDER BY {$orderBy}
            LIMIT " . max(1, $limit)), [$from, $to]);
    }

    /**
     * Best sellers by net revenue.
     */
    public function getBestSellersByRevenue(int $limit = 20): array
    {
        return $this->getProductSales('total_revenue DESC', $limit);
    }

    /**
     * Best sellers by net quantity.
     */
    public function getBestSellersByQty(int $limit = 20): array
    {
        return $this->getProductSales('total_qty_sold DESC', $limit);
    }

    /**
     * Lowest net revenue among products that have sold.
     */
    public function getWorstSellers(int $limit = 10): array
    {
        return $this->getProductSales('total_revenue ASC', $limit);
    }

    /**
     * Net revenue by product type.
     */
    public function getRevenueByProductType(): array
    {
        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                COALESCE(oi.product_type, 'unknown') AS product_type,
                COUNT(DISTINCT oi.order_id) AS order_count,
                SUM({$this->context->itemQtyExpr()}) AS total_qty,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS total_revenue
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
            GROUP BY oi.product_type
            ORDER BY total_revenue DESC
        "));
    }

    /**
     * Monthly trend for the top 5 SKUs (by net revenue) over the last 12 months.
     */
    public function getTopProductsTrend(): array
    {
        $conn  = $this->context->getConnection();
        $end   = $this->context->today()->modify('+1 day');
        $start = $this->context->today()->modify('first day of this month')->modify('-11 months');
        [$from, $to] = $this->context->utcRange($start, $end);
        $rev   = $this->context->itemRevenueExpr();

        $topSkus = $conn->fetchCol($this->context->resolveTables("
            SELECT oi.sku
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY oi.sku
            ORDER BY SUM({$rev}) DESC
            LIMIT 5
        "), [$from, $to]);

        if (empty($topSkus)) {
            return [];
        }

        $local   = $this->context->localTimeExpr('o.created_at', $start, $end);
        $skuCond = $conn->quoteInto('oi.sku IN (?)', $topSkus);

        $sql = $this->context->resolveTables("
            SELECT
                oi.sku,
                MAX(oi.name) AS product_name,
                DATE_FORMAT({$local}, '%Y-%m') AS month,
                DATE_FORMAT({$local}, '%b %Y') AS label,
                SUM({$this->context->itemQtyExpr()}) AS qty_sold,
                ROUND(SUM({$rev}), 2) AS revenue
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND __SKU_FILTER__
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY oi.sku, month, label
            ORDER BY month ASC, revenue DESC
        ");

        // SKU values are injected after table resolution so they can never be mistaken for tokens
        return $conn->fetchAll(str_replace('__SKU_FILTER__', $skuCond, $sql), [$from, $to]);
    }

    /**
     * Products that have never been part of a valid order.
     */
    public function getProductsNeverSold(int $limit = 20): array
    {
        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT e.entity_id, e.sku, e.type_id
            FROM {{catalog_product_entity}} e
            WHERE NOT EXISTS (
                SELECT 1
                FROM {{sales_order_item}} oi
                JOIN {{sales_order}} o ON o.entity_id = oi.order_id
                WHERE oi.product_id = e.entity_id
                  AND {$this->context->validOrderCondition()}
            )
            ORDER BY e.entity_id DESC
            LIMIT " . max(1, $limit)));
    }

    /**
     * Net revenue by category (admin/default store names, root categories excluded).
     * A product assigned to several categories counts towards each of them.
     */
    public function getRevenueByCategory(int $limit = 15): array
    {
        $link   = $this->context->getCategoryLinkField();
        $nameId = $this->context->getAttributeId('catalog_category', 'name');

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                cv.value AS category_name,
                COUNT(DISTINCT oi.order_id) AS order_count,
                SUM({$this->context->itemQtyExpr()}) AS total_qty,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS total_revenue
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            JOIN {{catalog_category_product}} ccp ON ccp.product_id = oi.product_id
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id AND ce.level >= 2{$this->context->currentVersionCondition('ce')}
            JOIN {{catalog_category_entity_varchar}} cv
                ON cv.{$link} = ce.{$link}
                AND cv.attribute_id = {$nameId}
                AND cv.store_id = 0
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
            GROUP BY ce.entity_id, cv.value
            ORDER BY total_revenue DESC
            LIMIT " . max(1, $limit)));
    }

    private function getProductSales(string $orderBy, int $limit): array
    {
        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                oi.product_id,
                oi.sku,
                MAX(oi.name) AS product_name,
                SUM({$this->context->itemQtyExpr()}) AS total_qty_sold,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS total_revenue,
                ROUND(AVG({$this->context->itemPriceExpr()}), 2) AS avg_price,
                COUNT(DISTINCT oi.order_id) AS order_count
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
            GROUP BY oi.product_id, oi.sku
            ORDER BY {$orderBy}
            LIMIT " . max(1, $limit)));
    }
}
