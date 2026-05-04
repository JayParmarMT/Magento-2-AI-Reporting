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

class OpenAIProvider implements ProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

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
        $apiKey = $this->config->getOpenAiApiKey();

        if (empty($apiKey)) {
            throw new LlmException(__('OpenAI API key is not configured. Please set it in Stores > Configuration > Meetanshi > AI Reporting.'));
        }

        $payload = $this->json->serialize([
            'model'      => $this->config->getOpenAiModel(),
            'messages'   => [
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
            $this->logger->error('Meetanshi AIReporting OpenAI error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);
            throw new LlmException(__('OpenAI API returned HTTP %1. Please check your API key and quota.', $statusCode));
        }

        try {
            $response = $this->json->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new LlmException(__('Failed to parse OpenAI response: %1', $e->getMessage()));
        }

        $content = $response['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            throw new LlmException(__('OpenAI returned an empty response.'));
        }

        return trim($content);
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'openai';
    }
}
