<?php
/**
 * Meetanshi AIReporting — Anthropic Claude provider (Messages API).
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

class ClaudeProvider implements ProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Anthropic API version header value. */
    private const API_VERSION = '2023-06-01';

    /** Retry transient failures (rate limit / server busy) a few times. */
    private const MAX_RETRIES = 3;

    /** HTTP statuses worth retrying. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504, 529];

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
        $apiKey = $this->config->getClaudeApiKey();

        if (empty($apiKey)) {
            throw new LlmException(__(
                'Claude API key is not configured. Get a key at https://console.anthropic.com and set it in '
                . 'Stores > Configuration > Meetanshi > AI Reporting.'
            ));
        }

        $systemInstruction = 'You are a Magento 2 database expert. Convert natural language questions into valid '
            . 'MySQL SELECT queries only. Never generate INSERT, UPDATE, DELETE, DROP, or ALTER statements. '
            . 'Return only the raw SQL query without any explanation, markdown, or code blocks.';

        $payload = $this->json->serialize([
            'model'      => $this->config->getClaudeModel(),
            'max_tokens' => $this->config->getMaxTokens(),
            'temperature' => 0.1,
            'system'     => $systemInstruction,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        $responseBody = '';
        $statusCode   = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->curl->setHeaders([
                'Content-Type'      => 'application/json',
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
            ]);
            $this->curl->setTimeout($this->config->getQueryTimeout());
            $this->curl->post(self::API_URL, $payload);

            $responseBody = $this->curl->getBody();
            $statusCode   = $this->curl->getStatus();

            if ($statusCode === 200) {
                break;
            }

            if (in_array($statusCode, self::RETRYABLE_STATUSES, true) && $attempt < self::MAX_RETRIES) {
                $this->logger->warning('Meetanshi AIReporting Claude transient error, retrying', [
                    'status'  => $statusCode,
                    'attempt' => $attempt + 1,
                ]);
                sleep(min(8, 2 ** $attempt)); // 1s, 2s, 4s
                continue;
            }

            break;
        }

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Claude error', [
                'status'   => $statusCode,
                'response' => $responseBody,
            ]);
            throw new LlmException($this->describeHttpError($statusCode));
        }

        try {
            $response = $this->json->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new LlmException(__('Failed to parse Claude response: %1', $e->getMessage()));
        }

        // Messages API returns content as an array of blocks; take the first text block.
        $content = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content = (string) ($block['text'] ?? '');
                break;
            }
        }

        if ($content === '') {
            throw new LlmException(__('Claude returned an empty response.'));
        }

        return trim($content);
    }

    /**
     * Human-friendly message for a non-200 Claude response.
     */
    private function describeHttpError(int $status): string
    {
        switch ($status) {
            case 429:
                return (string) __(
                    'Claude rate limit reached (HTTP 429). Please wait a moment and try again, '
                    . 'or check your usage limits at https://console.anthropic.com'
                );
            case 529:
            case 503:
            case 500:
            case 502:
            case 504:
                return (string) __(
                    'Claude is temporarily unavailable (HTTP %1). This is an Anthropic-side issue — '
                    . 'please try again in a few moments.',
                    $status
                );
            case 400:
                return (string) __('Claude rejected the request (HTTP 400). The selected model may be invalid — try Fetch Latest Models.');
            case 401:
            case 403:
                return (string) __('Claude authentication failed (HTTP %1). Please check your API key.', $status);
            case 404:
                return (string) __('Claude model not found (HTTP 404). Use "Fetch Latest Models" to select a current model.');
            default:
                return (string) __('Claude API returned HTTP %1. Please check your API key and quota.', $status);
        }
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'claude';
    }
}
