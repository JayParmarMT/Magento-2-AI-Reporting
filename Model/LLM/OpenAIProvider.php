<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

class OpenAIProvider extends AbstractChatCompletionsProvider
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Reasoning models (o1, o3, o4-mini, gpt-5 …) accept no temperature and count their reasoning
     * against the output limit, so they get a higher floor.
     */
    private const REASONING_MODEL_PATTERN = '/^(o\d|gpt-5)/i';

    private const REASONING_MIN_TOKENS = 8000;

    protected function getApiUrl(): string
    {
        return self::API_URL;
    }

    protected function getApiKey(): string
    {
        return $this->config->getOpenAiApiKey();
    }

    protected function getModel(): string
    {
        return $this->config->getOpenAiModel();
    }

    protected function getLabel(): string
    {
        return 'OpenAI';
    }

    protected function getMissingKeyMessage(): \Magento\Framework\Phrase
    {
        return __('OpenAI API key is not configured. Please set it in Stores > Configuration > Meetanshi > AI Reporting.');
    }

    protected function getGenerationOptions(): array
    {
        // max_completion_tokens replaces the deprecated max_tokens (rejected by reasoning models)
        if (preg_match(self::REASONING_MODEL_PATTERN, $this->getModel())) {
            return ['max_completion_tokens' => max(self::REASONING_MIN_TOKENS, $this->config->getMaxTokens())];
        }

        return ['max_completion_tokens' => $this->config->getMaxTokens(), 'temperature' => 0.1];
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'openai';
    }
}
