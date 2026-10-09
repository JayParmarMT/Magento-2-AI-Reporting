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

use Meetanshi\AIReporting\Exception\LlmException;

class GeminiProvider extends AbstractProvider
{
    // The API key is sent in the x-goog-api-key header, never in the URL (URLs end up in logs)
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * @inheritDoc
     */
    public function complete(string $prompt, string $system = ''): string
    {
        $apiKey = $this->config->getGeminiApiKey();
        if ($apiKey === '') {
            throw new LlmException(__('Gemini API key is not configured. Please set it in Stores > Configuration > Meetanshi > AI Reporting.'));
        }

        $payload = [
            'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'maxOutputTokens' => $this->config->getMaxTokens(),
                'temperature'     => 0.1,
            ],
        ];
        if ($system !== '') {
            $payload['system_instruction'] = ['parts' => [['text' => $system]]];
        }

        $response = $this->postJson(
            sprintf(self::API_URL, rawurlencode($this->getModel())),
            ['x-goog-api-key' => $apiKey],
            $payload
        );

        $candidate = $response['candidates'][0] ?? [];
        $content   = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (empty($part['thought'])) {
                $content .= (string) ($part['text'] ?? '');
            }
        }
        $content = trim($content);

        if ($content === '') {
            // Thinking models (gemini-2.5-*) count their reasoning against maxOutputTokens
            throw new LlmException(($candidate['finishReason'] ?? '') === 'MAX_TOKENS'
                ? __('Gemini used up the "Max Tokens" limit before answering (thinking models count their reasoning too). Increase Max Tokens in the configuration.')
                : __('Gemini returned an empty response.'));
        }

        return $content;
    }

    protected function getLabel(): string
    {
        return 'Gemini';
    }

    protected function getModel(): string
    {
        return $this->config->getGeminiModel();
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'gemini';
    }
}
