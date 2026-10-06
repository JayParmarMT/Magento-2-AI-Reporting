<?php
/**
 * Meetanshi AIReporting — Inventory Report
 *
 * Stock comes from ReportContext::getStockSql(): only stock-managed products of
 * quantity-holding types (no configurable/bundle/grouped parents), summed across
 * enabled MSI sources when MSI is installed. Stock status uses is_in_stock.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

class InventoryReport
{
    private const DEMAND_DAYS = 30;

    public function __construct(
        private readonly ReportContext $context
    ) {
    }

    /**
     * Inventory KPI cards.
     */
    public function getKpiCards(): array
    {
        $conn  = $this->context->getConnection();
        $stock = $this->context->getStockSql();

        $counts = $conn->fetchRow("
            SELECT
                COUNT(*) AS total_skus,
                COALESCE(SUM(s.is_in_stock = 1), 0) AS in_stock,
                COALESCE(SUM(s.is_in_stock = 0), 0) AS out_of_stock,
                COALESCE(SUM(s.is_in_stock = 1 AND s.qty > 0 AND s.qty <= 10), 0) AS low_stock
            FROM ({$stock}) s
        ") ?: [];

        $value = $conn->fetchOne("
            SELECT COALESCE(ROUND(SUM(s.qty * pp.value), 2), 0)
            FROM ({$stock}) s
            {$this->priceJoin()}
            WHERE s.qty > 0
        ");

        return [
            'total_skus'        => $counts['total_skus'] ?? 0,
            'in_stock'          => $counts['in_stock'] ?? 0,
            'out_of_stock'      => $counts['out_of_stock'] ?? 0,
            'low_stock'         => $counts['low_stock'] ?? 0,
            'total_stock_value' => $value ?: 0,
        ];
    }

    /**
     * Products at or below the stock threshold (lowest first).
     */
    public function getLowStockProducts(int $threshold = 10, int $limit = 30): array
    {
        return $this->context->getConnection()->fetchAll("
            SELECT
                s.product_id AS entity_id,
                s.sku,
                COALESCE(pv.value, s.sku) AS product_name,
                s.qty,
                s.min_qty,
                s.is_in_stock,
                COALESCE(pp.value, 0) AS price
            FROM ({$this->context->getStockSql()}) s
            {$this->nameJoin()}
            {$this->priceJoin()}
            WHERE s.qty <= ?
            ORDER BY s.qty ASC
            LIMIT " . max(1, $limit), [$threshold]);
    }

    /**
     * Products whose stock status is "Out of Stock" (most valuable first).
     */
    public function getOutOfStockProducts(int $limit = 30): array
    {
        return $this->context->getConnection()->fetchAll("
            SELECT
                s.product_id AS entity_id,
                s.sku,
                COALESCE(pv.value, s.sku) AS product_name,
                s.qty,
                COALESCE(pp.value, 0) AS price
            FROM ({$this->context->getStockSql()}) s
            {$this->nameJoin()}
            {$this->priceJoin()}
            WHERE s.is_in_stock = 0
            ORDER BY price DESC
            LIMIT " . max(1, $limit));
    }

    /**
     * Product count per stock-quantity range (all ranges returned, zero-filled).
     */
    public function getStockDistribution(): array
    {
        $ranges = ['Out of Stock', '1-5 (Critical)', '6-10 (Low)', '11-50 (Medium)', '51-100 (Good)', '100+ (High)'];

        $rows = $this->context->getConnection()->fetchAll("
            SELECT
                CASE
                    WHEN s.qty <= 0 THEN 'Out of Stock'
                    WHEN s.qty <= 5 THEN '1-5 (Critical)'
                    WHEN s.qty <= 10 THEN '6-10 (Low)'
                    WHEN s.qty <= 50 THEN '11-50 (Medium)'
                    WHEN s.qty <= 100 THEN '51-100 (Good)'
                    ELSE '100+ (High)'
                END AS stock_range,
                COUNT(*) AS product_count
            FROM ({$this->context->getStockSql()}) s
            GROUP BY stock_range
        ");

        $counts = array_column($rows, 'product_count', 'stock_range');
        $result = [];
        foreach ($ranges as $range) {
            $result[] = ['stock_range' => $range, 'product_count' => (int) ($counts[$range] ?? 0)];
        }

        return $result;
    }

    /**
     * Last 30 days of demand vs current stock, flagging items with under 7/14 days of cover.
     *
     * Stock rows are quantity-holding products, so demand is matched on the product actually
     * shipped: simple lines and the child lines of configurable/bundle items (their product_id).
     */
    public function getDemandVsSupply(int $limit = 15): array
    {
        [$from, $to] = $this->getDemandWindow();

        return $this->demandVsSupplyForRange($from, $to, self::DEMAND_DAYS, $limit);
    }

    /**
     * Demand vs current stock for each period tab. Stock is always "now"; only the
     * demand window (sales) changes with the period.
     *
     * @return array<string, array>
     */
    public function getDemandVsSupplyPerPeriod(int $limit = 15): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            [$start, $end] = $this->periodRange($period);
            [$from, $to]   = $this->context->utcRange($start, $end);
            $days = max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400));
            $result[$period] = $this->demandVsSupplyForRange($from, $to, $days, $limit);
        }
        return $result;
    }

    /**
     * Demand vs supply over an explicit UTC [from, to) window and cover-day basis.
     */
    private function demandVsSupplyForRange(string $from, string $to, int $days, int $limit): array
    {
        $qty  = $this->context->itemQtyExpr();
        $days = max(1, $days);

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                s.sku,
                MAX(oi.name) AS product_name,
                SUM({$qty}) AS qty_sold_30d,
                s.qty AS current_stock,
                s.is_in_stock,
                CASE
                    WHEN s.is_in_stock = 0 OR s.qty <= 0 THEN 'OUT OF STOCK'
                    WHEN s.qty < SUM({$qty}) / {$days} * 7 THEN 'CRITICAL'
                    WHEN s.qty < SUM({$qty}) / {$days} * 14 THEN 'LOW'
                    ELSE 'OK'
                END AS stock_status
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            JOIN ({$this->context->getStockSql()}) s ON s.product_id = oi.product_id
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY s.product_id, s.sku, s.qty, s.is_in_stock
            ORDER BY qty_sold_30d DESC
            LIMIT " . max(1, $limit)), [$from, $to]);
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
     * Last 30 days sold ÷ current stock, highest first.
     */
    public function getInventoryTurnover(int $limit = 20): array
    {
        [$from, $to] = $this->getDemandWindow();
        $qty = $this->context->itemQtyExpr();

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                s.sku,
                MAX(oi.name) AS product_name,
                SUM({$qty}) AS qty_sold,
                s.qty AS current_stock,
                ROUND(SUM({$qty}) / s.qty, 2) AS turnover_rate
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            JOIN ({$this->context->getStockSql()}) s ON s.product_id = oi.product_id
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
              AND s.qty > 0
            GROUP BY s.product_id, s.sku, s.qty
            ORDER BY turnover_rate DESC
            LIMIT " . max(1, $limit)), [$from, $to]);
    }

    /**
     * Rolling window covering the last 30 days including today (store timezone).
     */
    private function getDemandWindow(): array
    {
        $end = $this->context->today()->modify('+1 day');

        return $this->context->utcRange($end->modify('-' . self::DEMAND_DAYS . ' days'), $end);
    }

    private function nameJoin(): string
    {
        return $this->eavJoin('pv', 'catalog_product_entity_varchar', 'name');
    }

    private function priceJoin(): string
    {
        return $this->eavJoin('pp', 'catalog_product_entity_decimal', 'price');
    }

    private function eavJoin(string $alias, string $table, string $attributeCode): string
    {
        $link        = $this->context->getProductLinkField();
        $attributeId = $this->context->getAttributeId('catalog_product', $attributeCode);

        if ($link === 'entity_id') {
            return $this->context->resolveTables(
                "LEFT JOIN {{{$table}}} {$alias} ON {$alias}.entity_id = s.product_id"
                . " AND {$alias}.attribute_id = {$attributeId} AND {$alias}.store_id = 0"
            );
        }

        // Adobe Commerce: EAV values are keyed by row_id of the current (non-staged) version
        return $this->context->resolveTables(
            "LEFT JOIN {{catalog_product_entity}} {$alias}_e ON {$alias}_e.entity_id = s.product_id"
            . $this->context->currentVersionCondition("{$alias}_e")
            . " LEFT JOIN {{{$table}}} {$alias} ON {$alias}.{$link} = {$alias}_e.{$link}"
            . " AND {$alias}.attribute_id = {$attributeId} AND {$alias}.store_id = 0"
        );
    }
}
