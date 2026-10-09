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

    /**
     * Names of the CLV buckets above, lowest first.
     */
    private const CLV_TIER_NAMES = ['Entry', 'Starter', 'Core', 'Growth', 'VIP'];

    /**
     * RFM score bounds, score 5 first: days since the last order (at most), orders and net spend (at least).
     */
    private const RECENCY_BOUNDS   = [30, 60, 90, 180];
    private const FREQUENCY_BOUNDS = [10, 7, 4, 2];
    private const MONETARY_BOUNDS  = [1000, 500, 250, 100];

    /**
     * Buyers inactive for longer than this have a recency score of 2 or lower (At Risk / Lost).
     */
    public const LAPSED_AFTER_DAYS = 90;

    /**
     * RFM segments in display order.
     */
    public const SEGMENTS = ['Champions', 'Loyal', 'Promising', 'New Customer', 'At Risk', 'Lost'];

    public function __construct(
        private readonly ReportContext $context
    ) {
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
                {$this->scoreExpr('recency_days', self::RECENCY_BOUNDS, '<=')} AS r_score,
                {$this->scoreExpr('frequency', self::FREQUENCY_BOUNDS, '>=')} AS f_score,
                {$this->scoreExpr('monetary', self::MONETARY_BOUNDS, '>=')} AS m_score
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
     * RFM segment for a set of scores (each 1–5).
     */
    public function rfmLabel(int $r, int $f, int $m): string
    {
        $total = $r + $f + $m;
        if ($total >= 13) return 'Champions';
        if ($r >= 4 && $f >= 3) return 'Loyal';
        if ($r >= 4 && $f <= 2) return 'New Customer';
        if ($r <= 2 && $f >= 3) return 'At Risk';
        if ($r <= 2 && $f <= 2) return 'Lost';
        return 'Promising';
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
        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$this->clvBucketExpr('total_spent')} AS bucket,
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
     * Lifetime value tiers, highest first: buyers and net revenue per tier.
     *
     * @return array<int, array{tier: int, name: string, min: ?int, max: ?int, buyers: int, revenue: float}>
     */
    public function getClvTiers(): array
    {
        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$this->clvBucketExpr('total_spent')} AS bucket,
                COUNT(*) AS buyers,
                ROUND(SUM(total_spent), 2) AS revenue
            FROM (
                SELECT o.customer_email, SUM({$this->context->orderRevenueExpr()}) AS total_spent
                FROM {{sales_order}} o
                WHERE {$this->buyerCondition()}
                GROUP BY o.customer_email
            ) clv_data
            GROUP BY bucket
        "));

        $byBucket = array_column($rows, null, 'bucket');
        $count    = count(self::CLV_TIER_NAMES);
        $tiers    = [];
        for ($bucket = $count; $bucket >= 1; $bucket--) {
            $tiers[] = [
                'tier'    => $count - $bucket + 1,
                'name'    => self::CLV_TIER_NAMES[$bucket - 1],
                'min'     => self::CLV_BOUNDS[$bucket - 2] ?? null,
                'max'     => self::CLV_BOUNDS[$bucket - 1] ?? null,
                'buyers'  => (int) ($byBucket[$bucket]['buyers'] ?? 0),
                'revenue' => (float) ($byBucket[$bucket]['revenue'] ?? 0),
            ];
        }

        return $tiers;
    }

    /**
     * Every buyer's RFM segment, summarised: buyers and net revenue per segment, plus how many
     * buyers have lapsed (no order for more than LAPSED_AFTER_DAYS).
     *
     * @return array{segments: array<int, array{segment: string, buyers: int, revenue: float}>, buyers: int, lapsed_buyers: int}
     */
    public function getRfmSegments(): array
    {
        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$this->scoreExpr('b.recency_days', self::RECENCY_BOUNDS, '<=')} AS r_score,
                {$this->scoreExpr('b.orders', self::FREQUENCY_BOUNDS, '>=')} AS f_score,
                {$this->scoreExpr('b.lifetime_value', self::MONETARY_BOUNDS, '>=')} AS m_score,
                COUNT(*) AS buyers,
                SUM(b.lifetime_value) AS revenue,
                SUM(b.recency_days > " . self::LAPSED_AFTER_DAYS . ") AS lapsed
            FROM ({$this->buyerSummarySql()}) b
            GROUP BY r_score, f_score, m_score
        "));

        $segments = [];
        foreach (self::SEGMENTS as $name) {
            $segments[$name] = ['segment' => $name, 'buyers' => 0, 'revenue' => 0.0];
        }

        $buyers = 0;
        $lapsed = 0;
        foreach ($rows as $row) {
            $label = $this->rfmLabel((int) $row['r_score'], (int) $row['f_score'], (int) $row['m_score']);
            $segments[$label]['buyers']  += (int) $row['buyers'];
            $segments[$label]['revenue'] += (float) $row['revenue'];
            $buyers += (int) $row['buyers'];
            $lapsed += (int) $row['lapsed'];
        }

        foreach ($segments as &$segment) {
            $segment['revenue'] = round($segment['revenue'], 2);
        }
        unset($segment);

        return ['segments' => array_values($segments), 'buyers' => $buyers, 'lapsed_buyers' => $lapsed];
    }

    /**
     * Buyers by lifetime net spend with their RFM scores and segment, CLV tier and account type.
     *
     * @param int    $limit  0 = every buyer
     * @param string $filter '' (all), 'vip' (top CLV tier) or 'winback' (lapsed buyers)
     */
    public function getBuyers(int $limit = 0, string $filter = ''): array
    {
        $rows = $this->context->getConnection()->fetchAll($this->getBuyersSql($limit, $filter));

        foreach ($rows as &$row) {
            $row['customer_id']     = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
            $row['orders']          = (int) $row['orders'];
            $row['lifetime_value']  = (float) $row['lifetime_value'];
            $row['avg_order_value'] = (float) $row['avg_order_value'];
            $row['recency_days']    = (int) $row['recency_days'];
            $row['r_score']         = (int) $row['r_score'];
            $row['f_score']         = (int) $row['f_score'];
            $row['m_score']         = (int) $row['m_score'];
            $row['segment']         = $this->rfmLabel($row['r_score'], $row['f_score'], $row['m_score']);
            $row['tier']            = count(self::CLV_TIER_NAMES) + 1 - (int) $row['clv_bucket'];
            $row['first_order_at']  = $this->context->toLocalDateTime($row['first_order_at']);
            $row['last_order_at']   = $this->context->toLocalDateTime($row['last_order_at']);
            unset($row['clv_bucket']);
        }
        unset($row);

        return $rows;
    }

    /**
     * The (table-resolved) query behind getBuyers(), shown in the page's SQL inspector.
     */
    public function getBuyersSql(int $limit = 0, string $filter = ''): string
    {
        $where = match ($filter) {
            'vip'     => 'WHERE b.lifetime_value >= ' . self::CLV_BOUNDS[count(self::CLV_BOUNDS) - 1],
            'winback' => 'WHERE b.recency_days > ' . self::LAPSED_AFTER_DAYS,
            default   => '',
        };

        return $this->context->resolveTables("
            SELECT
                b.*,
                ROUND(b.lifetime_value / b.orders, 2) AS avg_order_value,
                {$this->scoreExpr('b.recency_days', self::RECENCY_BOUNDS, '<=')} AS r_score,
                {$this->scoreExpr('b.orders', self::FREQUENCY_BOUNDS, '>=')} AS f_score,
                {$this->scoreExpr('b.lifetime_value', self::MONETARY_BOUNDS, '>=')} AS m_score,
                {$this->clvBucketExpr('b.lifetime_value')} AS clv_bucket
            FROM ({$this->buyerSummarySql()}) b
            {$where}
            ORDER BY b.lifetime_value DESC, b.customer_email ASC"
            . ($limit > 0 ? ' LIMIT ' . $limit : ''));
    }

    /**
     * Each buyer's preferred category: the category with the most net item revenue across their
     * orders (root categories excluded, admin names; ties go to the deeper category).
     *
     * @param string[] $emails
     * @return array<string, string> email => category name
     */
    public function getPreferredCategories(array $emails): array
    {
        $emails = array_values(array_unique(array_filter($emails, 'strlen')));
        if (!$emails) {
            return [];
        }

        $conn   = $this->context->getConnection();
        $link   = $this->context->getCategoryLinkField();
        $nameId = $this->context->getAttributeId('catalog_category', 'name');

        $rows = $conn->fetchAll($this->context->resolveTables("
            SELECT
                o.customer_email,
                cv.value AS category_name,
                ce.level,
                SUM({$this->context->itemRevenueExpr()}) AS revenue
            FROM {{sales_order_item}} oi
            JOIN {{sales_order}} o ON o.entity_id = oi.order_id
            JOIN {{catalog_category_product}} ccp ON ccp.product_id = oi.product_id
            JOIN {{catalog_category_entity}} ce ON ce.entity_id = ccp.category_id AND ce.level >= 2{$this->context->currentVersionCondition('ce')}
            JOIN {{catalog_category_entity_varchar}} cv
                ON cv.{$link} = ce.{$link} AND cv.attribute_id = {$nameId} AND cv.store_id = 0
            WHERE {$this->context->validOrderCondition()}
              AND oi.parent_item_id IS NULL
              AND o.customer_email IN ({$conn->quote($emails)})
            GROUP BY o.customer_email, ce.entity_id, cv.value, ce.level
        "));

        $best = [];
        foreach ($rows as $row) {
            $email   = (string) $row['customer_email'];
            $revenue = (float) $row['revenue'];
            $current = $best[$email] ?? null;
            if ($current === null
                || $revenue > $current['revenue']
                || ($revenue === $current['revenue'] && (int) $row['level'] > $current['level'])
            ) {
                $best[$email] = ['name' => (string) $row['category_name'], 'revenue' => $revenue, 'level' => (int) $row['level']];
            }
        }

        return array_map(static fn (array $entry): string => $entry['name'], $best);
    }

    /**
     * Buyer figures for orders placed in [start, end): buyers (vs the previous period of the same
     * length), registered vs guest, spend, orders per buyer, first-time and repeat buyers (repeat =
     * two or more orders up to the end of the period), their average lifetime value, and new
     * account registrations.
     */
    public function getPeriodSummary(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $conn = $this->context->getConnection();
        $days = max(1, (int) $start->diff($end)->days);
        [$from, $to] = $this->context->utcRange($start, $end);
        [$prevFrom]  = $this->context->utcRange($start->modify("-{$days} days"), $start);
        $buyers = $this->periodBuyersSql();

        $row = $conn->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*) AS buyers,
                COALESCE(SUM(t.customer_id IS NOT NULL), 0) AS registered_buyers,
                COALESCE(SUM(t.period_orders), 0) AS orders,
                COALESCE(SUM(t.period_spend), 0) AS spend,
                COALESCE(SUM(t.period_orders > 1), 0) AS multi_order_buyers,
                COALESCE(SUM(t.lifetime_orders > 1), 0) AS repeat_buyers,
                COALESCE(SUM(t.first_order_at >= ?), 0) AS first_time_buyers,
                COALESCE(AVG(t.lifetime_value), 0) AS avg_ltv
            FROM ({$buyers}) t
        "), [$from, $from, $to, $to]) ?: [];

        $top = $conn->fetchRow($this->context->resolveTables("
            SELECT t.customer_name, t.customer_email, t.lifetime_value
            FROM ({$buyers}) t
            ORDER BY t.lifetime_value DESC
            LIMIT 1
        "), [$from, $to, $to]) ?: null;

        $previousBuyers = (int) $conn->fetchOne($this->context->resolveTables("
            SELECT COUNT(DISTINCT o.customer_email)
            FROM {{sales_order}} o
            WHERE {$this->buyerCondition()}
              AND o.created_at >= ? AND o.created_at < ?
        "), [$prevFrom, $from]);

        $registrations = (int) $conn->fetchOne($this->context->resolveTables(
            'SELECT COUNT(*) FROM {{customer_entity}} WHERE created_at >= ? AND created_at < ?'
        ), [$from, $to]);

        $registrationsOrdered = (int) $conn->fetchOne($this->context->resolveTables("
            SELECT COUNT(DISTINCT c.entity_id)
            FROM {{customer_entity}} c
            JOIN {{sales_order}} o ON o.customer_id = c.entity_id
                AND {$this->context->validOrderCondition()}
                AND o.created_at >= ? AND o.created_at < ?
            WHERE c.created_at >= ? AND c.created_at < ?
        "), [$from, $to, $from, $to]);

        $buyerCount = (int) ($row['buyers'] ?? 0);
        $orders     = (int) ($row['orders'] ?? 0);
        $firstTime  = (int) ($row['first_time_buyers'] ?? 0);
        $repeat     = (int) ($row['repeat_buyers'] ?? 0);
        $multi      = (int) ($row['multi_order_buyers'] ?? 0);
        $registered = (int) ($row['registered_buyers'] ?? 0);

        return [
            'buyers'                  => $buyerCount,
            'previous_buyers'         => $previousBuyers,
            'buyers_change'           => $previousBuyers > 0
                ? round(($buyerCount - $previousBuyers) / $previousBuyers * 100, 1)
                : null,
            'registered_buyers'       => $registered,
            'guest_buyers'            => $buyerCount - $registered,
            'orders'                  => $orders,
            'spend'                   => round((float) ($row['spend'] ?? 0), 2),
            'avg_orders_per_buyer'    => $buyerCount > 0 ? round($orders / $buyerCount, 2) : 0.0,
            'first_time_buyers'       => $firstTime,
            'returning_buyers'        => $buyerCount - $firstTime,
            'returning_rate'          => $this->rate($buyerCount - $firstTime, $buyerCount),
            'repeat_buyers'           => $repeat,
            'repeat_rate'             => $this->rate($repeat, $buyerCount),
            'multi_order_buyers'      => $multi,
            'multi_order_rate'        => $this->rate($multi, $buyerCount),
            'avg_ltv'                 => round((float) ($row['avg_ltv'] ?? 0), 2),
            'top_buyer'               => $top ? [
                'name'  => (string) $top['customer_name'],
                'email' => (string) $top['customer_email'],
                'value' => round((float) $top['lifetime_value'], 2),
            ] : null,
            'new_registrations'       => $registrations,
            'registrations_ordered'   => $registrationsOrdered,
            'registration_conversion' => $this->rate($registrationsOrdered, $registrations),
        ];
    }

    /**
     * New vs returning buyers across [start, end), by hour (one day), day (up to 62 days) or month.
     * "New" = the buyer's first ever order falls in that bucket; "returning" = it was earlier.
     *
     * @return array{unit: string, series: array<int, array{key: string, label: string, new_customers: int, returning_customers: int}>}
     */
    public function getAcquisitionTrend(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $days = (int) $start->diff($end)->days;
        $unit = $days <= 1 ? 'hour' : ($days <= 62 ? 'day' : 'month');
        [$sqlFormat, $keyFormat, $step] = [
            'hour'  => ['%Y-%m-%d %H:00', 'Y-m-d H:00', '+1 hour'],
            'day'   => ['%Y-%m-%d', 'Y-m-d', '+1 day'],
            'month' => ['%Y-%m', 'Y-m', 'first day of next month'],
        ][$unit];
        [$from, $to] = $this->context->utcRange($start, $end);
        $orderBucket = "DATE_FORMAT({$this->context->localTimeExpr('o.created_at', $start, $end)}, '{$sqlFormat}')";
        $firstBucket = "DATE_FORMAT({$this->context->localTimeExpr('f.first_at', $start, $end)}, '{$sqlFormat}')";

        $rows = $this->context->getConnection()->fetchAll($this->context->resolveTables("
            SELECT
                {$orderBucket} AS bucket,
                COUNT(DISTINCT CASE WHEN f.first_at >= ? AND {$firstBucket} = {$orderBucket} THEN o.customer_email END) AS new_customers,
                COUNT(DISTINCT CASE WHEN f.first_at < ? OR {$firstBucket} < {$orderBucket} THEN o.customer_email END) AS returning_customers
            FROM {{sales_order}} o
            JOIN (
                SELECT so.customer_email, MIN(so.created_at) AS first_at
                FROM {{sales_order}} so
                WHERE {$this->buyerCondition('so')}
                GROUP BY so.customer_email
            ) f ON f.customer_email = o.customer_email
            WHERE {$this->buyerCondition()}
              AND o.created_at >= ? AND o.created_at < ?
            GROUP BY bucket
            ORDER BY bucket ASC
        "), [$from, $from, $from, $to]);

        $byBucket  = array_column($rows, null, 'bucket');
        $last      = $end->modify('-1 second');
        $labelYear = $start->format('Y') !== $last->format('Y');
        $series    = [];
        $cursor    = $unit === 'month' ? $start->modify('first day of this month') : $start;
        for (; $cursor < $end; $cursor = $cursor->modify($step)) {
            $key = $cursor->format($keyFormat);
            $series[] = [
                'key'                 => $key,
                'label'               => match ($unit) {
                    'hour'  => $cursor->format('H:00'),
                    'day'   => $cursor->format($labelYear ? 'M j, Y' : 'M j'),
                    'month' => $cursor->format($labelYear ? 'M Y' : 'M'),
                },
                'new_customers'       => (int) ($byBucket[$key]['new_customers'] ?? 0),
                'returning_customers' => (int) ($byBucket[$key]['returning_customers'] ?? 0),
            ];
        }

        return ['unit' => $unit, 'series' => $series];
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

    /**
     * Store-wide customer totals used by the overview strip:
     *  - total_spend        : net lifetime revenue across all buyers
     *  - buyer_count        : distinct buyers (by order email)
     *  - avg_ltv            : total_spend / buyer_count
     *  - avg_orders_per_buyer
     *  - top_spender_name / top_spender_value
     */
    public function getOverallTotals(): array
    {
        $rev = $this->context->orderRevenueExpr();

        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                COUNT(*)                       AS buyer_count,
                COALESCE(SUM(total_spent), 0)  AS total_spend,
                COALESCE(SUM(order_count), 0)  AS total_orders
            FROM (
                SELECT o.customer_email,
                       SUM({$rev})  AS total_spent,
                       COUNT(*)     AS order_count
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                GROUP BY o.customer_email
            ) t
        ")) ?: ['buyer_count' => 0, 'total_spend' => 0, 'total_orders' => 0];

        $buyerCount  = (int) ($row['buyer_count'] ?? 0);
        $totalSpend  = (float) ($row['total_spend'] ?? 0);
        $totalOrders = (int) ($row['total_orders'] ?? 0);

        // Highest individual spender
        $top = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                MAX(TRIM(CONCAT(IFNULL(o.customer_firstname, ''), ' ', IFNULL(o.customer_lastname, '')))) AS customer_name,
                SUM({$rev}) AS total_spent
            FROM {{sales_order}} o
            WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
              AND {$this->context->validOrderCondition()}
            GROUP BY o.customer_email
            ORDER BY total_spent DESC
            LIMIT 1
        ")) ?: ['customer_name' => '', 'total_spent' => 0];

        return [
            'buyer_count'         => $buyerCount,
            'total_spend'         => round($totalSpend, 2),
            'total_orders'        => $totalOrders,
            'avg_ltv'             => $buyerCount > 0 ? round($totalSpend / $buyerCount, 2) : 0.0,
            'avg_orders_per_buyer'=> $buyerCount > 0 ? round($totalOrders / $buyerCount, 2) : 0.0,
            'top_spender_name'    => (string) ($top['customer_name'] ?? ''),
            'top_spender_value'   => round((float) ($top['total_spent'] ?? 0), 2),
        ];
    }

    /**
     * Revenue concentration of the top N buyers:
     *  - top_revenue  : net revenue from the top N buyers
     *  - total_revenue: net revenue from all buyers
     *  - share        : top_revenue / total_revenue as a percentage
     *  - top_n        : the N actually used
     */
    public function getVipConcentration(int $topN = 3): array
    {
        $conn  = $this->context->getConnection();
        $spent = "
            SELECT SUM({$this->context->orderRevenueExpr()}) AS total_spent
            FROM {{sales_order}} o
            WHERE {$this->buyerCondition()}
            GROUP BY o.customer_email";

        $top = $conn->fetchRow($this->context->resolveTables("
            SELECT COUNT(*) AS buyers, COALESCE(SUM(total_spent), 0) AS revenue
            FROM ({$spent} ORDER BY total_spent DESC LIMIT " . max(1, $topN) . ") t
        ")) ?: [];
        $totalRevenue = (float) $conn->fetchOne($this->context->resolveTables(
            "SELECT COALESCE(SUM(total_spent), 0) FROM ({$spent}) t"
        ));
        $topRevenue = (float) ($top['revenue'] ?? 0);

        return [
            'top_n'         => (int) ($top['buyers'] ?? 0),
            'top_revenue'   => round($topRevenue, 2),
            'total_revenue' => round($totalRevenue, 2),
            'share'         => $totalRevenue > 0 ? round($topRevenue / $totalRevenue * 100, 1) : 0.0,
        ];
    }

    /**
     * Average number of days between consecutive orders, across buyers with 2+ orders.
     * Returns 0.0 when there is not enough history.
     */
    public function getReorderCycleDays(): float
    {
        $avg = $this->context->getConnection()->fetchOne($this->context->resolveTables("
            SELECT AVG(cycle_days)
            FROM (
                SELECT o.customer_email,
                       DATEDIFF(MAX(o.created_at), MIN(o.created_at)) / NULLIF(COUNT(*) - 1, 0) AS cycle_days
                FROM {{sales_order}} o
                WHERE o.customer_email IS NOT NULL AND o.customer_email != ''
                  AND {$this->context->validOrderCondition()}
                GROUP BY o.customer_email
                HAVING COUNT(*) > 1
            ) t
        "));

        return $avg !== null ? round((float) $avg, 1) : 0.0;
    }

    /**
     * The most valuable lapsed buyer (no order for more than LAPSED_AFTER_DAYS), with their own
     * re-order cycle when they have 2+ orders. Null when no buyer has lapsed.
     */
    public function getWinBackCandidate(): ?array
    {
        $row = $this->context->getConnection()->fetchRow($this->context->resolveTables("
            SELECT
                b.*,
                DATEDIFF(b.last_order_at, b.first_order_at) / NULLIF(b.orders - 1, 0) AS cycle_days
            FROM ({$this->buyerSummarySql()}) b
            WHERE b.recency_days > " . self::LAPSED_AFTER_DAYS . "
            ORDER BY b.lifetime_value DESC, b.recency_days DESC
            LIMIT 1
        "));

        if (!$row) {
            return null;
        }

        return [
            'customer_name'  => (string) $row['customer_name'],
            'customer_email' => (string) $row['customer_email'],
            'customer_id'    => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'orders'         => (int) $row['orders'],
            'lifetime_value' => round((float) $row['lifetime_value'], 2),
            'inactive_days'  => (int) $row['recency_days'],
            'last_order_at'  => $this->context->toLocalDateTime($row['last_order_at']),
            'cycle_days'     => $row['cycle_days'] !== null ? round((float) $row['cycle_days'], 1) : null,
        ];
    }

    /**
     * One row per buyer (by order email), all time: account, name, orders, net lifetime value,
     * first and last order (UTC) and days since the last order.
     */
    private function buyerSummarySql(): string
    {
        return "
            SELECT
                o.customer_email,
                MAX(o.customer_id) AS customer_id,
                MAX(TRIM(CONCAT(IFNULL(o.customer_firstname, ''), ' ', IFNULL(o.customer_lastname, '')))) AS customer_name,
                COUNT(*) AS orders,
                SUM({$this->context->orderRevenueExpr()}) AS lifetime_value,
                MIN(o.created_at) AS first_order_at,
                MAX(o.created_at) AS last_order_at,
                DATEDIFF(UTC_TIMESTAMP(), MAX(o.created_at)) AS recency_days
            FROM {{sales_order}} o
            WHERE {$this->buyerCondition()}
            GROUP BY o.customer_email";
    }

    /**
     * Buyers who ordered in a period (binds: from, to) joined to their history up to its end
     * (bind: to): period orders and spend, lifetime orders and value, first order.
     */
    private function periodBuyersSql(): string
    {
        $rev = $this->context->orderRevenueExpr();

        return "
            SELECT
                p.customer_email, p.customer_id, p.period_orders, p.period_spend,
                l.customer_name, l.lifetime_orders, l.lifetime_value, l.first_order_at
            FROM (
                SELECT o.customer_email, MAX(o.customer_id) AS customer_id,
                       COUNT(*) AS period_orders, SUM({$rev}) AS period_spend
                FROM {{sales_order}} o
                WHERE {$this->buyerCondition()}
                  AND o.created_at >= ? AND o.created_at < ?
                GROUP BY o.customer_email
            ) p
            JOIN (
                SELECT o.customer_email,
                       MAX(TRIM(CONCAT(IFNULL(o.customer_firstname, ''), ' ', IFNULL(o.customer_lastname, '')))) AS customer_name,
                       COUNT(*) AS lifetime_orders, SUM({$rev}) AS lifetime_value, MIN(o.created_at) AS first_order_at
                FROM {{sales_order}} o
                WHERE {$this->buyerCondition()}
                  AND o.created_at < ?
                GROUP BY o.customer_email
            ) l ON l.customer_email = p.customer_email";
    }

    /**
     * Orders that count as sales and have a buyer email.
     */
    private function buyerCondition(string $alias = 'o'): string
    {
        return "{$alias}.customer_email IS NOT NULL AND {$alias}.customer_email != ''"
            . " AND {$this->context->validOrderCondition($alias)}";
    }

    /**
     * CASE expression scoring a column 5..1 against bounds listed from score 5 down.
     */
    private function scoreExpr(string $column, array $bounds, string $operator): string
    {
        $sql = 'CASE';
        foreach ($bounds as $i => $bound) {
            $sql .= sprintf(' WHEN %s %s %d THEN %d', $column, $operator, $bound, 5 - $i);
        }

        return $sql . ' ELSE 1 END';
    }

    /**
     * CASE expression for the CLV bucket (1 = lowest .. 5 = highest) of a spend column.
     */
    private function clvBucketExpr(string $column): string
    {
        $sql = 'CASE';
        foreach (self::CLV_BOUNDS as $i => $bound) {
            $sql .= sprintf(' WHEN %s < %d THEN %d', $column, $bound, $i + 1);
        }

        return $sql . ' ELSE ' . (count(self::CLV_BOUNDS) + 1) . ' END';
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
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
