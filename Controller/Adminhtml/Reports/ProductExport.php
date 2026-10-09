<?php
/**
 * Meetanshi AIReporting — Product Performance export (CSV)
 *
 * tab = revenue | qty | worst (every product sold in the range, in that order) or dead (every
 * sellable product without a sale in the range); range / start / end as on the page. Amounts are
 * net, in the base currency; stock is current.
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
use Meetanshi\AIReporting\Model\Report\ReportRange;
use Meetanshi\AIReporting\ViewModel\ProductPerformance;

class ProductExport extends Action implements HttpGetActionInterface
{
    private const TABS = [
        'revenue' => 'best_sellers_by_revenue',
        'qty'     => 'best_sellers_by_quantity',
        'worst'   => 'worst_sellers',
        'dead'    => 'never_sold',
    ];

    private const VELOCITY_LABELS = [
        'restock' => 'Restock Urgency',
        'high'    => 'High Velocity',
        'medium'  => 'Medium Velocity',
        'low'     => 'Low Velocity',
    ];

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly ProductPerformance $performance,
        private readonly ReportContext $reportContext
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $tab     = (string) $request->getParam('tab', 'revenue');
        $tab     = isset(self::TABS[$tab]) ? $tab : 'revenue';
        $range   = (string) $request->getParam('range', ReportRange::DEFAULT_RANGE);
        [$start, $end] = [$request->getParam('start'), $request->getParam('end')];

        try {
            $rows     = $this->filterRows(
                $this->performance->getExportRows($tab, $range, $start, $end),
                mb_strtolower(trim((string) $request->getParam('q', ''))),
                (string) $request->getParam('category', ''),
                (string) $request->getParam('stock', '')
            );
            $currency = $this->reportContext->getCurrencyCode();
            $output   = fopen('php://temp', 'r+');

            $this->writeRow($output, [$this->performance->getRangeLabel($range, $start, $end)]);
            if ($tab === 'dead') {
                $this->writeRow($output, [
                    '#', 'Product', 'SKU', 'Type', 'Category', 'Created', 'Stock Qty',
                    "List Price ({$currency})", "Stock Value ({$currency})",
                ]);
                foreach ($rows as $i => $row) {
                    $this->writeRow($output, [
                        $i + 1, $row['product_name'], $row['sku'], $row['product_type'], $row['category'],
                        substr((string) $row['created_at'], 0, 10), $row['stock_qty'] ?? 'not tracked',
                        $row['price'], $row['stock_value'],
                    ]);
                }
            } else {
                $this->writeRow($output, [
                    '#', 'Product', 'SKU', 'Type', 'Category', 'Units Sold', "Net Revenue ({$currency})",
                    "Avg Realized Price ({$currency})", 'Orders', 'Stock Qty', 'Days of Cover', 'Velocity', 'Margin %',
                ]);
                foreach ($rows as $i => $row) {
                    $this->writeRow($output, [
                        $i + 1, $row['product_name'], $row['sku'], $row['product_type'], $row['category'],
                        $row['qty_sold'], $row['revenue'], $row['avg_price'], $row['order_count'],
                        $row['stock_qty'] ?? 'not tracked', $row['days_of_cover'] ?? '',
                        self::VELOCITY_LABELS[$row['velocity']] ?? '', $row['margin'] ?? '',
                    ]);
                }
            }
            rewind($output);
            $csv = (string) stream_get_contents($output);
            fclose($output);

            // Written to a one-off file that is deleted once it has been sent
            $path = 'export/product_performance_' . bin2hex(random_bytes(8)) . '.csv';
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile($path, "\xEF\xBB\xBF" . $csv);

            return $this->fileFactory->create(
                'product_performance_' . self::TABS[$tab] . '_' . $this->reportContext->today()->format('Y-m-d') . '.csv',
                ['type' => 'filename', 'value' => $path, 'rm' => true],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));

            return $this->_redirect('*/*/product');
        }
    }

    /**
     * The table filters of the page: text in the name, SKU or category; the category; the stock level
     * ('restock', 'in', 'low', 'out', 'untracked').
     */
    private function filterRows(array $rows, string $query, string $category, string $stock): array
    {
        $low = ProductPerformance::LOW_STOCK_QTY;

        return array_values(array_filter($rows, static function (array $row) use ($query, $category, $stock, $low): bool {
            $qty = $row['stock_qty'];
            $stockMatch = match ($stock) {
                'restock'   => ($row['velocity'] ?? '') === 'restock',
                'in'        => $qty !== null && $qty > $low,
                'low'       => $qty !== null && $qty > 0 && $qty <= $low,
                'out'       => $qty !== null && $qty <= 0,
                'untracked' => $qty === null,
                default     => true,
            };

            return $stockMatch
                && ($category === '' || $row['category'] === $category)
                && ($query === '' || str_contains(mb_strtolower($row['product_name'] . ' ' . $row['sku'] . ' ' . $row['category']), $query));
        }));
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
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_product');
    }
}
