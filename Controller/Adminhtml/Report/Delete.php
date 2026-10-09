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
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Deletes one or more of the current admin's saved reports (ids[]). Ids of other admins'
 * reports are ignored.
 */
class Delete extends Action implements HttpPostActionInterface
{
    private const MAX_IDS = 500;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CollectionFactory $collectionFactory,
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

        $ids = $this->getRequest()->getParam('ids', []);
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', is_array($ids) ? $ids : [$ids]),
            static fn (int $id): bool => $id > 0
        ))), 0, self::MAX_IDS);

        if (!$ids) {
            return $resultJson->setData(['success' => false, 'message' => __('Please select at least one report.')]);
        }

        $reports = $this->collectionFactory->create()
            ->addFieldToFilter('admin_user_id', (int) $this->_auth->getUser()->getId())
            ->addFieldToFilter('report_id', ['in' => $ids]);

        $deleted = [];
        try {
            foreach ($reports as $report) {
                $this->savedReportResource->delete($report);
                $deleted[] = (int) $report->getId();
            }
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting: Failed to delete report', ['error' => $e->getMessage()]);
            return $resultJson->setData([
                'success' => false,
                'deleted' => $deleted,
                'message' => __('Failed to delete report: %1', $e->getMessage())
            ]);
        }

        if (!$deleted) {
            return $resultJson->setData(['success' => false, 'deleted' => [], 'message' => __('Saved report not found.')]);
        }

        return $resultJson->setData([
            'success' => true,
            'deleted' => $deleted,
            'message' => count($deleted) === 1
                ? __('1 saved report deleted.')
                : __('%1 saved reports deleted.', count($deleted))
        ]);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::saved_reports');
    }
}
