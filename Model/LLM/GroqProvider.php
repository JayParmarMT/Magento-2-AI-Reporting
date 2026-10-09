<?php
/**
 * Meetanshi AIReporting — Groq provider (OpenAI-compatible API, free tier available)
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

class GroqProvider extends AbstractChatCompletionsProvider
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    protected function getApiUrl(): string
    {
        return self::API_URL;
    }

    protected function getApiKey(): string
    {
        return $this->config->getGroqApiKey();
    }

    protected function getModel(): string
    {
        return $this->config->getGroqModel();
    }

    protected function getLabel(): string
    {
        return 'Groq';
    }

    protected function getMissingKeyMessage(): \Magento\Framework\Phrase
    {
        return __('Groq API key is not configured. Get a free key at https://console.groq.com — no credit card required.');
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'groq';
    }
}
