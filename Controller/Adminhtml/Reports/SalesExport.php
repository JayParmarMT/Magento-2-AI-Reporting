<?php
/**
 * Meetanshi AIReporting — Sales & Revenue export (CSV)
 *
 * type = summary (every section of the page for the range) or orders (every order placed in the
 * range, all states, with a "Counted in Sales" column); range / start / end as on the page.
 * Amounts are in the base currency; times in the store timezone.
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
use Meetanshi\AIReporting\ViewModel\SalesRevenue;

class SalesExport extends Action implements HttpGetActionInterface
{
    private const UNIT_LABELS = ['hour' => 'Hour', 'day' => 'Day', 'month' => 'Month'];

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly SalesRevenue $sales,
        private readonly ReportContext $reportContext
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $type    = $request->getParam('type') === 'orders' ? 'orders' : 'summary';
        $range   = (string) $request->getParam('range', ReportRange::DEFAULT_RANGE);
        [$start, $end] = [$request->getParam('start'), $request->getParam('end')];

        try {
            $output = fopen('php://temp', 'r+');
            $this->writeRow($output, ['Sales & Revenue Report', $this->sales->getRangeLabel($range, $start, $end)]);

            if ($type === 'orders') {
                $this->writeOrders($output, $this->sales->getOrderLines($range, $start, $end));
            } else {
                $this->writeSummary($output, $this->sales->getData($range, $start, $end));
            }

            rewind($output);
            $csv = (string) stream_get_contents($output);
            fclose($output);

            // Written to a one-off file that is deleted once it has been sent
            $path = 'export/sales_revenue_' . bin2hex(random_bytes(8)) . '.csv';
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile($path, "\xEF\xBB\xBF" . $csv);

            return $this->fileFactory->create(
                'sales_revenue_' . ($type === 'orders' ? 'orders' : 'report') . '_'
                    . $this->reportContext->today()->format('Y-m-d') . '.csv',
                ['type' => 'filename', 'value' => $path, 'rm' => true],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));

            return $this->_redirect('*/*/sales');
        }
    }

    /**
     * @param resource $output
     */
    private function writeSummary($output, array $data): void
    {
        $c = $this->sales->getCurrencyCode();
        $t = $data['totals'];

        $this->writeRow($output, []);
        $this->writeRow($output, ['SUMMARY']);
        $this->writeRow($output, ['Metric', 'Value', 'Change vs previous period (%)']);
        foreach ([
            ["Net Revenue ({$c})", $t['revenue'], $t['revenue_change']],
            ["Gross Revenue ({$c})", $t['gross'], ''],
            ['Orders (counted)', $t['orders'], $t['orders_change']],
            ["Average Order Value ({$c})", $t['aov'], $t['aov_change']],
            ['Pending Payment Orders (not counted)', $t['pending_payment'], ''],
            ['Canceled Orders (not counted)', $t['canceled'], ''],
            ["Refunded on Orders ({$c})", $t['refunded'], ''],
            ['Credit Memos Created', $t['memos'], ''],
            ["Credit Memo Total ({$c})", $t['memo_refunded'], ''],
            ['Refund Rate (% of gross)', $t['refund_rate'], ''],
            ["Discounts Applied ({$c})", $t['discount'], ''],
            ["Tax Collected ({$c})", $t['tax'], ''],
            ["Shipping Charged ({$c})", $t['shipping'], ''],
        ] as $row) {
            $this->writeRow($output, [$row[0], $row[1], $row[2] ?? '']);
        }

        $this->writeRow($output, []);
        $this->writeRow($output, ['REVENUE BY ' . strtoupper(self::UNIT_LABELS[$data['unit']] ?? 'Period')]);
        $this->writeRow($output, [self::UNIT_LABELS[$data['unit']] ?? 'Period', 'Orders', "Net Revenue ({$c})"]);
        foreach ($data['series'] as $row) {
            $this->writeRow($output, [$row['key'], $row['orders'], $row['revenue']]);
        }

        $this->writeRow($output, []);
        $this->writeRow($output, ['ORDERS BY STATUS (all orders placed)']);
        $this->writeRow($output, ['Status', 'State', 'Orders', "Order Value ({$c})", 'Counted in Sales']);
        foreach ($data['statuses'] as $row) {
            $this->writeRow($output, [$row['label'], $row['state'], $row['orders'], $row['value'], $row['counted'] ? 'yes' : 'no']);
        }

        $this->writeRow($output, []);
        $this->writeRow($output, ['REVENUE BY WEEKDAY']);
        $this->writeRow($output, ['Weekday', 'Orders', "Net Revenue ({$c})"]);
        foreach ($data['weekdays'] as $row) {
            $this->writeRow($output, [$row['day_name'], $row['orders'], $row['revenue']]);
        }

        $this->writeRow($output, []);
        $this->writeRow($output, ['REVENUE BY REGION']);
        $this->writeRow($output, ['Region', 'Country', 'Orders', "Net Revenue ({$c})", "AOV ({$c})", "Tax Collected ({$c})"]);
        foreach ($data['regions'] as $row) {
            $this->writeRow($output, [
                $row['region'] !== '' ? $row['region'] : 'Unknown', $row['country_name'], $row['orders'], $row['revenue'],
                $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : 0, $row['tax'],
            ]);
        }

        $this->writeRow($output, []);
        $this->writeRow($output, ['SHIPPING METHODS']);
        $this->writeRow($output, ['Method', 'Code', 'Orders', "Net Revenue ({$c})", "Shipping Charged ({$c})", 'Orders Shipped']);
        foreach ($data['shipping'] as $row) {
            $this->writeRow($output, [
                $row['description'] !== '' ? $row['description'] : 'No shipping (virtual)', $row['method'],
                $row['orders'], $row['revenue'], $row['shipping'], $row['shipped_orders'],
            ]);
        }

        $this->writeRow($output, []);
        $this->writeRow($output, ['PROMOTIONS (an order with several rules counts under each)']);
        $this->writeRow($output, ['Rule', 'Coupon Code', 'Action', 'Status', 'Orders', "Discount ({$c})", "Revenue Driven ({$c})", "AOV ({$c})"]);
        foreach ($data['promotions'] as $row) {
            $this->writeRow($output, [
                $row['name'], $row['code'], $row['action'], $row['status'], $row['orders'], $row['discount'], $row['revenue'], $row['aov'],
            ]);
        }
    }

    /**
     * @param resource $output
     */
    private function writeOrders($output, array $rows): void
    {
        $c = $this->sales->getCurrencyCode();

        $this->writeRow($output, [
            'Order #', 'Placed (store time)', 'Status', 'State', 'Counted in Sales', 'Customer', 'Email', 'Guest',
            'Region', 'Country', 'Shipping Method', 'Coupon', "Gross ({$c})", "Discount ({$c})", "Tax ({$c})",
            "Shipping ({$c})", "Refunded ({$c})", "Net ({$c})",
        ]);
        foreach ($rows as $row) {
            $this->writeRow($output, [
                $row['increment_id'], $row['created_at'], $row['status'], $row['state'], $row['counted'] ? 'yes' : 'no',
                $row['customer_name'], $row['customer_email'], $row['customer_is_guest'] ? 'yes' : 'no',
                $row['region'], $row['country'], $row['shipping_method'], $row['coupon_code'],
                $row['gross'], $row['discount'], $row['tax'], $row['shipping'], $row['refunded'], $row['net'],
            ]);
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
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_sales');
    }
}
