<?php
/**
 * Meetanshi AIReporting — ClaudeProvider Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\LLM;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Exception\LlmException;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\LLM\ClaudeProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClaudeProviderTest extends TestCase
{
    private Curl|MockObject $curl;
    private Config|MockObject $config;
    private array $sentHeaders = [];
    private array $sentBody = [];

    protected function setUp(): void
    {
        $this->curl   = $this->createMock(Curl::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getClaudeApiKey')->willReturn('sk-test');
        $this->config->method('getMaxTokens')->willReturn(1024);
        $this->config->method('getLlmTimeout')->willReturn(120);

        $this->curl->method('setHeaders')->willReturnCallback(function (array $headers) {
            $this->sentHeaders = $headers;
        });
        $this->curl->method('post')->willReturnCallback(function (string $url, string $body) {
            $this->sentBody = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
    }

    private function provider(string $model, string $responseBody): ClaudeProvider
    {
        $this->config->method('getClaudeModel')->willReturn($model);
        $this->curl->method('getBody')->willReturn($responseBody);

        return new ClaudeProvider($this->config, $this->curl, new Json(), $this->createMock(LoggerInterface::class));
    }

    public function testSystemPromptIsCachedAndCurrentModelsGetNoTemperature(): void
    {
        $provider = $this->provider('claude-opus-5-5', '{"content":[{"type":"text","text":"SELECT 1"}],"stop_reason":"end_turn"}');

        $this->assertSame('SELECT 1', $provider->complete('question', 'schema and rules'));
        $this->assertSame(
            [['type' => 'text', 'text' => 'schema and rules', 'cache_control' => ['type' => 'ephemeral']]],
            $this->sentBody['system']
        );
        $this->assertArrayNotHasKey('temperature', $this->sentBody);
        // Thinking counts against max_tokens: room for it is kept
        $this->assertSame(16000, $this->sentBody['max_tokens']);
        $this->assertSame('default', $this->sentBody['fallbacks']);
        $this->assertSame('server-side-fallback-2026-07-01', $this->sentHeaders['anthropic-beta']);
    }

    public function testOlderModelsKeepTemperatureAndConfiguredLimit(): void
    {
        $provider = $this->provider('claude-haiku-4-5', '{"content":[{"type":"text","text":"SELECT 1"}]}');

        $provider->complete('question');

        $this->assertSame(0.1, $this->sentBody['temperature']);
        $this->assertSame(1024, $this->sentBody['max_tokens']);
        $this->assertArrayNotHasKey('system', $this->sentBody);
        $this->assertArrayNotHasKey('fallbacks', $this->sentBody);
        $this->assertArrayNotHasKey('anthropic-beta', $this->sentHeaders);
    }

    public function testOpus47GetsNoTemperature(): void
    {
        $provider = $this->provider('claude-opus-4-7', '{"content":[{"type":"text","text":"SELECT 1"}]}');

        $provider->complete('question');

        $this->assertArrayNotHasKey('temperature', $this->sentBody);
    }

    public function testRefusalIsReported(): void
    {
        $provider = $this->provider('claude-opus-5-5', '{"content":[],"stop_reason":"refusal","stop_details":null}');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('declined');
        $provider->complete('question', 'system');
    }

    public function testRunningOutOfTokensIsExplained(): void
    {
        $provider = $this->provider('claude-haiku-4-5', '{"content":[],"stop_reason":"max_tokens"}');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Max Tokens');
        $provider->complete('question');
    }
}
