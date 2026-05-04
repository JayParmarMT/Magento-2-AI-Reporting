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
    private const XML_PATH_OPENROUTER_KEY       = 'meetanshi_aireporting/general/openrouter_api_key';
    private const XML_PATH_OPENROUTER_MODEL     = 'meetanshi_aireporting/general/openrouter_model';
    private const XML_PATH_MAX_TOKENS           = 'meetanshi_aireporting/general/max_tokens';
    private const XML_PATH_MAX_ROWS             = 'meetanshi_aireporting/general/max_rows';
    private const XML_PATH_QUERY_TIMEOUT        = 'meetanshi_aireporting/general/query_timeout';
    private const XML_PATH_LOG_QUERIES          = 'meetanshi_aireporting/security/log_queries';

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

    public function isQueryLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LOG_QUERIES,
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
}
