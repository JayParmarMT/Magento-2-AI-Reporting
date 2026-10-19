<?php
/**
 * Meetanshi AIReporting — ReportContext Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Report;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\ScopeInterface;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Report\ReportScope;
use PHPUnit\Framework\TestCase;

class ReportContextTest extends TestCase
{
    use ReportContextTrait;

    private ReportContext $context;

    protected function setUp(): void
    {
        $this->context = $this->createReportContext($this->createMock(AdapterInterface::class), 'America/Chicago');
    }

    public function testLocalTimeExprHandlesDaylightSavingTransitions(): void
    {
        $tz   = new \DateTimeZone('America/Chicago');
        $expr = $this->context->localTimeExpr(
            'o.created_at',
            new \DateTimeImmutable('2026-01-01', $tz),
            new \DateTimeImmutable('2026-12-31', $tz)
        );

        $this->assertSame(
            "CASE WHEN o.created_at < '2026-03-08 08:00:00' THEN (o.created_at + INTERVAL -21600 SECOND)"
            . " WHEN o.created_at < '2026-11-01 07:00:00' THEN (o.created_at + INTERVAL -18000 SECOND)"
            . ' ELSE (o.created_at + INTERVAL -21600 SECOND) END',
            $expr
        );
    }

    public function testLocalTimeExprWithoutTransitionIsSimpleShift(): void
    {
        $tz = new \DateTimeZone('America/Chicago');

        $this->assertSame(
            '(o.created_at + INTERVAL -18000 SECOND)',
            $this->context->localTimeExpr(
                'o.created_at',
                new \DateTimeImmutable('2026-06-01', $tz),
                new \DateTimeImmutable('2026-07-01', $tz)
            )
        );
    }

    public function testUtcRangeConvertsLocalMidnight(): void
    {
        $tz = new \DateTimeZone('America/Chicago');

        $this->assertSame(
            ['2026-07-01 05:00:00', '2026-07-02 05:00:00'],
            $this->context->utcRange(new \DateTimeImmutable('2026-07-01', $tz), new \DateTimeImmutable('2026-07-02', $tz))
        );
    }

    public function testParseLocalDateRejectsInvalidInput(): void
    {
        $this->assertNull($this->context->parseLocalDate('garbage'));
        $this->assertNull($this->context->parseLocalDate('2026-13-45'));
        $this->assertNull($this->context->parseLocalDate(null));
        $this->assertSame('2026-02-28 00:00:00', $this->context->parseLocalDate('2026-02-28')->format('Y-m-d H:i:s'));
    }

    public function testValidOrderConditionExcludesCancelledAndUnpaid(): void
    {
        $this->assertSame("so.state NOT IN ('canceled','pending_payment')", $this->context->validOrderCondition('so'));
    }

    public function testRevenueExpressionsUseBaseCurrencyNetOfRefunds(): void
    {
        $order = $this->context->orderRevenueExpr();
        $this->assertStringContainsString('o.base_grand_total - IFNULL(o.base_total_refunded, 0)', $order);
        $this->assertStringContainsString('o.base_to_global_rate', $order);

        $item = $this->context->itemRevenueExpr();
        $this->assertStringContainsString('oi.base_row_total', $item);
        $this->assertStringContainsString('oi.base_discount_amount', $item);
        $this->assertStringContainsString('oi.base_amount_refunded', $item);
    }

    public function testFillMonthlyZeroFillsMissingMonths(): void
    {
        $tz     = new \DateTimeZone('UTC');
        $result = $this->context->fillMonthly(
            [['month' => '2026-02', 'orders' => '4']],
            new \DateTimeImmutable('2026-01-01', $tz),
            new \DateTimeImmutable('2026-03-15', $tz),
            ['orders' => 0]
        );

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($result, 'month'));
        $this->assertSame([0, '4', 0], array_column($result, 'orders'));
        $this->assertSame('Feb 2026', $result[1]['label']);
    }

    public function testFillDailyUsesCustomKey(): void
    {
        $tz     = new \DateTimeZone('UTC');
        $result = $this->context->fillDaily(
            [['day' => '2026-05-02', 'orders' => '1']],
            new \DateTimeImmutable('2026-05-01', $tz),
            new \DateTimeImmutable('2026-05-04', $tz),
            ['orders' => 0],
            'day'
        );

        $this->assertSame(['2026-05-01', '2026-05-02', '2026-05-03'], array_column($result, 'day'));
        $this->assertSame('02 May', $result[1]['label']);
    }

    public function testResolveTablesReplacesTokens(): void
    {
        $this->assertSame(
            'SELECT * FROM sales_order o JOIN sales_order_item oi',
            $this->context->resolveTables('SELECT * FROM {{sales_order}} o JOIN {{sales_order_item}} oi')
        );
    }

    public function testFormatPriceUsesCurrency(): void
    {
        $this->assertSame('$1,234.50', $this->context->formatPrice(1234.5));
        $this->assertSame('USD', $this->context->getCurrencyCode());
    }

    // ── Store scope ──────────────────────────────────────────────────────

    public function testAllStoreViewsNeedNoStoreFilterAndConvertToTheGlobalCurrency(): void
    {
        $this->assertSame('', $this->context->storeFilter());
        $this->assertStringContainsString('IFNULL(o.base_to_global_rate, 1)', $this->context->orderRevenueExpr());
        $this->assertSame(1, $this->context->getStockId());
    }

    public function testWebsiteScopeFiltersStoresAndReportsInTheWebsiteCurrency(): void
    {
        $context = $this->createScopedContext(websiteId: 4, storeIds: [10, 11], valueStoreId: 10);

        $this->assertSame(' AND o.store_id IN (10,11)', $context->storeFilter());
        $this->assertSame(' AND cm.store_id IN (10,11)', $context->storeFilter('cm'));
        // the website's base amounts are already in its currency: no conversion to the global one
        $this->assertSame('((o.base_grand_total - IFNULL(o.base_total_refunded, 0)) * 1)', $context->orderRevenueExpr());
        $this->assertStringNotContainsString('base_to_global_rate', $context->itemRevenueExpr());
        $this->assertSame('EUR', $context->getCurrencyCode());
        $this->assertSame('Europe/Berlin', $context->getTimezone()->getName());
    }

    public function testWebsiteScopeLimitsStockToTheWebsite(): void
    {
        $context = $this->createScopedContext(websiteId: 4, storeIds: [10, 11], valueStoreId: 10);

        $this->assertStringContainsString('catalog_product_website pw ON pw.product_id = e.entity_id AND pw.website_id = 4', $context->getStockSql());
    }

    public function testAllStoreViewsKeepEveryCustomerAccountAndProduct(): void
    {
        $this->assertSame('', $this->context->customerFilter());
        $this->assertSame('', $this->context->productWebsiteJoin());
    }

    public function testWebsiteScopeKeepsItsCustomerAccountsAndProducts(): void
    {
        $context = $this->createScopedContext(websiteId: 4, storeIds: [10, 11], valueStoreId: 10);

        // by website: accounts created in the admin have store 0
        $this->assertSame(' AND c.website_id = 4', $context->customerFilter());
        $this->assertSame(
            ' JOIN catalog_product_website p_web ON p_web.product_id = p.entity_id AND p_web.website_id = 4',
            $context->productWebsiteJoin('p')
        );
    }

    public function testStoreViewScopeKeepsTheAccountsCreatedInIt(): void
    {
        $context = $this->createScopedContext(websiteId: 4, storeIds: [11], valueStoreId: 11, storeViewId: 11);

        $this->assertSame(' AND cu.store_id = 11', $context->customerFilter('cu'));
        $this->assertStringContainsString('e_web.website_id = 4', $context->productWebsiteJoin());
    }

    /**
     * A context whose scope is one website (EUR, Europe/Berlin) of a multi-store install, or one of
     * its store views.
     */
    private function createScopedContext(int $websiteId, array $storeIds, int $valueStoreId, ?int $storeViewId = null): ReportContext
    {
        $scope = $this->createMock(ReportScope::class);
        $scope->method('getKey')->willReturn($storeViewId !== null ? 'store:' . $storeViewId : 'website:' . $websiteId);
        $scope->method('getWebsiteId')->willReturn($websiteId);
        $scope->method('getWebsiteCode')->willReturn('perf_eu');
        $scope->method('getStoreIds')->willReturn($storeIds);
        $scope->method('getStoreViewId')->willReturn($storeViewId);
        $scope->method('getValueStoreId')->willReturn($valueStoreId);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, $type = null, $id = null) => match (true) {
                $path === 'currency/options/base' && $type === ScopeInterface::SCOPE_WEBSITES && (int) $id === $websiteId => 'EUR',
                $path === 'general/locale/timezone' && $type === ScopeInterface::SCOPE_STORE && (int) $id === $valueStoreId => 'Europe/Berlin',
                default => 'USD',
            }
        );

        return $this->createReportContext($this->createMock(AdapterInterface::class), 'America/Chicago', false, 'entity_id', $scope, $scopeConfig);
    }
}
