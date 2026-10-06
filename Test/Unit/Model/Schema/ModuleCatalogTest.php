<?php
/**
 * Meetanshi AIReporting — ModuleCatalog Unit Tests (fixture modules on disk)
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Test\Unit\Model\Schema;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use PHPUnit\Framework\TestCase;

class ModuleCatalogTest extends TestCase
{
    private const MODULES = [
        'Magento_Sales'             => ['enabled' => true],
        'Acme_SizeChart'            => ['enabled' => true],
        'Acme_Base'                 => ['enabled' => true],
        'Acme_EmailAttach'          => ['enabled' => false],
        'Vendorx_Braintree'         => ['enabled' => true],
        'Vendorx_BraintreeGiftCard' => ['enabled' => true],
    ];

    private string $root;
    private ModuleCatalog $catalog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aireporting_modules_' . uniqid();

        $this->writeFile('Magento_Sales/etc/db_schema.xml', $this->schemaXml([
            'sales_order' => ['entity_id', 'increment_id'],
            'sales_order_item' => ['item_id'],
        ]));

        $this->writeFile('Acme_SizeChart/etc/db_schema.xml', $this->schemaXml([
            'acme_sizechart' => ['chart_id', 'title', 'status'],
        ]));
        $this->writeFile('Acme_SizeChart/composer.json', json_encode([
            'name' => 'acme/magento-2-size-chart', 'version' => '2.1.0', 'description' => 'Magento 2 Size Chart Pro',
        ]));
        $this->writeFile('Acme_SizeChart/etc/adminhtml/system.xml', <<<'XML'
<?xml version="1.0"?>
<config>
    <system>
        <tab id="acme"><label>Acme</label></tab>
        <section id="sizechart">
            <label>Product <b>Size</b> Chart</label>
            <tab>acme</tab>
            <group id="general">
                <label>General</label>
                <field id="enabled" type="select">
                    <label>Enable Extension</label>
                    <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
                </field>
                <field id="api_key" type="obscure"><label>API Key</label></field>
                <group id="design">
                    <label>Design</label>
                    <field id="color"><label>Color</label></field>
                </group>
                <include path="Acme_SizeChart::system/extra.xml"/>
            </group>
        </section>
    </system>
</config>
XML);
        $this->writeFile('Acme_SizeChart/etc/adminhtml/system/extra.xml', <<<'XML'
<?xml version="1.0"?>
<include>
    <field id="custom">
        <label>Custom</label>
        <config_path>acme/custom/path</config_path>
    </field>
</include>
XML);

        // Adds a column to a core table only
        $this->writeFile('Acme_EmailAttach/etc/db_schema.xml', $this->schemaXml(['sales_order' => ['acme_attachment_sent']]));
        $this->writeFile('Acme_EmailAttach/composer.json', json_encode([
            'name' => 'acme/magento2-email-attachments', 'description' => 'Email Attachments',
        ]));

        $this->writeFile('Vendorx_Braintree/etc/db_schema.xml', $this->schemaXml(['vendorx_braintree_txn' => ['id']]));
        $this->writeFile('Vendorx_BraintreeGiftCard/etc/db_schema.xml', $this->schemaXml(['vendorx_braintree_giftcard' => ['id']]));

        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getNames')->willReturn(array_keys(self::MODULES));
        $fullModuleList->method('getOne')->willReturn(['setup_version' => '1.0.0']);

        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('has')->willReturnCallback(fn (string $name) => self::MODULES[$name]['enabled'] ?? false);

        $registrar = $this->createStub(ComponentRegistrarInterface::class);
        $registrar->method('getPath')->willReturnCallback(fn ($type, string $name) => $this->root . '/' . $name);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $this->catalog = new ModuleCatalog($fullModuleList, $moduleList, $registrar, new File(), $cache, new Json());
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testDetectsModuleByCode(): void
    {
        $this->assertSame(['Acme_SizeChart'], $this->catalog->detect('Tell me about acme_sizechart'));
        $this->assertSame(['Magento_Sales'], $this->catalog->detect('Is Magento_Sales enabled?'));
    }

    public function testDetectsThirdPartyModuleByNameAndAlias(): void
    {
        $this->assertSame(['Acme_SizeChart'], $this->catalog->detect('Is the size chart extension enabled?'));
        $this->assertSame(['Acme_SizeChart'], $this->catalog->detect('How many Acme size charts are active?'));
        $this->assertSame(['Acme_EmailAttach'], $this->catalog->detect('Which orders had email attachments sent?'));
    }

    public function testDetectsShortModuleNameOnlyWithVendor(): void
    {
        $this->assertSame([], $this->catalog->detect('Show the base price of products'));
        $this->assertSame(['Acme_Base'], $this->catalog->detect('What does the Acme base module do?'));
    }

    public function testCoreModulesAreOnlyDetectedByCode(): void
    {
        $this->assertSame([], $this->catalog->detect('How many sales this month?'));
    }

    public function testLongerMatchWins(): void
    {
        $this->assertSame(['Vendorx_BraintreeGiftCard'], $this->catalog->detect('Is braintree gift card enabled?'));
        $this->assertSame(['Vendorx_Braintree'], $this->catalog->detect('Is braintree enabled?'));
    }

    public function testDetectsVendors(): void
    {
        $this->assertSame(['Acme'], $this->catalog->detectVendors('Which Acme modules are installed?'));
        $this->assertSame([], $this->catalog->detectVendors('Which Magento modules are installed?'));
        $this->assertSame(['Acme_SizeChart', 'Acme_Base', 'Acme_EmailAttach'], $this->catalog->getModulesByVendor('acme'));
    }

    public function testTableOwnersAndAddedColumns(): void
    {
        $this->assertSame(['acme_sizechart'], $this->catalog->getOwnedTables('Acme_SizeChart'));
        $this->assertSame('Magento_Sales', $this->catalog->getTableOwner('sales_order'));
        $this->assertSame([], $this->catalog->getOwnedTables('Acme_EmailAttach'));
        $this->assertSame(['sales_order' => ['acme_attachment_sent']], $this->catalog->getExtendedColumns('Acme_EmailAttach'));
        $this->assertSame(
            ['sales_order' => ['acme_attachment_sent' => 'Acme_EmailAttach']],
            $this->catalog->getThirdPartyColumns()
        );
    }

    public function testInfoReadsComposerAndModuleList(): void
    {
        $info = $this->catalog->getInfo('Acme_SizeChart');

        $this->assertTrue($info['enabled']);
        $this->assertSame('2.1.0', $info['composer_version']);
        $this->assertSame('acme/magento-2-size-chart', $info['composer_name']);
        $this->assertSame('Magento 2 Size Chart Pro', $info['description']);
        $this->assertFalse($this->catalog->getInfo('Acme_EmailAttach')['enabled']);
    }

    public function testConfigFieldsFollowGroupsAndIncludes(): void
    {
        $fields = $this->catalog->getConfigFields('Acme_SizeChart');

        $this->assertSame(
            ['sizechart/general/enabled', 'sizechart/general/api_key', 'sizechart/general/design/color', 'acme/custom/path'],
            array_keys($fields)
        );
        $this->assertSame('General › Enable Extension', $fields['sizechart/general/enabled']['label']);
        $this->assertTrue($fields['sizechart/general/enabled']['yesno']);
        $this->assertTrue($fields['sizechart/general/api_key']['secret']);
        $this->assertSame('General › Design › Color', $fields['sizechart/general/design/color']['label']);
        $this->assertSame(['Product Size Chart'], $this->catalog->getConfigSectionLabels('Acme_SizeChart'));
    }

    public function testModuleWithoutFilesHasNoTablesOrSettings(): void
    {
        $this->assertSame([], $this->catalog->getOwnedTables('Acme_Base'));
        $this->assertSame([], $this->catalog->getConfigFields('Acme_Base'));
    }

    private function schemaXml(array $tables): string
    {
        $xml = '<?xml version="1.0"?><schema>';
        foreach ($tables as $table => $columns) {
            $xml .= '<table name="' . $table . '">';
            foreach ($columns as $column) {
                $xml .= '<column name="' . $column . '"/>';
            }
            $xml .= '</table>';
        }

        return $xml . '</schema>';
    }

    private function writeFile(string $path, string $content): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }
}
