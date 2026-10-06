<?php
/**
 * Meetanshi AIReporting — fetches the live model list from each provider's API.
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
use Psr\Log\LoggerInterface;

class ModelFetcher
{
    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return the available chat/completion models for a provider.
     *
     * @param string $provider
     * @return array{success: bool, models?: array<int, array{value:string,label:string}>, message?: string}
     */
    public function fetchModels(string $provider): array
    {
        try {
            switch ($provider) {
                case 'groq':
                    return $this->fetchGroq();
                case 'gemini':
                    return $this->fetchGemini();
                case 'openai':
                    return $this->fetchOpenAi();
                case 'openrouter':
                    return $this->fetchOpenRouter();
                case 'ollama':
                    return $this->fetchOllama();
                case 'claude':
                    return $this->fetchClaude();
                default:
                    return ['success' => false, 'message' => (string) __('Unknown provider.')];
            }
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting ModelFetcher: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function fetchGroq(): array
    {
        $key = $this->config->getGroqApiKey();
        if ($key === '') {
            return $this->noKey('Groq');
        }

        $this->curl->setHeaders(['Authorization' => 'Bearer ' . $key]);
        $this->curl->setTimeout(15);
        $this->curl->get('https://api.groq.com/openai/v1/models');

        return $this->parseOpenAiStyle($this->curl->getStatus(), $this->curl->getBody(), 'Groq', true);
    }

    private function fetchOpenAi(): array
    {
        $key = $this->config->getOpenAiApiKey();
        if ($key === '') {
            return $this->noKey('OpenAI');
        }

        $this->curl->setHeaders(['Authorization' => 'Bearer ' . $key]);
        $this->curl->setTimeout(15);
        $this->curl->get('https://api.openai.com/v1/models');

        // Keep only GPT chat models to avoid embeddings/whisper/tts noise
        return $this->parseOpenAiStyle($this->curl->getStatus(), $this->curl->getBody(), 'OpenAI', false, 'gpt');
    }

    private function fetchOpenRouter(): array
    {
        $key = $this->config->getOpenRouterApiKey();
        $headers = [];
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $this->curl->setHeaders($headers);
        $this->curl->setTimeout(15);
        $this->curl->get('https://openrouter.ai/api/v1/models');

        return $this->parseOpenAiStyle($this->curl->getStatus(), $this->curl->getBody(), 'OpenRouter', false);
    }

    private function fetchGemini(): array
    {
        $key = $this->config->getGeminiApiKey();
        if ($key === '') {
            return $this->noKey('Gemini');
        }

        $this->curl->setHeaders(['x-goog-api-key' => $key]);
        $this->curl->setTimeout(15);
        $this->curl->get('https://generativelanguage.googleapis.com/v1beta/models');

        if ($this->curl->getStatus() !== 200) {
            return $this->httpError('Gemini', $this->curl->getStatus());
        }

        $data   = $this->json->unserialize($this->curl->getBody());
        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (!in_array('generateContent', $methods, true)) {
                continue;
            }
            $id = str_replace('models/', '', (string) ($m['name'] ?? ''));
            if ($id === '') {
                continue;
            }
            $models[] = ['value' => $id, 'label' => $m['displayName'] ?? $id];
        }

        return $this->result($models, 'Gemini');
    }

    private function fetchOllama(): array
    {
        $base = rtrim($this->config->getOllamaBaseUrl(), '/');
        $this->curl->setTimeout(10);
        $this->curl->get($base . '/api/tags');

        if ($this->curl->getStatus() !== 200) {
            return [
                'success' => false,
                'message' => (string) __('Could not reach Ollama at %1. Make sure it is running.', $base),
            ];
        }

        $data   = $this->json->unserialize($this->curl->getBody());
        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $name = (string) ($m['name'] ?? $m['model'] ?? '');
            if ($name !== '') {
                $models[] = ['value' => $name, 'label' => $name];
            }
        }

        return $this->result($models, 'Ollama');
    }

    /**
     * Anthropic exposes GET /v1/models. If the key is missing or the call fails,
     * fall back to a known static list so the dropdown is still usable.
     */
    private function fetchClaude(): array
    {
        $key = $this->config->getClaudeApiKey();
        if ($key === '') {
            return $this->claudeStaticFallback(
                (string) __('No Claude API key saved — showing the built-in model list.')
            );
        }

        $this->curl->setHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ]);
        $this->curl->setTimeout(15);
        $this->curl->get('https://api.anthropic.com/v1/models?limit=100');

        if ($this->curl->getStatus() !== 200) {
            return $this->claudeStaticFallback(
                (string) __('Claude API returned HTTP %1 — showing the built-in model list.', $this->curl->getStatus())
            );
        }

        $data   = $this->json->unserialize($this->curl->getBody());
        $models = [];
        foreach ($data['data'] ?? [] as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $models[] = ['value' => $id, 'label' => $m['display_name'] ?? $id];
        }

        if (empty($models)) {
            return $this->claudeStaticFallback(
                (string) __('Claude returned no models — showing the built-in model list.')
            );
        }

        return $this->result($models, 'Claude');
    }

    /**
     * Built-in Claude model list used when the live API cannot be queried.
     */
    private function claudeStaticFallback(string $message): array
    {
        $models = [
            ['value' => 'claude-3-5-haiku-latest',  'label' => 'Claude 3.5 Haiku (Fast)'],
            ['value' => 'claude-3-5-sonnet-latest', 'label' => 'Claude 3.5 Sonnet'],
            ['value' => 'claude-3-7-sonnet-latest', 'label' => 'Claude 3.7 Sonnet'],
            ['value' => 'claude-sonnet-4-0',        'label' => 'Claude Sonnet 4'],
            ['value' => 'claude-opus-4-0',          'label' => 'Claude Opus 4'],
        ];

        return ['success' => true, 'models' => $models, 'message' => $message];
    }

    /**
     * Parse an OpenAI-compatible /models response (Groq, OpenAI, OpenRouter).
     */
    private function parseOpenAiStyle(int $status, string $body, string $label, bool $excludeNonChat, ?string $mustContain = null): array
    {
        if ($status !== 200) {
            return $this->httpError($label, $status);
        }

        $data   = $this->json->unserialize($body);
        $models = [];
        $skip   = ['whisper', 'tts', 'embedding', 'guard', 'orpheus', 'lyria', 'moderation', 'dall-e', 'image'];

        foreach ($data['data'] ?? [] as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($mustContain !== null && stripos($id, $mustContain) === false) {
                continue;
            }
            if ($excludeNonChat) {
                $lower = strtolower($id);
                foreach ($skip as $bad) {
                    if (str_contains($lower, $bad)) {
                        continue 2;
                    }
                }
            }
            $models[] = ['value' => $id, 'label' => $m['name'] ?? $id];
        }

        return $this->result($models, $label);
    }

    private function result(array $models, string $label): array
    {
        if (empty($models)) {
            return ['success' => false, 'message' => (string) __('%1 returned no usable models.', $label)];
        }

        // Sort alphabetically for a predictable dropdown
        usort($models, static fn ($a, $b) => strcmp($a['value'], $b['value']));

        return ['success' => true, 'models' => array_values($models)];
    }

    private function noKey(string $label): array
    {
        return [
            'success' => false,
            'message' => (string) __('Please enter and save your %1 API key first, then click Fetch Models.', $label),
        ];
    }

    private function httpError(string $label, int $status): array
    {
        return [
            'success' => false,
            'message' => (string) __('%1 API returned HTTP %2. Check your API key.', $label, $status),
        ];
    }
}
