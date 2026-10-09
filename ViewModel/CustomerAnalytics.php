<?php
/**
 * Meetanshi AIReporting — Customer Analytics Report page data
 *
 * Period figures (KPI cards, ticker, acquisition chart) follow the selected range; CLV tiers,
 * RFM segments, repeat/churn velocity and the buyer tables are all-time. Shared by the page
 * template and the reports/customerData and reports/customerExport controllers.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\ViewModel;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Meetanshi\AIReporting\Model\AiContext;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Report\CustomerReport;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Report\ReportRange;

class CustomerAnalytics implements ArgumentInterface
{
    public const DEFAULT_RANGE = ReportRange::DEFAULT_RANGE;

    /**
     * Buyers listed in the RFM and top-customer tables (filtered and paged in the browser).
     */
    public const TABLE_LIMIT = 200;

    public function __construct(
        private readonly CustomerReport $report,
        private readonly ReportContext $reportContext,
        private readonly ReportRange $reportRange,
        private readonly AiContext $aiContext,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    /**
     * Preset ranges for the header filter ("This Year (2026)"); the custom range has its own picker.
     *
     * @return array<string, string>
     */
    public function getPresetRanges(): array
    {
        return $this->reportRange->getPresetLabels();
    }

    /**
     * "Magento 2.4.9 Community"
     */
    public function getPlatformLabel(): string
    {
        return $this->aiContext->getPlatformLabel();
    }

    /**
     * "Store: Default Store View (Main Website)"
     */
    public function getStoreLabel(): string
    {
        $name = $this->aiContext->getStoreName();

        return $name !== '' ? (string) __('Store: %1', $name) : '';
    }

    /**
     * "America/Chicago (UTC-5)"
     */
    public function getTimezoneLabel(): string
    {
        return $this->aiContext->getTimezoneLabel();
    }

    /**
     * Figures for the selected range: buyer KPIs and the new vs returning trend.
     */
    public function getPeriodData(string $range = self::DEFAULT_RANGE, ?string $customStart = null, ?string $customEnd = null): array
    {
        $startedAt = microtime(true);
        $resolved  = $this->reportRange->resolve($range, $customStart, $customEnd);

        return [
            'range'       => $this->reportRange->toArray($resolved),
            'summary'     => $this->report->getPeriodSummary($resolved['start'], $resolved['end']),
            'acquisition' => $this->report->getAcquisitionTrend($resolved['start'], $resolved['end']),
            'query_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * All-time figures: CLV tiers, RFM segments, repeat and churn velocity, and the top buyers
     * (with their preferred category) for the tables.
     */
    public function getLifetimeData(): array
    {
        $startedAt  = microtime(true);
        $buyers     = $this->report->getBuyers(self::TABLE_LIMIT);
        $categories = $this->report->getPreferredCategories(array_column($buyers, 'customer_email'));
        foreach ($buyers as &$buyer) {
            $buyer['category'] = $categories[$buyer['customer_email']] ?? '';
        }
        unset($buyer);
        $rfm = $this->report->getRfmSegments();

        return [
            'totals'             => $this->report->getOverallTotals(),
            'tiers'              => $this->report->getClvTiers(),
            'segments'           => $rfm['segments'],
            'lapsed_buyers'      => $rfm['lapsed_buyers'],
            'lapsed_after_days'  => CustomerReport::LAPSED_AFTER_DAYS,
            'repeat'             => $this->report->getRepeatPurchaseRate(),
            'reorder_cycle_days' => $this->report->getReorderCycleDays(),
            'vip'                => $this->report->getVipConcentration(3),
            'winback'            => $this->report->getWinBackCandidate(),
            'buyers'             => $buyers,
            'buyers_limit'       => self::TABLE_LIMIT,
            'sql'                => trim((string) preg_replace('/\s+/', ' ', $this->report->getBuyersSql(self::TABLE_LIMIT))),
            'query_ms'           => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Settings and the first data set for Meetanshi_AIReporting/js/customer-report.
     */
    public function getJsConfig(): array
    {
        return [
            'period'       => $this->getPeriodData(),
            'lifetime'     => $this->getLifetimeData(),
            'currency'     => $this->reportContext->getCurrencyCode(),
            'formKey'      => $this->formKey->getFormKey(),
            'dataUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/customerData'),
            'exportUrl'    => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/customerExport'),
            'chatUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/chat/send'),
            'askAiUrl'     => $this->urlBuilder->getUrl('meetanshi_aireporting/query/index'),
            'customerUrl'  => $this->urlBuilder->getUrl('customer/index/edit', ['id' => '__ID__']),
            'couponUrl'    => $this->urlBuilder->getUrl('sales_rule/promo_quote/new'),
            'aiEnabled'    => $this->config->isEnabled(),
            'aiConfigured' => $this->aiContext->isProviderConfigured(),
            'provider'     => $this->aiContext->getProviderLabel(),
            'model'        => $this->aiContext->getModel(),
        ];
    }
}
