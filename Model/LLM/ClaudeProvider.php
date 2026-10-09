<?php
/**
 * Meetanshi AIReporting — Anthropic Claude provider (Messages API).
 *
 * The system prompt (database schema + rules, identical for every question) is marked for
 * prompt caching, so repeated questions and SQL repairs read it from cache instead of paying
 * for it again.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\LLM;

use Meetanshi\AIReporting\Exception\LlmException;

class ClaudeProvider extends AbstractProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Anthropic API version header value. */
    private const API_VERSION = '2023-06-01';

    /**
     * Models that still accept "temperature". Newer models (Opus 4.7+, Sonnet 5+, Fable) reject it with HTTP 400.
     */
    private const SAMPLING_MODEL_PATTERN = '/^claude-(3|haiku-4|sonnet-4|opus-4(?:-[0156])?(?:-\d{8})?$)/';

    /**
     * Models that think by default; thinking counts against max_tokens, so they need room for it.
     */
    private const THINKING_MODEL_PATTERN = '/^claude-(opus-5|sonnet-5|fable|mythos)/';

    private const THINKING_MIN_TOKENS = 16000;

    /**
     * Models that support server-side refusal fallbacks ("fallbacks": "default").
     */
    private const FALLBACK_MODEL_PATTERN = '/^claude-(opus-5(?:-5)?|fable-5-1|sonnet-5-5)$/';

    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @inheritDoc
     */
    public function complete(string $prompt, string $system = ''): string
    {
        $apiKey = $this->config->getClaudeApiKey();
        if ($apiKey === '') {
            throw new LlmException(__(
                'Claude API key is not configured. Get a key at https://console.anthropic.com and set it in '
                . 'Stores > Configuration > Meetanshi > AI Reporting.'
            ));
        }

        $model   = $this->getModel();
        $headers = ['x-api-key' => $apiKey, 'anthropic-version' => self::API_VERSION];
        $payload = [
            'model'      => $model,
            'max_tokens' => preg_match(self::THINKING_MODEL_PATTERN, $model)
                ? max(self::THINKING_MIN_TOKENS, $this->config->getMaxTokens())
                : $this->config->getMaxTokens(),
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($system !== '') {
            $payload['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
        }
        if (preg_match(self::SAMPLING_MODEL_PATTERN, $model)) {
            $payload['temperature'] = 0.1;
        }
        if (preg_match(self::FALLBACK_MODEL_PATTERN, $model)) {
            // A declined request is re-run server-side on the model Anthropic recommends for that case
            $payload['fallbacks'] = 'default';
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        $response = $this->postJson(self::API_URL, $headers, $payload);

        $this->logger->debug('Meetanshi AIReporting Claude usage', (array) ($response['usage'] ?? []));

        $stopReason = (string) ($response['stop_reason'] ?? '');
        if ($stopReason === 'refusal') {
            throw new LlmException(__('Claude declined to answer this request. Please rephrase the question.'));
        }

        $content = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= (string) ($block['text'] ?? '');
            }
        }
        $content = trim($content);

        if ($content === '') {
            throw new LlmException($stopReason === 'max_tokens'
                ? __('Claude used up the "Max Tokens" limit before answering. Increase Max Tokens in the configuration.')
                : __('Claude returned an empty response.'));
        }

        return $content;
    }

    protected function getLabel(): string
    {
        return 'Claude';
    }

    protected function getModel(): string
    {
        return $this->config->getClaudeModel();
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'claude';
    }
}
