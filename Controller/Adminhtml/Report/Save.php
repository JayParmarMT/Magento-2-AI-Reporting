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
use Magento\Framework\Controller\Result\JsonFactory;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Psr\Log\LoggerInterface;

class Save extends Action
{
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

        if (!$this->getRequest()->isAjax() || !$this->getRequest()->isPost()) {
            return $resultJson->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        $title      = trim((string) $this->getRequest()->getParam('title', ''));
        $nlpQuery   = trim((string) $this->getRequest()->getParam('nlp_query', ''));
        $sqlQuery   = trim((string) $this->getRequest()->getParam('sql_query', ''));
        $chartType  = trim((string) $this->getRequest()->getParam('chart_type', 'table'));
        $chartConfig = $this->getRequest()->getParam('chart_config', []);

        if (empty($title) || empty($nlpQuery) || empty($sqlQuery)) {
            return $resultJson->setData(['success' => false, 'message' => __('Title, query, and SQL are required.')]);
        }

        try {
            $report = $this->savedReportFactory->create();
            $report->setData([
                'title'        => $title,
                'nlp_query'    => $nlpQuery,
                'sql_query'    => $sqlQuery,
                'chart_type'   => $chartType,
                'chart_config' => is_array($chartConfig) ? json_encode($chartConfig) : $chartConfig,
                'admin_user_id' => (int) $this->_auth->getUser()->getId()
            ]);
            $this->savedReportResource->save($report);

            return $resultJson->setData([
                'success'   => true,
                'report_id' => $report->getId(),
                'message'   => __('Report "%1" saved successfully.', $title)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting: Failed to save report', ['error' => $e->getMessage()]);
            return $resultJson->setData(['success' => false, 'message' => __('Failed to save report: %1', $e->getMessage())]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::saved_reports');
    }
}
