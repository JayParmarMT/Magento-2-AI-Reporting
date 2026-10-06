<?php
/**
 * Meetanshi AIReporting — answers questions about a specific installed module or vendor
 *
 * "Is the Size Chart extension enabled?", "What version of Mageplaza SMTP is installed?",
 * "Show the settings of Meetanshi_Eattachment", "Which tables does Amasty Rewards use?",
 * "Which Meetanshi modules are installed?" — answered from the module's own files, the module
 * list, setup_module and the store configuration, without going through SQL generation.
 * Data questions about a module ("how many size charts are enabled?") are left to the SQL path,
 * which receives the module's tables and columns (see NlpToSql).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\ResourceInterface as ModuleResource;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;
use Psr\Log\LoggerInterface;

class ModuleInfoResponder
{
    private const MAX_MODULES = 3;
    private const MAX_TABLES  = 15;
    private const MAX_FIELDS  = 25;

    /**
     * Settings shown for an on/off question ("is X enabled?"): the switches and the settings it names.
     */
    private const MAX_STATE_FIELDS = 8;

    /**
     * Above this (approximate) size a table's row count is shown as an estimate instead of counted.
     */
    private const EXACT_COUNT_LIMIT = 500000;

    /**
     * Words that make a question about the module itself rather than about its data.
     */
    private const MODULE_WORDS = '/\b(modules?|extensions?|plugins?|add-?ons?|versions?|config(uration|ured)?|settings?'
        . '|install(ed|ation)?|uninstall(ed)?|vendor|composer|purpose|about|details|info(rmation)?)\b|\bwhat\s+does\b/i';

    private const STRUCTURE_WORDS = '/\b(which|what)\s+(db\s+|database\s+)?(tables?|columns?)\b|\bhow\s+many\s+(tables?|columns?)\b'
        . '|\b(creates?|created|adds?|added|uses?|owns?)\s+(\w+\s+){0,3}(tables?|columns?)\b/i';

    private const STATE_WORDS = '/\b(enabled?|disabled?|active|inactive|activated|deactivated|turned\s+(on|off)|on\s+or\s+off'
        . '|working|status)\b/i';

    /**
     * Questions that want to see the module's settings (others get a one-line summary of them).
     */
    private const CONFIG_WORDS = '/\b(config\w*|settings?|options?|enabled?|disabled?|active|inactive|activated|turned'
        . '|status|working|about|details|info(rmation)?)\b|\bwhat\s+does\b/i';

    private const SPECIFIC_WORDS = '/\b(versions?|tables?|columns?|install(ed|ation)?|uninstall(ed)?|vendor|composer)\b/i';

    /**
     * Counting / ranking words: "how many size charts are enabled" is a data question.
     */
    private const AGGREGATE_WORDS = '/\b(how\s+many|how\s+much|count|number\s+of|total|sum|average|avg|top|most|least|per'
        . '|list\s+all|show\s+all)\b/i';

    public function __construct(
        private readonly ModuleCatalog $moduleCatalog,
        private readonly ModuleResource $moduleResource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly SqlGuard $sqlGuard,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Markdown answer, or null when the question is not about a specific module or vendor.
     */
    public function answer(string $question): ?string
    {
        try {
            $modules = $this->moduleCatalog->detect($question);
            if ($modules && $this->isAboutModule($question)) {
                $answers = [];
                foreach (array_slice($modules, 0, self::MAX_MODULES) as $module) {
                    $answers[] = $this->describeModule($module, $question);
                }

                return implode("\n\n", $answers);
            }

            $vendors = $this->moduleCatalog->detectVendors($question);
            if ($vendors && !$modules && preg_match('/\b(modules?|extensions?|plugins?|add-?ons?|installed)\b/i', $question)) {
                return implode("\n\n", array_map([$this, 'describeVendor'], $vendors));
            }
        } catch (\Exception $e) {
            $this->logger->warning('Meetanshi AIReporting: module answer failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function isAboutModule(string $question): bool
    {
        return preg_match(self::MODULE_WORDS, $question)
            || preg_match(self::STRUCTURE_WORDS, $question)
            || (preg_match(self::STATE_WORDS, $question) && !preg_match(self::AGGREGATE_WORDS, $question));
    }

    private function describeModule(string $module, string $question): string
    {
        $info  = $this->moduleCatalog->getInfo($module);
        $title = "**{$module}**" . ($info['description'] !== '' ? ' — ' . $info['description'] : '');

        $lines = [$title, ''];
        $lines[] = '- **Status:** ' . ($info['enabled']
            ? '✅ Enabled'
            : '❌ Disabled (turned off in app/etc/config.php)');
        $lines[] = '- **Type:** ' . ($this->moduleCatalog->isThirdParty($module)
            ? 'Third-party / custom module'
            : 'Magento core module');

        $versions = [];
        if ($info['composer_version'] !== '') {
            $versions[] = 'package ' . $info['composer_version'];
        }
        if ($info['setup_version'] !== '') {
            $versions[] = 'setup_version ' . $info['setup_version'];
        }
        $schemaVersion = (string) $this->moduleResource->getDbVersion($module);
        $dataVersion   = (string) $this->moduleResource->getDataVersion($module);
        if ($schemaVersion !== '' || $dataVersion !== '') {
            $versions[] = 'installed schema ' . ($schemaVersion ?: '—') . ' / data ' . ($dataVersion ?: '—');
        } else {
            $versions[] = 'not registered in setup_module (setup:upgrade has not run for it)';
        }
        $lines[] = '- **Version:** ' . implode(' · ', $versions);
        if ($info['composer_name'] !== '') {
            $lines[] = '- **Package:** ' . $info['composer_name'];
        }

        $lines = [...$lines, ...$this->describeTables($module)];
        $lines = [...$lines, ...$this->describeConfig($module, $question)];

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private function describeTables(string $module): array
    {
        $tables   = $this->moduleCatalog->getOwnedTables($module);
        $extended = $this->moduleCatalog->getExtendedColumns($module);
        $lines    = [];

        if (!$tables) {
            $lines[] = '- **Database tables:** none (the module creates no tables of its own)';
        } else {
            $counts = $this->getRowCounts(array_slice($tables, 0, self::MAX_TABLES));
            $items  = [];
            foreach (array_slice($tables, 0, self::MAX_TABLES) as $table) {
                $items[] = $table . (isset($counts[$table]) ? ' (' . $counts[$table] . ')' : '');
            }
            $more    = count($tables) > self::MAX_TABLES ? ', …and ' . (count($tables) - self::MAX_TABLES) . ' more' : '';
            $lines[] = '- **Database tables (' . count($tables) . '):** ' . implode(', ', $items) . $more;
        }

        if ($extended) {
            $items = [];
            foreach ($extended as $table => $columns) {
                $items[] = $table . ' (' . implode(', ', $columns) . ')';
            }
            $lines[] = '- **Adds columns to:** ' . implode('; ', $items);
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private function describeConfig(string $module, string $question): array
    {
        $fields = $this->moduleCatalog->getConfigFields($module);
        if (!$fields) {
            return ['- **Configuration:** the module has no settings under Stores › Configuration'];
        }

        $sections = $this->moduleCatalog->getConfigSectionLabels($module);
        $location = $sections ? 'Stores › Configuration › ' . implode(', ', $sections) : 'Stores › Configuration';

        // "What version of X?" / "Which tables does X use?" — the settings are not what was asked
        if (preg_match(self::SPECIFIC_WORDS, $question) && !preg_match(self::CONFIG_WORDS, $question)) {
            return ['- **Configuration:** ' . count($fields) . ' settings under ' . $location];
        }

        $isStateQuestion = preg_match(self::STATE_WORDS, $question)
            && !preg_match('/\b(config\w*|settings?|options?|about|details|info(rmation)?)\b/i', $question);
        [$ranked, $relevant] = $this->prioritiseFields($fields, $question, $module);
        $limit = $isStateQuestion ? max(1, min(self::MAX_STATE_FIELDS, $relevant)) : self::MAX_FIELDS;

        $lines = ['', '**Configuration** (' . $location . ') — default scope', ''];
        foreach (array_slice($ranked, 0, $limit, true) as $path => $field) {
            $lines[] = '- ' . $field['label'] . ': ' . $this->formatValue($path, $field);
        }
        if (count($fields) > $limit) {
            $lines[] = '- …and ' . (count($fields) - $limit) . ' more settings';
        }

        $overrides = $this->countScopeOverrides(array_keys($fields));
        if ($overrides > 0) {
            $lines[] = '';
            $lines[] = "_{$overrides} of these settings are overridden at website or store-view level._";
        }

        return $lines;
    }

    /**
     * Settings named in the question first, then on/off switches, then the rest in file order.
     *
     * @return array{0: array, 1: int} the ordered fields, and how many are named or switches
     */
    private function prioritiseFields(array $fields, string $question, string $module): array
    {
        // Words that only name the module ("size chart", "braintree") match every setting: ignore them
        $info      = $this->moduleCatalog->getInfo($module);
        $moduleKey = (string) preg_replace('/[^a-z0-9]/', '', strtolower($module . $info['description'] . $info['composer_name']));

        preg_match_all('/[a-z]{4,}/', strtolower($question), $m);
        $words = array_filter(
            array_diff($m[0], [
                'enabled', 'enable', 'disabled', 'active', 'status', 'module', 'extension', 'plugin',
                'settings', 'setting', 'configuration', 'config', 'show', 'what', 'which', 'with', 'this', 'that',
            ]),
            static fn (string $word) => !str_contains($moduleKey, $word)
        );

        $ranked = [];
        $order  = 0;
        foreach ($fields as $path => $field) {
            $haystack = strtolower($path . ' ' . $field['label']);
            $rank     = 2;
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $rank = 0;
                    break;
                }
            }
            if ($rank === 2 && preg_match('#/(enabled?|active|status|is_enabled)$#', $path)) {
                $rank = 1;
            }
            $ranked[$path] = [$rank, $order++];
        }
        uksort($fields, static fn ($a, $b) => $ranked[$a] <=> $ranked[$b]);
        $relevant = count(array_filter($ranked, static fn (array $rank) => $rank[0] < 2));

        return [$fields, $relevant];
    }

    private function formatValue(string $path, array $field): string
    {
        $value = $this->scopeConfig->getValue($path);
        if (is_array($value)) {
            return '_(multiple values)_';
        }
        if ($value === null || $value === '') {
            return '_not set_';
        }
        if ($field['secret'] || $this->sqlGuard->isSecretConfigValue($path, $value)) {
            return '_[hidden]_';
        }
        if ($field['yesno'] && in_array((string) $value, ['0', '1'], true)) {
            return (string) $value === '1' ? '**Yes**' : '**No**';
        }

        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value));

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 80) . '…' : $value;
    }

    private function describeVendor(string $vendor): string
    {
        $modules = $this->moduleCatalog->getModulesByVendor($vendor);
        $enabled = array_filter($modules, [$this->moduleCatalog, 'isEnabled']);
        $lines   = [
            sprintf('**%s modules (%d installed, %d enabled)**', $vendor, count($modules), count($enabled)),
            '',
        ];

        foreach ($modules as $module) {
            $info    = $this->moduleCatalog->getInfo($module);
            $version = $info['composer_version'] ?: ((string) $this->moduleResource->getDbVersion($module) ?: $info['setup_version']);
            $parts   = [$info['enabled'] ? '✅ Enabled' : '❌ Disabled'];
            if ($version !== '') {
                $parts[] = 'v' . $version;
            }
            if ($info['description'] !== '') {
                $parts[] = $info['description'];
            }
            $lines[] = "- **{$module}** — " . implode(' · ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * Row count per table: exact for normal tables, an estimate ("≈") for very large ones.
     *
     * @param string[] $tables
     * @return array<string, string>
     */
    private function getRowCounts(array $tables): array
    {
        $connection = $this->resourceConnection->getConnection();
        $physical   = [];
        foreach ($tables as $table) {
            if (!$this->sqlGuard->isDeniedTable($table)) {
                $physical[$this->resourceConnection->getTableName($table)] = $table;
            }
        }
        if (!$physical) {
            return [];
        }

        $estimates = $connection->fetchPairs(
            $connection->select()
                ->from('INFORMATION_SCHEMA.TABLES', ['TABLE_NAME', 'TABLE_ROWS'])
                ->where('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME IN (?)', array_keys($physical))
        );

        $counts = [];
        foreach ($physical as $name => $table) {
            if (!array_key_exists($name, $estimates)) {
                $counts[$table] = 'not created';
                continue;
            }
            $estimate = (int) $estimates[$name];
            if ($estimate > self::EXACT_COUNT_LIMIT) {
                $counts[$table] = '≈' . number_format($estimate) . ' rows';
                continue;
            }
            $rows = (int) $connection->fetchOne($connection->select()->from($name, ['row_count' => 'COUNT(*)']));
            $counts[$table] = number_format($rows) . ($rows === 1 ? ' row' : ' rows');
        }

        return $counts;
    }

    /**
     * @param string[] $paths
     */
    private function countScopeOverrides(array $paths): int
    {
        if (!$paths) {
            return 0;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('core_config_data'), ['overrides' => 'COUNT(DISTINCT path)'])
            ->where('scope <> ?', 'default')
            ->where('path IN (?)', $paths);

        return (int) $connection->fetchOne($select);
    }
}
