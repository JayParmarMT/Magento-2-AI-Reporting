<?php
/**
 * Meetanshi AIReporting — AJAX endpoint to fetch live models for a provider.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Meetanshi\AIReporting\Model\LLM\ModelFetcher;

class FetchModels extends Action
{
    public const ADMIN_RESOURCE = 'Meetanshi_AIReporting::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ModelFetcher $modelFetcher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->getRequest()->isAjax()) {
            return $result->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        $provider = trim((string) $this->getRequest()->getParam('provider', ''));
        if ($provider === '') {
            return $result->setData(['success' => false, 'message' => __('No provider selected.')]);
        }

        return $result->setData($this->modelFetcher->fetchModels($provider));
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
