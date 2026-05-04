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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\SalesReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SalesReportTest extends TestCase
{
    private SalesReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection   = $this->createMock(AdapterInterface::class);

        $resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->report = new SalesReport($resourceConnection);
    }

    // ── getKpiCards ──────────────────────────────────────────────────────

    public function testGetKpiCardsReturnsAllTimePeriods(): void
    {
        $kpiRow = ['orders' => '10', 'revenue' => '1500.00', 'aov' => '150.00'];

        $this->connection->method('fetchRow')
            ->willReturn($kpiRow);

        $result = $this->report->getKpiCards();

        $this->assertArrayHasKey('today', $result);
        $this->assertArrayHasKey('week', $result);
        $this->assertArrayHasKey('month', $result);
        $this->assertArrayHasKey('year', $result);

        foreach (['today', 'week', 'month', 'year'] as $period) {
            $this->assertArrayHasKey('orders', $result[$period]);
            $this->assertArrayHasKey('revenue', $result[$period]);
            $this->assertArrayHasKey('aov', $result[$period]);
        }
    }

    public function testGetKpiCardsHandlesZeroOrders(): void
    {
        $emptyRow = ['orders' => '0', 'revenue' => '0', 'aov' => '0'];

        $this->connection->method('fetchRow')
            ->willReturn($emptyRow);

        $result = $this->report->getKpiCards();

        $this->assertSame('0', $result['today']['orders']);
        $this->assertSame('0', $result['today']['revenue']);
    }

    // ── getRevenueByMonth ────────────────────────────────────────────────

    public function testGetRevenueByMonthReturnsExpectedStructure(): void
    {
        $rows = [
            ['month' => '2026-01', 'label' => 'Jan 2026', 'orders' => '15', 'revenue' => '2250.00', 'aov' => '150.00'],
            ['month' => '2026-02', 'label' => 'Feb 2026', 'orders' => '20', 'revenue' => '3500.00', 'aov' => '175.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByMonth();

        $this->assertCount(2, $result);
        $this->assertSame('2026-01', $result[0]['month']);
        $this->assertSame('Jan 2026', $result[0]['label']);
    }

    public function testGetRevenueByMonthReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')
            ->willReturn([]);

        $result = $this->report->getRevenueByMonth();
        $this->assertSame([], $result);
    }

    // ── getRevenueByDay ──────────────────────────────────────────────────

    public function testGetRevenueByDayReturnsExpectedStructure(): void
    {
        $rows = [
            ['day' => '2026-05-01', 'label' => '01 May', 'orders' => '5', 'revenue' => '750.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByDay();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('day', $result[0]);
        $this->assertArrayHasKey('label', $result[0]);
    }

    // ── getOrdersByStatus ────────────────────────────────────────────────

    public function testGetOrdersByStatusReturnsAllStatuses(): void
    {
        $rows = [
            ['status' => 'complete',   'orders' => '30', 'revenue' => '4500.00'],
            ['status' => 'processing', 'orders' => '10', 'revenue' => '1500.00'],
            ['status' => 'pending',    'orders' => '5',  'revenue' => '750.00'],
            ['status' => 'canceled',   'orders' => '3',  'revenue' => '450.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getOrdersByStatus();

        $this->assertCount(4, $result);
        $statuses = array_column($result, 'status');
        $this->assertContains('complete', $statuses);
        $this->assertContains('canceled', $statuses);
    }

    // ── getRevenueByShipping ─────────────────────────────────────────────

    public function testGetRevenueByShippingReturnsExpectedStructure(): void
    {
        $rows = [
            ['shipping_method' => 'Flat Rate - Fixed', 'orders' => '25', 'revenue' => '3750.00', 'aov' => '150.00'],
            ['shipping_method' => 'Free Shipping - Free', 'orders' => '15', 'revenue' => '2250.00', 'aov' => '150.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByShipping();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('shipping_method', $result[0]);
    }

    // ── getCouponUsage ───────────────────────────────────────────────────

    public function testGetCouponUsageReturnsExpectedStructure(): void
    {
        $rows = [
            ['coupon_code' => 'SAVE10', 'times_used' => '8', 'revenue' => '1200.00', 'total_discount' => '120.00', 'avg_order_value' => '150.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getCouponUsage();

        $this->assertCount(1, $result);
        $this->assertSame('SAVE10', $result[0]['coupon_code']);
        $this->assertArrayHasKey('total_discount', $result[0]);
    }

    // ── getRevenueByRegion ───────────────────────────────────────────────

    public function testGetRevenueByRegionReturnsExpectedStructure(): void
    {
        $rows = [
            ['state' => 'California', 'country' => 'US', 'orders' => '12', 'revenue' => '1800.00'],
            ['state' => 'Texas',      'country' => 'US', 'orders' => '8',  'revenue' => '1200.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByRegion();

        $this->assertCount(2, $result);
        $this->assertSame('California', $result[0]['state']);
    }

    // ── getRefundSummary ─────────────────────────────────────────────────

    public function testGetRefundSummaryReturnsAllKeys(): void
    {
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('500.00', '3', '2', '300.00');

        $result = $this->report->getRefundSummary();

        $this->assertArrayHasKey('total_refunded', $result);
        $this->assertArrayHasKey('refund_count', $result);
        $this->assertArrayHasKey('cancel_count', $result);
        $this->assertArrayHasKey('cancel_revenue', $result);
    }

    // ── getRevenueByDayOfWeek ────────────────────────────────────────────

    public function testGetRevenueByDayOfWeekReturnsExpectedStructure(): void
    {
        $rows = [
            ['day_name' => 'Monday',  'day_num' => '2', 'orders' => '10', 'revenue' => '1500.00', 'aov' => '150.00'],
            ['day_name' => 'Tuesday', 'day_num' => '3', 'orders' => '8',  'revenue' => '1200.00', 'aov' => '150.00'],
        ];

        $this->connection->method('fetchAll')
            ->willReturn($rows);

        $result = $this->report->getRevenueByDayOfWeek();

        $this->assertCount(2, $result);
        $this->assertSame('Monday', $result[0]['day_name']);
    }
}
