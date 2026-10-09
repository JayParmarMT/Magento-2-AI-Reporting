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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use Psr\Log\LoggerInterface;

/**
 * Renames one of the current admin's saved reports and/or changes its chart format.
 * The question and SQL of a saved report never change.
 */
class Update extends Action implements HttpPostActionInterface
{
    private const CHART_TYPES = ['table', 'bar', 'line', 'pie'];

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SavedReportFactory $savedReportFactory,
        private readonly SavedReportResource $savedReportResource,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->getRequest()->isAjax()) {
            return $resultJson->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        $reportId  = (int) $this->getRequest()->getParam('report_id', 0);
        $title     = mb_substr(trim((string) $this->getRequest()->getParam('title', '')), 0, 255);
        $chartType = (string) $this->getRequest()->getParam('chart_type', 'table');

        if ($title === '') {
            return $resultJson->setData(['success' => false, 'message' => __('Please enter a report title.')]);
        }
        if (!in_array($chartType, self::CHART_TYPES, true)) {
            return $resultJson->setData(['success' => false, 'message' => __('Please choose a valid chart format.')]);
        }

        $report = $this->savedReportFactory->create();
        $this->savedReportResource->load($report, $reportId);
        if (!$report->getId() || $report->getAdminUserId() !== (int) $this->_auth->getUser()->getId()) {
            return $resultJson->setData(['success' => false, 'message' => __('Saved report not found.')]);
        }

        try {
            $report->setData('title', $title);
            $report->setData('chart_type', $chartType);
            $this->savedReportResource->save($report);

            return $resultJson->setData([
                'success' => true,
                'report'  => ['id' => (int) $report->getId(), 'title' => $title, 'chart' => $chartType],
                'message' => __('Report "%1" updated.', $title)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting: Failed to update report', ['error' => $e->getMessage()]);
            return $resultJson->setData(['success' => false, 'message' => __('Failed to update report: %1', $e->getMessage())]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::saved_reports');
    }
}
