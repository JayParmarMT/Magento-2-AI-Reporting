<?php
/**
 * Meetanshi AIReporting — Config Model Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Meetanshi\AIReporting\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private Config $config;
    private ScopeConfigInterface|MockObject $scopeConfig;
    private EncryptorInterface|MockObject $encryptor;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor   = $this->createMock(EncryptorInterface::class);

        $this->config = new Config($this->scopeConfig, $this->encryptor);
    }

    // ── isEnabled ─────────────────────────────────────────────────────────

    public function testIsEnabledReturnsTrue(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('meetanshi_aireporting/general/enabled', 'store')
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledReturnsFalse(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('meetanshi_aireporting/general/enabled', 'store')
            ->willReturn(false);

        $this->assertFalse($this->config->isEnabled());
    }

    // ── getLlmProvider ────────────────────────────────────────────────────

    public function testGetLlmProviderReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/llm_provider', 'store')
            ->willReturn('groq');

        $this->assertSame('groq', $this->config->getLlmProvider());
    }

    public function testGetLlmProviderReturnsEmptyStringWhenNull(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/llm_provider', 'store')
            ->willReturn(null);

        $this->assertSame('', $this->config->getLlmProvider());
    }

    // ── API Keys (encrypted) ─────────────────────────────────────────────

    public function testGetOpenAiApiKeyDecryptsValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/openai_api_key', 'store')
            ->willReturn('encrypted_key_123');

        $this->encryptor->method('decrypt')
            ->with('encrypted_key_123')
            ->willReturn('sk-real-api-key');

        $this->assertSame('sk-real-api-key', $this->config->getOpenAiApiKey());
    }

    public function testGetOpenAiApiKeyReturnsEmptyWhenNotSet(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/openai_api_key', 'store')
            ->willReturn('');

        $this->assertSame('', $this->config->getOpenAiApiKey());
    }

    public function testGetGeminiApiKeyDecryptsValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/gemini_api_key', 'store')
            ->willReturn('encrypted_gemini');

        $this->encryptor->method('decrypt')
            ->with('encrypted_gemini')
            ->willReturn('gemini-key-abc');

        $this->assertSame('gemini-key-abc', $this->config->getGeminiApiKey());
    }

    public function testGetGroqApiKeyDecryptsValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/groq_api_key', 'store')
            ->willReturn('encrypted_groq');

        $this->encryptor->method('decrypt')
            ->with('encrypted_groq')
            ->willReturn('groq-key-xyz');

        $this->assertSame('groq-key-xyz', $this->config->getGroqApiKey());
    }

    public function testGetOpenRouterApiKeyDecryptsValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/openrouter_api_key', 'store')
            ->willReturn('encrypted_or');

        $this->encryptor->method('decrypt')
            ->with('encrypted_or')
            ->willReturn('or-key-123');

        $this->assertSame('or-key-123', $this->config->getOpenRouterApiKey());
    }

    // ── Model selections ─────────────────────────────────────────────────

    public function testGetOpenAiModelReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/openai_model', 'store')
            ->willReturn('gpt-4o');

        $this->assertSame('gpt-4o', $this->config->getOpenAiModel());
    }

    public function testGetGeminiModelReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/gemini_model', 'store')
            ->willReturn('gemini-1.5-flash');

        $this->assertSame('gemini-1.5-flash', $this->config->getGeminiModel());
    }

    public function testGetGroqModelReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/groq_model', 'store')
            ->willReturn('');

        $this->assertSame('llama-3.3-70b-versatile', $this->config->getGroqModel());
    }

    public function testGetOllamaModelReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/ollama_model', 'store')
            ->willReturn('');

        $this->assertSame('llama3.2', $this->config->getOllamaModel());
    }

    public function testGetOpenRouterModelReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/openrouter_model', 'store')
            ->willReturn('');

        $this->assertSame('meta-llama/llama-3.1-8b-instruct:free', $this->config->getOpenRouterModel());
    }

    // ── Numeric settings ─────────────────────────────────────────────────

    public function testGetMaxTokensReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/max_tokens', 'store')
            ->willReturn('2048');

        $this->assertSame(2048, $this->config->getMaxTokens());
    }

    public function testGetMaxTokensReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/max_tokens', 'store')
            ->willReturn('');

        $this->assertSame(1024, $this->config->getMaxTokens());
    }

    public function testGetMaxRowsReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/max_rows', 'store')
            ->willReturn('1000');

        $this->assertSame(1000, $this->config->getMaxRows());
    }

    public function testGetMaxRowsReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/max_rows', 'store')
            ->willReturn('');

        $this->assertSame(500, $this->config->getMaxRows());
    }

    public function testGetQueryTimeoutReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/query_timeout', 'store')
            ->willReturn('60');

        $this->assertSame(60, $this->config->getQueryTimeout());
    }

    public function testGetQueryTimeoutReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/query_timeout', 'store')
            ->willReturn('');

        $this->assertSame(30, $this->config->getQueryTimeout());
    }

    // ── Query logging ────────────────────────────────────────────────────

    public function testIsQueryLoggingEnabledReturnsTrue(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('meetanshi_aireporting/security/log_queries', 'store')
            ->willReturn(true);

        $this->assertTrue($this->config->isQueryLoggingEnabled());
    }

    public function testIsQueryLoggingEnabledReturnsFalse(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('meetanshi_aireporting/security/log_queries', 'store')
            ->willReturn(false);

        $this->assertFalse($this->config->isQueryLoggingEnabled());
    }

    // ── Ollama URL ───────────────────────────────────────────────────────

    public function testGetOllamaBaseUrlReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/ollama_base_url', 'store')
            ->willReturn('http://custom-host:11434');

        $this->assertSame('http://custom-host:11434', $this->config->getOllamaBaseUrl());
    }

    public function testGetOllamaBaseUrlReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('meetanshi_aireporting/general/ollama_base_url', 'store')
            ->willReturn('');

        $this->assertSame('http://localhost:11434', $this->config->getOllamaBaseUrl());
    }
}
