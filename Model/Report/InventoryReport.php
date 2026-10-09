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

    /**
     * Available quantity at or below which a SKU is low stock.
     */
    public const LOW_STOCK_QTY = 10;

    /**
     * Days of cover (available ÷ average daily sales) below which a selling SKU is depleting.
     */
    public const CRITICAL_COVER_DAYS = 14;

    /**
     * Target days of cover: below is a reorder candidate, above is overstock.
     */
    public const HEALTHY_COVER_DAYS = [30, 90];

    /**
     * In-stock SKUs with no sale for longer than this are dead stock (idle capital).
     */
    public const IDLE_AFTER_DAYS = 90;

    /**
     * Stock tiers by available quantity, most stock first: key => upper bound (inclusive).
     */
    public const TIERS = ['high' => null, 'optimal' => 100, 'buffer' => 50, 'critical' => self::LOW_STOCK_QTY, 'out' => 0];

    public function __construct(
        private readonly ReportContext $context
    ) {
    }

    /**
     * One row per stock-managed product: stock, reservations, list price and sales in the local
     * [start, end) range, with days of cover, turnover, idle days, status and tier worked out.
     *
     * qty is the quantity on hand (summed over enabled MSI sources); reserved is what open orders
     * hold (MSI reservations, 0 without MSI, where qty is already net of orders); available = qty - reserved.
     */
    public function getSkuRows(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $days  = $this->rangeDays($start, $end);
        $today = $this->context->today();
        $rows  = $this->context->getConnection()->fetchAll($this->getSkuRowsSql($from, $to));

        foreach ($rows as &$row) {
            $qty       = (float) $row['qty'];
            $reserved  = max(0.0, (float) $row['reserved']);
            $available = $qty - $reserved;
            $sold      = max(0.0, (float) $row['sold']);
            $velocity  = $sold / $days;
            $lastSold  = $this->context->toLocalDateTime($row['last_sold_at']);
            $idleDays  = $lastSold !== null
                ? (int) $today->diff(new \DateTimeImmutable(substr($lastSold, 0, 10), $this->context->getTimezone()))->days
                : null;
            $isOut     = (int) $row['is_in_stock'] === 0 || $available <= 0;

            $row = [
                'product_id'   => (int) $row['product_id'],
                'sku'          => (string) $row['sku'],
                'name'         => (string) $row['product_name'],
                'enabled'      => (int) $row['status'] !== 2,
                'price'        => (float) $row['price'],
                'qty'          => $qty,
                'reserved'     => $reserved,
                'available'    => $available,
                'value'        => round(max(0.0, $qty) * (float) $row['price'], 2),
                'sold'         => $sold,
                'velocity'     => round($velocity, 4),
                'cover_days'   => $velocity > 0 ? round(max(0.0, $available) / $velocity, 1) : null,
                'turnover'     => $qty > 0 ? round($sold / $qty, 2) : null,
                'last_sold_at' => $lastSold,
                'idle_days'    => $idleDays,
                'idle'         => $qty > 0 && ($idleDays === null || $idleDays > self::IDLE_AFTER_DAYS),
                'tier'         => $isOut ? 'out' : $this->tierFor($available),
            ];
            $row['status'] = $this->statusFor($row, $isOut);
            $row['risk']   = in_array($row['status'], ['out', 'low', 'depleting'], true);
        }
        unset($row);

        return $rows;
    }

    /**
     * The (table-resolved) query behind getSkuRows(), with the UTC range inlined; also shown in
     * the page's SQL inspector.
     */
    public function getSkuRowsSql(string $from, string $to): string
    {
        $conn  = $this->context->getConnection();
        $qty   = $this->context->itemQtyExpr();
        $valid = $this->context->validOrderCondition();

        $reserved = $this->context->isMsiEnabled()
            ? 'COALESCE(r.reserved, 0)'
            : '0';
        $reservedJoin = $this->context->isMsiEnabled()
            ? 'LEFT JOIN (SELECT sku, -SUM(quantity) AS reserved FROM {{inventory_reservation}} GROUP BY sku) r ON r.sku = s.sku'
            : '';

        return $this->context->resolveTables("
            SELECT
                s.product_id,
                s.sku,
                COALESCE(pv.value, s.sku) AS product_name,
                s.qty,
                s.is_in_stock,
                COALESCE(pp.value, 0) AS price,
                COALESCE(pst.value, 1) AS status,
                {$reserved} AS reserved,
                COALESCE(d.sold, 0) AS sold,
                ls.last_sold_at
            FROM ({$this->context->getStockSql()}) s
            {$this->nameJoin()}
            {$this->priceJoin()}
            {$this->eavJoin('pst', 'catalog_product_entity_int', 'status')}
            {$reservedJoin}
            LEFT JOIN (
                SELECT oi.product_id, SUM({$qty}) AS sold
                FROM {{sales_order_item}} oi
                JOIN {{sales_order}} o ON o.entity_id = oi.order_id
                WHERE {$valid} AND o.created_at >= {$conn->quote($from)} AND o.created_at < {$conn->quote($to)}
                GROUP BY oi.product_id
            ) d ON d.product_id = s.product_id
            LEFT JOIN (
                SELECT oi.product_id, MAX(o.created_at) AS last_sold_at
                FROM {{sales_order_item}} oi
                JOIN {{sales_order}} o ON o.entity_id = oi.order_id
                WHERE {$valid}
                GROUP BY oi.product_id
            ) ls ON ls.product_id = s.product_id
            ORDER BY s.sku
        ");
    }

    /**
     * Page totals from getSkuRows(): KPI figures, SKUs/units/value per stock tier and the
     * size of each table tab (risk = out, low or depleting; idle = dead stock; turnover = sold in range).
     */
    public function summarize(array $rows, int $days): array
    {
        $tiers = [];
        foreach (array_keys(self::TIERS) as $key) {
            $tiers[$key] = ['key' => $key, 'skus' => 0, 'available' => 0.0, 'value' => 0.0];
        }
        $summary = [
            'skus' => count($rows), 'in_stock' => 0, 'out_of_stock' => 0, 'low_stock' => 0, 'critical_stock' => 0,
            'depleting' => 0, 'overstock' => 0, 'disabled' => 0, 'idle' => 0, 'risk' => 0, 'selling' => 0,
            'units_on_hand' => 0.0, 'units_reserved' => 0.0, 'units_available' => 0.0, 'units_sold' => 0.0,
            'stock_value' => 0.0, 'idle_value' => 0.0,
        ];

        foreach ($rows as $row) {
            $isOut = $row['status'] === 'out';
            $summary['in_stock']       += $isOut ? 0 : 1;
            $summary['out_of_stock']   += $isOut ? 1 : 0;
            $summary['low_stock']      += $row['status'] === 'low' ? 1 : 0;
            $summary['critical_stock'] += $row['status'] === 'low' && $row['available'] <= 5 ? 1 : 0;
            $summary['depleting']      += $row['status'] === 'depleting' ? 1 : 0;
            $summary['overstock']      += !$isOut && $row['cover_days'] !== null
                && $row['cover_days'] > self::HEALTHY_COVER_DAYS[1] ? 1 : 0;
            $summary['disabled']       += $row['enabled'] ? 0 : 1;
            $summary['idle']           += $row['idle'] ? 1 : 0;
            $summary['risk']           += $row['risk'] ? 1 : 0;
            $summary['selling']        += $row['sold'] > 0 ? 1 : 0;
            $summary['units_on_hand']  += max(0.0, $row['qty']);
            $summary['units_reserved'] += $row['reserved'];
            $summary['units_available'] += max(0.0, $row['available']);
            $summary['units_sold']     += $row['sold'];
            $summary['stock_value']    += $row['value'];
            $summary['idle_value']     += $row['idle'] ? $row['value'] : 0.0;

            $tiers[$row['tier']]['skus']++;
            $tiers[$row['tier']]['available'] += max(0.0, $row['available']);
            $tiers[$row['tier']]['value']     += $row['value'];
        }

        $summary['stock_value'] = round($summary['stock_value'], 2);
        $summary['idle_value']  = round($summary['idle_value'], 2);
        $summary['turnover']    = $summary['units_on_hand'] > 0
            ? round($summary['units_sold'] / $summary['units_on_hand'], 2) : 0.0;
        $summary['turnover_annualized'] = round($summary['turnover'] * 365 / max(1, $days), 1);
        foreach ($tiers as &$tier) {
            $tier['value'] = round($tier['value'], 2);
        }
        unset($tier);

        return ['summary' => $summary, 'tiers' => array_values($tiers)];
    }

    /**
     * Enabled MSI sources, code => name (empty without MSI).
     *
     * @return array<string, string>
     */
    public function getSources(): array
    {
        if (!$this->context->isMsiEnabled()) {
            return [];
        }

        return $this->context->getConnection()->fetchPairs($this->context->resolveTables(
            'SELECT source_code, name FROM {{inventory_source}} WHERE enabled = 1 ORDER BY name'
        ));
    }

    /**
     * Quantity per enabled MSI source (empty without MSI).
     *
     * @return array<string, array<string, float>> sku => [source code => quantity]
     */
    public function getSourceQuantities(): array
    {
        if (!$this->context->isMsiEnabled()) {
            return [];
        }

        $result = [];
        foreach ($this->context->getConnection()->fetchAll($this->context->resolveTables('
            SELECT isi.sku, isi.source_code, isi.quantity
            FROM {{inventory_source_item}} isi
            JOIN {{inventory_source}} src ON src.source_code = isi.source_code AND src.enabled = 1
            ORDER BY isi.sku, isi.source_code
        ')) as $row) {
            $result[$row['sku']][$row['source_code']] = (float) $row['quantity'];
        }

        return $result;
    }

    /**
     * Categories of every assigned product (root categories excluded, admin names), deepest first,
     * with the parent's name when the parent is not a root category.
     *
     * @return array<int, array<int, array{id: int, name: string, parent: string, level: int}>> product id => categories
     */
    public function getProductCategories(): array
    {
        $link        = $this->context->getCategoryLinkField();
        $attributeId = $this->context->getAttributeId('catalog_category', 'name');

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT ccp.product_id, ce.entity_id AS category_id, ce.level, cv.value AS name,
                   IF(pe.level >= 2, pv.value, NULL) AS parent_name
            FROM {{catalog_category_product}} ccp
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id"
            . $this->context->currentVersionCondition('ce') . "
            JOIN {{catalog_category_entity_varchar}} cv ON cv.{$link} = ce.{$link}
                AND cv.attribute_id = {$attributeId} AND cv.store_id = 0
            LEFT JOIN {{catalog_category_entity}} pe ON pe.entity_id = ce.parent_id"
            . $this->context->currentVersionCondition('pe') . "
            LEFT JOIN {{catalog_category_entity_varchar}} pv ON pv.{$link} = pe.{$link}
                AND pv.attribute_id = {$attributeId} AND pv.store_id = 0
            WHERE ce.level >= 2
            ORDER BY ccp.product_id, ce.level DESC, ce.position, ce.entity_id
        "));

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['product_id']][] = [
                'id'     => (int) $row['category_id'],
                'name'   => (string) $row['name'],
                'parent' => (string) ($row['parent_name'] ?? ''),
                'level'  => (int) $row['level'],
            ];
        }

        return $result;
    }

    /**
     * Whole days in a local [start, end) range (at least 1).
     */
    public function rangeDays(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400));
    }

    private function tierFor(float $available): string
    {
        foreach (array_reverse(self::TIERS, true) as $key => $max) {
            if ($max === null || $available <= $max) {
                return $key;
            }
        }

        return 'high';
    }

    /**
     * out > low (≤ LOW_STOCK_QTY available) > depleting (under CRITICAL_COVER_DAYS of cover) > idle > ok.
     */
    private function statusFor(array $row, bool $isOut): string
    {
        if ($isOut) {
            return 'out';
        }
        if ($row['available'] <= self::LOW_STOCK_QTY) {
            return 'low';
        }
        if ($row['cover_days'] !== null && $row['cover_days'] < self::CRITICAL_COVER_DAYS) {
            return 'depleting';
        }

        return $row['idle'] ? 'idle' : 'ok';
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
