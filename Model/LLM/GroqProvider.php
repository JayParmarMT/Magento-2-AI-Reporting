<?php
/**
 * Meetanshi AIReporting — Groq Provider (Free Tier Available)
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

class GroqProvider implements ProviderInterface
{
    /**
     * Groq uses OpenAI-compatible API format.
     * Free tier: https://console.groq.com (no credit card required)
     */
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /**
     * Number of times to retry when the API is rate limited (HTTP 429).
     */
    private const MAX_RETRIES = 3;

    /**
     * Base delay (seconds) for exponential backoff between retries.
     */
    private const BASE_RETRY_DELAY = 2;

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
        $apiKey = $this->config->getGroqApiKey();

        if (empty($apiKey)) {
            throw new LlmException(__(
                'Groq API key is not configured. Get a free key at https://console.groq.com — no credit card required.'
            ));
        }

        $payload = $this->json->serialize([
            'model'       => $this->config->getGroqModel(),
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You are a Magento 2 database expert. Convert natural language questions into valid MySQL SELECT queries only. Never generate INSERT, UPDATE, DELETE, DROP, or ALTER statements. Return only the raw SQL query without any explanation, markdown, or code blocks.'
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens'  => $this->config->getMaxTokens(),
            'temperature' => 0.1
        ]);

        $responseBody = '';
        $statusCode   = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            // A fresh Curl call each attempt
            $this->curl->setHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey
            ]);
            $this->curl->setTimeout($this->config->getQueryTimeout());
            $this->curl->post(self::API_URL, $payload);

            $responseBody = $this->curl->getBody();
            $statusCode   = $this->curl->getStatus();

            // Success
            if ($statusCode === 200) {
                break;
            }

            // Rate limited — wait and retry (unless this was the last attempt)
            if ($statusCode === 429 && $attempt < self::MAX_RETRIES) {
                $wait = $this->getRetryDelay($attempt);
                $this->logger->warning('Meetanshi AIReporting Groq rate limited, retrying', [
                    'attempt' => $attempt + 1,
                    'wait'    => $wait
                ]);
                sleep($wait);
                continue;
            }

            // Any other non-200, or 429 after all retries — stop and report
            break;
        }

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Groq error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);

            if ($statusCode === 429) {
                throw new LlmException(__(
                    'Groq rate limit reached (HTTP 429). The free tier has per-minute and daily limits. '
                    . 'Please wait a moment and try again, or upgrade your plan at https://console.groq.com/settings/billing'
                ));
            }

            throw new LlmException(__(
                'Groq API returned HTTP %1. Please check your API key at https://console.groq.com',
                $statusCode
            ));
        }

        try {
            $response = $this->json->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new LlmException(__('Failed to parse Groq response: %1', $e->getMessage()));
        }

        $content = $response['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            throw new LlmException(__('Groq returned an empty response.'));
        }

        return trim($content);
    }

    /**
     * Determine how long to wait before the next retry.
     * Honors Groq's Retry-After header when present, otherwise uses exponential backoff.
     *
     * @param int $attempt Zero-based attempt index
     * @return int Seconds to wait (capped to keep the request responsive)
     */
    private function getRetryDelay(int $attempt): int
    {
        $retryAfter = 0;

        try {
            $headers = $this->curl->getHeaders();
            if (isset($headers['retry-after'])) {
                $retryAfter = (int) ceil((float) $headers['retry-after']);
            } elseif (isset($headers['Retry-After'])) {
                $retryAfter = (int) ceil((float) $headers['Retry-After']);
            }
        } catch (\Exception $e) {
            $retryAfter = 0;
        }

        // Exponential backoff fallback: 2s, 4s, 8s ...
        $backoff = self::BASE_RETRY_DELAY * (2 ** $attempt);

        $wait = max($retryAfter, $backoff);

        // Cap the wait so we never block the request for too long
        return min($wait, 10);
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'groq';
    }
}
