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

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Exception\LlmException;
use Psr\Log\LoggerInterface;

class GeminiProvider implements ProviderInterface
{
    // The API key is sent in the x-goog-api-key header, never in the URL (URLs end up in logs)
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Retry transient failures (rate limit / server busy) a few times. */
    private const MAX_RETRIES = 3;

    /** HTTP statuses worth retrying: rate limit + transient server errors. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function complete(string $prompt): string
    {
        $apiKey = $this->config->getGeminiApiKey();

        if (empty($apiKey)) {
            throw new LlmException(__('Gemini API key is not configured. Please set it in Stores > Configuration > Meetanshi > AI Reporting.'));
        }

        $model  = $this->config->getGeminiModel();
        $apiUrl = sprintf(self::API_URL, rawurlencode($model));

        $systemInstruction = 'You are a Magento 2 database expert. Convert natural language questions into valid MySQL SELECT queries only. Never generate INSERT, UPDATE, DELETE, DROP, or ALTER statements. Return only the raw SQL query without any explanation, markdown, or code blocks.';

        $payload = $this->json->serialize([
            'system_instruction' => [
                'parts' => [['text' => $systemInstruction]]
            ],
            'contents' => [
                [
                    'parts' => [['text' => $prompt]]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $this->config->getMaxTokens(),
                'temperature'     => 0.1
            ]
        ]);

        $responseBody = '';
        $statusCode   = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->curl->setHeaders([
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $apiKey
            ]);
            $this->curl->setTimeout($this->config->getQueryTimeout());
            $this->curl->post($apiUrl, $payload);

            $responseBody = $this->curl->getBody();
            $statusCode   = $this->curl->getStatus();

            if ($statusCode === 200) {
                break;
            }

            // Retry transient errors (rate limit / server busy) with exponential backoff
            if (in_array($statusCode, self::RETRYABLE_STATUSES, true) && $attempt < self::MAX_RETRIES) {
                $this->logger->warning('Meetanshi AIReporting Gemini transient error, retrying', [
                    'status'  => $statusCode,
                    'attempt' => $attempt + 1,
                ]);
                sleep(min(8, 2 ** $attempt)); // 1s, 2s, 4s
                continue;
            }

            break;
        }

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Gemini error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);
            throw new LlmException($this->describeHttpError($statusCode));
        }

        try {
            $response = $this->json->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new LlmException(__('Failed to parse Gemini response: %1', $e->getMessage()));
        }

        $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($content)) {
            throw new LlmException(__('Gemini returned an empty response.'));
        }

        return trim($content);
    }

    /**
     * Human-friendly message for a non-200 Gemini response.
     */
    private function describeHttpError(int $status): string
    {
        switch ($status) {
            case 429:
                return (string) __(
                    'Gemini rate limit reached (HTTP 429). The free tier has per-minute and daily limits. '
                    . 'Please wait a moment and try again, or switch provider / upgrade your plan.'
                );
            case 503:
            case 500:
            case 502:
            case 504:
                return (string) __(
                    'Gemini is temporarily unavailable (HTTP %1). This is a Google-side issue — '
                    . 'please try again in a few moments.',
                    $status
                );
            case 400:
                return (string) __('Gemini rejected the request (HTTP 400). The selected model may be invalid — try Fetch Latest Models.');
            case 401:
            case 403:
                return (string) __('Gemini authentication failed (HTTP %1). Please check your API key.', $status);
            case 404:
                return (string) __('Gemini model not found (HTTP 404). Use "Fetch Latest Models" to select a current model.');
            default:
                return (string) __('Gemini API returned HTTP %1. Please check your API key and quota.', $status);
        }
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'gemini';
    }
}
