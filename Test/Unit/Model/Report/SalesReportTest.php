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
}
