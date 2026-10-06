<?php
/**
 * Meetanshi AIReporting — OllamaProvider Unit Tests
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
use Meetanshi\AIReporting\Model\LLM\OllamaProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OllamaProviderTest extends TestCase
{
    private OllamaProvider $provider;
    private Curl|MockObject $curl;
    private Config|MockObject $config;

    protected function setUp(): void
    {
        $this->curl   = $this->createMock(Curl::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getOllamaModel')->willReturn('llama3.2');
        $this->config->method('getMaxTokens')->willReturn(1024);
        $this->config->method('getOllamaTimeout')->willReturn(240);

        $this->provider = new OllamaProvider(
            $this->config,
            $this->curl,
            new Json(),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testSendsContextSizeKeepAliveAndOptions(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434/');
        $sent = [];
        $this->curl->expects($this->once())->method('post')->willReturnCallback(
            function (string $url, string $body) use (&$sent) {
                $sent = ['url' => $url, 'body' => json_decode($body, true)];
            }
        );
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"message":{"content":"SELECT 1"}}');

        $result = $this->provider->complete(str_repeat('schema ', 2000));

        $this->assertSame('SELECT 1', $result);
        $this->assertSame('http://localhost:11434/api/chat', $sent['url']);
        $this->assertSame('30m', $sent['body']['keep_alive']);
        $this->assertSame(8192, $sent['body']['options']['num_ctx']);
        $this->assertSame(1024, $sent['body']['options']['num_predict']);
        $this->assertFalse($sent['body']['stream']);
    }

    public function testContextSizeGrowsInCoarseSteps(): void
    {
        $this->assertSame(8192, $this->provider->getContextSize(str_repeat('x', 13000), 1024));
        $this->assertSame(16384, $this->provider->getContextSize(str_repeat('x', 30000), 1024));
        $this->assertSame(32768, $this->provider->getContextSize(str_repeat('x', 500000), 1024));
    }

    public function testBaseUrlEnteredWithApiSuffixStillWorks(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434/api/chat');
        $this->curl->expects($this->once())->method('post')->with('http://localhost:11434/api/chat', $this->anything());
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"message":{"content":"SELECT 1"}}');

        $this->provider->complete('q');
    }

    public function testMissingModelListsInstalledModels(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(404, 200);
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            '{"error":"model \'llama3.2\' not found"}',
            '{"models":[{"name":"qwen2.5-coder:3b"},{"name":"qwen3-coder:latest"}]}'
        );

        try {
            $this->provider->complete('q');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertStringContainsString('"llama3.2" is not installed', $e->getMessage());
            $this->assertStringContainsString('qwen2.5-coder:3b, qwen3-coder:latest', $e->getMessage());
        }
    }

    public function testConnectionClosedDuringLoadPointsToMemory(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->curl->method('post')->willThrowException(new \Exception('Empty reply from server'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('run out of memory');

        $this->provider->complete('q');
    }

    public function testTimeoutGetsActionableMessage(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->curl->method('post')->willThrowException(new \Exception('Operation timed out after 240001 milliseconds'));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Ollama Request Timeout');

        $this->provider->complete('q');
    }

    public function testOutOfMemoryErrorIsExplained(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('{"error":"model requires more system memory (17.7 GiB) than is available (4.1 GiB)"}');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('smaller model');

        $this->provider->complete('q');
    }

    public function testStripsThinkingBlocks(): void
    {
        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            json_encode(['message' => ['content' => "<think>\nLet me plan the join…\n</think>\nSELECT COUNT(*) FROM sales_order"]])
        );

        $this->assertSame('SELECT COUNT(*) FROM sales_order', $this->provider->complete('q'));
    }
}
