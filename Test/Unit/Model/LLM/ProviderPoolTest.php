<?php
/**
 * Meetanshi AIReporting — ProviderPool Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\LLM;

use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\LLM\ProviderInterface;
use Meetanshi\AIReporting\Model\LLM\ProviderPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderPoolTest extends TestCase
{
    private Config|MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
    }

    // ── getActiveProvider ────────────────────────────────────────────────

    public function testGetActiveProviderReturnsConfiguredProvider(): void
    {
        $groqProvider = $this->createMock(ProviderInterface::class);
        $groqProvider->method('getProviderCode')->willReturn('groq');

        $openaiProvider = $this->createMock(ProviderInterface::class);
        $openaiProvider->method('getProviderCode')->willReturn('openai');

        $this->config->method('getLlmProvider')->willReturn('groq');

        $pool = new ProviderPool($this->config, [
            'groq'   => $groqProvider,
            'openai' => $openaiProvider,
        ]);

        $result = $pool->getActiveProvider();
        $this->assertSame($groqProvider, $result);
    }

    public function testGetActiveProviderThrowsForUnregisteredProvider(): void
    {
        $this->config->method('getLlmProvider')->willReturn('unknown_provider');

        $pool = new ProviderPool($this->config, []);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('unknown_provider');

        $pool->getActiveProvider();
    }

    // ── getProvider ──────────────────────────────────────────────────────

    public function testGetProviderReturnsByCode(): void
    {
        $geminiProvider = $this->createMock(ProviderInterface::class);

        $pool = new ProviderPool($this->config, [
            'gemini' => $geminiProvider,
        ]);

        $this->assertSame($geminiProvider, $pool->getProvider('gemini'));
    }

    public function testGetProviderThrowsForUnregisteredCode(): void
    {
        $pool = new ProviderPool($this->config, []);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('not registered');

        $pool->getProvider('nonexistent');
    }

    // ── Multiple providers ───────────────────────────────────────────────

    public function testPoolSupportsAllFiveProviders(): void
    {
        $providers = [];
        foreach (['openai', 'gemini', 'groq', 'ollama', 'openrouter'] as $code) {
            $mock = $this->createMock(ProviderInterface::class);
            $mock->method('getProviderCode')->willReturn($code);
            $providers[$code] = $mock;
        }

        $pool = new ProviderPool($this->config, $providers);

        foreach (['openai', 'gemini', 'groq', 'ollama', 'openrouter'] as $code) {
            $this->assertSame($providers[$code], $pool->getProvider($code));
        }
    }

    public function testGetActiveProviderSwitchesBetweenProviders(): void
    {
        $groq   = $this->createMock(ProviderInterface::class);
        $openai = $this->createMock(ProviderInterface::class);

        // First call returns groq, second returns openai
        $this->config->method('getLlmProvider')
            ->willReturnOnConsecutiveCalls('groq', 'openai');

        $pool = new ProviderPool($this->config, [
            'groq'   => $groq,
            'openai' => $openai,
        ]);

        $this->assertSame($groq, $pool->getActiveProvider());
        $this->assertSame($openai, $pool->getActiveProvider());
    }
}
