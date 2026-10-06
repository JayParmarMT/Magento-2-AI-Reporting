<?php
/**
 * Meetanshi AIReporting — answers Magento system / environment questions directly
 * from live Magento APIs, without going through SQL generation.
 *
 * These facts (version, edition, PHP, deploy mode, module lists, cache status, and the cache,
 * session, search and queue backends from app/etc/env.php) are not reliably available as plain
 * database columns, so we resolve them in PHP and build the answer.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\FullModuleList;
use Meetanshi\AIReporting\Model\Schema\ModuleCatalog;

class SystemInfoResponder
{
    private const INFRASTRUCTURE_PATTERN = '/\b(redis|valkey|varnish|rabbit\s?mq|amqp|message\s+queues?|queue\s+backend'
        . '|session\s+(storage|save|handler|backend)|sessions?\s+(are\s+)?(stored|saved)|cache\s+backend|cache\s+storage'
        . '|search\s+engine|elastic\s?search|open\s?search|infrastructure|tech(nology)?\s+stack|server\s+setup'
        . '|lock\s+provider|remote\s+storage|media\s+storage)\b/i';

    private const CACHE_PATTERN = '/\b(caches?|fpc)\b/i';

    private const DISABLED_MODULES_PATTERN = '/\b(disabled|inactive|turned\s+off|deactivated)\s+(\w+\s+)?(modules?|extensions?)\b'
        . '|\b(modules?|extensions?)\s+(\w+\s+){0,2}(disabled|inactive|turned\s+off|deactivated)\b/i';

    private const SEARCH_ENGINES = [
        'opensearch'     => 'OpenSearch',
        'elasticsearch7' => 'Elasticsearch 7',
        'elasticsearch8' => 'Elasticsearch 8',
        'elasticsearch'  => 'Elasticsearch',
        'mysql'          => 'MySQL',
    ];

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly State $appState,
        private readonly ModuleListInterface $moduleList,
        private readonly FullModuleList $fullModuleList,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ModuleCatalog $moduleCatalog
    ) {
    }

    /**
     * Decide whether a question is about the Magento platform/environment itself
     * (version, edition, PHP, deploy mode, module/extension counts).
     */
    public function canAnswer(string $question): bool
    {
        return $this->match($question) !== null;
    }

    /**
     * Build a ready-to-display markdown answer, or null if not a system question.
     */
    public function answer(string $question): ?string
    {
        $topic = $this->match($question);
        if ($topic === null) {
            return null;
        }

        switch ($topic) {
            case 'version':
                return $this->versionAnswer();
            case 'php':
                return $this->phpAnswer();
            case 'mode':
                return $this->modeAnswer();
            case 'infrastructure':
                return $this->infrastructureAnswer();
            case 'cache':
                return $this->cacheAnswer();
            case 'disabled':
                return $this->disabledModulesAnswer();
            case 'modules':
                return $this->modulesAnswer();
            case 'thirdparty':
                return $this->thirdPartyAnswer();
            default:
                return null;
        }
    }

    /**
     * Classify the question into a system topic (or null).
     */
    private function match(string $question): ?string
    {
        $q = strtolower($question);

        // Must be clearly about Magento/the platform/system, not store data
        $versionWords = ['magento version', 'current version', 'which version', 'what version',
            'version of magento', 'ce or ee', 'community or enterprise', 'edition'];
        foreach ($versionWords as $w) {
            if (str_contains($q, $w)) {
                return 'version';
            }
        }

        if (str_contains($q, 'php version') || str_contains($q, 'php are') || (str_contains($q, 'php') && str_contains($q, 'running'))) {
            return 'php';
        }

        if (str_contains($q, 'deploy mode') || str_contains($q, 'deployment mode')
            || str_contains($q, 'developer mode') || str_contains($q, 'production mode')
            || (str_contains($q, 'what mode') && str_contains($q, 'magento'))) {
            return 'mode';
        }

        // Backends configured in app/etc/env.php — not visible in core_config_data
        if (preg_match(self::INFRASTRUCTURE_PATTERN, $q)) {
            return 'infrastructure';
        }

        if (preg_match(self::CACHE_PATTERN, $q)) {
            return 'cache';
        }

        if (preg_match(self::DISABLED_MODULES_PATTERN, $q)) {
            return 'disabled';
        }

        if (str_contains($q, 'third-party') || str_contains($q, 'third party') || str_contains($q, '3rd party')
            || str_contains($q, 'custom module') || str_contains($q, 'custom extension')
            || str_contains($q, 'installed extension') || str_contains($q, 'what extension')
            || str_contains($q, 'which extension')) {
            return 'thirdparty';
        }

        if ((str_contains($q, 'how many') && (str_contains($q, 'module') || str_contains($q, 'extension')))
            || str_contains($q, 'module count') || str_contains($q, 'list modules')
            || str_contains($q, 'enabled modules') || str_contains($q, 'all modules')) {
            return 'modules';
        }

        return null;
    }

    private function versionAnswer(): string
    {
        $name    = $this->productMetadata->getName();
        $edition = $this->productMetadata->getEdition();
        $version = $this->productMetadata->getVersion();

        return "**Magento Platform**\n\n"
            . "- **Product:** {$name}\n"
            . "- **Edition:** {$edition}\n"
            . "- **Version:** {$version}\n"
            . "- **PHP:** " . PHP_VERSION;
    }

    private function phpAnswer(): string
    {
        return "**PHP Environment**\n\n"
            . "- **PHP Version:** " . PHP_VERSION . "\n"
            . "- **Magento:** " . $this->productMetadata->getEdition() . ' ' . $this->productMetadata->getVersion();
    }

    private function modeAnswer(): string
    {
        return "**Deployment Mode**\n\n- Magento is running in **" . strtoupper($this->getMode()) . "** mode.";
    }

    private function infrastructureAnswer(): string
    {
        $queueHost = (string) $this->deploymentConfig->get('queue/amqp/host');
        $queue     = $queueHost !== ''
            ? 'RabbitMQ / AMQP (' . $queueHost . ':' . ($this->deploymentConfig->get('queue/amqp/port') ?: '5672') . ')'
            : 'MySQL database queue (no RabbitMQ / AMQP connection configured)';

        $remoteStorage = (string) $this->deploymentConfig->get('remote_storage/driver');

        return implode("\n", [
            '**Infrastructure** (from app/etc/env.php and store configuration)',
            '',
            '- **Default cache backend:** ' . $this->describeCacheBackend('default'),
            '- **Page cache backend:** ' . $this->describeCacheBackend('page_cache'),
            '- **Full page cache application:** ' . $this->getFullPageCacheApplication(),
            '- **Session storage:** ' . $this->describeSessionStorage(),
            '- **Search engine:** ' . $this->describeSearchEngine(),
            '- **Message queue:** ' . $queue,
            '- **Lock provider:** ' . ($this->deploymentConfig->get('lock/provider') ?: 'db'),
            '- **Media storage:** ' . ($remoteStorage !== '' && $remoteStorage !== 'file'
                ? 'Remote (' . $remoteStorage . ')'
                : 'Local file system'),
            '- **Deploy mode:** ' . $this->getMode(),
        ]);
    }

    private function cacheAnswer(): string
    {
        $types       = $this->cacheTypeList->getTypes();
        $invalidated = $this->cacheTypeList->getInvalidated();
        $enabled     = array_filter($types, static fn ($type) => (int) $type->getData('status') === 1);

        $lines = [
            sprintf('**Cache Management** — %d of %d cache types enabled', count($enabled), count($types)),
            '',
        ];
        foreach ($types as $id => $type) {
            $line = ((int) $type->getData('status') === 1 ? '✅ ' : '❌ ') . $type->getData('cache_type') . " ({$id})";
            if ((int) $type->getData('status') !== 1) {
                $line .= ' — disabled';
            } elseif (isset($invalidated[$id])) {
                $line .= ' — ⚠️ invalidated, needs refresh';
            }
            $lines[] = '- ' . $line;
        }

        $lines[] = '';
        $lines[] = '- **Full page cache application:** ' . $this->getFullPageCacheApplication();
        $lines[] = '- **Default cache backend:** ' . $this->describeCacheBackend('default');
        $lines[] = '- **Page cache backend:** ' . $this->describeCacheBackend('page_cache');

        return implode("\n", $lines);
    }

    private function disabledModulesAnswer(): string
    {
        $disabled = array_values(array_diff($this->fullModuleList->getNames(), $this->moduleList->getNames()));
        sort($disabled);

        if (!$disabled) {
            return "**Disabled Modules**\n\nAll " . count($this->fullModuleList->getNames())
                . ' declared modules are enabled.';
        }

        $lines = ['**Disabled Modules (' . count($disabled) . ')**', ''];
        foreach ($disabled as $module) {
            $lines[] = '- ' . $module . ($this->moduleCatalog->isThirdParty($module) ? ' (third-party)' : ' (core)');
        }

        return implode("\n", $lines);
    }

    private function modulesAnswer(): string
    {
        $enabled = $this->moduleList->getNames();
        $all     = $this->fullModuleList->getNames();
        $core    = array_filter($enabled, static fn ($m) => str_starts_with($m, 'Magento_'));
        $third   = array_filter($enabled, static fn ($m) => !str_starts_with($m, 'Magento_'));

        return "**Installed Modules**\n\n"
            . "- **Total enabled:** " . count($enabled) . "\n"
            . "- **Magento core modules:** " . count($core) . "\n"
            . "- **Third-party / custom modules:** " . count($third) . "\n"
            . "- **Total declared (incl. disabled):** " . count($all);
    }

    private function thirdPartyAnswer(): string
    {
        $third = array_values(array_filter(
            $this->fullModuleList->getNames(),
            static fn ($m) => !str_starts_with($m, 'Magento_')
        ));
        sort($third);

        if (empty($third)) {
            return "**Third-party Modules**\n\nNo third-party or custom modules are installed — only Magento core modules.";
        }

        $enabled = array_filter($third, [$this->moduleList, 'has']);
        $lines   = [
            '**Third-party / Custom Modules (' . count($third) . ' installed, ' . count($enabled) . ' enabled)**',
            '',
        ];
        foreach ($third as $module) {
            $info    = $this->moduleCatalog->getInfo($module);
            $version = $info['composer_version'] ?: $info['setup_version'];
            $lines[] = '- ' . $module
                . ($version !== '' ? ' — v' . $version : '')
                . ($info['enabled'] ? '' : ' — ❌ disabled');
        }

        return implode("\n", $lines);
    }

    private function getMode(): string
    {
        try {
            return (string) $this->appState->getMode();
        } catch (\Exception $e) {
            return (string) ($this->deploymentConfig->get('MAGE_MODE') ?: 'default');
        }
    }

    private function getFullPageCacheApplication(): string
    {
        return (string) $this->scopeConfig->getValue('system/full_page_cache/caching_application') === '2'
            ? 'Varnish'
            : 'Built-in (Magento)';
    }

    /**
     * Readable cache backend of an env.php cache frontend (hosts only — never passwords).
     */
    private function describeCacheBackend(string $frontend): string
    {
        $config  = (array) $this->deploymentConfig->get('cache/frontend/' . $frontend);
        $backend = (string) ($config['backend'] ?? '');
        $options = (array) ($config['backend_options'] ?? []);

        if ($backend === '') {
            return 'File system (var/' . ($frontend === 'page_cache' ? 'page_cache' : 'cache') . ')';
        }
        if (stripos($backend, 'RemoteSynchronizedCache') !== false) {
            return $this->describeBackendClass(
                (string) ($options['remote_backend'] ?? ''),
                (array) ($options['remote_backend_options'] ?? [])
            ) . ' with a local L2 cache';
        }

        return $this->describeBackendClass($backend, $options);
    }

    private function describeBackendClass(string $backend, array $options): string
    {
        if (stripos($backend, 'redis') !== false) {
            return sprintf(
                'Redis (%s:%s, database %s)',
                $options['server'] ?? '127.0.0.1',
                $options['port'] ?? '6379',
                $options['database'] ?? '0'
            );
        }
        if (stripos($backend, 'database') !== false) {
            return 'Database';
        }
        if (stripos($backend, 'file') !== false) {
            return 'File system';
        }

        return $backend !== '' ? $backend : 'Unknown';
    }

    private function describeSessionStorage(): string
    {
        $save = strtolower((string) ($this->deploymentConfig->get('session/save') ?: 'files'));
        if ($save === 'redis') {
            return sprintf(
                'Redis (%s:%s, database %s)',
                $this->deploymentConfig->get('session/redis/host') ?: '127.0.0.1',
                $this->deploymentConfig->get('session/redis/port') ?: '6379',
                $this->deploymentConfig->get('session/redis/database') ?: '0'
            );
        }

        return match ($save) {
            'files'                 => 'Files (var/session)',
            'db'                    => 'Database',
            'memcache', 'memcached' => 'Memcached',
            default                 => $save,
        };
    }

    private function describeSearchEngine(): string
    {
        $engine = (string) $this->scopeConfig->getValue('catalog/search/engine');
        if ($engine === '') {
            return 'Not configured';
        }

        $host = (string) $this->scopeConfig->getValue("catalog/search/{$engine}_server_hostname");
        $port = (string) $this->scopeConfig->getValue("catalog/search/{$engine}_server_port");

        return (self::SEARCH_ENGINES[$engine] ?? $engine)
            . ($host !== '' ? " ({$host}" . ($port !== '' ? ":{$port}" : '') . ')' : '');
    }
}
