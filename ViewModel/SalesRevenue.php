<?php
/**
 * Meetanshi AIReporting — Sales & Revenue Report page data
 *
 * Every figure follows the selected range (Today, This Week, This Month, This Year, custom),
 * except the daily trajectory, which is always the last 30 days. Net revenue = grand total
 * minus refunds, in the base currency; canceled / pending-payment orders are not sales.
 * Shared by the page template and the reports/salesData and reports/salesExport controllers.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\ViewModel;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Locale\ListsInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Meetanshi\AIReporting\Model\AiContext;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Report\ReportRange;
use Meetanshi\AIReporting\Model\Report\SalesReport;

class SalesRevenue implements ArgumentInterface
{
    /**
     * Days of the daily trajectory chart (ending today).
     */
    public const DAILY_DAYS = 30;

    /**
     * Ranges up to this many days are charted by day; longer ones by month (one day: by hour).
     */
    public const DAILY_MAX_DAYS = 62;

    /**
     * Regions listed before "View All Regions".
     */
    public const REGION_PREVIEW = 5;

    private array $countryNames = [];

    public function __construct(
        private readonly SalesReport $report,
        private readonly ReportRange $reportRange,
        private readonly ReportContext $reportContext,
        private readonly AiContext $aiContext,
        private readonly Config $config,
        private readonly ListsInterface $localeLists,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getPresetRanges(): array
    {
        return $this->reportRange->getPresetLabels();
    }

    public function getPlatformLabel(): string
    {
        return $this->aiContext->getPlatformLabel();
    }

    public function getStoreLabel(): string
    {
        $name = $this->aiContext->getStoreName();

        return $name !== '' ? (string) __('Store: %1', $name) : '';
    }

    public function getTimezoneLabel(): string
    {
        return $this->aiContext->getTimezoneLabel();
    }

    public function getCurrencyLabel(): string
    {
        $code   = $this->reportContext->getCurrencyCode();
        $symbol = $this->reportContext->getCurrencySymbol();

        return $symbol !== '' && $symbol !== $code ? "{$code} ({$symbol})" : $code;
    }

    /**
     * "state NOT IN ('canceled','pending_payment')"
     */
    public function getNetRule(): string
    {
        return "state NOT IN ('" . implode("','", ReportContext::EXCLUDED_STATES) . "')";
    }

    /**
     * Every figure of the page for a range.
     */
    public function getData(string $range = ReportRange::DEFAULT_RANGE, ?string $customStart = null, ?string $customEnd = null): array
    {
        $startedAt = microtime(true);
        $resolved  = $this->reportRange->resolve($range, $customStart, $customEnd);
        [$start, $end, $days] = [$resolved['start'], $resolved['end'], $resolved['days']];
        $today     = $this->reportContext->today();
        $unit      = $days <= 1 ? 'hour' : ($days <= self::DAILY_MAX_DAYS ? 'day' : 'month');

        $totals   = $this->report->getRangeTotals($start, $end);
        $previous = $this->report->getRangeTotals($start->modify("-{$days} days"), $start);
        $memos    = $this->report->getCreditMemoTotals($start, $end);
        $regions  = $this->report->getRegionBreakdown($start, $end);
        $home     = $this->homeCountry($regions);
        foreach ($regions as &$region) {
            $region['place']        = $this->placeLabel($region['region'], $region['country'], $home);
            $region['country_name'] = $this->countryName($region['country']);
        }
        unset($region);

        $topOrder = $this->report->getTopOrder($start, $end);
        if ($topOrder) {
            $topOrder['place'] = $this->placeLabel($topOrder['region'], $topOrder['country'], $home);
        }

        return [
            'range'      => $this->reportRange->toArray($resolved),
            'unit'       => $unit,
            'totals'     => $totals + [
                'aov'            => $totals['orders'] > 0 ? round($totals['revenue'] / $totals['orders'], 2) : 0.0,
                'gross_aov'      => $totals['orders'] > 0 ? round($totals['gross'] / $totals['orders'], 2) : 0.0,
                'memos'          => $memos['memos'],
                'memo_refunded'  => $memos['refunded'],
                'refund_rate'    => $totals['gross'] > 0 ? round($memos['refunded'] / $totals['gross'] * 100, 1) : 0.0,
                'revenue_change' => $this->change($totals['revenue'], $previous['revenue']),
                'orders_change'  => $this->change((float) $totals['orders'], (float) $previous['orders']),
                'aov_change'     => $this->change(
                    $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : 0.0,
                    $previous['orders'] > 0 ? $previous['revenue'] / $previous['orders'] : 0.0
                ),
                'promo_roi'      => $totals['discount'] > 0 ? round($totals['discounted_revenue'] / $totals['discount'], 1) : null,
            ],
            'previous'   => [
                'revenue' => $previous['revenue'],
                'orders'  => $previous['orders'],
                'start'   => $start->modify("-{$days} days")->format('Y-m-d'),
                'end'     => $start->modify('-1 day')->format('Y-m-d'),
            ],
            'top_order'  => $topOrder,
            'series'     => $this->report->getRevenueSeries($start, $end, $unit),
            'daily'      => $this->report->getRevenueSeries($today->modify('-' . (self::DAILY_DAYS - 1) . ' days'), $today->modify('+1 day'), 'day'),
            'statuses'   => $this->report->getStatusBreakdown($start, $end),
            'weekdays'   => $this->report->getWeekdayBreakdown($start, $end),
            'peak_hour'  => $this->report->getPeakHour($start, $end),
            'regions'    => $regions,
            'shipping'   => $this->report->getShippingBreakdown($start, $end),
            'promotions' => $this->describePromotions($this->report->getPromotionBreakdown($start, $end), $today),
            'sql'        => trim((string) preg_replace('/\s+/', ' ', $this->reportContext->resolveTables($this->report->getRangeTotalsSql()))),
            'query_ms'   => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Every order placed in a range (all states) for the order-level CSV, times in the store timezone.
     */
    public function getOrderLines(string $range, ?string $customStart, ?string $customEnd): array
    {
        $resolved = $this->reportRange->resolve($range, $customStart, $customEnd);
        $rows     = $this->report->getOrderLines($resolved['start'], $resolved['end']);

        foreach ($rows as &$row) {
            $row['created_at'] = $this->reportContext->toLocalDateTime($row['created_at']);
            $row['counted']    = !in_array($row['state'], ReportContext::EXCLUDED_STATES, true);
        }
        unset($row);

        return $rows;
    }

    public function getRangeLabel(string $range, ?string $customStart, ?string $customEnd): string
    {
        return $this->reportRange->resolve($range, $customStart, $customEnd)['label'];
    }

    public function getCurrencyCode(): string
    {
        return $this->reportContext->getCurrencyCode();
    }

    /**
     * Settings and the first data set for Meetanshi_AIReporting/js/sales-report.
     */
    public function getJsConfig(): array
    {
        return [
            'data'          => $this->getData(),
            'currency'      => $this->reportContext->getCurrencyCode(),
            'regionPreview' => self::REGION_PREVIEW,
            'formKey'       => $this->formKey->getFormKey(),
            'dataUrl'       => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/salesData'),
            'exportUrl'     => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/salesExport'),
            'chatUrl'       => $this->urlBuilder->getUrl('meetanshi_aireporting/chat/send'),
            'askAiUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/query/index'),
            'orderUrl'      => $this->urlBuilder->getUrl('sales/order/view', ['order_id' => '__ID__']),
            'ruleUrl'       => $this->urlBuilder->getUrl('sales_rule/promo_quote/edit', ['id' => '__ID__']),
            'rulesUrl'      => $this->urlBuilder->getUrl('sales_rule/promo_quote/index'),
            'carriersUrl'   => $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'carriers']),
            'aiEnabled'     => $this->config->isEnabled(),
            'aiConfigured'  => $this->aiContext->isProviderConfigured(),
            'provider'      => $this->aiContext->getProviderLabel(),
            'model'         => $this->aiContext->getModel(),
        ];
    }

    /**
     * Percent change against the previous period of the same length; null when it had nothing.
     */
    private function change(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    /**
     * Adds a readable action ("20% off items + free shipping") and a status to each rule row.
     */
    private function describePromotions(array $rows, \DateTimeImmutable $today): array
    {
        $date = $today->format('Y-m-d');

        foreach ($rows as &$row) {
            $amount = $row['discount_amount'];
            $action = match ($row['simple_action']) {
                'by_percent'  => $amount > 0 ? __('%1% off items', round($amount, 2)) : '',
                'by_fixed'    => $amount > 0 ? __('%1 off each item', $this->reportContext->formatPrice($amount)) : '',
                'cart_fixed'  => $amount > 0 ? __('%1 off the cart', $this->reportContext->formatPrice($amount)) : '',
                'buy_x_get_y' => __('Buy X, get Y free'),
                default       => '',
            };
            $parts = array_filter([(string) $action, $row['free_shipping'] ? (string) __('free shipping') : '']);
            $row['action'] = $parts ? ucfirst(implode(' + ', $parts)) : (string) __('Cart price rule');

            $row['status'] = match (true) {
                !$row['is_active']                                     => 'inactive',
                $row['to_date'] !== null && $row['to_date'] < $date    => 'expired',
                $row['from_date'] !== null && $row['from_date'] > $date => 'scheduled',
                $row['coupon_type'] === 1                              => 'automatic',
                default                                                => 'active',
            };
            $row['aov'] = $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : 0.0;
        }
        unset($row);

        return $rows;
    }

    /**
     * "California", "Gujarat (India)", "United Kingdom", "Unknown": regions of the home country
     * are shown without the country name.
     */
    private function placeLabel(string $region, string $country, string $homeCountry): string
    {
        $countryName = $this->countryName($country);

        if ($region === '') {
            return $countryName !== '' ? $countryName : (string) __('Unknown');
        }

        return $country !== '' && $country !== $homeCountry ? "{$region} ({$countryName})" : $region;
    }

    private function countryName(string $code): string
    {
        if ($code === '') {
            return '';
        }

        return $this->countryNames[$code] ??= (string) ($this->localeLists->getCountryTranslation($code) ?: $code);
    }

    /**
     * The country with the most orders among the region rows.
     */
    private function homeCountry(array $regions): string
    {
        $orders = [];
        foreach ($regions as $region) {
            $orders[$region['country']] = ($orders[$region['country']] ?? 0) + $region['orders'];
        }
        arsort($orders);

        return (string) (array_key_first($orders) ?? '');
    }
}
