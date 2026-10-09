<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\QueryExecutor;
use Meetanshi\AIReporting\Model\QueryLogger;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use Psr\Log\LoggerInterface;

/**
 * Re-runs one of the current admin's saved reports (read-only, row-limited, like Ask AI)
 * and downloads the result as CSV. The run is written to the query log.
 */
class ExportData extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly SavedReportFactory $savedReportFactory,
        private readonly SavedReportResource $savedReportResource,
        private readonly QueryExecutor $queryExecutor,
        private readonly QueryLogger $queryLogger,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $adminUserId = (int) $this->_auth->getUser()->getId();
        $startTime   = microtime(true);
        $report      = $this->savedReportFactory->create();

        try {
            if (!$this->config->isEnabled()) {
                throw new LocalizedException(__('Meetanshi AI Reporting is disabled.'));
            }

            $this->savedReportResource->load($report, (int) $this->getRequest()->getParam('report_id', 0));
            if (!$report->getId() || $report->getAdminUserId() !== $adminUserId) {
                throw new LocalizedException(__('Saved report not found.'));
            }

            $result = $this->queryExecutor->execute($report->getSqlQuery());
        } catch (\Exception $e) {
            if ($report->getId() && $report->getAdminUserId() === $adminUserId) {
                $this->log($report->getNlpQuery(), $report->getSqlQuery(), false, $e->getMessage(), $startTime, 0, $adminUserId);
            }
            $this->logger->error('Meetanshi AIReporting: Saved report export failed', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));

            return $this->resultRedirectFactory->create()->setPath('*/*/saved');
        }

        $this->log($report->getNlpQuery(), $report->getSqlQuery(), true, '', $startTime, $result['row_count'], $adminUserId);

        // The report id keeps two exports from sharing the temporary file in var/
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($report->getTitle())), '_');
        $fileName = ($slug !== '' ? substr($slug, 0, 80) . '_' : 'saved_report_')
            . $report->getId() . '_' . date('Y-m-d') . '.csv';

        // 'rm': the temporary copy in var/ is deleted once it has been sent
        return $this->fileFactory->create(
            $fileName,
            ['type' => 'string', 'value' => $this->toCsv($result['columns'], $result['rows']), 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }

    private function log(
        string $nlp,
        string $sql,
        bool $success,
        string $error,
        float $startTime,
        int $rows,
        int $adminUserId
    ): void {
        $this->queryLogger->log($nlp, $sql, $success, $error, (int) round((microtime(true) - $startTime) * 1000), $rows, $adminUserId);
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function toCsv(array $columns, array $rows): string
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM, so spreadsheet apps detect the encoding

        if ($columns) {
            fputcsv($output, $columns, ',', '"', '');
        }
        foreach ($rows as $row) {
            fputcsv($output, array_map(fn (mixed $value): string => $this->cell($value), array_values($row)), ',', '"', '');
        }

        rewind($output);
        $csv = (string) stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Text that a spreadsheet would run as a formula gets a leading apostrophe.
     */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && !is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::saved_reports')
            && $this->_authorization->isAllowed('Meetanshi_AIReporting::query');
    }
}
