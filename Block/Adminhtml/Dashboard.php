<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Report\ReportContext;

class Dashboard extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ReportContext $reportContext,
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
        $dates = $this->getDateRangeFilter($dateRange, $customStart, $customEnd);
        $start = $this->reportContext->parseLocalDate($dates['start']);
        $end   = $this->reportContext->parseLocalDate($dates['end'])->modify('+1 day');

        return [
            'date_range'          => $dates,
            'currency_code'       => $this->reportContext->getCurrencyCode(),
            'kpis'                => $this->getKpiMetrics($start, $end),
            'revenue_trend'       => $this->getRevenueTrend($start, $end),
            'revenue_by_category' => $this->getRevenueByCategory($start, $end),
            'top_products'        => $this->getTopProducts($start, $end),
            'orders_by_status'    => $this->getOrdersByStatus($start, $end),
            'revenue_by_dow'      => $this->getRevenueByDayOfWeek($start, $end),
            'customer_segments'   => $this->getCustomerSegments($start, $end),
            'stock_distribution'  => $this->getStockDistribution()
        ];
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
        $productsSold = $conn->fetchOne($this->reportContext->resolveTables("
            SELECT COALESCE(SUM({$this->reportContext->itemQtyExpr()}), 0)
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            WHERE {$this->reportContext->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]);

        $stock = $conn->fetchRow("
            SELECT
                COALESCE(SUM(s.is_in_stock = 1 AND s.qty > 0 AND s.qty <= 10), 0) AS low_stock,
                COALESCE(SUM(s.is_in_stock = 0), 0) AS out_of_stock
            FROM ({$this->reportContext->getStockSql()}) s
        ") ?: [];

        return [
            'total_revenue'           => (float) $current['total_revenue'],
            'total_revenue_change'    => $this->calculatePercentageChange((float) $previous['total_revenue'], (float) $current['total_revenue']),
            'total_orders'            => (int) $current['total_orders'],
            'total_orders_change'     => $this->calculatePercentageChange((float) $previous['total_orders'], (float) $current['total_orders']),
            'active_customers'        => (int) $current['active_customers'],
            'active_customers_change' => $this->calculatePercentageChange((float) $previous['active_customers'], (float) $current['active_customers']),
            'avg_order_value'         => (float) $current['avg_order_value'],
            'avg_order_value_change'  => $this->calculatePercentageChange((float) $previous['avg_order_value'], (float) $current['avg_order_value']),
            'products_sold'           => (int) $productsSold,
            'low_stock'               => (int) ($stock['low_stock'] ?? 0),
            'out_of_stock'            => (int) ($stock['out_of_stock'] ?? 0)
        ];
    }

    /**
     * Orders, net revenue, AOV and distinct buyers (by email, guests included) for [start, end).
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
                COUNT(DISTINCT o.customer_email) AS active_customers
            FROM {{sales_order}} o
            WHERE {$this->reportContext->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]) ?: ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'active_customers' => 0];
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
     * Top 10 categories by net item revenue (root categories excluded, admin names).
     */
    private function getRevenueByCategory(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);
        $link   = $this->reportContext->getCategoryLinkField();
        $nameId = $this->reportContext->getAttributeId('catalog_category', 'name');

        return $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
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
            LIMIT 10
        "), [$from, $to]);
    }

    /**
     * Top 10 products by net revenue (top-level order lines only).
     */
    private function getTopProducts(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);

        return $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
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
            LIMIT 10
        "), [$from, $to]);
    }

    /**
     * All orders placed in the range (every state) by status label, with gross order value.
     */
    private function getOrdersByStatus(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);

        return $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
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
     * Buyers in the range (by email, guests included) grouped by their order count in the range.
     */
    private function getCustomerSegments(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->reportContext->utcRange($start, $end);

        return $this->reportContext->getConnection()->fetchAll($this->reportContext->resolveTables("
            SELECT
                CASE
                    WHEN order_count = 1 THEN 'New'
                    WHEN order_count BETWEEN 2 AND 5 THEN 'Returning'
                    ELSE 'VIP'
                END AS segment,
                COUNT(*) AS customers,
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
            ORDER BY FIELD(segment, 'New', 'Returning', 'VIP')
        "), [$from, $to]);
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
