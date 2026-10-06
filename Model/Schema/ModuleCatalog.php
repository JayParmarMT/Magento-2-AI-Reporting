<?php
/**
 * Meetanshi AIReporting — what every installed module (core or third-party) brings to the store
 *
 * Built from each module's own files: etc/db_schema.xml (tables it creates and columns it adds to
 * other modules' tables), composer.json (package, version, description) and etc/adminhtml/system.xml
 * (configuration sections and fields). Lets the chat recognise a module in a question — by its code
 * ("Meetanshi_SizeChart"), its name ("size chart", "email attachments") or its vendor ("Amasty") —
 * and use that module's real tables, columns and settings.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Schema;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;

class ModuleCatalog
{
    private const CACHE_ID       = 'meetanshi_aireporting_module_catalog';
    private const CACHE_LIFETIME = 86400;

    /**
     * Shortest name (letters only) that may identify a module on its own; shorter ones such as
     * "Base" or "Core" only count together with the vendor name.
     */
    private const MIN_ALIAS_LENGTH = 6;

    /**
     * Words removed from composer names/descriptions before they are used as module names.
     */
    private const NAME_NOISE = '/\b(magento\s*2|magento2|magento|m2|module|extension|for|by|the|plugin|free|pro)\b/i';

    /**
     * Names too generic to identify a single module.
     */
    private const GENERIC_ALIASES = [
        'general', 'catalog', 'customer', 'customers', 'payment', 'payments', 'shipping', 'sales', 'checkout',
        'advanced', 'security', 'reports', 'report', 'settings', 'configuration', 'product', 'products',
        'order', 'orders', 'email', 'emails', 'service', 'services', 'connector', 'integration', 'graphql',
    ];

    /**
     * @var array<string, array{tables: array<string, string[]>, composer_name: string,
     *     composer_version: string, description: string, aliases: string[]}>|null
     */
    private ?array $index = null;

    /**
     * @var array<string, array{owner: string, extenders: array<string, string[]>}>|null
     */
    private ?array $tableModules = null;

    public function __construct(
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleListInterface $moduleList,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly File $fileDriver,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * All declared modules (enabled and disabled), in load order.
     *
     * @return string[]
     */
    public function getModuleNames(): array
    {
        return array_keys($this->getIndex());
    }

    public function isEnabled(string $module): bool
    {
        return $this->moduleList->has($module);
    }

    public function isThirdParty(string $module): bool
    {
        return !str_starts_with($module, 'Magento_');
    }

    /**
     * Modules a question refers to, best match first.
     *
     * A module is recognised by its code ("Meetanshi_SizeChart", any module), and — third-party
     * modules only — by vendor + name ("meetanshi size chart"), by its name alone when it is
     * distinctive ("size chart"), or by its composer package / description / config section label
     * ("email attachments"). A match inside a longer match ("braintree" in "braintree gift card")
     * is dropped.
     *
     * @return string[]
     */
    public function detect(string $question): array
    {
        $lower   = strtolower($question);
        $compact = $this->compact($question);
        $words   = array_flip(preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($compact === '') {
            return [];
        }

        $found = []; // module => [score, matched text]
        foreach ($this->getIndex() as $module => $info) {
            if (str_contains($lower, strtolower($module))) {
                $found[$module] = [1000, $this->compact($module)];
                continue;
            }
            if (!$this->isThirdParty($module)) {
                continue;
            }

            [$vendor, $name] = array_pad(explode('_', $module, 2), 2, '');
            $vendorKey = $this->compact($vendor);
            $nameKey   = $this->compact($name);

            $candidates = [$vendorKey . $nameKey];
            if (strlen($nameKey) >= self::MIN_ALIAS_LENGTH && !in_array($nameKey, self::GENERIC_ALIASES, true)) {
                $candidates[] = $nameKey;
            }
            foreach ($info['aliases'] as $alias) {
                $candidates[] = $alias;
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && str_contains($compact, $candidate)
                    && strlen($candidate) > strlen($found[$module][1] ?? '')
                ) {
                    $found[$module] = [strlen($candidate), $candidate];
                }
            }

            // "the SMTP extension by Mageplaza": vendor word and module name both present, not adjacent
            if (!isset($found[$module]) && $vendorKey !== '' && isset($words[$vendorKey]) && $nameKey !== ''
                && str_contains($compact, $nameKey)
            ) {
                $found[$module] = [strlen($nameKey), $nameKey];
            }
        }

        // Drop matches contained in a longer match of another module
        foreach ($found as $module => [, $text]) {
            foreach ($found as $other => [, $otherText]) {
                if ($other !== $module && strlen($otherText) > strlen($text) && str_contains($otherText, $text)) {
                    unset($found[$module]);
                    break;
                }
            }
        }

        uasort($found, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_keys($found);
    }

    /**
     * Third-party vendor names that appear as a word in the question ("which Amasty modules …").
     *
     * @return string[]
     */
    public function detectVendors(string $question): array
    {
        $words   = array_flip(preg_split('/[^a-z0-9]+/', strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $vendors = [];
        foreach ($this->getModuleNames() as $module) {
            $vendor = strstr($module, '_', true) ?: $module;
            if ($this->isThirdParty($module) && strlen($vendor) >= 3 && isset($words[strtolower($vendor)])) {
                $vendors[$vendor] = true;
            }
        }

        return array_keys($vendors);
    }

    /**
     * @return string[]
     */
    public function getModulesByVendor(string $vendor): array
    {
        return array_values(array_filter(
            $this->getModuleNames(),
            static fn (string $module) => strcasecmp((string) strstr($module, '_', true), $vendor) === 0
        ));
    }

    /**
     * Tables the module creates (it is the first module to declare them).
     *
     * @return string[]
     */
    public function getOwnedTables(string $module): array
    {
        $tables = [];
        foreach ($this->getTableModules() as $table => $info) {
            if ($info['owner'] === $module) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Columns the module adds to tables created by other modules, e.g. ['sales_order' => ['my_flag']].
     *
     * @return array<string, string[]>
     */
    public function getExtendedColumns(string $module): array
    {
        $columns = [];
        foreach ($this->getTableModules() as $table => $info) {
            if (!empty($info['extenders'][$module])) {
                $columns[$table] = $info['extenders'][$module];
            }
        }

        return $columns;
    }

    /**
     * Columns third-party modules add to tables created by other modules: [table => [column => module]].
     *
     * @return array<string, array<string, string>>
     */
    public function getThirdPartyColumns(): array
    {
        $columns = [];
        foreach ($this->getTableModules() as $table => $info) {
            foreach ($info['extenders'] as $module => $moduleColumns) {
                if ($this->isThirdParty($module)) {
                    foreach ($moduleColumns as $column) {
                        $columns[$table][$column] = $module;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * The module that creates a table, or null when no db_schema.xml declares it.
     */
    public function getTableOwner(string $table): ?string
    {
        return $this->getTableModules()[strtolower($table)]['owner'] ?? null;
    }

    /**
     * Package, version and description of a module.
     *
     * @return array{name: string, enabled: bool, setup_version: string, composer_name: string,
     *     composer_version: string, description: string}
     */
    public function getInfo(string $module): array
    {
        $info = $this->getIndex()[$module] ?? null;
        $full = $this->fullModuleList->getOne($module) ?? [];

        return [
            'name'             => $module,
            'enabled'          => $this->isEnabled($module),
            'setup_version'    => (string) ($full['setup_version'] ?? ''),
            'composer_name'    => $info['composer_name'] ?? '',
            'composer_version' => $info['composer_version'] ?? '',
            'description'      => $info['description'] ?? '',
        ];
    }

    /**
     * Configuration fields the module declares in etc/adminhtml/system.xml (and the files it includes).
     *
     * @return array<string, array{label: string, yesno: bool, secret: bool}> keyed by config path
     */
    public function getConfigFields(string $module): array
    {
        $dir = $this->getModuleDir($module);
        if ($dir === null) {
            return [];
        }

        $document = $this->loadXml($dir . '/etc/adminhtml/system.xml');
        if ($document === null) {
            return [];
        }

        $fields = [];
        foreach ($this->childElements($document->documentElement) as $system) {
            if ($system->nodeName === 'system') {
                $this->collectFields($system, [], [], $fields, 0);
            }
        }

        return $fields;
    }

    /**
     * Labels of the configuration sections the module declares (Stores › Configuration pages).
     *
     * @return string[]
     */
    public function getConfigSectionLabels(string $module): array
    {
        $dir      = $this->getModuleDir($module);
        $document = $dir === null ? null : $this->loadXml($dir . '/etc/adminhtml/system.xml');
        if ($document === null) {
            return [];
        }

        $labels = [];
        foreach ($document->getElementsByTagName('section') as $section) {
            $label = $this->cleanLabel($this->childText($section, 'label'));
            if ($label !== '' && $this->childText($section, 'tab') !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Walk system.xml sections/groups/fields (following <include> files) and collect field paths.
     *
     * @param string[] $path   ids from the section down to the current group
     * @param string[] $labels labels from the group(s) below the section
     */
    private function collectFields(\DOMElement $node, array $path, array $labels, array &$fields, int $depth): void
    {
        if ($depth > 8) {
            return;
        }

        foreach ($this->childElements($node) as $child) {
            $id = $child->getAttribute('id');
            switch ($child->nodeName) {
                case 'section':
                case 'group':
                    $label       = $this->cleanLabel($this->childText($child, 'label'));
                    $childLabels = $child->nodeName === 'group'
                        ? array_filter([...$labels, $label])
                        : [];
                    $this->collectFields($child, [...$path, $id], $childLabels, $fields, $depth + 1);
                    break;

                case 'field':
                    if (count($path) < 2) {
                        break;
                    }
                    $configPath = $this->childText($child, 'config_path') ?: implode('/', [...$path, $id]);
                    $type       = strtolower($child->getAttribute('type'));
                    $backend    = $this->childText($child, 'backend_model');
                    $source     = $this->childText($child, 'source_model');
                    $fields[$configPath] = [
                        'label'  => implode(' › ', [...$labels, $this->cleanLabel($this->childText($child, 'label')) ?: $id]),
                        'yesno'  => (bool) preg_match('/(Yesno|Enabledisable)$/i', $source),
                        'secret' => in_array($type, ['obscure', 'password'], true) || stripos($backend, 'Encrypted') !== false,
                    ];
                    break;

                case 'include':
                    $included = $this->resolveInclude($child->getAttribute('path'));
                    if ($included !== null) {
                        $this->collectFields($included->documentElement, $path, $labels, $fields, $depth + 1);
                    }
                    break;
            }
        }
    }

    /**
     * "Vendor_Module::system/file.xml" → the parsed etc/adminhtml/system/file.xml of that module.
     */
    private function resolveInclude(string $path): ?\DOMDocument
    {
        if (!preg_match('/^([A-Za-z0-9]+_[A-Za-z0-9]+)::(.+\.xml)$/', $path, $m) || str_contains($m[2], '..')) {
            return null;
        }
        $dir = $this->getModuleDir($m[1]);

        return $dir === null ? null : $this->loadXml($dir . '/etc/adminhtml/' . $m[2]);
    }

    /**
     * @return array<string, array{owner: string, extenders: array<string, string[]>}>
     */
    private function getTableModules(): array
    {
        if ($this->tableModules !== null) {
            return $this->tableModules;
        }

        // Modules are in load order, so the module that creates a table comes before those extending it
        $this->tableModules = [];
        foreach ($this->getIndex() as $module => $info) {
            foreach ($info['tables'] as $table => $columns) {
                if (!isset($this->tableModules[$table])) {
                    $this->tableModules[$table] = ['owner' => $module, 'extenders' => []];
                } elseif ($columns) {
                    $this->tableModules[$table]['extenders'][$module] = $columns;
                }
            }
        }

        return $this->tableModules;
    }

    /**
     * @return array<string, array{tables: array<string, string[]>, composer_name: string,
     *     composer_version: string, description: string, aliases: string[]}>
     */
    private function getIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $cached = $this->cache->load(self::CACHE_ID);
        if ($cached) {
            try {
                return $this->index = (array) $this->serializer->unserialize($cached);
            } catch (\InvalidArgumentException $e) {
                $this->index = null;
            }
        }

        $this->index = [];
        foreach ($this->fullModuleList->getNames() as $module) {
            $this->index[$module] = $this->readModule($module);
        }

        $this->cache->save(
            (string) $this->serializer->serialize($this->index),
            self::CACHE_ID,
            [ConfigCache::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $this->index;
    }

    /**
     * @return array{tables: array<string, string[]>, composer_name: string, composer_version: string,
     *     description: string, aliases: string[]}
     */
    private function readModule(string $module): array
    {
        $info = ['tables' => [], 'composer_name' => '', 'composer_version' => '', 'description' => '', 'aliases' => []];
        $dir  = $this->getModuleDir($module);
        if ($dir === null) {
            return $info;
        }

        $schema = $this->loadXml($dir . '/etc/db_schema.xml');
        if ($schema !== null) {
            foreach ($schema->getElementsByTagName('table') as $table) {
                $columns = [];
                foreach ($this->childElements($table) as $column) {
                    if ($column->nodeName === 'column' && $column->getAttribute('name') !== '') {
                        $columns[] = strtolower($column->getAttribute('name'));
                    }
                }
                $info['tables'][strtolower($table->getAttribute('name'))] = $columns;
            }
        }

        $composer = $this->readComposer($dir . '/composer.json');
        $info['composer_name']    = (string) ($composer['name'] ?? '');
        $info['composer_version'] = (string) ($composer['version'] ?? '');
        $description = trim((string) ($composer['description'] ?? ''));
        $info['description'] = strcasecmp($description, 'N/A') === 0 ? '' : $description;

        if ($this->isThirdParty($module)) {
            $names = [$info['description'], str_replace(['-', '_'], ' ', (string) strstr($info['composer_name'], '/'))];
            foreach ($this->getConfigSectionLabels($module) as $label) {
                $names[] = $label;
            }
            foreach ($names as $name) {
                $alias = $this->compact((string) preg_replace(self::NAME_NOISE, ' ', $name));
                if (strlen($alias) >= self::MIN_ALIAS_LENGTH && !in_array($alias, self::GENERIC_ALIASES, true)) {
                    $info['aliases'][] = $alias;
                }
            }
            $info['aliases'] = array_values(array_unique($info['aliases']));
        }

        return $info;
    }

    private function getModuleDir(string $module): ?string
    {
        $dir = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $module);

        return $dir ? rtrim($dir, '/') : null;
    }

    private function readComposer(string $file): array
    {
        try {
            if (!$this->fileDriver->isExists($file)) {
                return [];
            }
            $data = json_decode($this->fileDriver->fileGetContents($file), true);
        } catch (\Exception $e) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function loadXml(string $file): ?\DOMDocument
    {
        try {
            if (!$this->fileDriver->isExists($file)) {
                return null;
            }
            $content = $this->fileDriver->fileGetContents($file);
        } catch (\Exception $e) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return ($loaded && $document->documentElement) ? $document : null;
    }

    /**
     * @return \DOMElement[]
     */
    private function childElements(\DOMElement $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function childText(\DOMElement $node, string $name): string
    {
        foreach ($this->childElements($node) as $child) {
            if ($child->nodeName === $name) {
                return trim($child->textContent);
            }
        }

        return '';
    }

    /**
     * Admin labels may contain HTML (links, version notes) and line breaks: keep the plain words.
     */
    private function cleanLabel(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', strip_tags($label)), " \t-–—:");

        return mb_strlen($label) > 50 ? rtrim(mb_substr($label, 0, 50)) . '…' : $label;
    }

    /**
     * Lower-case letters and digits only: "Size Chart" → "sizechart".
     */
    private function compact(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($text));
    }
}
