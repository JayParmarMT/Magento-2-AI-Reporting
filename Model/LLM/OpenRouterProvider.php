<?php
/**
 * Meetanshi AIReporting — OpenRouter provider (OpenAI-compatible API, free models available)
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

class OpenRouterProvider extends AbstractChatCompletionsProvider
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    protected function getApiUrl(): string
    {
        return self::API_URL;
    }

    protected function getApiKey(): string
    {
        return $this->config->getOpenRouterApiKey();
    }

    protected function getModel(): string
    {
        return $this->config->getOpenRouterModel();
    }

    protected function getLabel(): string
    {
        return 'OpenRouter';
    }

    protected function getMissingKeyMessage(): \Magento\Framework\Phrase
    {
        return __(
            'OpenRouter API key is not configured. Get a free key at https://openrouter.ai — free models available, no credit card required.'
        );
    }

    protected function getExtraHeaders(): array
    {
        return [
            'HTTP-Referer' => 'https://meetanshi.com',
            'X-Title'      => 'Meetanshi AI Reporting',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'openrouter';
    }
}
