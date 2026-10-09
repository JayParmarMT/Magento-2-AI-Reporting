<?php
/**
 * Meetanshi AIReporting — Inventory report data (JSON) for the range filter and refresh button
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
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Meetanshi\AIReporting\ViewModel\InventoryAnalytics;
use Psr\Log\LoggerInterface;

class InventoryData extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly InventoryAnalytics $analytics,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            $data = $this->analytics->getReportData(
                (string) $this->getRequest()->getParam('range', InventoryAnalytics::DEFAULT_RANGE),
                $this->getRequest()->getParam('start'),
                $this->getRequest()->getParam('end')
            );

            return $result->setData(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting inventory report error', ['error' => $e->getMessage()]);

            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_inventory');
    }
}
