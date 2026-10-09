<?php
/**
 * Meetanshi AIReporting — Saved AI Reports page data
 *
 * The logged-in admin's saved reports, each with its run history (from the query log: a
 * saved report run logs the report's stored SQL, so runs after the report was saved are
 * matched on admin + SQL), the
 * tables it reads and whether SqlGuard still accepts its SQL. KPIs, filters, paging and
 * the inspection panel are computed in the browser from this data.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\ViewModel;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Meetanshi\AIReporting\Exception\QueryException;
use Meetanshi\AIReporting\Model\AiContext;
use Meetanshi\AIReporting\Model\Config;
use Meetanshi\AIReporting\Model\Query\ReadOnlyConnectionProvider;
use Meetanshi\AIReporting\Model\Query\SqlGuard;
use Meetanshi\AIReporting\Model\Report\ReportContext;
use Meetanshi\AIReporting\Model\ResourceModel\SavedReport\CollectionFactory;
use Meetanshi\AIReporting\Model\SavedReport;

class SavedReports implements ArgumentInterface
{
    public const CHART_TYPES = ['table' => 'Table', 'bar' => 'Bar', 'line' => 'Line', 'pie' => 'Pie'];

    /**
     * "Recent" window of the run KPIs.
     */
    public const RECENT_DAYS = 30;

    /**
     * Newest query-log rows scanned for run history, and rows listed in the audit log drawer.
     */
    private const LOG_SCAN_LIMIT = 5000;
    private const AUDIT_LIMIT = 30;

    private const LOG_TABLE = 'meetanshi_aireporting_query_log';

    private ?array $reports = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly AuthSession $authSession,
        private readonly ResourceConnection $resource,
        private readonly SqlGuard $sqlGuard,
        private readonly Config $config,
        private readonly AiContext $aiContext,
        private readonly ReportContext $reportContext,
        private readonly ReadOnlyConnectionProvider $connectionProvider,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * "Magento 2.4.9 Community"
     */
    public function getPlatformLabel(): string
    {
        return $this->aiContext->getPlatformLabel();
    }

    /**
     * "Claude: claude-sonnet-5-5"
     */
    public function getEngineLabel(): string
    {
        $model = $this->aiContext->getModel();

        return $this->aiContext->getProviderLabel() . ($model !== '' ? ': ' . $model : '');
    }

    public function isProviderConfigured(): bool
    {
        return $this->aiContext->isProviderConfigured();
    }

    /**
     * Whether saved reports run on the dedicated "aireporting" connection from env.php.
     */
    public function isDedicatedConnection(): bool
    {
        return $this->connectionProvider->isDedicated();
    }

    public function getMaxRows(): int
    {
        return $this->config->getMaxRows();
    }

    public function getQueryTimeout(): int
    {
        return max(1, $this->config->getQueryTimeout());
    }

    public function isQueryLoggingEnabled(): bool
    {
        return $this->config->isQueryLoggingEnabled();
    }

    public function canConfigure(): bool
    {
        return $this->authorization->isAllowed('Meetanshi_AIReporting::config');
    }

    public function canRun(): bool
    {
        return $this->authorization->isAllowed('Meetanshi_AIReporting::query');
    }

    public function getAskAiUrl(): string
    {
        return $this->urlBuilder->getUrl('meetanshi_aireporting/query/index');
    }

    public function getConfigUrl(): string
    {
        return $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'meetanshi_aireporting']);
    }

    /**
     * The admin's saved reports, newest first, with run history and guard status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReports(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $adminUserId = $this->getAdminUserId();
        if ($adminUserId === 0) {
            return $this->reports = [];
        }

        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('admin_user_id', $adminUserId)
            ->setOrder('created_at', 'DESC')
            ->setOrder('report_id', 'DESC');

        $reports = [];
        /** @var SavedReport $report */
        foreach ($collection as $report) {
            $sql = $report->getSqlQuery();
            $reports[] = [
                'id'      => (int) $report->getId(),
                'title'   => $report->getTitle(),
                'nlp'     => $report->getNlpQuery(),
                'sql'     => $sql,
                'chart'   => isset(self::CHART_TYPES[$report->getChartType()]) ? $report->getChartType() : 'table',
                'created' => $this->toIso($report->getData('created_at')),
                'updated' => $this->toIso($report->getData('updated_at')),
                'tables'  => $this->extractTables($sql),
                'guard'   => $this->checkGuard($sql),
            ];
        }

        $logRows = $this->getLogRows($adminUserId, array_column($reports, 'sql'));
        foreach ($reports as &$row) {
            $row += $this->summarizeRuns($logRows[$row['sql']] ?? [], (string) $row['created']);
        }
        unset($row);

        return $this->reports = $reports;
    }

    /**
     * Latest AI questions of this admin (Ask AI, Chat, saved report runs and exports).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAuditLog(): array
    {
        $adminUserId = $this->getAdminUserId();
        if ($adminUserId === 0) {
            return [];
        }

        $titles = [];
        foreach ($this->getReports() as $report) {
            $titles[$report['sql']] ??= $report['title'];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::LOG_TABLE), [
                'nlp_query', 'sql_query', 'status', 'error_message', 'execution_time_ms', 'rows_returned', 'created_at'
            ])
            ->where('admin_user_id = ?', $adminUserId)
            ->order('log_id DESC')
            ->limit(self::AUDIT_LIMIT);

        $entries = [];
        foreach ($connection->fetchAll($select) as $row) {
            $sql = (string) $row['sql_query'];
            $entries[] = [
                'question' => mb_substr((string) $row['nlp_query'], 0, 300),
                'status'   => $row['status'] === 'success' ? 'success' : 'error',
                'error'    => mb_substr((string) $row['error_message'], 0, 300),
                'ms'       => $row['execution_time_ms'] !== null ? (int) $row['execution_time_ms'] : null,
                'rows'     => (int) $row['rows_returned'],
                'time'     => $this->toIso($row['created_at']),
                'report'   => $sql !== '' ? ($titles[$sql] ?? null) : null,
            ];
        }

        return $entries;
    }

    /**
     * Settings for Meetanshi_AIReporting/js/saved-reports.
     */
    public function getJsConfig(): array
    {
        return [
            'reports'      => $this->getReports(),
            'auditLog'     => $this->isQueryLoggingEnabled() ? $this->getAuditLog() : [],
            'chartTypes'   => array_map('strval', array_map('__', self::CHART_TYPES)),
            'recentDays'   => self::RECENT_DAYS,
            'logging'      => $this->isQueryLoggingEnabled(),
            'canRun'       => $this->canRun() && $this->isEnabled(),
            'maxRows'      => $this->getMaxRows(),
            'queryTimeout' => $this->getQueryTimeout(),
            'dedicated'    => $this->isDedicatedConnection(),
            'timezone'     => $this->reportContext->getTimezone()->getName(),
            'formKey'      => $this->formKey->getFormKey(),
            'askAiUrl'     => $this->getAskAiUrl(),
            'updateUrl'    => $this->urlBuilder->getUrl('meetanshi_aireporting/report/update'),
            'deleteUrl'    => $this->urlBuilder->getUrl('meetanshi_aireporting/report/delete'),
            'exportUrl'    => $this->urlBuilder->getUrl('meetanshi_aireporting/report/exportData'),
        ];
    }

    private function getAdminUserId(): int
    {
        $user = $this->authSession->getUser();

        return $user ? (int) $user->getId() : 0;
    }

    /**
     * The newest query-log rows of these SQL statements, grouped by SQL (newest first).
     *
     * @param string[] $sqls
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getLogRows(int $adminUserId, array $sqls): array
    {
        $sqls = array_values(array_unique(array_filter($sqls, 'strlen')));
        if (!$sqls) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::LOG_TABLE), [
                'sql_query', 'status', 'execution_time_ms', 'rows_returned', 'created_at'
            ])
            ->where('admin_user_id = ?', $adminUserId)
            ->where('sql_query IN (?)', $sqls)
            ->order('log_id DESC')
            ->limit(self::LOG_SCAN_LIMIT);

        $grouped = [];
        foreach ($connection->fetchAll($select) as $row) {
            $grouped[(string) $row['sql_query']][] = $row;
        }

        return $grouped;
    }

    /**
     * Run count, recent runs and the latest run of a report. Only runs after the report was
     * saved count: the Ask AI question it was saved from is not a run of the saved report.
     *
     * @param array<int, array<string, mixed>> $rows newest first
     */
    private function summarizeRuns(array $rows, string $createdIso): array
    {
        // Log and report timestamps are both UTC "Y-m-d H:i:s" (the connection runs in UTC)
        $savedAt    = str_replace(['T', 'Z'], [' ', ''], $createdIso);
        $recentFrom = gmdate('Y-m-d H:i:s', time() - self::RECENT_DAYS * 86400);
        $summary    = $this->emptyHistory();

        foreach ($rows as $row) {
            $loggedAt = (string) $row['created_at'];
            if ($loggedAt <= $savedAt) {
                continue;
            }

            $ms = $row['execution_time_ms'] !== null ? (int) $row['execution_time_ms'] : null;
            if ($summary['last_run'] === null) {
                $summary['last_run']    = $this->toIso($loggedAt);
                $summary['last_status'] = $row['status'] === 'success' ? 'success' : 'error';
                $summary['last_ms']     = $ms;
                $summary['last_rows']   = (int) $row['rows_returned'];
            }

            $summary['runs']++;
            if ($loggedAt >= $recentFrom) {
                $summary['recent_runs']++;
                if ($row['status'] === 'success' && $ms !== null) {
                    $summary['recent_ms'] += $ms;
                    $summary['recent_timed']++;
                }
            }
        }

        return $summary;
    }

    private function emptyHistory(): array
    {
        return [
            'runs'         => 0,
            'recent_runs'  => 0,
            'recent_ms'    => 0,
            'recent_timed' => 0,
            'last_run'     => null,
            'last_status'  => null,
            'last_ms'      => null,
            'last_rows'    => null,
        ];
    }

    /**
     * Whether SqlGuard still accepts the stored SQL (rules may have tightened since it was saved).
     *
     * @return array{ok: bool, message: string}
     */
    private function checkGuard(string $sql): array
    {
        try {
            $this->sqlGuard->sanitize($sql);

            return ['ok' => true, 'message' => ''];
        } catch (QueryException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => (string) __('The SQL could not be validated.')];
        }
    }

    /**
     * Tables named after FROM / JOIN, in order of appearance.
     *
     * @return string[]
     */
    private function extractTables(string $sql): array
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([A-Za-z_][A-Za-z0-9_$]*)`?(?:\s*\.\s*`?([A-Za-z_][A-Za-z0-9_$]*)`?)?/i', $sql, $matches, PREG_SET_ORDER);

        $tables = [];
        foreach ($matches as $match) {
            $table = strtolower(!empty($match[2]) ? $match[2] : $match[1]);
            if (!in_array($table, ['select', 'lateral', 'dual'], true)) {
                $tables[$table] = true;
            }
        }

        return array_slice(array_keys($tables), 0, 8);
    }

    /**
     * "2026-10-08 06:15:00" (UTC, as stored) → "2026-10-08T06:15:00Z"
     */
    private function toIso(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : str_replace(' ', 'T', $value) . 'Z';
    }
}
