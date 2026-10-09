<?php
/**
 * Meetanshi AIReporting — SalesReport Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\SalesReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SalesReportTest extends TestCase
{
    use ReportContextTrait;

    private SalesReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->report     = new SalesReport($this->createReportContext($this->connection));
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllTimePeriods(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['orders' => '10', 'revenue' => '1500.00', 'aov' => '150.00']);

        $result = $this->report->getKpiCards();

        foreach (['today', 'week', 'month', 'year'] as $period) {
            $this->assertArrayHasKey($period, $result);
            $this->assertArrayHasKey('orders', $result[$period]);
            $this->assertArrayHasKey('revenue', $result[$period]);
            $this->assertArrayHasKey('aov', $result[$period]);
        }
    }

    public function testGetKpiCardsUsesNetBaseRevenueAndExcludesNonSales(): void
    {
        $queries = [];
        $this->connection->method('fetchRow')->willReturnCallback(
            function (string $sql, array $bind) use (&$queries) {
                $queries[] = [$sql, $bind];
                return ['orders' => '0', 'revenue' => '0', 'aov' => '0'];
            }
        );

        $this->report->getKpiCards();

        $this->assertCount(4, $queries);
        [$sql, $bind] = $queries[0];
        $this->assertStringContainsString('base_grand_total - IFNULL(o.base_total_refunded, 0)', $sql);
        $this->assertStringContainsString("o.state NOT IN ('canceled','pending_payment')", $sql);
        $this->assertStringContainsString('o.created_at >= ? AND o.created_at < ?', $sql);
        $this->assertSame(
            [(new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d 00:00:00'),
             (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d 00:00:00')],
            $bind
        );
    }

    public function testGetKpiCardsHandlesZeroOrders(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['orders' => '0', 'revenue' => '0', 'aov' => '0']);

        $result = $this->report->getKpiCards();

        $this->assertSame('0', $result['today']['orders']);
        $this->assertSame('0', $result['today']['revenue']);
    }

    // ── getRevenueByMonth ────────────────────────────────────────────────

    public function testGetRevenueByMonthReturnsTwelveZeroFilledMonths(): void
    {
        $currentMonth = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC')));
        $this->connection->method('fetchAll')->willReturn([
            ['month' => $currentMonth->format('Y-m'), 'orders' => '15', 'revenue' => '2250.00', 'aov' => '150.00'],
        ]);

        $result = $this->report->getRevenueByMonth();

        $this->assertCount(12, $result);
        $this->assertSame($currentMonth->modify('-11 months')->format('Y-m'), $result[0]['month']);
        $this->assertSame(0, $result[0]['orders']);
        $this->assertSame($currentMonth->format('Y-m'), $result[11]['month']);
        $this->assertSame($currentMonth->format('M Y'), $result[11]['label']);
        $this->assertSame('2250.00', $result[11]['revenue']);
    }

    // ── getRevenueByDay ──────────────────────────────────────────────────

    public function testGetRevenueByDayReturnsThirtyDays(): void
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->connection->method('fetchAll')->willReturn([
            ['day' => $today, 'orders' => '5', 'revenue' => '750.00'],
        ]);

        $result = $this->report->getRevenueByDay();

        $this->assertCount(30, $result);
        $this->assertSame($today, $result[29]['day']);
        $this->assertSame('5', $result[29]['orders']);
        $this->assertArrayHasKey('label', $result[0]);
        $this->assertSame(0, $result[0]['orders']);
    }

    // ── getOrdersByStatus ────────────────────────────────────────────────

    public function testGetOrdersByStatusReturnsAllStatuses(): void
    {
        $rows = [
            ['status' => 'Complete',   'orders' => '30', 'revenue' => '4500.00'],
            ['status' => 'Processing', 'orders' => '10', 'revenue' => '1500.00'],
            ['status' => 'Canceled',   'orders' => '3',  'revenue' => '450.00'],
        ];
        $this->connection->method('fetchAll')->willReturn($rows);

        $this->assertSame($rows, $this->report->getOrdersByStatus());
    }

    // ── getRevenueByShipping ─────────────────────────────────────────────

    public function testGetRevenueByShippingGroupsByDescriptionNotMethodColumn(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [['shipping_method' => 'Flat Rate - Fixed', 'orders' => '25', 'revenue' => '3750.00', 'aov' => '150.00']];
        });

        $result = $this->report->getRevenueByShipping();

        $this->assertSame('Flat Rate - Fixed', $result[0]['shipping_method']);
        $this->assertStringContainsString("GROUP BY COALESCE(NULLIF(o.shipping_description, '')", $captured);
    }

    // ── getCouponUsage ───────────────────────────────────────────────────

    public function testGetCouponUsageReturnsExpectedStructure(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['coupon_code' => 'SAVE10', 'times_used' => '8', 'revenue' => '1200.00', 'total_discount' => '120.00', 'avg_order_value' => '150.00'],
        ]);

        $result = $this->report->getCouponUsage();

        $this->assertCount(1, $result);
        $this->assertSame('SAVE10', $result[0]['coupon_code']);
        $this->assertArrayHasKey('total_discount', $result[0]);
    }

    // ── getRevenueByRegion ───────────────────────────────────────────────

    public function testGetRevenueByRegionFallsBackToBillingAddress(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [['state' => 'California', 'country' => 'US', 'orders' => '12', 'revenue' => '1800.00']];
        });

        $result = $this->report->getRevenueByRegion();

        $this->assertSame('California', $result[0]['state']);
        $this->assertStringContainsString("ba.address_type = 'billing'", $captured);
        $this->assertStringNotContainsString('GROUP BY state', $captured);
    }

    // ── getRefundSummary ─────────────────────────────────────────────────

    public function testGetRefundSummaryReturnsAllKeys(): void
    {
        $this->connection->method('fetchRow')->willReturn(
            ['total_refunded' => '500.00', 'refund_count' => '3', 'cancel_count' => '2', 'cancel_revenue' => '300.00']
        );

        $result = $this->report->getRefundSummary();

        $this->assertSame('500.00', $result['total_refunded']);
        $this->assertSame('3', $result['refund_count']);
        $this->assertSame('2', $result['cancel_count']);
        $this->assertSame('300.00', $result['cancel_revenue']);
    }

    // ── getRevenueByDayOfWeek ────────────────────────────────────────────

    public function testGetRevenueByDayOfWeekReturnsAllSevenDays(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['day_num' => '2', 'orders' => '10', 'revenue' => '1500.00', 'aov' => '150.00'],
        ]);

        $result = $this->report->getRevenueByDayOfWeek();

        $this->assertCount(7, $result);
        $this->assertSame('Sunday', $result[0]['day_name']);
        $this->assertSame('Monday', $result[1]['day_name']);
        $this->assertSame('10', $result[1]['orders']);
        $this->assertSame(0, $result[0]['orders']);
    }

    // ── Range-based figures (Sales & Revenue page) ───────────────────────

    public function testGetRangeTotalsCountsOnlySalesButReportsExcludedOrders(): void
    {
        $captured = [];
        $this->connection->method('fetchRow')->willReturnCallback(
            function (string $sql, array $bind) use (&$captured) {
                $captured = [$sql, $bind];
                return [
                    'all_orders' => '5', 'orders' => '3', 'revenue' => '250.555', 'gross' => '300', 'refunded' => '49.445',
                    'refund_orders' => '1', 'discount' => '10', 'discounted_orders' => '1', 'discounted_revenue' => '90',
                    'tax' => '20', 'shipping' => '15', 'pending_payment' => '1', 'canceled' => '1',
                ];
            }
        );
        $start = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));

        $totals = $this->report->getRangeTotals($start, $start->modify('+1 month'));

        [$sql, $bind] = $captured;
        $this->assertStringContainsString("SUM(IF(o.state NOT IN ('canceled','pending_payment'), ((o.base_grand_total - IFNULL(o.base_total_refunded, 0))", $sql);
        $this->assertStringContainsString("SUM(o.state = 'pending_payment')", $sql);
        $this->assertStringNotContainsString('{{', $sql);
        $this->assertSame(['2026-01-01 00:00:00', '2026-02-01 00:00:00'], $bind);
        $this->assertSame(250.56, $totals['revenue']);
        $this->assertSame(3, $totals['orders']);
        $this->assertSame(5, $totals['all_orders']);
        $this->assertSame(1, $totals['canceled']);
    }

    public function testGetRevenueSeriesFillsEveryDayOfTheRange(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['bucket' => '2026-03-02', 'orders' => '2', 'revenue' => '80.50'],
        ]);
        $start = new \DateTimeImmutable('2026-03-01', new \DateTimeZone('UTC'));

        $series = $this->report->getRevenueSeries($start, $start->modify('+3 days'), 'day');

        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($series, 'key'));
        $this->assertSame([0, 2, 0], array_column($series, 'orders'));
        $this->assertSame(80.5, $series[1]['revenue']);
    }

    public function testGetRevenueSeriesByMonthStartsAtTheFirstMonth(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $start = new \DateTimeImmutable('2026-01-15', new \DateTimeZone('UTC'));

        $series = $this->report->getRevenueSeries($start, new \DateTimeImmutable('2026-03-10', new \DateTimeZone('UTC')), 'month');

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($series, 'key'));
        $this->assertSame('Jan 2026', $series[0]['label']);
    }

    public function testGetStatusBreakdownMarksExcludedStates(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['status' => 'complete', 'state' => 'complete', 'label' => 'Complete', 'orders' => '4', 'value' => '400'],
            ['status' => 'canceled', 'state' => 'canceled', 'label' => 'Canceled', 'orders' => '1', 'value' => '50'],
        ]);
        $start = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));

        $rows = $this->report->getStatusBreakdown($start, $start->modify('+1 day'));

        $this->assertTrue($rows[0]['counted']);
        $this->assertFalse($rows[1]['counted']);
        $this->assertSame(4, $rows[0]['orders']);
    }

    public function testGetPromotionBreakdownCreditsDiscountOnlyToDiscountRules(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [[
                'rule_id' => '2', 'name' => 'Free shipping over $50', 'coupon_type' => '1', 'simple_action' => 'by_percent',
                'discount_amount' => '0', 'simple_free_shipping' => '1', 'is_active' => '1', 'from_date' => null,
                'to_date' => null, 'code' => '', 'orders' => '3', 'discount' => '0', 'revenue' => '300',
            ]];
        });
        $start = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));

        $rows = $this->report->getPromotionBreakdown($start, $start->modify('+1 month'));

        $this->assertStringContainsString('FIND_IN_SET(r.rule_id, o.applied_rule_ids)', $captured);
        $this->assertStringContainsString('IF(r.discount_amount > 0,', $captured);
        $this->assertStringContainsString("IF(r.coupon_type = 1, '', COALESCE(o.coupon_code, ''))", $captured);
        $this->assertTrue($rows[0]['free_shipping']);
        $this->assertSame(3, $rows[0]['orders']);
        $this->assertSame('', $rows[0]['code']);
    }

    public function testGetPeakHourIsNullWithoutOrders(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $start = new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC'));

        $this->assertNull($this->report->getPeakHour($start, $start->modify('+1 day')));
    }
}
