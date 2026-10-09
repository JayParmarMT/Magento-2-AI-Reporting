<?php
/**
 * Meetanshi AIReporting — Customer Analytics buyer export (CSV)
 *
 * segment = '' (every buyer), 'vip' (top CLV tier) or 'winback' (lapsed buyers); optionally narrowed
 * by segments (comma-separated RFM segments) and q (text in the name or email). Amounts are net
 * lifetime values in the base currency; dates are in the store timezone.
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
use Meetanshi\AIReporting\Model\Report\CustomerReport;
use Meetanshi\AIReporting\Model\Report\ReportContext;

class CustomerExport extends Action implements HttpGetActionInterface
{
    private const SEGMENTS = [
        ''        => 'all_buyers',
        'vip'     => 'vip_buyers',
        'winback' => 'winback_buyers',
    ];

    /**
     * Emails per preferred-category query.
     */
    private const CATEGORY_BATCH = 500;

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly CustomerReport $report,
        private readonly ReportContext $reportContext
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $segment = (string) $this->getRequest()->getParam('segment', '');
        if (!isset(self::SEGMENTS[$segment])) {
            $segment = '';
        }

        $rfmSegments = array_values(array_intersect(
            CustomerReport::SEGMENTS,
            explode(',', (string) $this->getRequest()->getParam('segments', ''))
        ));
        $query = mb_strtolower(trim((string) $this->getRequest()->getParam('q', '')));

        try {
            $buyers = array_values(array_filter(
                $this->report->getBuyers(0, $segment),
                static fn (array $buyer): bool => (!$rfmSegments || in_array($buyer['segment'], $rfmSegments, true))
                    && ($query === ''
                        || str_contains(mb_strtolower((string) $buyer['customer_name']), $query)
                        || str_contains(mb_strtolower((string) $buyer['customer_email']), $query))
            ));
            $categories = [];
            foreach (array_chunk(array_column($buyers, 'customer_email'), self::CATEGORY_BATCH) as $emails) {
                $categories += $this->report->getPreferredCategories($emails);
            }

            $currency = $this->reportContext->getCurrencyCode();
            $output   = fopen('php://temp', 'r+');
            $this->writeRow($output, [
                'Rank', 'Customer', 'Email', 'Account', 'Customer ID', 'Orders',
                "Lifetime Value ({$currency})", "Avg Order Value ({$currency})", 'First Order', 'Last Order',
                'Days Since Last Order', 'R', 'F', 'M', 'Segment', 'CLV Tier', 'Preferred Category',
            ]);
            foreach ($buyers as $i => $buyer) {
                $this->writeRow($output, [
                    $i + 1,
                    $buyer['customer_name'],
                    $buyer['customer_email'],
                    $buyer['customer_id'] !== null ? 'Registered' : 'Guest',
                    $buyer['customer_id'] ?? '',
                    $buyer['orders'],
                    $buyer['lifetime_value'],
                    $buyer['avg_order_value'],
                    $buyer['first_order_at'],
                    $buyer['last_order_at'],
                    $buyer['recency_days'],
                    $buyer['r_score'],
                    $buyer['f_score'],
                    $buyer['m_score'],
                    $buyer['segment'],
                    $buyer['tier'],
                    $categories[$buyer['customer_email']] ?? '',
                ]);
            }
            rewind($output);
            $csv = (string) stream_get_contents($output);
            fclose($output);

            $name = $rfmSegments
                ? strtolower((string) preg_replace('/[^A-Za-z]+/', '_', implode('_', $rfmSegments)))
                : self::SEGMENTS[$segment];

            // Buyer data: written to a one-off file that is deleted once it has been sent
            $path = 'export/customer_analytics_' . bin2hex(random_bytes(8)) . '.csv';
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile($path, "\xEF\xBB\xBF" . $csv);

            return $this->fileFactory->create(
                'customer_analytics_' . $name . '_' . $this->reportContext->today()->format('Y-m-d') . '.csv',
                ['type' => 'filename', 'value' => $path, 'rm' => true],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));

            return $this->_redirect('*/*/customer');
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
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_customer');
    }
}
