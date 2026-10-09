<?php
/**
 * Meetanshi AIReporting — Inventory & Stock Intelligence page data
 *
 * Stock (on hand, reserved, available, value, tiers) is the current snapshot; sales, days of
 * cover, turnover and the top sellers follow the selected range. Shared by the page template and
 * the reports/inventoryData and reports/inventoryExport controllers.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\ViewModel;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Meetanshi\AIReporting\Model\AiContext;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Report\InventoryReport;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\Report\ReportRange;

class InventoryAnalytics implements ArgumentInterface
{
    public const DEFAULT_RANGE = ReportRange::DEFAULT_RANGE;

    /**
     * Table tabs: all SKUs, low stock & depletion, dead stock, and SKUs that sold in the range.
     */
    public const TABS = ['all', 'risk', 'idle', 'turnover'];

    /**
     * Rows sent to the browser per tab (filtered, sorted and paged there); exports include every row.
     */
    public const TABLE_LIMIT = 500;

    /**
     * Products in the "Top Sellers: Demand vs Current Stock" panel.
     */
    public const TOP_SELLERS = 6;

    /**
     * Days of cover a reorder brings a SKU back to (middle of the healthy band).
     */
    public const REORDER_COVER_DAYS = 60;

    private ?array $categories = null;

    public function __construct(
        private readonly InventoryReport $report,
        private readonly ReportContext $reportContext,
        private readonly ReportRange $reportRange,
        private readonly AiContext $aiContext,
        private readonly Config $config,
        private readonly ModuleListInterface $moduleList,
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

    public function isMsiEnabled(): bool
    {
        return $this->reportContext->isMsiEnabled();
    }

    /**
     * "Multi-Source Inventory · 1 source" or "Single-source stock (cataloginventory)"
     */
    public function getStockSourceLabel(): string
    {
        if (!$this->isMsiEnabled()) {
            return (string) __('Single-source stock (cataloginventory)');
        }
        $count = count($this->report->getSources());

        return (string) __('Multi-Source Inventory · %1', $count === 1 ? __('1 source') : __('%1 sources', $count));
    }

    public function getModuleVersion(): string
    {
        return (string) ($this->moduleList->getOne('Meetanshi_AIReporting')['setup_version'] ?? '');
    }

    /**
     * Every figure on the page for a range: totals, tiers, top sellers, the table rows (up to
     * TABLE_LIMIT per tab), filter options and the SQL behind the table.
     */
    public function getReportData(string $range = self::DEFAULT_RANGE, ?string $customStart = null, ?string $customEnd = null): array
    {
        $startedAt = microtime(true);
        $resolved  = $this->reportRange->resolve($range, $customStart, $customEnd);
        $rows      = $this->getRows($resolved);
        $days      = $this->report->rangeDays($resolved['start'], $resolved['end']);
        $totals    = $this->report->summarize($rows, $days);

        $sellers = array_filter($rows, static fn (array $row): bool => $row['sold'] > 0);
        usort($sellers, static fn (array $a, array $b): int => [$b['sold'], $a['sku']] <=> [$a['sold'], $b['sku']]);

        [$from, $to] = $this->reportContext->utcRange($resolved['start'], $resolved['end']);

        return [
            'range'        => $this->reportRange->toArray($resolved),
            'summary'      => $totals['summary'],
            'tiers'        => $totals['tiers'],
            'top_sellers'  => array_slice($sellers, 0, self::TOP_SELLERS),
            'rows'         => $this->limitRows($rows),
            'counts'       => [
                'all'      => count($rows),
                'risk'     => $totals['summary']['risk'],
                'idle'     => $totals['summary']['idle'],
                'turnover' => $totals['summary']['selling'],
            ],
            'categories'   => $this->categoryOptions($rows),
            'sources'      => $this->sourceOptions(),
            'sql'          => trim((string) preg_replace('/\s+/', ' ', $this->report->getSkuRowsSql($from, $to))),
            'generated_at' => $this->reportContext->now()->format('Y-m-d H:i'),
            'query_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Every row of a tab for the CSV export, narrowed like the page table (search text, source,
     * category, stock tier) and in the tab's default order. Tab "reorder" = SKUs below the healthy cover band
     * with a suggested reorder quantity; "clearance" = dead stock by idle capital.
     */
    public function getExportRows(
        string $tab,
        string $range = self::DEFAULT_RANGE,
        ?string $customStart = null,
        ?string $customEnd = null,
        string $query = '',
        string $source = '',
        int $categoryId = 0,
        string $tier = ''
    ): array {
        $resolved = $this->reportRange->resolve($range, $customStart, $customEnd);
        $query    = mb_strtolower(trim($query));

        $rows = array_filter($this->getRows($resolved), static function (array $row) use ($query, $source, $categoryId, $tier): bool {
            return ($query === '' || str_contains(mb_strtolower($row['name']), $query)
                    || str_contains(mb_strtolower($row['sku']), $query))
                && ($source === '' || isset($row['sources'][$source]))
                && ($categoryId === 0 || in_array($categoryId, $row['category_ids'], true))
                && ($tier === '' || $row['tier'] === $tier);
        });

        if ($tab === 'reorder') {
            $rows = array_filter(array_map(function (array $row): array {
                $row['reorder_qty'] = $this->reorderQty($row);
                return $row;
            }, $rows), static fn (array $row): bool => $row['reorder_qty'] > 0);
            usort($rows, static fn (array $a, array $b): int => [$a['cover_days'], $a['sku']] <=> [$b['cover_days'], $b['sku']]);

            return $rows;
        }

        return $this->sortTab($this->filterTab($rows, $tab === 'clearance' ? 'idle' : $tab), $tab === 'clearance' ? 'idle' : $tab);
    }

    /**
     * Units to order to reach REORDER_COVER_DAYS of cover at the range's sales pace (0 when the
     * SKU has not sold or already has at least HEALTHY_COVER_DAYS[0] days of cover).
     */
    public function reorderQty(array $row): int
    {
        if ($row['velocity'] <= 0 || ($row['cover_days'] !== null && $row['cover_days'] >= InventoryReport::HEALTHY_COVER_DAYS[0])) {
            return 0;
        }

        return max(0, (int) ceil($row['velocity'] * self::REORDER_COVER_DAYS - max(0.0, $row['available'])));
    }

    public function getRangeLabel(string $range = self::DEFAULT_RANGE, ?string $customStart = null, ?string $customEnd = null): string
    {
        return $this->reportRange->resolve($range, $customStart, $customEnd)['label'];
    }

    /**
     * Settings and the first data set for Meetanshi_AIReporting/js/inventory-report.
     */
    public function getJsConfig(): array
    {
        return [
            'data'         => $this->getReportData(),
            'currency'     => $this->reportContext->getCurrencyCode(),
            'msi'          => $this->isMsiEnabled(),
            'thresholds'   => [
                'low'           => InventoryReport::LOW_STOCK_QTY,
                'criticalCover' => InventoryReport::CRITICAL_COVER_DAYS,
                'healthyCover'  => InventoryReport::HEALTHY_COVER_DAYS,
                'idleDays'      => InventoryReport::IDLE_AFTER_DAYS,
                'reorderCover'  => self::REORDER_COVER_DAYS,
            ],
            'tableLimit'   => self::TABLE_LIMIT,
            'formKey'      => $this->formKey->getFormKey(),
            'dataUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/inventoryData'),
            'exportUrl'    => $this->urlBuilder->getUrl('meetanshi_aireporting/reports/inventoryExport'),
            'chatUrl'      => $this->urlBuilder->getUrl('meetanshi_aireporting/chat/send'),
            'askAiUrl'     => $this->urlBuilder->getUrl('meetanshi_aireporting/query/index'),
            'productUrl'   => $this->urlBuilder->getUrl('catalog/product/edit', ['id' => '__ID__']),
            'couponUrl'    => $this->urlBuilder->getUrl('sales_rule/promo_quote/new'),
            'aiEnabled'    => $this->config->isEnabled(),
            'aiConfigured' => $this->aiContext->isProviderConfigured(),
            'provider'     => $this->aiContext->getProviderLabel(),
            'model'        => $this->aiContext->getModel(),
        ];
    }

    /**
     * SKU rows with their source quantities and categories (deepest category as the display name).
     */
    private function getRows(array $resolved): array
    {
        $rows       = $this->report->getSkuRows($resolved['start'], $resolved['end']);
        $sources    = $this->report->getSourceQuantities();
        $categories = $this->getCategories();

        foreach ($rows as &$row) {
            $productCategories   = $categories[$row['product_id']] ?? [];
            $row['sources']      = $sources[$row['sku']] ?? [];
            $row['category']     = $productCategories[0]['name'] ?? '';
            $row['category_ids'] = array_column($productCategories, 'id');
        }
        unset($row);

        return $rows;
    }

    /**
     * The first TABLE_LIMIT rows of every tab in its default order, merged (each row once).
     */
    private function limitRows(array $rows): array
    {
        if (count($rows) <= self::TABLE_LIMIT) {
            return $rows;
        }

        $kept = [];
        foreach (self::TABS as $tab) {
            foreach (array_slice($this->sortTab($this->filterTab($rows, $tab), $tab), 0, self::TABLE_LIMIT) as $row) {
                $kept[$row['product_id']] = $row;
            }
        }

        return array_values($kept);
    }

    private function filterTab(array $rows, string $tab): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => match ($tab) {
            'risk'     => $row['risk'],
            'idle'     => $row['idle'],
            'turnover' => $row['sold'] > 0,
            default    => true,
        }));
    }

    /**
     * Default order per tab: stock value (all, dead stock), least available (risk), turnover.
     */
    private function sortTab(array $rows, string $tab): array
    {
        usort($rows, static fn (array $a, array $b): int => match ($tab) {
            'risk'     => [$a['available'], $a['cover_days'] ?? PHP_INT_MAX, $a['sku']]
                <=> [$b['available'], $b['cover_days'] ?? PHP_INT_MAX, $b['sku']],
            'turnover' => [$b['turnover'] ?? 0, $b['sold'], $a['sku']] <=> [$a['turnover'] ?? 0, $a['sold'], $b['sku']],
            default    => [$b['value'], $a['sku']] <=> [$a['value'], $b['sku']],
        });

        return $rows;
    }

    /**
     * Product categories, fetched once per request (they do not depend on the range).
     */
    private function getCategories(): array
    {
        return $this->categories ??= $this->report->getProductCategories();
    }

    /**
     * Categories that hold at least one stock-managed SKU: id, name ("Men › Tops" below the top
     * level) and SKU count, by name.
     */
    private function categoryOptions(array $rows): array
    {
        $names  = [];
        $counts = [];
        foreach ($this->getCategories() as $productCategories) {
            foreach ($productCategories as $category) {
                $names[$category['id']] = $category['parent'] !== ''
                    ? $category['parent'] . ' › ' . $category['name']
                    : $category['name'];
            }
        }
        foreach ($rows as $row) {
            foreach ($row['category_ids'] as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        $options = [];
        foreach ($counts as $id => $count) {
            $options[] = ['id' => $id, 'name' => $names[$id] ?? (string) $id, 'skus' => $count];
        }
        usort($options, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return $options;
    }

    private function sourceOptions(): array
    {
        $options = [];
        foreach ($this->report->getSources() as $code => $name) {
            $options[] = ['code' => (string) $code, 'name' => (string) $name];
        }

        return $options;
    }
}
