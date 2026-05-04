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

        $this->curl->setHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey
        ]);
        $this->curl->setTimeout($this->config->getQueryTimeout());
        $this->curl->post(self::API_URL, $payload);

        $responseBody = $this->curl->getBody();
        $statusCode   = $this->curl->getStatus();

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Groq error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);
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
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'groq';
    }
}
