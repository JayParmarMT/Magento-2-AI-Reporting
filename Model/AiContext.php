<?php
/**
 * Meetanshi AIReporting — store and AI provider facts shown in page headers
 *
 * Shared by the Executive Dashboard and Ask AI pages: platform and store labels, the active
 * provider and model, and the store timezone.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use Meetanshi\AIReporting\Model\Report\ReportContext;

class AiContext
{
    private const PROVIDER_LABELS = [
        'openai'     => 'OpenAI',
        'gemini'     => 'Gemini',
        'groq'       => 'Groq',
        'ollama'     => 'Ollama',
        'openrouter' => 'OpenRouter',
        'claude'     => 'Claude',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReportContext $reportContext
    ) {
    }

    /**
     * "Magento 2.4.9 Community"
     */
    public function getPlatformLabel(): string
    {
        return trim($this->productMetadata->getName() . ' ' . $this->productMetadata->getVersion()
            . ' ' . $this->productMetadata->getEdition());
    }

    /**
     * "Default Store View (Main Website)"
     */
    public function getStoreName(): string
    {
        try {
            $store   = $this->storeManager->getDefaultStoreView();
            $website = $store ? $this->storeManager->getWebsite($store->getWebsiteId()) : null;

            return $store ? $store->getName() . ($website ? ' (' . $website->getName() . ')' : '') : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getProviderLabel(): string
    {
        $code = $this->config->getLlmProvider();

        return self::PROVIDER_LABELS[$code] ?? $code;
    }

    public function getModel(): string
    {
        return $this->config->getActiveModel();
    }

    public function isProviderConfigured(): bool
    {
        return $this->config->isActiveProviderConfigured();
    }

    /**
     * "America/Chicago (UTC-5)"
     */
    public function getTimezoneLabel(): string
    {
        $offset = $this->reportContext->now()->format('P');
        $offset = (string) preg_replace(['/^([+-])0/', '/:00$/'], ['$1', ''], $offset);

        return $this->reportContext->getTimezone()->getName() . ' (UTC' . $offset . ')';
    }
}
