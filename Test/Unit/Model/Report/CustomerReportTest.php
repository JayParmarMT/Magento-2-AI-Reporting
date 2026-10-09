<?php
/**
 * Meetanshi AIReporting — CustomerReport Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Meetanshi\AIReporting\Model\Report\CustomerReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerReportTest extends TestCase
{
    use ReportContextTrait;

    private CustomerReport $report;
    private AdapterInterface|MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->report     = new CustomerReport($this->createReportContext($this->connection, 'America/Chicago'));
    }

    // ── getPeriodSummary ─────────────────────────────────────────────────

    public function testGetPeriodSummaryReturnsBuyerMetrics(): void
    {
        $this->connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            [
                'buyers' => '8', 'registered_buyers' => '7', 'orders' => '69', 'spend' => '7447.2',
                'multi_order_buyers' => '6', 'repeat_buyers' => '7', 'first_time_buyers' => '2', 'avg_ltv' => '930.9',
            ],
            ['customer_name' => 'Riley Wilkerson', 'customer_email' => 'riley@example.com', 'lifetime_value' => '3576']
        );
        // previous-period buyers, registrations, registrations that ordered
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls('4', '5', '2');

        [$start, $end] = $this->yearToDate();
        $result = $this->report->getPeriodSummary($start, $end);

        $this->assertSame(8, $result['buyers']);
        $this->assertSame(100.0, $result['buyers_change']);
        $this->assertSame(1, $result['guest_buyers']);
        $this->assertSame(8.63, $result['avg_orders_per_buyer']);
        $this->assertSame(87.5, $result['repeat_rate']);
        $this->assertSame(75.0, $result['multi_order_rate']);
        $this->assertSame(6, $result['returning_buyers']);
        $this->assertSame(75.0, $result['returning_rate']);
        $this->assertSame(930.9, $result['avg_ltv']);
        $this->assertSame(['name' => 'Riley Wilkerson', 'email' => 'riley@example.com', 'value' => 3576.0], $result['top_buyer']);
        $this->assertSame(40.0, $result['registration_conversion']);
    }

    public function testGetPeriodSummaryHasNoChangeWithoutPreviousBuyers(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->method('fetchOne')->willReturn('0');

        [$start, $end] = $this->yearToDate();
        $result = $this->report->getPeriodSummary($start, $end);

        $this->assertSame(0, $result['buyers']);
        $this->assertNull($result['buyers_change']);
        $this->assertNull($result['top_buyer']);
        $this->assertSame(0.0, $result['repeat_rate']);
    }

    public function testGetPeriodSummaryUsesStoreTimezoneDayBoundaries(): void
    {
        $binds = [];
        $this->connection->method('fetchRow')->willReturnCallback(
            function (string $sql, array $bind = []) use (&$binds) {
                $binds[] = $bind;
                return false;
            }
        );
        $this->connection->method('fetchOne')->willReturn('0');

        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Chicago'));
        $this->report->getPeriodSummary($today, $today->modify('+1 day'));

        $expectedFrom = $today->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // Local midnight converted to UTC (05:00 or 06:00 depending on DST): [first-time, from, to, to]
        $this->assertSame($expectedFrom, $binds[0][0]);
        $this->assertSame($expectedFrom, $binds[0][1]);
        $this->assertStringNotContainsString(' 00:00:00', $binds[0][1]);
    }

    // ── getAcquisitionTrend ──────────────────────────────────────────────

    public function testGetAcquisitionTrendUsesHoursForOneDay(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Chicago'));

        $result = $this->report->getAcquisitionTrend($today, $today->modify('+1 day'));

        $this->assertSame('hour', $result['unit']);
        $this->assertSame('00:00', $result['series'][0]['label']);
    }

    public function testGetAcquisitionTrendUsesMonthsForAYearAndMergesRows(): void
    {
        $tz = new \DateTimeZone('America/Chicago');
        $start = new \DateTimeImmutable('2026-01-01', $tz);
        $this->connection->method('fetchAll')->willReturn([
            ['bucket' => '2026-03', 'new_customers' => '2', 'returning_customers' => '1'],
        ]);

        $result = $this->report->getAcquisitionTrend($start, new \DateTimeImmutable('2026-10-09', $tz));

        $this->assertSame('month', $result['unit']);
        $this->assertCount(10, $result['series']);
        $this->assertSame(['key' => '2026-03', 'label' => 'Mar', 'new_customers' => 2, 'returning_customers' => 1], $result['series'][2]);
        $this->assertSame(0, $result['series'][0]['new_customers']);
    }

    public function testGetAcquisitionTrendUsesDaysForAMonth(): void
    {
        $tz = new \DateTimeZone('America/Chicago');
        $this->connection->method('fetchAll')->willReturn([]);

        $result = $this->report->getAcquisitionTrend(
            new \DateTimeImmutable('2026-09-01', $tz),
            new \DateTimeImmutable('2026-10-01', $tz)
        );

        $this->assertSame('day', $result['unit']);
        $this->assertCount(30, $result['series']);
        $this->assertSame('Sep 1', $result['series'][0]['label']);
    }

    // ── RFM segments, CLV tiers, buyers ──────────────────────────────────

    public function testRfmLabel(): void
    {
        $this->assertSame('Champions', $this->report->rfmLabel(5, 5, 5));
        $this->assertSame('Loyal', $this->report->rfmLabel(5, 3, 2));
        $this->assertSame('New Customer', $this->report->rfmLabel(5, 1, 2));
        $this->assertSame('At Risk', $this->report->rfmLabel(1, 4, 5));
        $this->assertSame('Lost', $this->report->rfmLabel(1, 2, 3));
        $this->assertSame('Promising', $this->report->rfmLabel(3, 2, 2));
    }

    public function testGetRfmSegmentsSummarisesScoreGroups(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['r_score' => '5', 'f_score' => '5', 'm_score' => '5', 'buyers' => '4', 'revenue' => '6423.2', 'lapsed' => '0'],
            ['r_score' => '1', 'f_score' => '2', 'm_score' => '3', 'buyers' => '1', 'revenue' => '252', 'lapsed' => '1'],
            ['r_score' => '2', 'f_score' => '1', 'm_score' => '1', 'buyers' => '2', 'revenue' => '80', 'lapsed' => '2'],
        ]);

        $result = $this->report->getRfmSegments();

        $segments = array_column($result['segments'], null, 'segment');
        $this->assertSame(['Champions', 'Loyal', 'Promising', 'New Customer', 'At Risk', 'Lost'], array_keys($segments));
        $this->assertSame(4, $segments['Champions']['buyers']);
        $this->assertSame(3, $segments['Lost']['buyers']);
        $this->assertSame(332.0, $segments['Lost']['revenue']);
        $this->assertSame(7, $result['buyers']);
        $this->assertSame(3, $result['lapsed_buyers']);
    }

    public function testGetClvTiersListsHighestTierFirst(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['bucket' => '5', 'buyers' => '4', 'revenue' => '6423.20'],
            ['bucket' => '2', 'buyers' => '1', 'revenue' => '202.00'],
        ]);

        $result = $this->report->getClvTiers();

        $this->assertSame(['VIP', 'Growth', 'Core', 'Starter', 'Entry'], array_column($result, 'name'));
        $this->assertSame(['tier' => 1, 'name' => 'VIP', 'min' => 1000, 'max' => null, 'buyers' => 4, 'revenue' => 6423.2], $result[0]);
        $this->assertSame(['tier' => 4, 'name' => 'Starter', 'min' => 100, 'max' => 250, 'buyers' => 1, 'revenue' => 202.0], $result[3]);
        $this->assertNull($result[4]['min']);
        $this->assertSame(100, $result[4]['max']);
    }

    public function testGetBuyersAddsSegmentTierAndStoreDates(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'customer_email' => 'chandresh@example.com', 'customer_id' => null, 'customer_name' => 'Chandresh Chauhan',
            'orders' => '2', 'lifetime_value' => '252.0000', 'avg_order_value' => '126.00',
            'first_order_at' => '2026-01-15 03:00:00', 'last_order_at' => '2026-03-14 18:00:00', 'recency_days' => '200',
            'r_score' => '1', 'f_score' => '2', 'm_score' => '3', 'clv_bucket' => '3',
        ]]);

        $buyer = $this->report->getBuyers(10)[0];

        $this->assertNull($buyer['customer_id']);
        $this->assertSame('Lost', $buyer['segment']);
        $this->assertSame(3, $buyer['tier']);
        $this->assertSame(252.0, $buyer['lifetime_value']);
        $this->assertSame('2026-01-14 21:00:00', $buyer['first_order_at']);
        $this->assertSame('2026-03-14 13:00:00', $buyer['last_order_at']);
        $this->assertArrayNotHasKey('clv_bucket', $buyer);
    }

    public function testGetBuyersSqlFilters(): void
    {
        $this->assertStringContainsString('WHERE b.recency_days > 90', $this->report->getBuyersSql(0, 'winback'));
        $this->assertStringContainsString('WHERE b.lifetime_value >= 1000', $this->report->getBuyersSql(0, 'vip'));
        $this->assertStringContainsString('LIMIT 200', $this->report->getBuyersSql(200));
        $this->assertStringNotContainsString('LIMIT', $this->report->getBuyersSql());
    }

    public function testGetPreferredCategoriesPicksTopRevenueThenDeeperCategory(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['customer_email' => 'a@example.com', 'category_name' => 'Gear', 'level' => '2', 'revenue' => '50'],
            ['customer_email' => 'a@example.com', 'category_name' => 'Bags', 'level' => '3', 'revenue' => '120'],
            ['customer_email' => 'b@example.com', 'category_name' => 'Men', 'level' => '2', 'revenue' => '80'],
            ['customer_email' => 'b@example.com', 'category_name' => 'Tops', 'level' => '3', 'revenue' => '80'],
        ]);

        $this->assertSame(
            ['a@example.com' => 'Bags', 'b@example.com' => 'Tops'],
            $this->report->getPreferredCategories(['a@example.com', 'b@example.com'])
        );
    }

    public function testGetPreferredCategoriesSkipsQueryWithoutEmails(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->report->getPreferredCategories([]));
    }

    public function testGetWinBackCandidateReturnsNullWithoutLapsedBuyers(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        $this->assertNull($this->report->getWinBackCandidate());
    }

    public function testGetVipConcentration(): void
    {
        $this->connection->method('fetchRow')->willReturn(['buyers' => '3', 'revenue' => '6302.2']);
        $this->connection->method('fetchOne')->willReturn('7447.2');

        $result = $this->report->getVipConcentration(3);

        $this->assertSame(3, $result['top_n']);
        $this->assertSame(6302.2, $result['top_revenue']);
        $this->assertSame(84.6, $result['share']);
    }

    /**
     * @return \DateTimeImmutable[] [Jan 1, tomorrow) in the store timezone
     */
    private function yearToDate(): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Chicago'));

        return [$today->setDate((int) $today->format('Y'), 1, 1), $today->modify('+1 day')];
    }

    // ── getTopCustomersBySpend ───────────────────────────────────────────

    public function testGetTopCustomersBySpendConvertsDatesToStoreTimezone(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'customer_email'   => 'john@example.com',
            'customer_name'    => 'John Smith',
            'total_orders'     => '12',
            'lifetime_value'   => '5400.00',
            'avg_order_value'  => '450.00',
            'first_order_date' => '2026-01-15 03:00:00',
            'last_order_date'  => '2026-07-01 12:00:00',
        ]]);

        $result = $this->report->getTopCustomersBySpend();

        $this->assertSame('John Smith', $result[0]['customer_name']);
        $this->assertSame('5400.00', $result[0]['lifetime_value']);
        $this->assertSame('2026-01-14 21:00:00', $result[0]['first_order_date']); // CST, UTC-6
        $this->assertSame('2026-07-01 07:00:00', $result[0]['last_order_date']);  // CDT, UTC-5
    }

    public function testGetTopCustomersBySpendReturnsEmptyForNoData(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->report->getTopCustomersBySpend());
    }

    public function testGetTopCustomersGroupsByEmailOnly(): void
    {
        $captured = '';
        $this->connection->method('fetchAll')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return [];
        });

        $this->report->getTopCustomersBySpend(5);

        $this->assertStringContainsString('GROUP BY o.customer_email', $captured);
        $this->assertStringContainsString('LIMIT 5', $captured);
    }

    // ── getRfmSegmentation ───────────────────────────────────────────────

    public function testGetRfmSegmentationReturnsScores(): void
    {
        $this->connection->method('fetchAll')->willReturn([[
            'customer_email' => 'john@example.com', 'customer_name' => 'John Smith',
            'recency_days' => '5', 'frequency' => '12', 'monetary' => '5400.00',
            'r_score' => '5', 'f_score' => '5', 'm_score' => '5',
        ]]);

        $result = $this->report->getRfmSegmentation();

        $this->assertSame('5', $result[0]['r_score']);
        $this->assertArrayHasKey('f_score', $result[0]);
        $this->assertArrayHasKey('m_score', $result[0]);
    }

    // ── getNewVsReturning ────────────────────────────────────────────────

    public function testGetNewVsReturningReturnsTwelveMonths(): void
    {
        $month = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Chicago')))->format('Y-m');
        $this->connection->method('fetchAll')->willReturn([
            ['month' => $month, 'new_customers' => '5', 'returning_customers' => '3'],
        ]);

        $result = $this->report->getNewVsReturning();

        $this->assertCount(12, $result);
        $this->assertSame('5', $result[11]['new_customers']);
        $this->assertSame('3', $result[11]['returning_customers']);
        $this->assertSame(0, $result[0]['new_customers']);
    }

    // ── getCustomerAcquisition ───────────────────────────────────────────

    public function testGetCustomerAcquisitionReturnsTwelveMonths(): void
    {
        $month = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Chicago')))->format('Y-m');
        $this->connection->method('fetchAll')->willReturn([
            ['month' => $month, 'new_customers' => '8'],
        ]);

        $result = $this->report->getCustomerAcquisition();

        $this->assertCount(12, $result);
        $this->assertSame('8', $result[11]['new_customers']);
    }

    // ── getClvDistribution ───────────────────────────────────────────────

    public function testGetClvDistributionReturnsAllRangesWithCurrencyLabels(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['bucket' => '1', 'customer_count' => '10'],
            ['bucket' => '5', 'customer_count' => '2'],
        ]);

        $result = $this->report->getClvDistribution();

        $this->assertSame(
            ['$0-$99', '$100-$249', '$250-$499', '$500-$999', '$1000+'],
            array_column($result, 'clv_range')
        );
        $this->assertSame([10, 0, 0, 0, 2], array_column($result, 'customer_count'));
    }

    // ── getRepeatPurchaseRate ────────────────────────────────────────────

    public function testGetRepeatPurchaseRateReturnsAllMetrics(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['total_customers' => '20', 'repeat_customers' => '8']);

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(20, $result['total_customers']);
        $this->assertSame(8, $result['repeat_customers']);
        $this->assertSame(12, $result['one_time_customers']);
        $this->assertSame(40.0, $result['repeat_rate']);
    }

    public function testGetRepeatPurchaseRateHandlesZeroCustomers(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['total_customers' => '0', 'repeat_customers' => '0']);

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(0, $result['total_customers']);
        $this->assertSame(0.0, $result['repeat_rate']);
    }

    public function testGetRepeatPurchaseRateCalculatesCorrectPercentage(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['total_customers' => '100', 'repeat_customers' => '33']);

        $result = $this->report->getRepeatPurchaseRate();

        $this->assertSame(33.0, $result['repeat_rate']);
        $this->assertSame(67, $result['one_time_customers']);
    }
}
