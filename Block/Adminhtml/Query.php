<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Meetanshi\AIReporting\Model\Config;

class Query extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
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

    public function getLlmProvider(): string
    {
        return $this->config->getLlmProvider();
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
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
}
