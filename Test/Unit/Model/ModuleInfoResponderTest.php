<?php
/**
 * Meetanshi AIReporting — ModuleInfoResponder Unit Tests
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\ResourceInterface as ModuleResource;
use Meetanshi\AIReporting\Model\ModuleInfoResponder;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ModuleInfoResponderTest extends TestCase
{
    private ModuleInfoResponder $responder;

    protected function setUp(): void
    {
        $catalog = $this->createStub(ModuleCatalog::class);
        $catalog->method('detect')->willReturnCallback(
            static fn (string $question) => str_contains(strtolower($question), 'size chart') ? ['Acme_SizeChart'] : []
        );
        $catalog->method('detectVendors')->willReturnCallback(
            static fn (string $question) => str_contains(strtolower($question), 'acme') ? ['Acme'] : []
        );
        $catalog->method('getModulesByVendor')->willReturn(['Acme_SizeChart', 'Acme_Old']);
        $catalog->method('isEnabled')->willReturnCallback(static fn (string $module) => $module === 'Acme_SizeChart');
        $catalog->method('isThirdParty')->willReturn(true);
        $catalog->method('getInfo')->willReturnCallback(static fn (string $module) => [
            'name'             => $module,
            'enabled'          => $module === 'Acme_SizeChart',
            'setup_version'    => '1.0.0',
            'composer_name'    => 'acme/size-chart',
            'composer_version' => '2.1.0',
            'description'      => $module === 'Acme_SizeChart' ? 'Size Chart Pro' : '',
        ]);
        $catalog->method('getOwnedTables')->willReturn(['acme_sizechart']);
        $catalog->method('getExtendedColumns')->willReturn(['sales_order' => ['acme_flag']]);
        $catalog->method('getConfigSectionLabels')->willReturn(['Size Chart']);
        $catalog->method('getConfigFields')->willReturn([
            'sizechart/general/enabled' => ['label' => 'General › Enable Extension', 'yesno' => true, 'secret' => false],
            'sizechart/general/api_key' => ['label' => 'General › API Key', 'yesno' => false, 'secret' => true],
            'sizechart/design/color'    => ['label' => 'Design › Border Color', 'yesno' => false, 'secret' => false],
            'sizechart/design/label'    => ['label' => 'Design › Label', 'yesno' => false, 'secret' => false],
        ]);

        $moduleResource = $this->createStub(ModuleResource::class);
        $moduleResource->method('getDbVersion')->willReturn('1.0.0');
        $moduleResource->method('getDataVersion')->willReturn('1.0.0');

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['sizechart/general/enabled', 'default', null, '1'],
            ['sizechart/general/api_key', 'default', null, 'secret-value'],
            ['sizechart/design/color', 'default', null, '#3399cc'],
            ['sizechart/design/label', 'default', null, null],
        ]);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn(['acme_sizechart' => 3]);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('3', '1');
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->responder = new ModuleInfoResponder(
            $catalog,
            $moduleResource,
            $scopeConfig,
            $resourceConnection,
            new SqlGuard($this->createStub(DeploymentConfig::class)),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testEnabledQuestionShowsStatusAndTheSwitch(): void
    {
        $answer = (string) $this->responder->answer('Is the size chart extension enabled?');

        $this->assertStringContainsString('**Acme_SizeChart** — Size Chart Pro', $answer);
        $this->assertStringContainsString('✅ Enabled', $answer);
        $this->assertStringContainsString('General › Enable Extension: **Yes**', $answer);
        $this->assertStringNotContainsString('Border Color', $answer);
        $this->assertStringContainsString('…and 3 more settings', $answer);
    }

    public function testSettingsQuestionListsAllSettingsAndHidesSecrets(): void
    {
        $answer = (string) $this->responder->answer('Show the size chart configuration');

        $this->assertStringContainsString('Design › Border Color: #3399cc', $answer);
        $this->assertStringContainsString('General › API Key: _[hidden]_', $answer);
        $this->assertStringNotContainsString('secret-value', $answer);
        $this->assertStringContainsString('Design › Label: _not set_', $answer);
        $this->assertStringContainsString('1 of these settings are overridden', $answer);
    }

    public function testVersionQuestionSummarisesSettings(): void
    {
        $answer = (string) $this->responder->answer('Which version of the size chart is installed?');

        $this->assertStringContainsString('package 2.1.0', $answer);
        $this->assertStringContainsString('acme_sizechart (3 rows)', $answer);
        $this->assertStringContainsString('sales_order (acme_flag)', $answer);
        $this->assertStringContainsString('**Configuration:** 4 settings under Stores › Configuration › Size Chart', $answer);
    }

    public function testVendorQuestionListsTheVendorsModules(): void
    {
        $answer = (string) $this->responder->answer('Which Acme modules are installed?');

        $this->assertStringContainsString('**Acme modules (2 installed, 1 enabled)**', $answer);
        $this->assertStringContainsString('**Acme_SizeChart** — ✅ Enabled · v2.1.0 · Size Chart Pro', $answer);
        $this->assertStringContainsString('**Acme_Old** — ❌ Disabled', $answer);
    }

    #[DataProvider('dataQuestionProvider')]
    public function testDataQuestionsAreLeftToSql(string $question): void
    {
        $this->assertNull($this->responder->answer($question));
    }

    public static function dataQuestionProvider(): array
    {
        return [
            'count records'      => ['How many size charts are enabled?'],
            'list records'       => ['Show all size charts created this month'],
            'no module named'    => ['Is the newsletter enabled?'],
            'vendor without ask' => ['Top Acme customers'],
        ];
    }
}
