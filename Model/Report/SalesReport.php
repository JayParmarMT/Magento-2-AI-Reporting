<?php
/**
 * Meetanshi AIReporting — Sales & Revenue Report
 *
 * Revenue = net revenue (grand total minus refunds) in the global base currency.
 * Orders in state canceled / pending_payment are not counted as sales.
 * All date buckets use the store timezone.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

class SalesReport
{
    public function __construct(
        private readonly ReportContext $context
    ) {
    }

    /**
     * KPI cards: today, this week (Mon–Sun), this month, this year.
     */
    public function getKpiCards(): array
    {
        $today    = $this->context->today();
        $tomorrow = $today->modify('+1 day');

        $periods = [
            'today' => $today,
            'week'  => $today->modify('monday this week'),
            'month' => $today->modify('first day of this month'),
            'year'  => $today->setDate((int) $today->format('Y'), 1, 1),
        ];

        $result = [];
        foreach ($periods as $key => $start) {
            $result[$key] = $this->getTotals($start, $tomorrow);
        }

        return $result;
    }

    /**
     * Revenue by calendar month for the last 12 months (including the current one).
     */
    public function getRevenueByMonth(): array
    {
        $end   = $this->context->today()->modify('+1 day');
        $start = $this->context->today()->modify('first day of this month')->modify('-11 months');
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);
        $rev   = $this->context->orderRevenueExpr();

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                DATE_FORMAT({$local}, '%Y-%m') AS month,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue,
                ROUND(AVG({$rev}), 2) AS aov
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY month
            ORDER BY month ASC
        "), [$from, $to]);

        return $this->context->fillMonthly($rows, $start, $end, ['orders' => 0, 'revenue' => 0, 'aov' => 0]);
    }

    /**
     * Revenue by day for the last 30 days (including today).
     */
    public function getRevenueByDay(): array
    {
        $end   = $this->context->today()->modify('+1 day');
        $start = $end->modify('-30 days');
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);
        $rev   = $this->context->orderRevenueExpr();

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                DATE({$local}) AS day,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY day
            ORDER BY day ASC
        "), [$from, $to]);

        return $this->context->fillDaily($rows, $start, $end, ['orders' => 0, 'revenue' => 0], 'day');
    }

    /**
     * All orders (every state) grouped by status label, with gross order value.
     */
    public function getOrdersByStatus(): array
    {
        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                COALESCE(st.label, o.status) AS status,
                COUNT(*) AS orders,
                ROUND(SUM({$this->context->orderValueExpr()}), 2) AS revenue
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_status}} st ON st.status = o.status
            GROUP BY o.status, st.label
            ORDER BY orders DESC
        "));
    }

    /**
     * Orders grouped by status for each period (today, week, month, year),
     * so the pie chart can update with the selected period tab.
     *
     * @return array<string, array>
     */
    public function getOrdersByStatusPerPeriod(): array
    {
        $today    = $this->context->today();
        $tomorrow = $today->modify('+1 day');

        $periods = [
            'today' => $today,
            'week'  => $today->modify('monday this week'),
            'month' => $today->modify('first day of this month'),
            'year'  => $today->setDate((int) $today->format('Y'), 1, 1),
        ];

        $result = [];
        foreach ($periods as $key => $start) {
            [$from, $to] = $this->context->utcRange($start, $tomorrow);
            $result[$key] = $this->context->getConnection()->fetchAll($this->context->resolveTables("
                SELECT
                    COALESCE(st.label, o.status) AS status,
                    COUNT(*) AS orders,
                    ROUND(SUM({$this->context->orderValueExpr()}), 2) AS revenue
                FROM {{sales_order}} o
                LEFT JOIN {{sales_order_status}} st ON st.status = o.status
                WHERE o.created_at >= ? AND o.created_at < ?
                GROUP BY o.status, st.label
                ORDER BY orders DESC
            "), [$from, $to]);
        }

        return $result;
    }

    /**
     * Top 10 shipping methods by net revenue.
     */
    public function getRevenueByShipping(): array
    {
        $rev = $this->context->orderRevenueExpr();

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                COALESCE(NULLIF(o.shipping_description, ''), 'No Shipping (Virtual)') AS shipping_method,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue,
                ROUND(AVG({$rev}), 2) AS aov
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
            GROUP BY COALESCE(NULLIF(o.shipping_description, ''), 'No Shipping (Virtual)')
            ORDER BY revenue DESC
            LIMIT 10
        "));
    }

    /**
     * Top 20 coupon codes by usage.
     */
    public function getCouponUsage(): array
    {
        $rev      = $this->context->orderRevenueExpr();
        $discount = $this->context->orderAmountExpr('ABS(o.base_discount_amount)');

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                o.coupon_code,
                COUNT(*) AS times_used,
                ROUND(SUM({$rev}), 2) AS revenue,
                ROUND(SUM({$discount}), 2) AS total_discount,
                ROUND(AVG({$rev}), 2) AS avg_order_value
            FROM {{sales_order}} o
            WHERE o.coupon_code IS NOT NULL AND o.coupon_code != ''
              AND {$this->context->validOrderCondition()}
            GROUP BY o.coupon_code
            ORDER BY times_used DESC
            LIMIT 20
        "));
    }

    /**
     * Top 15 regions by net revenue (shipping address, billing address for virtual orders).
     */
    public function getRevenueByRegion(): array
    {
        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                COALESCE(NULLIF(sa.region, ''), NULLIF(ba.region, ''), 'N/A') AS state,
                COALESCE(sa.country_id, ba.country_id) AS country,
                COUNT(*) AS orders,
                ROUND(SUM({$this->context->orderRevenueExpr()}), 2) AS revenue
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_address}} sa ON sa.parent_id = o.entity_id AND sa.address_type = 'shipping'
            LEFT JOIN {{sales_order_address}} ba ON ba.parent_id = o.entity_id AND ba.address_type = 'billing'
            WHERE {$this->context->validOrderCondition()}
            GROUP BY COALESCE(NULLIF(sa.region, ''), NULLIF(ba.region, ''), 'N/A'), COALESCE(sa.country_id, ba.country_id)
            ORDER BY revenue DESC
            LIMIT 15
        "));
    }

    /**
     * Refund and cancellation totals.
     */
    public function getRefundSummary(): array
    {
        $refunded = $this->context->orderAmountExpr('o.base_total_refunded');

        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                COALESCE(ROUND(SUM({$refunded}), 2), 0) AS total_refunded,
                COALESCE(SUM(o.base_total_refunded > 0), 0) AS refund_count,
                COALESCE(SUM(o.state = 'canceled'), 0) AS cancel_count,
                COALESCE(ROUND(SUM(IF(o.state = 'canceled', {$this->context->orderValueExpr()}, 0)), 2), 0) AS cancel_revenue
            FROM {{sales_order}} o
        "));

        return [
            'total_refunded' => $row['total_refunded'] ?? 0,
            'refund_count'   => $row['refund_count'] ?? 0,
            'cancel_count'   => $row['cancel_count'] ?? 0,
            'cancel_revenue' => $row['cancel_revenue'] ?? 0,
        ];
    }

    /**
     * Revenue by weekday for the last 6 months.
     */
    public function getRevenueByDayOfWeek(): array
    {
        $end   = $this->context->today()->modify('+1 day');
        $start = $end->modify('-6 months');
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);
        $rev   = $this->context->orderRevenueExpr();

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                DAYOFWEEK({$local}) AS day_num,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue,
                ROUND(AVG({$rev}), 2) AS aov
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY day_num
            ORDER BY day_num ASC
        "), [$from, $to]);

        return $this->context->fillDaysOfWeek($rows, ['orders' => 0, 'revenue' => 0, 'aov' => 0]);
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
     * Revenue + orders time-series for each period, with granularity adapted to the range:
     *  - today  -> hourly
     *  - week   -> daily
     *  - month  -> daily
     *  - year   -> monthly
     *
     * @return array<string, array>
     */
    public function getRevenueTimeSeriesPerPeriod(): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            $result[$period] = $this->getRevenueTimeSeries($period);
        }
        return $result;
    }

    /**
     * Revenue + orders series for a single period.
     */
    private function getRevenueTimeSeries(string $period): array
    {
        [$start, $end] = $this->periodRange($period);
        [$from, $to]   = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);
        $rev   = $this->context->orderRevenueExpr();

        // Choose grouping expression + label format per granularity
        if ($period === 'today') {
            $groupExpr = "DATE_FORMAT({$local}, '%Y-%m-%d %H:00')";
        } elseif ($period === 'year') {
            $groupExpr = "DATE_FORMAT({$local}, '%Y-%m')";
        } else {
            $groupExpr = "DATE({$local})";
        }

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$groupExpr} AS bucket,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY bucket
            ORDER BY bucket ASC
        "), [$from, $to]);

        return $this->buildContinuousSeries($period, $rows, $start, $end);
    }

    /**
     * Fill gaps so the chart has a continuous X axis, and format labels.
     */
    private function buildContinuousSeries(string $period, array $rows, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $byBucket = [];
        foreach ($rows as $r) {
            $byBucket[(string) $r['bucket']] = $r;
        }

        $series = [];

        if ($period === 'today') {
            for ($h = 0; $h < 24; $h++) {
                $dt  = $start->setTime($h, 0);
                if ($dt >= $end) {
                    break;
                }
                $key = $dt->format('Y-m-d H:00');
                $series[] = [
                    'label'   => $dt->format('H:00'),
                    'orders'  => (int) ($byBucket[$key]['orders'] ?? 0),
                    'revenue' => (float) ($byBucket[$key]['revenue'] ?? 0),
                ];
            }
        } elseif ($period === 'year') {
            $cursor = $start;
            while ($cursor < $end) {
                $key = $cursor->format('Y-m');
                $series[] = [
                    'label'   => $cursor->format('M Y'),
                    'orders'  => (int) ($byBucket[$key]['orders'] ?? 0),
                    'revenue' => (float) ($byBucket[$key]['revenue'] ?? 0),
                ];
                $cursor = $cursor->modify('first day of next month');
            }
        } else {
            // daily (week / month)
            $cursor = $start;
            while ($cursor < $end) {
                $key = $cursor->format('Y-m-d');
                $series[] = [
                    'label'   => $cursor->format('d M'),
                    'orders'  => (int) ($byBucket[$key]['orders'] ?? 0),
                    'revenue' => (float) ($byBucket[$key]['revenue'] ?? 0),
                ];
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $series;
    }

    /**
     * Revenue by weekday for each period, so the Day-of-Week chart follows the period tab.
     *
     * @return array<string, array>
     */
    public function getRevenueByDayOfWeekPerPeriod(): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            [$start, $end] = $this->periodRange($period);
            [$from, $to]   = $this->context->utcRange($start, $end);
            $local = $this->context->localTimeExpr('o.created_at', $start, $end);
            $rev   = $this->context->orderRevenueExpr();

            $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
                SELECT
                    DAYOFWEEK({$local}) AS day_num,
                    COUNT(*) AS orders,
                    ROUND(SUM({$rev}), 2) AS revenue,
                    ROUND(AVG({$rev}), 2) AS aov
                FROM {{sales_order}} o
                WHERE {$this->context->validOrderCondition()}
                  AND o.created_at >= ? AND o.created_at < ?
                GROUP BY day_num
                ORDER BY day_num ASC
            "), [$from, $to]);

            $result[$period] = $this->context->fillDaysOfWeek($rows, ['orders' => 0, 'revenue' => 0, 'aov' => 0]);
        }

        return $result;
    }

    /**
     * Orders, net revenue and AOV for a local [start, end) period.
     */
    private function getTotals(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $rev = $this->context->orderRevenueExpr();

        return $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*) AS orders,
                COALESCE(SUM({$rev}), 0) AS revenue,
                COALESCE(AVG({$rev}), 0) AS aov
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
        "), [$from, $to]) ?: ['orders' => 0, 'revenue' => 0, 'aov' => 0];
    }
}
