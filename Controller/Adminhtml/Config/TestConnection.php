<?php
/**
 * Meetanshi AIReporting — "Test Connection" button on the configuration page
 *
 * Sends a tiny request with the saved API key and model, so a wrong key, a retired model name
 * or an exhausted quota shows up on the configuration page instead of in the first report.
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
use Meetanshi\AIReporting\Model\LLM\ProviderPool;

class TestConnection extends Action
{
    public const ADMIN_RESOURCE = 'Meetanshi_AIReporting::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ProviderPool $providerPool
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->getRequest()->isAjax() || !$this->getRequest()->isPost()) {
            return $result->setData(['success' => false, 'message' => __('Invalid request.')]);
        }

        $code = trim((string) $this->getRequest()->getParam('provider', ''));
        try {
            $started = microtime(true);
            $reply   = $this->providerPool->getProvider($code)->complete(
                'Reply with the single word OK.',
                'You are checking a connection. Reply with the single word OK.'
            );

            return $result->setData([
                'success' => true,
                'message' => __(
                    'Connected (saved settings) — the model replied "%1" in %2 s.',
                    mb_substr(trim($reply), 0, 40),
                    round(microtime(true) - $started, 1)
                ),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
