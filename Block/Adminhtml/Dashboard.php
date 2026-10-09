<?php
/**
 * Meetanshi AIReporting — Executive Dashboard data
 *
 * All figures follow the ReportContext rules (net revenue in base currency, canceled and
 * pending-payment orders excluded from sales, store-local dates, buyers by email).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\AiContext;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Report\ReportContext;

class Dashboard extends Template
{
    /**
     * Products listed in the performance table (filtered and paged in the browser).
     */
    private const MAX_PRODUCTS = 200;

    /**
     * Stock at or below this quantity counts as low.
     */
    private const LOW_STOCK_QTY = 10;

    private const RANGE_LABELS = [
        'today'         => 'Today',
        'yesterday'     => 'Yesterday',
        'last_7_days'   => 'Last 7 Days',
        'last_30_days'  => 'Last 30 Days',
        'this_month'    => 'This Month',
        'last_3_months' => 'Last 3 Months',
        'last_6_months' => 'Last 6 Months',
        'this_year'     => 'This Year',
        'last_year'     => 'Last Year',
        'custom'        => 'Custom Range',
    ];

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ReportContext $reportContext,
        private readonly AiContext $aiContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getLlmProvider(): string
    {
        return $this->config->getLlmProvider();
    }

    public function getQueryExecuteUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/query/execute');
    }

    public function getSaveReportUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/report/save');
    }

    public function getRefreshDataUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/dashboard/refreshData');
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/dashboard/export');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getCurrencySymbol(): string
    {
        return $this->reportContext->getCurrencySymbol();
    }

    public function formatCurrency(float $amount): string
    {
        return $this->reportContext->formatPrice($amount);
    }

    /**
     * Range keys and labels for the date selector.
     *
     * @return array<string, string>
     */
    public function getRangeOptions(): array
    {
        return array_map(static fn (string $label) => (string) __($label), self::RANGE_LABELS);
    }

    /**
     * "Magento 2.4.9 Community"
     */
    public function getPlatformLabel(): string
    {
        return $this->aiContext->getPlatformLabel();
    }

    /**
     * "Store: Default Store View (Main Website)"
     */
    public function getStoreLabel(): string
    {
        $name = $this->aiContext->getStoreName();

        return $name !== '' ? (string) __('Store: %1', $name) : '';
    }

    /**
     * Front-end settings and the first data set for the dashboard script.
     */
    public function getJsConfig(): array
    {
        return [
            'data'         => $this->getDashboardData(),
            'currency'     => $this->reportContext->getCurrencyCode(),
            'formKey'      => $this->getFormKey(),
            'refreshUrl'   => $this->getRefreshDataUrl(),
            'exportUrl'    => $this->getExportUrl(),
            'chatUrl'      => $this->getUrl('meetanshi_aireporting/chat/send'),
            'askAiUrl'     => $this->getUrl('meetanshi_aireporting/query/index'),
            'productUrl'   => $this->getUrl('catalog/product/edit', ['id' => '__ID__']),
            'aiEnabled'    => $this->config->isEnabled(),
            'provider'     => $this->aiContext->getProviderLabel(),
            'model'        => $this->aiContext->getModel(),
            'aiConfigured' => $this->aiContext->isProviderConfigured(),
        ];
    }

    /**
     * Resolve a named range to local (store timezone) dates.
     *
     * @return array{start: string, end: string} inclusive Y-m-d dates
     */
    private function getDateRangeFilter(string $range, ?string $customStart = null, ?string $customEnd = null): array
    {
        $today = $this->reportContext->today();

        switch ($range) {
            case 'today':
                $start = $end = $today;
                break;
            case 'yesterday':
                $start = $end = $today->modify('-1 day');
                break;
            case 'last_7_days':
                $start = $today->modify('-6 days');
                $end   = $today;
                break;
            case 'last_30_days':
                $start = $today->modify('-29 days');
                $end   = $today;
                break;
            case 'last_3_months':
                $start = $today->modify('-3 months');
                $end   = $today;
                break;
            case 'last_year':
                $start = $today->modify('-1 year');
                $end   = $today;
                break;
            case 'this_month':
                $start = $today->modify('first day of this month');
                $end   = $today;
                break;
            case 'this_year':
                $start = $today->setDate((int) $today->format('Y'), 1, 1);
                $end   = $today;
                break;
            case 'custom':
                $start = $this->reportContext->parseLocalDate($customStart) ?? $today->modify('-6 months');
                $end   = $this->reportContext->parseLocalDate($customEnd) ?? $today;
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                break;
            case 'last_6_months':
            default:
                $start = $today->modify('-6 months');
                $end   = $today;
        }

        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    /**
     * Get comprehensive dashboard data
     */
    public function getDashboardData(string $dateRange = 'last_6_months', ?string $customStart = null, ?string $customEnd = null): array
    {
        $dateRange = isset(self::RANGE_LABELS[$dateRange]) ? $dateRange : 'last_6_months';
        $dates = $this->getDateRangeFilter($dateRange, $customStart, $customEnd);
        $start = $this->reportContext->parseLocalDate($dates['start']);
        $end   = $this->reportContext->parseLocalDate($dates['end'])->modify('+1 day');

        $kpis       = $this->getKpiMetrics($start, $end);
        $trend      = $this->getRevenueTrend($start, $end);
        $dayOfWeek  = $this->getRevenueByDayOfWeek($start, $end);
        $segments   = $this->getCustomerSegments($start, $end);
        $products   = $this->getProductPerformance($start, $end);
        $categories = $this->getCategoryRevenue($start, $end, (float) $kpis['total_revenue']);

        $kpis += $this->getKpiDetails($products, $dayOfWeek, $segments);

        return [
            'date_range'          => $dates + [
                'key'   => $dateRange,
                'label' => $this->getRangeLabel($dateRange, $start, $end),
                'days'  => (int) $start->diff($end)->days,
            ],
            'currency_code'       => $this->reportContext->getCurrencyCode(),
            'kpis'                => $kpis,
            'revenue_trend'       => $trend,
            'trend_stats'         => $this->getTrendStats($trend),
            'revenue_by_category' => array_slice($categories, 0, 10),
            'category_count'      => count($categories),
            'top_products'        => array_slice($products, 0, 10),
            'products'            => $products,
            'orders_by_status'    => $this->getOrdersByStatus($start, $end),
            'revenue_by_dow'      => $dayOfWeek,
            'peak_window'         => $this->getPeakOrderingWindow($start, $end),
            'customer_segments'   => $segments,
            'stock_distribution'  => $this->getStockDistribution(),
        ];
    }

    /**
     * "Last 6 Months (Apr 7 – Oct 7, 2026)"
     */
    private function getRangeLabel(string $range, \DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $last  = $end->modify('-1 day');
        $dates = $start->format('Y') === $last->format('Y')
            ? $start->format('M j') . ' – ' . $last->format('M j, Y')
            : $start->format('M j, Y') . ' – ' . $last->format('M j, Y');
        if ($start->format('Y-m-d') === $last->format('Y-m-d')) {
            $dates = $start->format('M j, Y');
        }

        return __(self::RANGE_LABELS[$range]) . ' (' . $dates . ')';
    }

    /**
     * KPI metrics with comparison to the previous period of the same length.
     */
    private function getKpiMetrics(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $conn = $this->reportContext->getConnection();
        $days = (int) $start->diff($end)->days;

        $current  = $this->getPeriodTotals($start, $end);
        $previous = $this->getPeriodTotals($start->modify("-{$days} days"), $start);

        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $sold = $conn->fetchRow($this->reportContext->resolveTables("
            SELECT
                COALESCE(SUM({$this->reportContext->itemQtyExpr()}), 0) AS qty,
                COUNT(DISTINCT oi.sku) AS skus
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->reportContext->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]) ?: [];

        $stock = $this->getStockHealth();

        return [
            'total_revenue'           => (float) $current['total_revenue'],
            'total_revenue_change'    => $this->calculatePercentageChange((float) $previous['total_revenue'], (float) $current['total_revenue']),
            'refunded'                => (float) $current['refunded'],
            'total_orders'            => (int) $current['total_orders'],
            'total_orders_change'     => $this->calculatePercentageChange((float) $previous['total_orders'], (float) $current['total_orders']),
            'complete_orders'         => (int) $current['complete_orders'],
            'active_customers'        => (int) $current['active_customers'],
            'active_customers_change' => $this->calculatePercentageChange((float) $previous['active_customers'], (float) $current['active_customers']),
            'avg_order_value'         => (float) $current['avg_order_value'],
            'avg_order_value_change'  => $this->calculatePercentageChange((float) $previous['avg_order_value'], (float) $current['avg_order_value']),
            'products_sold'           => (int) ($sold['qty'] ?? 0),
            'skus_sold'               => (int) ($sold['skus'] ?? 0),
            'low_stock'               => $stock['low_stock'],
            'out_of_stock'            => $stock['out_of_stock'],
            'stock_items'             => $stock['total'],
            'stock_healthy_pct'       => $stock['healthy_pct'],
            'inventory_value'         => $stock['inventory_value'],
        ];
    }

    /**
     * KPI side notes derived from data already loaded: best weekday AOV, top seller, repeat buyers.
     */
    private function getKpiDetails(array $products, array $dayOfWeek, array $segments): array
    {
        $bestDay = null;
        foreach ($dayOfWeek as $day) {
            $orders = (int) $day['orders'];
            if ($orders > 0) {
                $aov = (float) $day['revenue'] / $orders;
                if ($bestDay === null || $aov > $bestDay['aov']) {
                    $bestDay = ['day' => substr((string) $day['day_name'], 0, 3), 'aov' => round($aov, 2)];
                }
            }
        }

        $top = null;
        foreach ($products as $product) {
            if ($top === null || $product['qty_sold'] > $top['qty']) {
                $top = ['name' => $product['product_name'], 'qty' => $product['qty_sold']];
            }
        }

        $repeat = 0;
        foreach ($segments as $segment) {
            if ($segment['segment'] !== 'New') {
                $repeat += (int) $segment['customers'];
            }
        }

        return ['best_aov_day' => $bestDay, 'top_product' => $top, 'repeat_buyers' => $repeat];
    }

    /**
     * Orders, net revenue, AOV, refunds, completed orders and distinct buyers (by email) for [start, end).
     */
    private function getPeriodTotals(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $rev = $this->reportContext->orderRevenueExpr();

        return $this->reportContext->getConnection()->fetchRow($this->reportContext->resolveTables("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM({$rev}), 0) AS total_revenue,
                COALESCE(AVG({$rev}), 0) AS avg_order_value,
                COUNT(DISTINCT o.customer_email) AS active_customers,
                COALESCE(SUM({$this->reportContext->orderAmountExpr('o.base_total_refunded')}), 0) AS refunded,
                COALESCE(SUM(o.state = 'complete'), 0) AS complete_orders
            FROM {{sales_order}} o
            WHERE {$this->reportContext->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]) ?: [
            'total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0,
            'active_customers' => 0, 'refunded' => 0, 'complete_orders' => 0,
        ];
    }

    /**
     * Calculate percentage change
     */
    private function calculatePercentageChange(float $old, float $new): float
    {
        if ($old == 0) {
            return $new > 0 ? 100.0 : 0.0;
        }
        return round((($new - $old) / $old) * 100, 1);
    }

    /**
     * Daily orders and net revenue, one row per day in the range.
     */
    private function getRevenueTrend(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $local = $this->reportContext->localTimeExpr('o.created_at', $start, $end);

        $rows = $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
                DATE({$local}) AS date,
                COUNT(*) AS orders,
                ROUND(SUM({$this->reportContext->orderRevenueExpr()}), 2) AS revenue
            FROM {{sales_order}} o
            WHERE {$this->reportContext->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY date
            ORDER BY date ASC
        "), [$from, $to]);

        return $this->reportContext->fillDaily($rows, $start, $end, ['orders' => 0, 'revenue' => 0]);
    }

    /**
     * Highest-revenue day, highest-order day and the store timezone.
     */
    private function getTrendStats(array $trend): array
    {
        $peakRevenue = null;
        $peakOrders  = null;
        foreach ($trend as $day) {
            if ((float) $day['revenue'] > 0 && ($peakRevenue === null || (float) $day['revenue'] > (float) $peakRevenue['revenue'])) {
                $peakRevenue = $day;
            }
            if ((int) $day['orders'] > 0 && ($peakOrders === null || (int) $day['orders'] > (int) $peakOrders['orders'])) {
                $peakOrders = $day;
            }
        }

        $now = $this->reportContext->now();

        return [
            'peak_revenue' => $peakRevenue ? [
                'date'    => $peakRevenue['date'],
                'revenue' => (float) $peakRevenue['revenue'],
                'orders'  => (int) $peakRevenue['orders'],
            ] : null,
            'peak_orders'  => $peakOrders ? ['date' => $peakOrders['date'], 'orders' => (int) $peakOrders['orders']] : null,
            'timezone'     => $this->reportContext->getTimezone()->getName(),
            'utc_offset'   => 'UTC' . preg_replace('/:00$/', '', preg_replace('/^([+-])0/', '$1', $now->format('P'))),
        ];
    }

    /**
     * Categories by net item revenue (root categories excluded, admin names), with their share of
     * total net revenue. A product in several categories counts in each, so shares can exceed 100% together.
     */
    private function getCategoryRevenue(\DateTimeImmutable $start, \DateTimeImmutable $end, float $totalRevenue): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $link   = $this->reportContext->getCategoryLinkField();
        $nameId = $this->reportContext->getAttributeId('catalog_category', 'name');

        $rows = $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
                ce.entity_id AS category_id,
                cv.value AS category_name,
                ROUND(SUM({$this->reportContext->itemRevenueExpr()}), 2) AS revenue,
                SUM({$this->reportContext->itemQtyExpr()}) AS qty_sold
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            JOIN {{catalog_category_product}} ccp ON ccp.product_id = oi.product_id
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id AND ce.level >= 2{$this->reportContext->currentVersionCondition('ce')}
            JOIN {{catalog_category_entity_varchar}} cv
                ON cv.{$link} = ce.{$link} AND cv.attribute_id = {$nameId} AND cv.store_id = 0
            WHERE {$this->reportContext->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY ce.entity_id, cv.value
            ORDER BY revenue DESC
        "), [$from, $to]);

        foreach ($rows as &$row) {
            $row['revenue'] = (float) $row['revenue'];
            $row['qty_sold'] = (float) $row['qty_sold'];
            $row['share']   = $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 1) : 0.0;
        }
        unset($row);

        return $rows;
    }

    /**
     * Products sold in the range by net revenue (top-level order lines), with their category and stock.
     */
    private function getProductPerformance(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $conn = $this->reportContext->getConnection();

        $rows = $conn->fetchAll($this->reportContext->resolveTables("
            SELECT
                oi.product_id,
                MAX(oi.name) AS product_name,
                oi.sku,
                SUM({$this->reportContext->itemQtyExpr()}) AS qty_sold,
                ROUND(SUM({$this->reportContext->itemRevenueExpr()}), 2) AS revenue,
                ROUND(AVG({$this->reportContext->itemPriceExpr()}), 2) AS avg_price,
                COUNT(DISTINCT oi.order_id) AS order_count
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->reportContext->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY oi.product_id, oi.sku
            ORDER BY revenue DESC
            LIMIT " . self::MAX_PRODUCTS
        ), [$from, $to]);

        $ids        = array_values(array_filter(array_map('intval', array_column($rows, 'product_id'))));
        $categories = $this->getProductCategories($ids);
        $stock      = $this->getProductStock($ids);

        foreach ($rows as &$row) {
            $id = (int) $row['product_id'];
            $row['product_id']  = $id;
            $row['qty_sold']    = (float) $row['qty_sold'];
            $row['revenue']     = (float) $row['revenue'];
            $row['avg_price']   = (float) $row['avg_price'];
            $row['order_count'] = (int) $row['order_count'];
            $row['category']    = $categories[$id] ?? '';
            $row['stock_qty']   = isset($stock[$id]) ? (float) $stock[$id]['qty'] : null;
            $row['in_stock']    = isset($stock[$id]) ? (bool) $stock[$id]['is_in_stock'] : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Deepest assigned category name per product (admin names, roots excluded).
     *
     * @param int[] $productIds
     * @return array<int, string>
     */
    private function getProductCategories(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $link   = $this->reportContext->getCategoryLinkField();
        $nameId = $this->reportContext->getAttributeId('catalog_category', 'name');

        return $this->reportContext->getConnection()->fetchPairs($this->reportContext->resolveTables("
            SELECT
                ccp.product_id,
                SUBSTRING_INDEX(GROUP_CONCAT(cv.value ORDER BY ce.level DESC, ce.position ASC SEPARATOR '||'), '||', 1)
            FROM {{catalog_category_product}} ccp
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id AND ce.level >= 2{$this->reportContext->currentVersionCondition('ce')}
            JOIN {{catalog_category_entity_varchar}} cv
                ON cv.{$link} = ce.{$link} AND cv.attribute_id = {$nameId} AND cv.store_id = 0
            WHERE ccp.product_id IN (" . implode(',', $productIds) . ")
            GROUP BY ccp.product_id
        "));
    }

    /**
     * Stock of stock-managed products (MSI-aware); products that hold no quantity are absent.
     *
     * @param int[] $productIds
     * @return array<int, array{qty: string, is_in_stock: string}>
     */
    private function getProductStock(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $rows = $this->reportContext->getConnection()->fetchAll("
            SELECT s.product_id, s.qty, s.is_in_stock
            FROM ({$this->reportContext->getStockSql()}) s
            WHERE s.product_id IN (" . implode(',', $productIds) . ")
        ");

        return array_column($rows, null, 'product_id');
    }

    /**
     * Stock-managed products: total, low, out of stock, share healthy, and stock value (qty × base price).
     *
     * @return array{total: int, low_stock: int, out_of_stock: int, healthy_pct: float, inventory_value: float}
     */
    private function getStockHealth(): array
    {
        $link    = $this->reportContext->getProductLinkField();
        $priceId = $this->reportContext->getAttributeId('catalog_product', 'price');
        $low     = self::LOW_STOCK_QTY;

        $row = $this->reportContext->getConnection()->fetchRow($this->reportContext->resolveTables("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(s.is_in_stock = 1 AND s.qty > 0 AND s.qty <= {$low}), 0) AS low_stock,
                COALESCE(SUM(s.is_in_stock = 0), 0) AS out_of_stock,
                COALESCE(SUM(GREATEST(s.qty, 0) * IFNULL(p.value, 0)), 0) AS inventory_value
            FROM ({$this->reportContext->getStockSql()}) s
            JOIN {{catalog_product_entity}} e ON e.entity_id = s.product_id{$this->reportContext->currentVersionCondition('e')}
            LEFT JOIN {{catalog_product_entity_decimal}} p
                ON p.{$link} = e.{$link} AND p.attribute_id = {$priceId} AND p.store_id = 0
        ")) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $bad   = (int) ($row['low_stock'] ?? 0) + (int) ($row['out_of_stock'] ?? 0);

        return [
            'total'           => $total,
            'low_stock'       => (int) ($row['low_stock'] ?? 0),
            'out_of_stock'    => (int) ($row['out_of_stock'] ?? 0),
            'healthy_pct'     => $total > 0 ? round(($total - $bad) / $total * 100, 1) : 100.0,
            'inventory_value' => round((float) ($row['inventory_value'] ?? 0), 2),
        ];
    }

    /**
     * All orders placed in the range (every state) by status, with gross order value.
     */
    private function getOrdersByStatus(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);

        return $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
                o.status AS code,
                COALESCE(st.label, o.status) AS status,
                COUNT(*) AS orders,
                ROUND(SUM({$this->reportContext->orderValueExpr()}), 2) AS revenue
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_status}} st ON st.status = o.status
            WHERE o.created_at >= ? AND o.created_at < ?
            GROUP BY o.status, st.label
            ORDER BY orders DESC
        "), [$from, $to]);
    }

    /**
     * Orders and net revenue by weekday (store timezone), all 7 days returned.
     */
    private function getRevenueByDayOfWeek(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $local = $this->reportContext->localTimeExpr('o.created_at', $start, $end);

        $rows = $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
                DAYOFWEEK({$local}) AS day_num,
                COUNT(*) AS orders,
                ROUND(SUM({$this->reportContext->orderRevenueExpr()}), 2) AS revenue
            FROM {{sales_order}} o
            WHERE {$this->reportContext->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY day_num
            ORDER BY day_num ASC
        "), [$from, $to]);

        return $this->reportContext->fillDaysOfWeek($rows, ['orders' => 0, 'revenue' => 0]);
    }

    /**
     * The weekday and hour (store time) with the most orders, or null when there were none.
     */
    private function getPeakOrderingWindow(\DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $local = $this->reportContext->localTimeExpr('o.created_at', $start, $end);

        $row = $this->reportContext->getConnection()->fetchRow($this->reportContext->resolveTables("
            SELECT DAYOFWEEK({$local}) AS day_num, HOUR({$local}) AS hour, COUNT(*) AS orders
            FROM {{sales_order}} o
            WHERE {$this->reportContext->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY day_num, hour
            ORDER BY orders DESC, SUM({$this->reportContext->orderRevenueExpr()}) DESC
            LIMIT 1
        "), [$from, $to]);

        if (!$row) {
            return null;
        }
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return [
            'day_name' => $days[(int) $row['day_num'] - 1] ?? '',
            'hour'     => (int) $row['hour'],
            'orders'   => (int) $row['orders'],
        ];
    }

    /**
     * Buyers in the range (by email, guests included) grouped by their order count in the range:
     * New = 1 order, Returning = 2–5, VIP = 6+. All three segments are returned.
     */
    private function getCustomerSegments(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);

        $rows = $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
                CASE
                    WHEN order_count = 1 THEN 'New'
                    WHEN order_count BETWEEN 2 AND 5 THEN 'Returning'
                    ELSE 'VIP'
                END AS segment,
                COUNT(*) AS customers,
                SUM(order_count) AS transactions,
                ROUND(SUM(total_spent), 2) AS revenue
            FROM (
                SELECT
                    o.customer_email,
                    COUNT(*) AS order_count,
                    SUM({$this->reportContext->orderRevenueExpr()}) AS total_spent
                FROM {{sales_order}} o
                WHERE {$this->reportContext->validOrderCondition()}
                  AND o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND o.created_at >= ? AND o.created_at < ?
                GROUP BY o.customer_email
            ) AS customer_orders
            GROUP BY segment
        "), [$from, $to]);

        $bySegment = array_column($rows, null, 'segment');
        $result = [];
        foreach (['New', 'Returning', 'VIP'] as $segment) {
            $row = $bySegment[$segment] ?? [];
            $result[] = [
                'segment'      => $segment,
                'customers'    => (int) ($row['customers'] ?? 0),
                'transactions' => (int) ($row['transactions'] ?? 0),
                'revenue'      => (float) ($row['revenue'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Stock-managed products by quantity range (all ranges returned, zero-filled).
     */
    private function getStockDistribution(): array
    {
        $ranges = ['Out of Stock', 'Low (1-10)', 'Medium (11-50)', 'Good (51-100)', 'High (100+)'];

        $rows = $this->reportContext->getConnection()->fetchAll("
            SELECT
                CASE
                    WHEN s.qty <= 0 THEN 'Out of Stock'
                    WHEN s.qty <= 10 THEN 'Low (1-10)'
                    WHEN s.qty <= 50 THEN 'Medium (11-50)'
                    WHEN s.qty <= 100 THEN 'Good (51-100)'
                    ELSE 'High (100+)'
                END AS stock_range,
                COUNT(*) AS product_count
            FROM ({$this->reportContext->getStockSql()}) s
            GROUP BY stock_range
        ");

        $counts = array_column($rows, 'product_count', 'stock_range');
        $result = [];
        foreach ($ranges as $range) {
            $result[] = ['stock_range' => $range, 'product_count' => (int) ($counts[$range] ?? 0)];
        }

        return $result;
    }
}
