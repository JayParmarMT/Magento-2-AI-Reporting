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
    private const SYSTEM_PROMPT = 'You are a Magento 2 database expert. Convert natural language questions into valid MySQL SELECT queries only. Never generate INSERT, UPDATE, DELETE, DROP, or ALTER statements. Return only the raw SQL query without any explanation, markdown, or code blocks.';

    /**
     * Context window (tokens). Ollama silently drops the start of a prompt that does not fit its
     * context — which would cut off the instructions and schema. The size moves in coarse steps
     * because every change of num_ctx makes Ollama reload the model, and a stable size lets it
     * reuse the cached schema prefix between questions.
     */
    private const CONTEXT_STEP = 8192;
    private const MAX_CONTEXT  = 32768;

    /**
     * Keep the model in memory between admin questions (loading a model on CPU is slow).
     */
    private const KEEP_ALIVE = '30m';

    /**
     * Ollama runs locally — completely free, no API key needed.
     * Install: https://ollama.com
     * Then run: ollama pull <model>  (it must fit in the machine's free RAM/VRAM)
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
        $baseUrl = $this->getBaseUrl();
        $model   = trim($this->config->getOllamaModel());

        if ($baseUrl === '') {
            throw new LlmException(__(
                'Ollama base URL is not configured. Default is http://localhost:11434 — install Ollama from https://ollama.com'
            ));
        }

        $maxTokens = $this->config->getMaxTokens();
        $payload = $this->json->serialize([
            'model'      => $model,
            'messages'   => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ],
            'stream'     => false,
            'keep_alive' => self::KEEP_ALIVE,
            'options'    => [
                'temperature' => 0.1,
                'num_predict' => $maxTokens,
                'num_ctx'     => $this->getContextSize(self::SYSTEM_PROMPT . $prompt, $maxTokens),
            ],
        ]);

        $this->curl->setHeaders(['Content-Type' => 'application/json']);
        $this->curl->setTimeout($this->config->getOllamaTimeout());

        try {
            $this->curl->post($baseUrl . '/api/chat', $payload);
        } catch (\Exception $e) {
            $this->logger->error('Meetanshi AIReporting Ollama connection error', ['error' => $e->getMessage()]);
            $message = $e->getMessage();
            if (stripos($message, 'timed out') !== false) {
                throw new LlmException(__(
                    'Ollama did not answer within %1 seconds. Local models on CPU can be slow — increase "Ollama Request Timeout" or use a smaller model.',
                    $this->config->getOllamaTimeout()
                ));
            }
            if (stripos($message, 'Empty reply') !== false || stripos($message, 'reset by peer') !== false) {
                throw new LlmException($this->describeError(0, '', $baseUrl, $model));
            }
            throw new LlmException(__(
                'Could not connect to Ollama at %1 (%2). Make sure Ollama is running (ollama serve).',
                $baseUrl,
                $message
            ));
        }

        $responseBody = $this->curl->getBody();
        $statusCode   = $this->curl->getStatus();

        if ($statusCode !== 200) {
            $this->logger->error('Meetanshi AIReporting Ollama error', [
                'status'   => $statusCode,
                'response' => $responseBody
            ]);
            throw new LlmException($this->describeError($statusCode, $responseBody, $baseUrl, $model));
        }

        try {
            $response = $this->json->unserialize($responseBody);
        } catch (\Exception $e) {
            throw new LlmException(__('Failed to parse Ollama response: %1', $e->getMessage()));
        }

        $content = $this->stripThinking((string) ($response['message']['content'] ?? ''));

        if ($content === '') {
            throw new LlmException(__('Ollama returned an empty response. Make sure the model is loaded.'));
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function getProviderCode(): string
    {
        return 'ollama';
    }

    /**
     * Base URL without trailing slash; tolerates a URL entered with /api or /api/chat.
     */
    private function getBaseUrl(): string
    {
        $url = rtrim(trim($this->config->getOllamaBaseUrl()), '/');

        return (string) preg_replace('#/api(/chat)?$#', '', $url);
    }

    /**
     * Context window large enough for the prompt plus the answer (≈3 characters per token),
     * rounded up to 8192 / 16384 / 32768.
     */
    public function getContextSize(string $text, int $maxTokens): int
    {
        $needed = (int) ceil(strlen($text) / 3) + $maxTokens + 256;
        $size   = (int) (ceil($needed / self::CONTEXT_STEP) * self::CONTEXT_STEP);

        return max(self::CONTEXT_STEP, min(self::MAX_CONTEXT, $size));
    }

    /**
     * Turn Ollama's error response into an actionable message.
     */
    private function describeError(int $statusCode, string $body, string $baseUrl, string $model): \Magento\Framework\Phrase
    {
        $error = '';
        try {
            $decoded = $this->json->unserialize($body);
            $error   = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
        } catch (\Exception $e) {
            $error = trim(strip_tags($body));
        }

        if ($statusCode === 404 && stripos($error, 'not found') !== false) {
            $installed = $this->getInstalledModels($baseUrl);

            return $installed
                ? __(
                    'Ollama model "%1" is not installed. Installed models: %2. Set "Ollama Model Name" to one of them, or run: ollama pull %1',
                    $model,
                    implode(', ', $installed)
                )
                : __('Ollama model "%1" is not installed and no models are available. Run: ollama pull %1', $model);
        }

        if (stripos($error, 'memory') !== false) {
            return __(
                'Ollama cannot load model "%1": %2. Choose a smaller model that fits in this machine\'s free memory.',
                $model,
                $error
            );
        }

        if ($statusCode === 0) {
            return __(
                'Ollama at %1 closed the connection without answering. It may have run out of memory while loading "%2" — check "journalctl -u ollama" and try a smaller model.',
                $baseUrl,
                $model
            );
        }

        return __('Ollama returned HTTP %1: %2', $statusCode, $error !== '' ? $error : __('no details'));
    }

    /**
     * Names of the models installed in Ollama (empty on any failure).
     *
     * @return string[]
     */
    private function getInstalledModels(string $baseUrl): array
    {
        try {
            $this->curl->setTimeout(5);
            $this->curl->get($baseUrl . '/api/tags');
            if ($this->curl->getStatus() !== 200) {
                return [];
            }
            $data = $this->json->unserialize($this->curl->getBody());

            return array_values(array_filter(array_map(
                fn ($m) => is_array($m) ? (string) ($m['name'] ?? '') : '',
                is_array($data) ? ($data['models'] ?? []) : []
            )));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Remove <think>…</think> reasoning that thinking models (qwen3, deepseek-r1, …) may prepend.
     */
    private function stripThinking(string $content): string
    {
        return trim((string) preg_replace('#<think>.*?</think>#s', '', $content));
    }
}
