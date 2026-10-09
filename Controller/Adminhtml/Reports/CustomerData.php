<?php
/**
 * Meetanshi AIReporting — Customer Analytics period data (JSON) for the range filter
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
use Meetanshi\AIReporting\ViewModel\CustomerAnalytics;
use Psr\Log\LoggerInterface;

class CustomerData extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CustomerAnalytics $analytics,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            $data = $this->analytics->getPeriodData(
                (string) $this->getRequest()->getParam('range', CustomerAnalytics::DEFAULT_RANGE),
                $this->getRequest()->getParam('start'),
                $this->getRequest()->getParam('end')
            );

            return $result->setData(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting customer report error', ['error' => $e->getMessage()]);

            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::reports_customer');
    }
}
