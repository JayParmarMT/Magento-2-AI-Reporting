<?php
/**
 * Meetanshi AIReporting — Inventory report export (CSV)
 *
 * tab = all | risk (low stock & depletion) | idle (dead stock) | turnover | reorder (PO reorder
 * plan) | clearance (dead stock by idle capital); range/start/end pick the sales window, and
 * q (SKU or name text), source (MSI source code), category (id) and tier (stock tier key) narrow
 * the rows like the page table. Amounts are in the base currency at list price.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Reports;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\ViewModel\InventoryAnalytics;

class InventoryExport extends Action implements HttpGetActionInterface
{
    private const FILE_NAMES = [
        'all'       => 'all_skus',
        'risk'      => 'low_stock',
        'idle'      => 'dead_stock',
        'turnover'  => 'turnover',
        'reorder'   => 'reorder_plan',
        'clearance' => 'clearance_list',
    ];

    private const STATUS_LABELS = [
        'out'       => 'Out of Stock',
        'low'       => 'Low Stock',
        'depleting' => 'Depleting',
        'idle'      => 'Idle Capital',
        'ok'        => 'In Stock',
    ];

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly InventoryAnalytics $analytics,
        private readonly ReportContext $reportContext
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $tab     = (string) $request->getParam('tab', 'all');
        if (!isset(self::FILE_NAMES[$tab])) {
            $tab = 'all';
        }
        $range = (string) $request->getParam('range', InventoryAnalytics::DEFAULT_RANGE);
        $start = $request->getParam('start');
        $end   = $request->getParam('end');

        try {
            $rows = $this->analytics->getExportRows(
                $tab,
                $range,
                $start,
                $end,
                (string) $request->getParam('q', ''),
                (string) $request->getParam('source', ''),
                (int) $request->getParam('category', 0),
                (string) $request->getParam('tier', '')
            );
            $sold     = 'Units Sold – ' . $this->analytics->getRangeLabel($range, $start, $end);
            $currency = $this->reportContext->getCurrencyCode();

            $output = fopen('php://temp', 'r+');
            if ($tab === 'reorder') {
                $this->writeRow($output, [
                    'SKU', 'Product', 'Category', 'Available', 'Reserved', $sold, 'Avg Daily Sales', 'Days of Cover',
                    'Suggested Reorder Qty', 'Days of Cover After Reorder', "Unit Price ({$currency})",
                    "Reorder Value ({$currency})",
                ]);
                foreach ($rows as $row) {
                    $this->writeRow($output, [
                        $row['sku'], $row['name'], $row['category'], $row['available'], $row['reserved'], $row['sold'],
                        $row['velocity'], $row['cover_days'], $row['reorder_qty'],
                        round((max(0.0, $row['available']) + $row['reorder_qty']) / $row['velocity'], 1),
                        $row['price'], round($row['reorder_qty'] * $row['price'], 2),
                    ]);
                }
            } elseif ($tab === 'clearance') {
                $this->writeRow($output, [
                    'SKU', 'Product', 'Category', 'On Hand', 'Available', "Unit Price ({$currency})",
                    "Idle Capital ({$currency})", 'Last Sold', 'Days Since Last Sale',
                ]);
                foreach ($rows as $row) {
                    $this->writeRow($output, [
                        $row['sku'], $row['name'], $row['category'], $row['qty'], $row['available'], $row['price'],
                        $row['value'], $row['last_sold_at'] ?? 'Never', $row['idle_days'] ?? '',
                    ]);
                }
            } else {
                $this->writeRow($output, [
                    'SKU', 'Product', 'Category', 'Enabled', 'Sources', 'On Hand', 'Reserved', 'Available',
                    "Unit Price ({$currency})", "Stock Value ({$currency})", $sold, 'Avg Daily Sales', 'Days of Cover',
                    'Turnover', 'Last Sold', 'Days Since Last Sale', 'Status',
                ]);
                foreach ($rows as $row) {
                    $sources = [];
                    foreach ($row['sources'] as $code => $qty) {
                        $sources[] = $code . ': ' . $qty;
                    }
                    $this->writeRow($output, [
                        $row['sku'], $row['name'], $row['category'], $row['enabled'] ? 'Yes' : 'No',
                        implode('; ', $sources), $row['qty'], $row['reserved'], $row['available'], $row['price'],
                        $row['value'], $row['sold'], $row['velocity'], $row['cover_days'] ?? '', $row['turnover'] ?? '',
                        $row['last_sold_at'] ?? 'Never', $row['idle_days'] ?? '', self::STATUS_LABELS[$row['status']],
                    ]);
                }
            }
            rewind($output);
            $csv = (string) stream_get_contents($output);
            fclose($output);

            // A one-off file per download, deleted once it has been sent
            $path = 'export/inventory_' . bin2hex(random_bytes(8)) . '.csv';
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile($path, "\xEF\xBB\xBF" . $csv);

            return $this->fileFactory->create(
                'inventory_' . self::FILE_NAMES[$tab] . '_' . $this->reportContext->today()->format('Y-m-d') . '.csv',
                ['type' => 'filename', 'value' => $path, 'rm' => true],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));

            return $this->_redirect('*/*/inventory');
        }
    }

    /**
     * Write one CSV row; text that a spreadsheet would run as a formula is prefixed with an apostrophe.
     *
     * @param resource $handle
     */
    private function writeRow($handle, array $cells): void
    {
        foreach ($cells as &$cell) {
            if (is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell)) {
                $cell = "'" . $cell;
            }
        }
        unset($cell);

        fputcsv($handle, $cells, ',', '"', '');
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_inventory');
    }
}
