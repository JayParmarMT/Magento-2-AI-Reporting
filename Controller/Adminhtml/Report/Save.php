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
use Meetanshi\AIReporting\Model\Query\QueryTokenStorage;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\SavedReportFactory;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport as SavedReportResource;
use Psr\Log\LoggerInterface;

/**
 * Saves a query the current admin has just run. The SQL is taken from the server-side
 * QueryTokenStorage (identified by query_token), never from the request.
 */
class Save extends Action
{
    private const CHART_TYPES = ['table', 'bar', 'line', 'pie'];

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SavedReportFactory $savedReportFactory,
        private readonly SavedReportResource $savedReportResource,
        private readonly LoggerInterface $logger,
        private readonly QueryTokenStorage $queryTokenStorage,
        private readonly SqlGuard $sqlGuard
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->getRequest()->isAjax() || !$this->getRequest()->isPost()) {
            return $resultJson->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        $title     = mb_substr(trim((string) $this->getRequest()->getParam('title', '')), 0, 255);
        $token     = trim((string) $this->getRequest()->getParam('query_token', ''));
        $chartType = (string) $this->getRequest()->getParam('chart_type', 'table');
        $chartType = in_array($chartType, self::CHART_TYPES, true) ? $chartType : 'table';

        if ($title === '' || $token === '') {
            return $resultJson->setData(['success' => false, 'message' => __('Title and a query result are required.')]);
        }

        $query = $this->queryTokenStorage->get($token);
        if ($query === null) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('This result has expired. Please run the query again before saving it.')
            ]);
        }

        try {
            $report = $this->savedReportFactory->create();
            $report->setData([
                'title'         => $title,
                'nlp_query'     => $query['nlp'],
                'sql_query'     => $this->sqlGuard->sanitize($query['sql']),
                'chart_type'    => $chartType,
                'chart_config'  => null,
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
