<?php
/**
 * Meetanshi AIReporting — SystemInfoResponder Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use Meetanshi\AIReporting\Model\SystemInfoResponder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SystemInfoResponderTest extends TestCase
{
    private SystemInfoResponder $responder;

    protected function setUp(): void
    {
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            ['cache/frontend/default', null, [
                'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                'backend_options' => ['server' => 'redis', 'port' => '6379', 'database' => '0', 'password' => 'redis-secret'],
            ]],
            ['cache/frontend/page_cache', null, null],
            ['session/save', null, 'redis'],
            ['session/redis/host', null, 'redis'],
            ['session/redis/port', null, '6380'],
            ['session/redis/database', null, '2'],
            ['session/redis/password', null, 'session-secret'],
            ['queue/amqp/host', null, 'rabbitmq'],
            ['queue/amqp/port', null, '5672'],
            ['lock/provider', null, null],
            ['remote_storage/driver', null, null],
        ]);

        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn('production');

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['system/full_page_cache/caching_application', 'default', null, '2'],
            ['catalog/search/engine', 'default', null, 'opensearch'],
            ['catalog/search/opensearch_server_hostname', 'default', null, 'search.local'],
            ['catalog/search/opensearch_server_port', 'default', null, '9200'],
        ]);

        $cacheTypeList = $this->createStub(TypeListInterface::class);
        $cacheTypeList->method('getTypes')->willReturn([
            'config'    => new DataObject(['cache_type' => 'Configuration', 'status' => 1]),
            'full_page' => new DataObject(['cache_type' => 'Page Cache', 'status' => 0]),
            'layout'    => new DataObject(['cache_type' => 'Layouts', 'status' => 1]),
        ]);
        $cacheTypeList->method('getInvalidated')->willReturn(['layout' => new DataObject()]);

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getNames')->willReturn(['Magento_Catalog', 'Acme_Blog']);
        $moduleList->method('has')->willReturnCallback(static fn ($m) => in_array($m, ['Magento_Catalog', 'Acme_Blog'], true));

        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getNames')->willReturn(['Magento_Catalog', 'Magento_TwoFactorAuth', 'Acme_Blog', 'Acme_Old']);

        $moduleCatalog = $this->createStub(ModuleCatalog::class);
        $moduleCatalog->method('isThirdParty')->willReturnCallback(static fn ($m) => !str_starts_with($m, 'Magento_'));
        $moduleCatalog->method('getInfo')->willReturnCallback(static fn ($m) => [
            'name' => $m, 'enabled' => $m === 'Acme_Blog', 'setup_version' => '1.0.0',
            'composer_name' => '', 'composer_version' => $m === 'Acme_Blog' ? '3.2.1' : '', 'description' => '',
        ]);

        $this->responder = new SystemInfoResponder(
            $this->createStub(ProductMetadataInterface::class),
            $deploymentConfig,
            $state,
            $moduleList,
            $fullModuleList,
            $scopeConfig,
            $cacheTypeList,
            $moduleCatalog
        );
    }

    #[DataProvider('infrastructureQuestionProvider')]
    public function testInfrastructureComesFromEnvPhpWithoutSecrets(string $question): void
    {
        $answer = (string) $this->responder->answer($question);

        $this->assertStringContainsString('**Default cache backend:** Redis (redis:6379, database 0)', $answer);
        $this->assertStringContainsString('**Page cache backend:** File system (var/page_cache)', $answer);
        $this->assertStringContainsString('**Full page cache application:** Varnish', $answer);
        $this->assertStringContainsString('**Session storage:** Redis (redis:6380, database 2)', $answer);
        $this->assertStringContainsString('**Search engine:** OpenSearch (search.local:9200)', $answer);
        $this->assertStringContainsString('**Message queue:** RabbitMQ / AMQP (rabbitmq:5672)', $answer);
        $this->assertStringNotContainsString('secret', $answer);
    }

    public static function infrastructureQuestionProvider(): array
    {
        return [
            ['Is Redis enabled?'],
            ['Are we using Varnish?'],
            ['Where are sessions stored?'],
            ['Which search engine does the store use?'],
            ['Is RabbitMQ configured?'],
        ];
    }

    public function testCacheStatusListsEachCacheType(): void
    {
        $answer = (string) $this->responder->answer('Which cache types are disabled?');

        $this->assertStringContainsString('2 of 3 cache types enabled', $answer);
        $this->assertStringContainsString('❌ Page Cache (full_page) — disabled', $answer);
        $this->assertStringContainsString('✅ Layouts (layout) — ⚠️ invalidated, needs refresh', $answer);
    }

    public function testDisabledModules(): void
    {
        $answer = (string) $this->responder->answer('Which modules are disabled?');

        $this->assertStringContainsString('**Disabled Modules (2)**', $answer);
        $this->assertStringContainsString('- Acme_Old (third-party)', $answer);
        $this->assertStringContainsString('- Magento_TwoFactorAuth (core)', $answer);
    }

    public function testThirdPartyModulesIncludeVersionAndDisabledOnes(): void
    {
        $answer = (string) $this->responder->answer('Which third-party extensions are installed?');

        $this->assertStringContainsString('(2 installed, 1 enabled)', $answer);
        $this->assertStringContainsString('- Acme_Blog — v3.2.1', $answer);
        $this->assertStringContainsString('- Acme_Old — v1.0.0 — ❌ disabled', $answer);
    }

    public function testStoreDataQuestionsAreNotAnswered(): void
    {
        $this->assertNull($this->responder->answer('What is the total revenue this month?'));
        $this->assertNull($this->responder->answer('Is the CDN configured?'));
    }
}
