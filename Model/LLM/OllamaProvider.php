<?php
/**
 * Meetanshi AIReporting — Ollama Provider (100% Free, Runs Locally)
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

class OllamaProvider implements ProviderInterface
{
    /**
     * Ollama runs locally — completely free, no API key needed.
     * Install: https://ollama.com
     * Then run: ollama pull llama3.2  (or any model)
     */
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
        $baseUrl = rtrim($this->config->getOllamaBaseUrl(), '/');

        if (empty($baseUrl)) {
            throw new LlmException(__(
                'Ollama base URL is not configured. Default is http://localhost:11434 — install Ollama from https://ollama.com'
            ));
        }

        $apiUrl = $baseUrl . '/api/chat';

        $payload = $this->json->serialize([
            'model'    => $this->config->getOllamaModel(),
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are a Magento 2 database expert. Convert natural language questions into valid MySQL SELECT queries only. Never generate INSERT, UPDATE, DELETE, DROP, or ALTER statements. Return only the raw SQL query without any explanation, markdown, or code blocks.'
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt
                ]
            ],
            'stream' => false
        ]);

        $this->curl->setHeaders(['Content-Type' => 'application/json']);
        $this->curl->setTimeout($this->config->getQueryTimeout());
        $this->curl->post($apiUrl, $payload);

        $responseBody = $this->curl->getBody();
        $statusCode   = $this->curl->getStatus();

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Ollama error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);
            throw new LlmException(__(
                'Ollama returned HTTP %1. Make sure Ollama is running locally (ollama serve) and the model is pulled.',
                $statusCode
            ));
        }

        try {
            $response = $this->json->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new LlmException(__('Failed to parse Ollama response: %1', $e->getMessage()));
        }

        $content = $response['message']['content'] ?? '';

        if (empty($content)) {
            throw new LlmException(__('Ollama returned an empty response. Make sure the model is loaded.'));
        }

        return trim($content);
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'ollama';
    }
}
