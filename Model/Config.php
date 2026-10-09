<?php
/**
 * Meetanshi AIReporting
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED              = 'meetanshi_aireporting/general/enabled';
    private const XML_PATH_LLM_PROVIDER         = 'meetanshi_aireporting/general/llm_provider';
    private const XML_PATH_OPENAI_KEY           = 'meetanshi_aireporting/general/openai_api_key';
    private const XML_PATH_OPENAI_MODEL         = 'meetanshi_aireporting/general/openai_model';
    private const XML_PATH_GEMINI_KEY           = 'meetanshi_aireporting/general/gemini_api_key';
    private const XML_PATH_GEMINI_MODEL         = 'meetanshi_aireporting/general/gemini_model';
    private const XML_PATH_GROQ_KEY             = 'meetanshi_aireporting/general/groq_api_key';
    private const XML_PATH_GROQ_MODEL           = 'meetanshi_aireporting/general/groq_model';
    private const XML_PATH_OLLAMA_URL           = 'meetanshi_aireporting/general/ollama_base_url';
    private const XML_PATH_OLLAMA_MODEL         = 'meetanshi_aireporting/general/ollama_model';
    private const XML_PATH_OLLAMA_TIMEOUT       = 'meetanshi_aireporting/general/ollama_timeout';
    private const XML_PATH_OPENROUTER_KEY       = 'meetanshi_aireporting/general/openrouter_api_key';
    private const XML_PATH_OPENROUTER_MODEL     = 'meetanshi_aireporting/general/openrouter_model';
    private const XML_PATH_CLAUDE_KEY           = 'meetanshi_aireporting/general/claude_api_key';
    private const XML_PATH_CLAUDE_MODEL         = 'meetanshi_aireporting/general/claude_model';
    private const XML_PATH_MAX_TOKENS           = 'meetanshi_aireporting/general/max_tokens';
    private const XML_PATH_MAX_ROWS             = 'meetanshi_aireporting/general/max_rows';
    private const XML_PATH_QUERY_TIMEOUT        = 'meetanshi_aireporting/general/query_timeout';
    private const XML_PATH_LLM_TIMEOUT          = 'meetanshi_aireporting/general/llm_timeout';
    private const XML_PATH_LOG_QUERIES          = 'meetanshi_aireporting/security/log_queries';
    private const XML_PATH_SHARE_RESULTS        = 'meetanshi_aireporting/security/share_results_with_llm';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getLlmProvider(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_LLM_PROVIDER,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Model id of the selected provider.
     */
    public function getActiveModel(): string
    {
        return match ($this->getLlmProvider()) {
            'openai'     => $this->getOpenAiModel(),
            'gemini'     => $this->getGeminiModel(),
            'groq'       => $this->getGroqModel(),
            'ollama'     => $this->getOllamaModel(),
            'openrouter' => $this->getOpenRouterModel(),
            'claude'     => $this->getClaudeModel(),
            default      => '',
        };
    }

    /**
     * Whether the selected provider has what it needs to be called (an API key, or Ollama's URL).
     * Says nothing about whether the key is valid — use "Test Connection" for that.
     */
    public function isActiveProviderConfigured(): bool
    {
        return match ($this->getLlmProvider()) {
            'openai'     => $this->getOpenAiApiKey() !== '',
            'gemini'     => $this->getGeminiApiKey() !== '',
            'groq'       => $this->getGroqApiKey() !== '',
            'ollama'     => $this->getOllamaBaseUrl() !== '',
            'openrouter' => $this->getOpenRouterApiKey() !== '',
            'claude'     => $this->getClaudeApiKey() !== '',
            default      => false,
        };
    }

    public function getOpenAiApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPENAI_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getOpenAiModel(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPENAI_MODEL,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getGeminiApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_GEMINI_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getGeminiModel(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GEMINI_MODEL,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getMaxTokens(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PATH_MAX_TOKENS,
            ScopeInterface::SCOPE_STORE
        ) ?: 1024);
    }

    public function getMaxRows(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PATH_MAX_ROWS,
            ScopeInterface::SCOPE_STORE
        ) ?: 500);
    }

    public function getQueryTimeout(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PATH_QUERY_TIMEOUT,
            ScopeInterface::SCOPE_STORE
        ) ?: 30);
    }

    /**
     * HTTP timeout (seconds) for hosted AI providers. Separate from the database query timeout:
     * a model that thinks before answering needs much longer than a report query.
     */
    public function getLlmTimeout(): int
    {
        return max(10, (int) ($this->scopeConfig->getValue(
            self::XML_PATH_LLM_TIMEOUT,
            ScopeInterface::SCOPE_STORE
        ) ?: 120));
    }

    public function isQueryLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LOG_QUERIES,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Whether query result rows may be sent to the LLM provider (Chat answers).
     */
    public function isResultSharingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHARE_RESULTS,
            ScopeInterface::SCOPE_STORE
        );
    }

    // ── Groq ──────────────────────────────────────────────────────────────

    public function getGroqApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_GROQ_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getGroqModel(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_GROQ_MODEL,
            ScopeInterface::SCOPE_STORE
        ) ?: 'llama-3.3-70b-versatile');
    }

    // ── Ollama ────────────────────────────────────────────────────────────

    public function getOllamaBaseUrl(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_OLLAMA_URL,
            ScopeInterface::SCOPE_STORE
        ) ?: 'http://localhost:11434');
    }

    public function getOllamaModel(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_OLLAMA_MODEL,
            ScopeInterface::SCOPE_STORE
        ) ?: 'llama3.2');
    }

    /**
     * HTTP timeout for Ollama (seconds). Local models need longer than hosted APIs:
     * the first request also loads the model into memory.
     */
    public function getOllamaTimeout(): int
    {
        return max(10, (int) ($this->scopeConfig->getValue(
            self::XML_PATH_OLLAMA_TIMEOUT,
            ScopeInterface::SCOPE_STORE
        ) ?: 240));
    }

    // ── OpenRouter ────────────────────────────────────────────────────────

    public function getOpenRouterApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_OPENROUTER_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getOpenRouterModel(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_OPENROUTER_MODEL,
            ScopeInterface::SCOPE_STORE
        ) ?: 'meta-llama/llama-3.1-8b-instruct:free');
    }

    // ── Claude (Anthropic) ────────────────────────────────────────────────

    public function getClaudeApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CLAUDE_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getClaudeModel(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_CLAUDE_MODEL,
            ScopeInterface::SCOPE_STORE
        ) ?: 'claude-opus-5-5');
    }
}
