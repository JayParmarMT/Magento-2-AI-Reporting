<?php
/**
 * Meetanshi AIReporting — Ask AI & Copilot Analytics page
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Meetanshi\AIReporting\Model\AiContext;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\CollectionFactory as SavedReportCollectionFactory;

class Query extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly AiContext $aiContext,
        private readonly ReportContext $reportContext,
        private readonly AuthSession $authSession,
        private readonly SavedReportCollectionFactory $savedReportCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getQueryExecuteUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/query/execute');
    }

    public function getSaveReportUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/report/save');
    }

    public function getChatSendUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/chat/send');
    }

    public function getSavedReportsUrl(): string
    {
        return $this->getUrl('meetanshi_aireporting/report/saved');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'meetanshi_aireporting']);
    }

    public function getLlmProvider(): string
    {
        return $this->config->getLlmProvider();
    }

    public function getAiContext(): AiContext
    {
        return $this->aiContext;
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Saved reports of the logged-in admin (the Saved Reports page lists only these).
     */
    public function getSavedReportCount(): int
    {
        $user = $this->authSession->getUser();
        if (!$user) {
            return 0;
        }

        return (int) $this->savedReportCollectionFactory->create()
            ->addFieldToFilter('admin_user_id', (int) $user->getId())
            ->getSize();
    }

    public function getSuggestedQueries(): array
    {
        return [
            'Show me total sales for today',
            'Top 10 best selling products this month',
            'How many new customers registered this week?',
            'Show orders with status pending',
            'Which coupon codes were used today?',
            'List products with low stock (qty < 10)',
            'Show revenue by month for this year',
            'Top 5 customers by total spend',
            'Show abandoned carts from last 7 days',
            'Average order value this month',
        ];
    }

    /**
     * Recommended prompts by topic: [label, icon, [prompts]].
     *
     * @return array<int, array{label: string, icon: string, prompts: string[]}>
     */
    public function getPromptGroups(): array
    {
        return [
            ['label' => (string) __('Quick Insights'), 'icon' => 'bolt', 'prompts' => [
                (string) __("Today's net sales vs. the same day last week"),
                (string) __('Orders waiting for processing'),
            ]],
            ['label' => (string) __('Sales & Revenue'), 'icon' => 'trending_up', 'prompts' => [
                (string) __('Top 10 products this month with revenue and stock level'),
                (string) __('Average order value by payment method'),
            ]],
            ['label' => (string) __('Customer Intelligence'), 'icon' => 'group', 'prompts' => [
                (string) __('Top 5 customers by total lifetime spend'),
                (string) __('Guest vs. registered customer orders this year'),
            ]],
            ['label' => (string) __('Inventory & Risk'), 'icon' => 'inventory', 'prompts' => [
                (string) __('Low stock products (qty < 10) with their sales in the last 30 days'),
                (string) __('Out-of-stock products that sold in the last 90 days'),
            ]],
        ];
    }

    /**
     * Front-end settings for the Ask AI script.
     */
    public function getJsConfig(): array
    {
        $suggestions = $this->getSuggestedQueries();
        foreach ($this->getPromptGroups() as $group) {
            $suggestions = [...$suggestions, ...$group['prompts']];
        }

        return [
            'executeUrl'    => $this->getQueryExecuteUrl(),
            'chatUrl'       => $this->getChatSendUrl(),
            'saveUrl'       => $this->getSaveReportUrl(),
            'formKey'       => $this->getFormKey(),
            'currency'      => $this->reportContext->getCurrencyCode(),
            'timezone'      => $this->aiContext->getTimezoneLabel(),
            'provider'      => $this->aiContext->getProviderLabel(),
            'model'         => $this->aiContext->getModel(),
            'aiConfigured'  => $this->aiContext->isProviderConfigured(),
            'maxRows'       => $this->config->getMaxRows(),
            'queryTimeout'  => $this->config->getQueryTimeout(),
            'resultSharing' => $this->config->isResultSharingEnabled(),
            'savedCount'    => $this->getSavedReportCount(),
            'suggestions'   => array_values(array_unique($suggestions)),
            // Row links in the result table, by the id column a row carries
            'entityUrls'    => [
                'product_id'  => $this->getUrl('catalog/product/edit', ['id' => '__ID__']),
                'customer_id' => $this->getUrl('customer/index/edit', ['id' => '__ID__']),
                'order_id'    => $this->getUrl('sales/order/view', ['order_id' => '__ID__']),
            ],
        ];
    }
}
