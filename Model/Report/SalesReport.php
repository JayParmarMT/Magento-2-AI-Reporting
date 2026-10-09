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

    // ── Range-based figures of the Sales & Revenue page (any local [start, end) range) ──────────

    /**
     * Totals of a range. Sales figures count orders that are not canceled / pending payment;
     * all_orders, pending_payment and canceled count every order placed in the range.
     */
    public function getRangeTotals(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables($this->getRangeTotalsSql()), [$from, $to]) ?: [];

        $totals = [];
        foreach (['revenue', 'gross', 'refunded', 'discount', 'tax', 'shipping', 'discounted_revenue'] as $key) {
            $totals[$key] = round((float) ($row[$key] ?? 0), 2);
        }
        foreach (['all_orders', 'orders', 'refund_orders', 'discounted_orders', 'pending_payment', 'canceled'] as $key) {
            $totals[$key] = (int) ($row[$key] ?? 0);
        }

        return $totals;
    }

    /**
     * The totals query (with ? placeholders for the UTC range), as shown under the copilot panel.
     */
    public function getRangeTotalsSql(): string
    {
        $valid    = $this->context->validOrderCondition();
        $rev      = $this->context->orderRevenueExpr();
        $gross    = $this->context->orderValueExpr();
        $refunded = $this->context->orderAmountExpr('o.base_total_refunded');
        $discount = $this->context->orderAmountExpr('ABS(o.base_discount_amount)');
        $tax      = $this->context->orderAmountExpr('o.base_tax_amount');
        $shipping = $this->context->orderAmountExpr('o.base_shipping_amount');

        return "SELECT COUNT(*) AS all_orders,
                COALESCE(SUM({$valid}), 0) AS orders,
                COALESCE(SUM(IF({$valid}, {$rev}, 0)), 0) AS revenue,
                COALESCE(SUM(IF({$valid}, {$gross}, 0)), 0) AS gross,
                COALESCE(SUM(IF({$valid}, {$refunded}, 0)), 0) AS refunded,
                COALESCE(SUM({$valid} AND o.base_total_refunded > 0), 0) AS refund_orders,
                COALESCE(SUM(IF({$valid}, {$discount}, 0)), 0) AS discount,
                COALESCE(SUM({$valid} AND o.base_discount_amount <> 0), 0) AS discounted_orders,
                COALESCE(SUM(IF({$valid} AND o.base_discount_amount <> 0, {$rev}, 0)), 0) AS discounted_revenue,
                COALESCE(SUM(IF({$valid}, {$tax}, 0)), 0) AS tax,
                COALESCE(SUM(IF({$valid}, {$shipping}, 0)), 0) AS shipping,
                COALESCE(SUM(o.state = 'pending_payment'), 0) AS pending_payment,
                COALESCE(SUM(o.state = 'canceled'), 0) AS canceled
            FROM {{sales_order}} o
            WHERE o.created_at >= ? AND o.created_at < ?";
    }

    /**
     * The highest counted order of a range (gross value) with its ship-to region, or null.
     */
    public function getTopOrder(\DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $gross = $this->context->orderValueExpr();

        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                o.entity_id,
                o.increment_id,
                ROUND({$gross}, 2) AS amount,
                COALESCE(NULLIF(sa.region, ''), NULLIF(ba.region, ''), '') AS region,
                COALESCE(sa.country_id, ba.country_id, '') AS country
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_address}} sa ON sa.parent_id = o.entity_id AND sa.address_type = 'shipping'
            LEFT JOIN {{sales_order_address}} ba ON ba.parent_id = o.entity_id AND ba.address_type = 'billing'
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            ORDER BY amount DESC, o.entity_id DESC
            LIMIT 1
        "), [$from, $to]);

        return $row ? [
            'order_id'     => (int) $row['entity_id'],
            'increment_id' => (string) $row['increment_id'],
            'amount'       => (float) $row['amount'],
            'region'       => (string) $row['region'],
            'country'      => (string) $row['country'],
        ] : null;
    }

    /**
     * Credit memos created in a range: count and refunded amount.
     */
    public function getCreditMemoTotals(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*) AS memos,
                COALESCE(SUM(cm.base_grand_total * IFNULL(cm.base_to_global_rate, 1)), 0) AS refunded
            FROM {{sales_creditmemo}} cm
            WHERE cm.created_at >= ? AND cm.created_at < ?
        "), [$from, $to]) ?: [];

        return ['memos' => (int) ($row['memos'] ?? 0), 'refunded' => round((float) ($row['refunded'] ?? 0), 2)];
    }

    /**
     * Net revenue and orders per hour, day or month of a range, with empty buckets filled in.
     *
     * @return array<int, array{key: string, label: string, orders: int, revenue: float}>
     */
    public function getRevenueSeries(\DateTimeImmutable $start, \DateTimeImmutable $end, string $unit): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);
        $rev   = $this->context->orderRevenueExpr();
        $group = match ($unit) {
            'hour'  => "DATE_FORMAT({$local}, '%Y-%m-%d %H:00')",
            'month' => "DATE_FORMAT({$local}, '%Y-%m')",
            default => "DATE({$local})",
        };

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$group} AS bucket,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY bucket
            ORDER BY bucket ASC
        "), [$from, $to]);

        $byKey  = array_column($rows, null, 'bucket');
        $series = [];
        [$step, $keyFormat, $labelFormat] = match ($unit) {
            'hour'  => ['+1 hour', 'Y-m-d H:00', 'H:00'],
            'month' => ['first day of next month', 'Y-m', 'M Y'],
            default => ['+1 day', 'Y-m-d', 'M j'],
        };
        $cursor = $unit === 'month' ? $start->modify('first day of this month') : $start;

        for (; $cursor < $end; $cursor = $cursor->modify($step)) {
            $key = $cursor->format($keyFormat);
            $series[] = [
                'key'     => $key,
                'label'   => $cursor->format($labelFormat),
                'orders'  => (int) ($byKey[$key]['orders'] ?? 0),
                'revenue' => (float) ($byKey[$key]['revenue'] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Every order placed in a range by status (all states), with gross order value.
     */
    public function getStatusBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                o.status,
                o.state,
                COALESCE(st.label, o.status) AS label,
                COUNT(*) AS orders,
                ROUND(SUM({$this->context->orderValueExpr()}), 2) AS value
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_status}} st ON st.status = o.status
            WHERE o.created_at >= ? AND o.created_at < ?
            GROUP BY o.status, o.state, st.label
            ORDER BY orders DESC, value DESC
        "), [$from, $to]);

        return array_map(static fn (array $row): array => [
            'status'  => (string) $row['status'],
            'state'   => (string) $row['state'],
            'label'   => (string) $row['label'],
            'orders'  => (int) $row['orders'],
            'value'   => (float) $row['value'],
            'counted' => !in_array($row['state'], ReportContext::EXCLUDED_STATES, true),
        ], $rows);
    }

    /**
     * Net revenue, orders and AOV per weekday of a range (Sunday first).
     */
    public function getWeekdayBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);
        $rev   = $this->context->orderRevenueExpr();

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                DAYOFWEEK({$local}) AS day_num,
                COUNT(*) AS orders,
                ROUND(SUM({$rev}), 2) AS revenue
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY day_num
        "), [$from, $to]);

        return array_map(static fn (array $row): array => [
            'day_num'  => (int) $row['day_num'],
            'day_name' => (string) $row['day_name'],
            'orders'   => (int) $row['orders'],
            'revenue'  => (float) $row['revenue'],
        ], $this->context->fillDaysOfWeek($rows, ['orders' => 0, 'revenue' => 0]));
    }

    /**
     * The local hour (0–23) with the most counted orders in a range, or null without orders.
     */
    public function getPeakHour(\DateTimeImmutable $start, \DateTimeImmutable $end): ?int
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);

        $hour = $this->context->getConnection()->fetchOne($this->context->resolveTables("
            SELECT HOUR({$local}) AS hour_num
            FROM {{sales_order}} o
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY hour_num
            ORDER BY COUNT(*) DESC, SUM({$this->context->orderRevenueExpr()}) DESC
            LIMIT 1
        "), [$from, $to]);

        return $hour === false || $hour === null ? null : (int) $hour;
    }

    /**
     * Counted orders by ship-to region (billing region for virtual orders), by net revenue.
     */
    public function getRegionBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 50): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $region  = "COALESCE(NULLIF(sa.region, ''), NULLIF(ba.region, ''), '')";
        $country = "COALESCE(sa.country_id, ba.country_id, '')";

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$region} AS region,
                {$country} AS country,
                COUNT(*) AS orders,
                ROUND(SUM({$this->context->orderRevenueExpr()}), 2) AS revenue,
                ROUND(SUM({$this->context->orderAmountExpr('o.base_tax_amount')}), 2) AS tax
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_address}} sa ON sa.parent_id = o.entity_id AND sa.address_type = 'shipping'
            LEFT JOIN {{sales_order_address}} ba ON ba.parent_id = o.entity_id AND ba.address_type = 'billing'
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY {$region}, {$country}
            ORDER BY revenue DESC, orders DESC
            LIMIT " . max(1, $limit)), [$from, $to]);

        return array_map(static fn (array $row): array => [
            'region'  => (string) $row['region'],
            'country' => (string) $row['country'],
            'orders'  => (int) $row['orders'],
            'revenue' => (float) $row['revenue'],
            'tax'     => (float) $row['tax'],
        ], $rows);
    }

    /**
     * Counted orders by shipping method: net revenue, shipping charged and how many orders shipped.
     */
    public function getShippingBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $method      = "COALESCE(NULLIF(o.shipping_method, ''), '')";
        $description = "COALESCE(NULLIF(o.shipping_description, ''), '')";

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$method} AS method,
                {$description} AS description,
                COUNT(*) AS orders,
                ROUND(SUM({$this->context->orderRevenueExpr()}), 2) AS revenue,
                ROUND(SUM({$this->context->orderAmountExpr('o.base_shipping_amount')}), 2) AS shipping,
                COALESCE(SUM(o.is_virtual = 1), 0) AS virtual_orders,
                COALESCE(SUM(sh.order_id IS NOT NULL), 0) AS shipped_orders
            FROM {{sales_order}} o
            LEFT JOIN (SELECT DISTINCT order_id FROM {{sales_shipment}}) sh ON sh.order_id = o.entity_id
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY {$method}, {$description}
            ORDER BY revenue DESC, orders DESC
            LIMIT 20
        "), [$from, $to]);

        return array_map(static fn (array $row): array => [
            'method'         => (string) $row['method'],
            'description'    => (string) $row['description'],
            'orders'         => (int) $row['orders'],
            'revenue'        => (float) $row['revenue'],
            'shipping'       => (float) $row['shipping'],
            'virtual_orders' => (int) $row['virtual_orders'],
            'shipped_orders' => (int) $row['shipped_orders'],
        ], $rows);
    }

    /**
     * Counted orders per cart price rule that applied to them (coupon rules split by code).
     * An order with several rules counts under each with its whole revenue; its discount counts
     * only under rules that give one (not under a free-shipping-only rule).
     */
    public function getPromotionBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit = 30): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);
        $code = "IF(r.coupon_type = 1, '', COALESCE(o.coupon_code, ''))";

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                r.rule_id,
                r.name,
                r.coupon_type,
                r.simple_action,
                r.discount_amount,
                r.simple_free_shipping,
                r.is_active,
                r.from_date,
                r.to_date,
                {$code} AS code,
                COUNT(*) AS orders,
                ROUND(SUM(IF(r.discount_amount > 0, {$this->context->orderAmountExpr('ABS(o.base_discount_amount)')}, 0)), 2) AS discount,
                ROUND(SUM({$this->context->orderRevenueExpr()}), 2) AS revenue
            FROM {{sales_order}} o
            INNER JOIN {{salesrule}} r ON FIND_IN_SET(r.rule_id, o.applied_rule_ids){$this->context->currentVersionCondition('r')}
            WHERE {$this->context->validOrderCondition()}
              AND o.applied_rule_ids IS NOT NULL AND o.applied_rule_ids <> ''
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY r.rule_id, r.name, r.coupon_type, r.simple_action, r.discount_amount, r.simple_free_shipping,
                     r.is_active, r.from_date, r.to_date, {$code}
            ORDER BY revenue DESC, orders DESC
            LIMIT " . max(1, $limit)), [$from, $to]);

        return array_map(static fn (array $row): array => [
            'rule_id'         => (int) $row['rule_id'],
            'name'            => (string) $row['name'],
            'code'            => (string) $row['code'],
            'coupon_type'     => (int) $row['coupon_type'],
            'simple_action'   => (string) $row['simple_action'],
            'discount_amount' => (float) $row['discount_amount'],
            'free_shipping'   => (int) $row['simple_free_shipping'] > 0,
            'is_active'       => (bool) $row['is_active'],
            'from_date'       => $row['from_date'] ? (string) $row['from_date'] : null,
            'to_date'         => $row['to_date'] ? (string) $row['to_date'] : null,
            'orders'          => (int) $row['orders'],
            'discount'        => (float) $row['discount'],
            'revenue'         => (float) $row['revenue'],
        ], $rows);
    }

    /**
     * Every order placed in a range, oldest first, for the order-level CSV export.
     */
    public function getOrderLines(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        [$from, $to] = $this->context->utcRange($start, $end);

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                o.increment_id,
                o.created_at,
                o.status,
                o.state,
                o.customer_email,
                TRIM(CONCAT_WS(' ', o.customer_firstname, o.customer_lastname)) AS customer_name,
                o.customer_is_guest,
                COALESCE(NULLIF(sa.region, ''), NULLIF(ba.region, ''), '') AS region,
                COALESCE(sa.country_id, ba.country_id, '') AS country,
                COALESCE(o.shipping_description, '') AS shipping_method,
                COALESCE(o.coupon_code, '') AS coupon_code,
                ROUND({$this->context->orderValueExpr()}, 2) AS gross,
                ROUND({$this->context->orderAmountExpr('ABS(o.base_discount_amount)')}, 2) AS discount,
                ROUND({$this->context->orderAmountExpr('o.base_tax_amount')}, 2) AS tax,
                ROUND({$this->context->orderAmountExpr('o.base_shipping_amount')}, 2) AS shipping,
                ROUND({$this->context->orderAmountExpr('o.base_total_refunded')}, 2) AS refunded,
                ROUND({$this->context->orderRevenueExpr()}, 2) AS net
            FROM {{sales_order}} o
            LEFT JOIN {{sales_order_address}} sa ON sa.parent_id = o.entity_id AND sa.address_type = 'shipping'
            LEFT JOIN {{sales_order_address}} ba ON ba.parent_id = o.entity_id AND ba.address_type = 'billing'
            WHERE o.created_at >= ? AND o.created_at < ?
            ORDER BY o.created_at ASC, o.entity_id ASC
        "), [$from, $to]);
    }
}
