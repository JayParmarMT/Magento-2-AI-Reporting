<?php
/**
 * Meetanshi AIReporting — Product Performance Report page data
 *
 * Sales figures follow the selected range (Today, This Week, This Month, This Year, custom);
 * catalog size and stock are as of now. Shared by the page template and the
 * reports/productData and reports/productExport controllers.
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
use Meetanshi\AIReporting\Model\Report\ProductReport;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Report\ReportRange;

class ProductPerformance implements ArgumentInterface
{
    /**
     * Products listed in each table tab (filtered and paged in the browser); exports have no limit.
     */
    public const TABLE_LIMIT = 500;

    /**
     * Products in the "Worst Sellers / Low Velocity" tab.
     */
    public const WORST_LIMIT = 10;

    /**
     * Stock at or below this quantity is low.
     */
    public const LOW_STOCK_QTY = 10;

    /**
     * Fewer days of stock than this, at the period's sales pace, needs a restock.
     */
    public const COVER_DAYS = 14;

    public function __construct(
        private readonly ProductReport $report,
        private readonly ReportRange $reportRange,
        private readonly ReportContext $reportContext,
        private readonly AiContext $aiContext,
        private readonly Config $config,
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

    /**
     * Every figure of the page for a range.
     */
    public function getData(string $range = ReportRange::DEFAULT_RANGE, ?string $customStart = null, ?string $customEnd = null): array
    {
        $startedAt = microtime(true);
        $resolved  = $this->reportRange->resolve($range, $customStart, $customEnd);
        [$start, $end, $days] = [$resolved['start'], $resolved['end'], $resolved['days']];

        $totals   = $this->report->getSalesTotals($start, $end);
        $previous = $this->report->getSalesTotals($start->modify("-{$days} days"), $start);
        $products = $this->getProductRows($start, $end, $days);
        $dead     = $this->report->getDeadStock($start, $end, self::TABLE_LIMIT);
        $dead['rows'] = $this->addCategories($dead['rows']);

        $top5 = array_sum(array_column(array_slice($products, 0, 5), 'revenue'));

        return [
            'range'        => $this->reportRange->toArray($resolved),
            'catalog'      => $this->report->getCatalogSummary($start, $end),
            'totals'       => $totals + [
                'previous_units' => $previous['units'],
                'units_change'   => $previous['units'] > 0
                    ? round(($totals['units'] - $previous['units']) / $previous['units'] * 100, 1)
                    : null,
                'top5_share'     => $totals['revenue'] > 0 ? round($top5 / $totals['revenue'] * 100, 1) : 0.0,
            ],
            'products'     => array_slice($products, 0, self::TABLE_LIMIT),
            'product_count' => count($products),
            'worst'        => array_slice(array_reverse($products), 0, self::WORST_LIMIT),
            'dead'         => $dead,
            'types'        => $this->report->getProductTypeMix($start, $end),
            'bands'        => $this->report->getPriceBands($start, $end),
            'categories'   => $this->report->getTopCategories($start, $end, 5, self::LOW_STOCK_QTY),
            'sql'          => trim((string) preg_replace('/\s+/', ' ', $this->report->getProductPerformanceSql())),
            'query_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Rows of a table tab for the CSV export: 'revenue', 'qty', 'worst' (all sold products) or
     * 'dead' (every product without a sale), with no row limit.
     */
    public function getExportRows(string $tab, string $range, ?string $customStart, ?string $customEnd): array
    {
        $resolved = $this->reportRange->resolve($range, $customStart, $customEnd);

        if ($tab === 'dead') {
            return $this->addCategories($this->report->getDeadStock($resolved['start'], $resolved['end'])['rows']);
        }

        $rows = $this->getProductRows($resolved['start'], $resolved['end'], $resolved['days']);
        if ($tab === 'qty') {
            usort($rows, static fn ($a, $b) => [$b['qty_sold'], $b['revenue']] <=> [$a['qty_sold'], $a['revenue']]);
        } elseif ($tab === 'worst') {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    public function getRangeLabel(string $range, ?string $customStart, ?string $customEnd): string
    {
        return $this->reportRange->resolve($range, $customStart, $customEnd)['label'];
    }

    /**
     * Settings and the first data set for Meetanshi_AIReporting/js/product-report.
     */
    public function getJsConfig(): array
    {
        return [
            'data'         => $this->getData(),
            'currency'     => $this->reportContext->getCurrencyCode(),
            'formKey'      => $this->formKey->getFormKey(),
            'dataUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/productData'),
            'exportUrl'    => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/productExport'),
            'chatUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/chat/send'),
            'askAiUrl'     => $this->urlBuilder->getUrl('meetanshi_aireporting/query/index'),
            'productUrl'   => $this->urlBuilder->getUrl('catalog/product/edit', ['id' => '__ID__']),
            'promoUrl'     => $this->urlBuilder->getUrl('sales_rule/promo_quote/new'),
            'lowStockQty'  => self::LOW_STOCK_QTY,
            'coverDays'    => self::COVER_DAYS,
            'aiEnabled'    => $this->config->isEnabled(),
            'aiConfigured' => $this->aiContext->isProviderConfigured(),
            'provider'     => $this->aiContext->getProviderLabel(),
            'model'        => $this->aiContext->getModel(),
        ];
    }

    /**
     * Sold products by revenue with category, current stock, margin (when the lines carry a cost)
     * and velocity.
     */
    private function getProductRows(\DateTimeImmutable $start, \DateTimeImmutable $end, int $days): array
    {
        $rows  = $this->addCategories($this->report->getProductPerformance($start, $end));
        $stock = $this->report->getProductStock(array_column($rows, 'product_id'));

        foreach ($rows as $i => &$row) {
            $row['rank']        = $i + 1;
            $row['stock_qty']   = isset($stock[$row['product_id']]) ? $stock[$row['product_id']]['qty'] : null;
            $row['is_in_stock'] = isset($stock[$row['product_id']]) ? $stock[$row['product_id']]['is_in_stock'] : null;
            $row['margin']      = $row['cost'] !== null && $row['revenue'] > 0
                ? round(($row['revenue'] - $row['cost']) / $row['revenue'] * 100, 1)
                : null;
            unset($row['cost']);
        }
        unset($row);

        return $this->report->rateVelocity($rows, $days, self::COVER_DAYS);
    }

    private function addCategories(array $rows): array
    {
        $categories = $this->report->getProductCategories(array_column($rows, 'product_id'));
        foreach ($rows as &$row) {
            $row['category'] = $categories[$row['product_id']] ?? '';
        }
        unset($row);

        return $rows;
    }
}
