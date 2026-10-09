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

    // ── Range-based figures (Product Performance page) ───────────────────

    /**
     * Catalog size now: all products, enabled ones, and how many were created in [start, end).
     */
    public function getCatalogSummary(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM({$this->enabledExpr()}), 0) AS enabled,
                COALESCE(SUM(e.created_at >= ? AND e.created_at < ?), 0) AS created
            FROM {{catalog_product_entity}} e
            {$this->statusJoin()}
            WHERE 1 = 1{$this->context->currentVersionCondition('e')}
        "), [$from, $to]) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $enabled = (int) ($row['enabled'] ?? 0);

        return ['total' => $total, 'enabled' => $enabled, 'disabled' => $total - $enabled, 'created' => (int) ($row['created'] ?? 0)];
    }

    /**
     * Sales totals for [start, end): units, net revenue and orders (top-level lines), and how many
     * sellable catalog products (enabled, not grouped) sold at least once — as an order line or a
     * configurable/bundle child.
     */
    public function getSalesTotals(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $conn = $this->context->getConnection();
        [$from, $to] = $this->context->utcRange($start, $end);

        $sales = $conn->fetchRow($this->context->resolveTables("
            SELECT
                COALESCE(SUM({$this->context->itemQtyExpr()}), 0) AS units,
                COALESCE(SUM({$this->context->itemRevenueExpr()}), 0) AS revenue,
                COUNT(DISTINCT oi.order_id) AS orders
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]) ?: [];

        $catalog = $conn->fetchRow($this->context->resolveTables("
            SELECT COUNT(*) AS sellable, COALESCE(SUM({$this->soldExpr()}), 0) AS sold
            FROM {{catalog_product_entity}} e
            {$this->statusJoin()}
            WHERE {$this->sellableCondition()}
        "), [$from, $to]) ?: [];

        $sellable = (int) ($catalog['sellable'] ?? 0);
        $sold     = (int) ($catalog['sold'] ?? 0);
        $units    = (float) ($sales['units'] ?? 0);
        $orders   = (int) ($sales['orders'] ?? 0);

        return [
            'units'          => $units,
            'revenue'        => round((float) ($sales['revenue'] ?? 0), 2),
            'orders'         => $orders,
            'avg_basket'     => $orders > 0 ? round($units / $orders, 2) : 0.0,
            'sellable'       => $sellable,
            'sold_products'  => $sold,
            'idle_products'  => $sellable - $sold,
            'sell_through'   => $sellable > 0 ? round($sold / $sellable * 100, 1) : 0.0,
        ];
    }

    /**
     * Products sold in [start, end) by net revenue (top-level order lines): type, units, revenue,
     * realized price, orders, and product cost where the order lines carry one.
     *
     * @param int $limit 0 = every product
     */
    public function getProductPerformance(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 0): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        $rows = $this->context->getConnection()->fetchAll($this->getProductPerformanceSql($limit), [$from, $to]);

        foreach ($rows as &$row) {
            $row['product_id']     = (int) $row['product_id'];
            $row['product_name']   = $this->plainName($row['product_name']);
            $row['qty_sold']       = (float) $row['qty_sold'];
            $row['revenue']        = (float) $row['revenue'];
            $row['avg_price']      = (float) $row['avg_price'];
            $row['order_count']    = (int) $row['order_count'];
            $row['cost']           = $row['cost'] !== null ? (float) $row['cost'] : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * The (table-resolved) query behind getProductPerformance(); binds: from, to.
     */
    public function getProductPerformanceSql(int $limit = 0): string
    {
        return $this->context->resolveTables("
            SELECT
                oi.product_id,
                oi.sku,
                MAX(oi.name) AS product_name,
                MAX(oi.product_type) AS product_type,
                SUM({$this->context->itemQtyExpr()}) AS qty_sold,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS revenue,
                ROUND(AVG({$this->context->itemPriceExpr()}), 2) AS avg_price,
                COUNT(DISTINCT oi.order_id) AS order_count,
                {$this->costExpr()} AS cost
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY oi.product_id, oi.sku
            ORDER BY revenue DESC, qty_sold DESC"
            . ($limit > 0 ? ' LIMIT ' . $limit : ''));
    }

    /**
     * Velocity of each sold product over a period of $days days, by units sold:
     *  - restock: stock-managed and out of stock, or under $coverDays days of stock at this pace
     *  - high / medium / low: top 20% / next 40% / the rest of the products sold
     * Adds 'velocity' and 'days_of_cover' (null when stock is not tracked) to each row; rows need
     * 'qty_sold' and 'stock_qty' (null when not tracked).
     */
    public function rateVelocity(array $rows, int $days, int $coverDays = 14): array
    {
        $count = count($rows);
        $order = array_keys($rows);
        usort($order, static fn ($a, $b) => $rows[$b]['qty_sold'] <=> $rows[$a]['qty_sold']);
        $rank = array_flip($order);

        foreach ($rows as $key => &$row) {
            $daily = $days > 0 ? $row['qty_sold'] / $days : 0.0;
            $stock = $row['stock_qty'];
            $row['days_of_cover'] = $stock !== null && $daily > 0 ? round(max(0.0, (float) $stock) / $daily, 1) : null;

            $position = $count > 0 ? ($rank[$key] + 1) / $count : 1;
            if ($stock !== null && ($stock <= 0 || ($row['days_of_cover'] !== null && $row['days_of_cover'] < $coverDays))) {
                $row['velocity'] = 'restock';
            } elseif ($position <= 0.2) {
                $row['velocity'] = 'high';
            } elseif ($position <= 0.6) {
                $row['velocity'] = 'medium';
            } else {
                $row['velocity'] = 'low';
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Sellable products (enabled, not grouped) with no sale in [start, end), most stock value first:
     * name, type, created date, stock (null when not tracked), list price and stock value at list
     * price. Also the totals over all of them.
     *
     * @param int $limit 0 = every product
     * @return array{rows: array, count: int, stock_value: float}
     */
    public function getDeadStock(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 0): array
    {
        $conn   = $this->context->getConnection();
        [$from, $to] = $this->context->utcRange($start, $end);
        $link   = $this->context->getProductLinkField();
        $nameId = $this->context->getAttributeId('catalog_product', 'name');
        $priceId = $this->context->getAttributeId('catalog_product', 'price');
        $value  = 'GREATEST(IFNULL(s.qty, 0), 0) * IFNULL(pr.value, 0)';
        $base   = "
            FROM {{catalog_product_entity}} e
            {$this->statusJoin()}
            LEFT JOIN {{catalog_product_entity_varchar}} nm
                ON nm.{$link} = e.{$link} AND nm.attribute_id = {$nameId} AND nm.store_id = 0
            LEFT JOIN {{catalog_product_entity_decimal}} pr
                ON pr.{$link} = e.{$link} AND pr.attribute_id = {$priceId} AND pr.store_id = 0
            LEFT JOIN ({$this->context->getStockSql()}) s ON s.product_id = e.entity_id
            WHERE {$this->sellableCondition()} AND NOT {$this->soldExpr()}";

        $totals = $conn->fetchRow($this->context->resolveTables(
            "SELECT COUNT(*) AS products, COALESCE(SUM({$value}), 0) AS stock_value {$base}"
        ), [$from, $to]) ?: [];

        $rows = $conn->fetchAll($this->context->resolveTables("
            SELECT
                e.entity_id AS product_id, e.sku, e.type_id AS product_type, e.created_at,
                IFNULL(nm.value, e.sku) AS product_name,
                s.qty AS stock_qty, s.is_in_stock,
                ROUND(IFNULL(pr.value, 0), 2) AS price,
                ROUND({$value}, 2) AS stock_value
            {$base}
            ORDER BY stock_value DESC, e.entity_id DESC"
            . ($limit > 0 ? ' LIMIT ' . $limit : '')), [$from, $to]);

        foreach ($rows as &$row) {
            $row['product_id']  = (int) $row['product_id'];
            $row['product_name'] = $this->plainName($row['product_name']);
            $row['stock_qty']   = $row['stock_qty'] !== null ? (float) $row['stock_qty'] : null;
            $row['is_in_stock'] = $row['is_in_stock'] !== null ? (bool) $row['is_in_stock'] : null;
            $row['price']       = (float) $row['price'];
            $row['stock_value'] = (float) $row['stock_value'];
            $row['created_at']  = $this->context->toLocalDateTime($row['created_at']);
        }
        unset($row);

        return [
            'rows'        => $rows,
            'count'       => (int) ($totals['products'] ?? 0),
            'stock_value' => round((float) ($totals['stock_value'] ?? 0), 2),
        ];
    }

    /**
     * Net revenue, units and products sold by product type in [start, end).
     */
    public function getProductTypeMix(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                COALESCE(oi.product_type, 'unknown') AS product_type,
                COUNT(DISTINCT oi.product_id) AS products,
                SUM({$this->context->itemQtyExpr()}) AS units,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS revenue
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY product_type
            ORDER BY revenue DESC
        "), [$from, $to]);

        return array_map(static fn (array $row): array => [
            'product_type' => (string) $row['product_type'],
            'products'     => (int) $row['products'],
            'units'        => (float) $row['units'],
            'revenue'      => (float) $row['revenue'],
        ], $rows);
    }

    /**
     * Units, net revenue and products sold by realized unit price band in [start, end), cheapest first.
     *
     * @param int[] $bounds upper bounds of the bands; the last band is open-ended
     */
    public function getPriceBands(\DateTimeImmutable $start, \DateTimeImmutable $end, array $bounds = [25, 50, 100, 250]): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $price = $this->context->itemPriceExpr();
        $case  = 'CASE';
        foreach (array_values($bounds) as $i => $bound) {
            $case .= sprintf(' WHEN %s < %d THEN %d', $price, $bound, $i + 1);
        }
        $case .= ' ELSE ' . (count($bounds) + 1) . ' END';

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$case} AS band,
                SUM({$this->context->itemQtyExpr()}) AS units,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS revenue,
                COUNT(DISTINCT oi.product_id) AS products
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY band
        "), [$from, $to]);

        $byBand = array_column($rows, null, 'band');
        $bounds = array_values($bounds);
        $bands  = [];
        for ($band = 1; $band <= count($bounds) + 1; $band++) {
            $bands[] = [
                'min'      => $bounds[$band - 2] ?? null,
                'max'      => $bounds[$band - 1] ?? null,
                'units'    => (float) ($byBand[$band]['units'] ?? 0),
                'revenue'  => (float) ($byBand[$band]['revenue'] ?? 0),
                'products' => (int) ($byBand[$band]['products'] ?? 0),
            ];
        }

        return $bands;
    }

    /**
     * Top categories by net revenue in [start, end) (root categories excluded, admin names; a product
     * in several categories counts in each): units, orders, products sold, product cost where the
     * order lines carry one, and how many of the category's stock-tracked products are at or below
     * $lowStockQty.
     */
    public function getTopCategories(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 5, int $lowStockQty = 10): array
    {
        $conn   = $this->context->getConnection();
        [$from, $to] = $this->context->utcRange($start, $end);
        $link   = $this->context->getCategoryLinkField();
        $nameId = $this->context->getAttributeId('catalog_category', 'name');

        $rows = $conn->fetchAll($this->context->resolveTables("
            SELECT
                ce.entity_id AS category_id,
                cv.value AS category_name,
                ROUND(SUM({$this->context->itemRevenueExpr()}), 2) AS revenue,
                SUM({$this->context->itemQtyExpr()}) AS units,
                COUNT(DISTINCT oi.order_id) AS orders,
                COUNT(DISTINCT oi.product_id) AS products,
                {$this->costExpr()} AS cost
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            JOIN {{catalog_category_product}} ccp ON ccp.product_id = oi.product_id
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id AND ce.level >= 2{$this->context->currentVersionCondition('ce')}
            JOIN {{catalog_category_entity_varchar}} cv
                ON cv.{$link} = ce.{$link} AND cv.attribute_id = {$nameId} AND cv.store_id = 0
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY ce.entity_id, cv.value
            ORDER BY revenue DESC
            LIMIT " . max(1, $limit)), [$from, $to]);

        $ids = array_map('intval', array_column($rows, 'category_id'));
        $low = $ids ? $conn->fetchPairs("
            SELECT ccp.category_id, COUNT(DISTINCT s.product_id)
            FROM ({$this->context->getStockSql()}) s
            JOIN {$this->context->resolveTables('{{catalog_category_product}}')} ccp ON ccp.product_id = s.product_id
            WHERE ccp.category_id IN (" . implode(',', $ids) . ") AND s.qty <= " . (int) $lowStockQty . "
            GROUP BY ccp.category_id
        ") : [];

        return array_map(static fn (array $row): array => [
            'category_id'   => (int) $row['category_id'],
            'category_name' => (string) $row['category_name'],
            'revenue'       => (float) $row['revenue'],
            'units'         => (float) $row['units'],
            'orders'        => (int) $row['orders'],
            'products'      => (int) $row['products'],
            'cost'          => $row['cost'] !== null ? (float) $row['cost'] : null,
            'low_stock'     => (int) ($low[$row['category_id']] ?? 0),
        ], $rows);
    }

    /**
     * Deepest assigned category name per product (admin names, roots excluded).
     *
     * @param int[] $productIds
     * @return array<int, string>
     */
    public function getProductCategories(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $link   = $this->context->getCategoryLinkField();
        $nameId = $this->context->getAttributeId('catalog_category', 'name');

        return $this->context->getConnection()->fetchPairs($this->context->resolveTables("
            SELECT
                ccp.product_id,
                SUBSTRING_INDEX(GROUP_CONCAT(cv.value ORDER BY ce.level DESC, ce.position ASC SEPARATOR '||'), '||', 1)
            FROM {{catalog_category_product}} ccp
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id AND ce.level >= 2{$this->context->currentVersionCondition('ce')}
            JOIN {{catalog_category_entity_varchar}} cv
                ON cv.{$link} = ce.{$link} AND cv.attribute_id = {$nameId} AND cv.store_id = 0
            WHERE ccp.product_id IN (" . implode(',', $productIds) . ")
            GROUP BY ccp.product_id
        "));
    }

    /**
     * Current stock of stock-tracked products: product_id => [qty, is_in_stock].
     *
     * @param int[] $productIds
     */
    public function getProductStock(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }

        $rows = $this->context->getConnection()->fetchAll("
            SELECT s.product_id, s.qty, s.is_in_stock
            FROM ({$this->context->getStockSql()}) s
            WHERE s.product_id IN (" . implode(',', $productIds) . ")
        ");

        $stock = [];
        foreach ($rows as $row) {
            $stock[(int) $row['product_id']] = ['qty' => (float) $row['qty'], 'is_in_stock' => (bool) $row['is_in_stock']];
        }

        return $stock;
    }

    /**
     * Status of each product at store 0 (products without a status value count as enabled).
     */
    private function statusJoin(): string
    {
        $link     = $this->context->getProductLinkField();
        $statusId = $this->context->getAttributeId('catalog_product', 'status');

        return "LEFT JOIN {{catalog_product_entity_int}} st
                ON st.{$link} = e.{$link} AND st.attribute_id = {$statusId} AND st.store_id = 0";
    }

    /**
     * Product cost of the lines (base_cost × net qty, global base currency), only when every line
     * carries a cost — otherwise NULL, so no margin is shown on partial or missing cost data.
     */
    private function costExpr(): string
    {
        return "CASE WHEN SUM(IFNULL(oi.base_cost, 0) > 0) = COUNT(*) THEN ROUND(SUM(oi.base_cost * "
            . $this->context->itemQtyExpr() . " * IFNULL(o.base_to_global_rate, 1)), 2) END";
    }

    /**
     * Product names can hold HTML entities (sample data: "Lumaflex&trade;"); the page and the CSV
     * escape text themselves, so names are passed on as plain text.
     */
    private function plainName(?string $name): string
    {
        return html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function enabledExpr(): string
    {
        return 'IFNULL(st.value, 1) = 1';
    }

    /**
     * Enabled products that are sold themselves (grouped products are only containers).
     */
    private function sellableCondition(): string
    {
        return "{$this->enabledExpr()} AND e.type_id <> 'grouped'{$this->context->currentVersionCondition('e')}";
    }

    /**
     * Whether product e appears on any line of a valid order in the period (binds: from, to).
     */
    private function soldExpr(): string
    {
        return "EXISTS (
                SELECT 1
                FROM {{sales_order_item}} soi
                JOIN {{sales_order}} so ON so.entity_id = soi.order_id
                WHERE soi.product_id = e.entity_id
                  AND {$this->context->validOrderCondition('so')}
                  AND so.created_at >= ? AND so.created_at < ?
            )";
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
