<?php
/**
 * Meetanshi AIReporting — Customer Analytics Report
 *
 * Buyers are identified by order email, so guest buyers are included.
 * Spend = net revenue (after refunds) in the global base currency; cancelled
 * and unpaid (pending_payment) orders are ignored. Dates use the store timezone.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

class CustomerReport
{
    /**
     * CLV bucket upper bounds (in base currency); the last bucket is open-ended.
     */
    private const CLV_BOUNDS = [100, 250, 500, 1000];

    public function __construct(
        private readonly ReportContext $context
    ) {
    }

    /**
     * Customer KPI cards.
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
     * KPI totals for a single period:
     *  - new_customers        : registrations in the period
     *  - total_customers      : all customers registered up to the end of the period
     *  - customers_with_orders: distinct buyers who ordered within the period
     *  - repeat_rate          : share of buyers with >1 order within the period
     */
    private function getKpiTotals(string $period): array
    {
        $conn = $this->context->getConnection();
        [$start, $end] = $this->periodRange($period);
        [$from, $to]   = $this->context->utcRange($start, $end);

        $newCustomers = (int) $conn->fetchOne(
            $this->context->resolveTables(
                'SELECT COUNT(*) FROM {{customer_entity}} WHERE created_at >= ? AND created_at < ?'
            ),
            [$from, $to]
        );

        // Total customers registered up to the end of the selected period
        $totalCustomers = (int) $conn->fetchOne(
            $this->context->resolveTables('SELECT COUNT(*) FROM {{customer_entity}} WHERE created_at < ?'),
            [$to]
        );

        $customersWithOrders = (int) $conn->fetchOne(
            $this->context->resolveTables("
                SELECT COUNT(DISTINCT o.customer_email)
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                  AND o.created_at >= ? AND o.created_at < ?
            "),
            [$from, $to]
        );

        // Repeat rate for buyers active in this period
        $repeat = $conn->fetchRow(
            $this->context->resolveTables("
                SELECT COUNT(*) AS total, COALESCE(SUM(order_count > 1), 0) AS repeat_customers
                FROM (
                    SELECT o.customer_email, COUNT(*) AS order_count
                    FROM {{sales_order}} o
                    WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                      AND {$this->context->validOrderCondition()}
                      AND o.created_at >= ? AND o.created_at < ?
                    GROUP BY o.customer_email
                ) t
            "),
            [$from, $to]
        ) ?: ['total' => 0, 'repeat_customers' => 0];

        $total  = (int) ($repeat['total'] ?? 0);
        $rep    = (int) ($repeat['repeat_customers'] ?? 0);

        return [
            'total_customers'       => $totalCustomers,
            'new_customers'         => $newCustomers,
            'customers_with_orders' => $customersWithOrders,
            'repeat_rate'           => $total > 0 ? round($rep / $total * 100, 2) : 0.0,
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
     * Top customers by lifetime net spend.
     */
    public function getTopCustomersBySpend(int $limit = 20): array
    {
        $rev = $this->context->orderRevenueExpr();

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                o.customer_email,
                MAX(TRIM(CONCAT(IFNULL(o.customer_firstname, ''), ' ', IFNULL(o.customer_lastname, '')))) AS customer_name,
                COUNT(*) AS total_orders,
                ROUND(SUM({$rev}), 2) AS lifetime_value,
                ROUND(AVG({$rev}), 2) AS avg_order_value,
                MIN(o.created_at) AS first_order_date,
                MAX(o.created_at) AS last_order_date
            FROM {{sales_order}} o
            WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
              AND {$this->context->validOrderCondition()}
            GROUP BY o.customer_email
            ORDER BY lifetime_value DESC
            LIMIT " . max(1, $limit)));

        return $this->localiseDates($rows, ['first_order_date', 'last_order_date']);
    }

    /**
     * RFM (Recency, Frequency, Monetary) scores for the top customers by spend.
     */
    public function getRfmSegmentation(int $limit = 50): array
    {
        $rev = $this->context->orderRevenueExpr();

        return $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                customer_email,
                customer_name,
                recency_days,
                frequency,
                ROUND(monetary, 2) AS monetary,
                CASE
                    WHEN recency_days <= 30 THEN 5
                    WHEN recency_days <= 60 THEN 4
                    WHEN recency_days <= 90 THEN 3
                    WHEN recency_days <= 180 THEN 2
                    ELSE 1
                END AS r_score,
                CASE
                    WHEN frequency >= 10 THEN 5
                    WHEN frequency >= 7 THEN 4
                    WHEN frequency >= 4 THEN 3
                    WHEN frequency >= 2 THEN 2
                    ELSE 1
                END AS f_score,
                CASE
                    WHEN monetary >= 1000 THEN 5
                    WHEN monetary >= 500 THEN 4
                    WHEN monetary >= 250 THEN 3
                    WHEN monetary >= 100 THEN 2
                    ELSE 1
                END AS m_score
            FROM (
                SELECT
                    o.customer_email,
                    MAX(TRIM(CONCAT(IFNULL(o.customer_firstname, ''), ' ', IFNULL(o.customer_lastname, '')))) AS customer_name,
                    DATEDIFF(UTC_TIMESTAMP(), MAX(o.created_at)) AS recency_days,
                    COUNT(*) AS frequency,
                    SUM({$rev}) AS monetary
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                GROUP BY o.customer_email
            ) rfm
            ORDER BY monetary DESC
            LIMIT " . max(1, $limit)));
    }

    /**
     * New vs returning buyers per month for the last 12 months.
     * "New" = the buyer's first ever order is in that month; "returning" = first order was earlier.
     */
    public function getNewVsReturning(): array
    {
        $end    = $this->context->today()->modify('+1 day');
        $start  = $this->context->today()->modify('first day of this month')->modify('-11 months');
        [$from, $to] = $this->context->utcRange($start, $end);
        $orderMonth = "DATE_FORMAT({$this->context->localTimeExpr('o.created_at', $start, $end)}, '%Y-%m')";
        $firstMonth = "DATE_FORMAT({$this->context->localTimeExpr('f.first_at', $start, $end)}, '%Y-%m')";

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$orderMonth} AS month,
                COUNT(DISTINCT CASE WHEN f.first_at >= ? AND {$firstMonth} = {$orderMonth} THEN o.customer_email END) AS new_customers,
                COUNT(DISTINCT CASE WHEN f.first_at < ? OR {$firstMonth} < {$orderMonth} THEN o.customer_email END) AS returning_customers
            FROM {{sales_order}} o
            JOIN (
                SELECT so.customer_email, MIN(so.created_at) AS first_at
                FROM {{sales_order}} so
                WHERE so.customer_email IS NOT NULL AND so.customer_email != ''
                  AND {$this->context->validOrderCondition('so')}
                GROUP BY so.customer_email
            ) f ON f.customer_email = o.customer_email
            WHERE {$this->context->validOrderCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY month
            ORDER BY month ASC
        "), [$from, $from, $from, $to]);

        return $this->context->fillMonthly($rows, $start, $end, ['new_customers' => 0, 'returning_customers' => 0]);
    }

    /**
     * New customer registrations per month for the last 12 months.
     */
    public function getCustomerAcquisition(): array
    {
        $end   = $this->context->today()->modify('+1 day');
        $start = $this->context->today()->modify('first day of this month')->modify('-11 months');
        [$from, $to] = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('c.created_at', $start, $end);

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                DATE_FORMAT({$local}, '%Y-%m') AS month,
                COUNT(*) AS new_customers
            FROM {{customer_entity}} c
            WHERE c.created_at >= ? AND c.created_at < ?
            GROUP BY month
            ORDER BY month ASC
        "), [$from, $to]);

        return $this->context->fillMonthly($rows, $start, $end, ['new_customers' => 0]);
    }

    /**
     * Distribution of buyers by lifetime net spend.
     */
    public function getClvDistribution(): array
    {
        $cases = '';
        foreach (self::CLV_BOUNDS as $i => $bound) {
            $cases .= sprintf(' WHEN total_spent < %d THEN %d', $bound, $i + 1);
        }

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                CASE{$cases} ELSE " . (count(self::CLV_BOUNDS) + 1) . " END AS bucket,
                COUNT(*) AS customer_count
            FROM (
                SELECT o.customer_email, SUM({$this->context->orderRevenueExpr()}) AS total_spent
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                GROUP BY o.customer_email
            ) clv_data
            GROUP BY bucket
            ORDER BY bucket
        "));

        $counts = array_column($rows, 'customer_count', 'bucket');
        $symbol = $this->context->getCurrencySymbol();
        $result = [];
        $lower  = 0;
        foreach (self::CLV_BOUNDS as $i => $bound) {
            $result[] = [
                'clv_range'      => $symbol . $lower . '-' . $symbol . ($bound - 1),
                'customer_count' => (int) ($counts[$i + 1] ?? 0),
            ];
            $lower = $bound;
        }
        $result[] = [
            'clv_range'      => $symbol . $lower . '+',
            'customer_count' => (int) ($counts[count(self::CLV_BOUNDS) + 1] ?? 0),
        ];

        return $result;
    }

    /**
     * New vs returning buyers, bucketed for each period so the chart follows the tab:
     *  - today -> hourly, week/month -> daily, year -> monthly.
     *
     * @return array<string, array>
     */
    public function getNewVsReturningPerPeriod(): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            $result[$period] = $this->getNewVsReturningForPeriod($period);
        }
        return $result;
    }

    private function getNewVsReturningForPeriod(string $period): array
    {
        [$start, $end] = $this->periodRange($period);
        [$from, $to]   = $this->context->utcRange($start, $end);
        $local = $this->context->localTimeExpr('o.created_at', $start, $end);

        if ($period === 'today') {
            $bucketExpr = "DATE_FORMAT({$local}, '%Y-%m-%d %H:00')";
        } elseif ($period === 'year') {
            $bucketExpr = "DATE_FORMAT({$local}, '%Y-%m')";
        } else {
            $bucketExpr = "DATE({$local})";
        }

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$bucketExpr} AS bucket,
                COUNT(DISTINCT CASE WHEN f.first_at >= ? THEN o.customer_email END) AS new_customers,
                COUNT(DISTINCT CASE WHEN f.first_at < ?  THEN o.customer_email END) AS returning_customers
            FROM {{sales_order}} o
            JOIN (
                SELECT so.customer_email, MIN(so.created_at) AS first_at
                FROM {{sales_order}} so
                WHERE so.customer_email IS NOT NULL AND so.customer_email != ''
                  AND {$this->context->validOrderCondition('so')}
                GROUP BY so.customer_email
            ) f ON f.customer_email = o.customer_email
            WHERE {$this->context->validOrderCondition()}
              AND o.customer_email IS NOT NULL AND o.customer_email != ''
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY bucket
            ORDER BY bucket ASC
        "), [$from, $from, $from, $to]);

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
                $dt = $start->setTime($h, 0);
                if ($dt >= $end) {
                    break;
                }
                $key = $dt->format('Y-m-d H:00');
                $series[] = [
                    'label'               => $dt->format('H:00'),
                    'new_customers'       => (int) ($byBucket[$key]['new_customers'] ?? 0),
                    'returning_customers' => (int) ($byBucket[$key]['returning_customers'] ?? 0),
                ];
            }
        } elseif ($period === 'year') {
            $cursor = $start;
            while ($cursor < $end) {
                $key = $cursor->format('Y-m');
                $series[] = [
                    'label'               => $cursor->format('M Y'),
                    'new_customers'       => (int) ($byBucket[$key]['new_customers'] ?? 0),
                    'returning_customers' => (int) ($byBucket[$key]['returning_customers'] ?? 0),
                ];
                $cursor = $cursor->modify('first day of next month');
            }
        } else {
            $cursor = $start;
            while ($cursor < $end) {
                $key = $cursor->format('Y-m-d');
                $series[] = [
                    'label'               => $cursor->format('d M'),
                    'new_customers'       => (int) ($byBucket[$key]['new_customers'] ?? 0),
                    'returning_customers' => (int) ($byBucket[$key]['returning_customers'] ?? 0),
                ];
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $series;
    }

    /**
     * CLV distribution of buyers who ordered within each period.
     *
     * @return array<string, array>
     */
    public function getClvDistributionPerPeriod(): array
    {
        $result = [];
        foreach (['today', 'week', 'month', 'year'] as $period) {
            $result[$period] = $this->getClvDistributionForPeriod($period);
        }
        return $result;
    }

    private function getClvDistributionForPeriod(string $period): array
    {
        [$start, $end] = $this->periodRange($period);
        [$from, $to]   = $this->context->utcRange($start, $end);

        $cases = '';
        foreach (self::CLV_BOUNDS as $i => $bound) {
            $cases .= sprintf(' WHEN total_spent < %d THEN %d', $bound, $i + 1);
        }

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                CASE{$cases} ELSE " . (count(self::CLV_BOUNDS) + 1) . " END AS bucket,
                COUNT(*) AS customer_count
            FROM (
                SELECT o.customer_email, SUM({$this->context->orderRevenueExpr()}) AS total_spent
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                  AND o.created_at >= ? AND o.created_at < ?
                GROUP BY o.customer_email
            ) clv_data
            GROUP BY bucket
            ORDER BY bucket
        "), [$from, $to]);

        $counts = array_column($rows, 'customer_count', 'bucket');
        $symbol = $this->context->getCurrencySymbol();
        $result = [];
        $lower  = 0;
        foreach (self::CLV_BOUNDS as $i => $bound) {
            $result[] = [
                'clv_range'      => $symbol . $lower . '-' . $symbol . ($bound - 1),
                'customer_count' => (int) ($counts[$i + 1] ?? 0),
            ];
            $lower = $bound;
        }
        $result[] = [
            'clv_range'      => $symbol . $lower . '+',
            'customer_count' => (int) ($counts[count(self::CLV_BOUNDS) + 1] ?? 0),
        ];

        return $result;
    }

    /**
     * Share of buyers with more than one order.
     */
    public function getRepeatPurchaseRate(): array
    {
        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*) AS total_customers,
                COALESCE(SUM(order_count > 1), 0) AS repeat_customers
            FROM (
                SELECT o.customer_email, COUNT(*) AS order_count
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                GROUP BY o.customer_email
            ) repeat_data
        "));

        $total  = (int) ($row['total_customers'] ?? 0);
        $repeat = (int) ($row['repeat_customers'] ?? 0);

        return [
            'total_customers'    => $total,
            'repeat_customers'   => $repeat,
            'one_time_customers' => $total - $repeat,
            'repeat_rate'        => $total > 0 ? round($repeat / $total * 100, 2) : 0.0,
        ];
    }

    private function localiseDates(array $rows, array $fields): array
    {
        foreach ($rows as &$row) {
            foreach ($fields as $field) {
                if (isset($row[$field])) {
                    $row[$field] = $this->context->toLocalDateTime($row[$field]);
                }
            }
        }

        return $rows;
    }
}
