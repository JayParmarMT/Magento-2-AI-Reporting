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
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s';

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
        $apiUrl = sprintf(self::API_URL, $model, $apiKey);

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

        $this->curl->setHeaders(['Content-Type' => 'application/json']);
        $this->curl->setTimeout($this->config->getQueryTimeout());
        $this->curl->post($apiUrl, $payload);

        $responseBody = $this->curl->getBody();
        $statusCode   = $this->curl->getStatus();

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Gemini error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);
            throw new LlmException(__('Gemini API returned HTTP %1. Please check your API key and quota.', $statusCode));
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
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'gemini';
    }
}
