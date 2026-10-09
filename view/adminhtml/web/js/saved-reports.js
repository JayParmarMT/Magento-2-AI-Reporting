/**
 * Meetanshi AIReporting — Saved AI Reports
 *
 * Renders the KPI strip, the report table (search, format filter, sort, paging, selection),
 * the SQL inspection panel and the audit log drawer from the page data, and wires the row
 * actions: run (opens Ask AI, which runs the report by id), edit (report/update), CSV export
 * of the result (report/exportData), delete (report/delete) and definition exports (JSON/CSV).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    var SAVED_RUN_KEY = 'ai_run_saved_report',
        CHART_ICONS = {table: 'table_chart', bar: 'bar_chart', line: 'show_chart', pie: 'pie_chart'},
        SQL_KEYWORDS = ('SELECT FROM WHERE AND OR NOT IN IS NULL AS ON JOIN LEFT RIGHT INNER OUTER CROSS STRAIGHT_JOIN '
            + 'GROUP BY ORDER HAVING LIMIT OFFSET DESC ASC DISTINCT UNION ALL WITH CASE WHEN THEN ELSE END BETWEEN '
            + 'LIKE EXISTS INTERVAL OVER PARTITION ROLLUP USING DAY MONTH YEAR WEEK HOUR MINUTE TRUE FALSE').split(' '),
        CLAUSE_STARTS = ['FROM', 'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'LEFT', 'RIGHT', 'INNER',
            'CROSS', 'JOIN'],
        JOIN_PREFIXES = ['LEFT', 'RIGHT', 'INNER', 'CROSS', 'OUTER', 'STRAIGHT_JOIN'],
        // Keywords that are functions when a "(" follows: LEFT(name, 3), YEAR(created_at)
        FUNCTION_KEYWORDS = ['LEFT', 'RIGHT', 'DAY', 'MONTH', 'YEAR', 'WEEK', 'HOUR', 'MINUTE'],
        SQL_TOKEN = /('(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.)*"|`[^`]*`|--[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/|\b\d+(?:\.\d+)?\b|\b[A-Za-z_][A-Za-z0-9_]*\b)/g;

    return function (config, element) {
        var $root = $(element),
            reports = config.reports || [],
            state = {query: '', chart: '', sort: 'created', page: 1, perPage: 10, selected: {}, activeId: null},
            menuReportId = null,
            confirmIds = [],
            editId = null,
            lastFocus = null,
            numberFmt = new Intl.NumberFormat(),
            dayKeyFmt = makeFormat('en-CA', {year: 'numeric', month: '2-digit', day: '2-digit'}),
            timeFmt = makeFormat(undefined, {hour: '2-digit', minute: '2-digit'}),
            dateFmt = makeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric'}),
            dateTimeFmt = makeFormat(undefined, {
                year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
            });

        // ── Helpers ─────────────────────────────────────────────────────────

        function $el(role) {
            return $root.find('[data-role="' + role + '"]');
        }

        function esc(value) {
            return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
        }

        function icon(name, size) {
            return '<span class="mxd-ico mxd-ico--' + (size || 'sm') + '" aria-hidden="true">' + name + '</span>';
        }

        function fmt(text) {
            var args = Array.prototype.slice.call(arguments, 1);

            return $t(text).replace(/%(\d)/g, function (match, index) {
                var value = args[index - 1];

                return value === undefined ? match : value;
            });
        }

        /** Dates in the store's time zone (falls back to the browser's if the zone is unknown). */
        function makeFormat(locale, options) {
            try {
                return new Intl.DateTimeFormat(locale, $.extend({timeZone: config.timezone}, options));
            } catch (e) {
                return new Intl.DateTimeFormat(locale, options);
            }
        }

        function dayKey(date) {
            return dayKeyFmt.format(date);
        }

        /** "Today at 10:42", "Yesterday at 16:32", "3 days ago", "Sep 28, 2026" */
        function relativeTime(iso) {
            var date = new Date(iso),
                now = new Date(),
                day = dayKey(date),
                today = dayKey(now),
                days;

            if (day === today) {
                return fmt('Today at %1', timeFmt.format(date));
            }
            if (day === dayKey(new Date(now.getTime() - 86400000))) {
                return fmt('Yesterday at %1', timeFmt.format(date));
            }
            days = Math.round((Date.parse(today) - Date.parse(day)) / 86400000);
            if (days > 1 && days < 7) {
                return fmt('%1 days ago', days);
            }

            return dateFmt.format(date);
        }

        function chartLabel(chart) {
            return (config.chartTypes && config.chartTypes[chart]) || chart;
        }

        function findReport(id) {
            id = parseInt(id, 10);

            for (var i = 0; i < reports.length; i++) {
                if (reports[i].id === id) {
                    return reports[i];
                }
            }

            return null;
        }

        function selectedIds() {
            return Object.keys(state.selected).map(Number).filter(function (id) {
                return !!findReport(id);
            });
        }

        function oneLine(sql) {
            return String(sql || '').replace(/\s+/g, ' ').trim();
        }

        function toast(message, isError) {
            var $toast = $el('toast');

            $toast.text(message).toggleClass('is-error', !!isError).prop('hidden', false);
            clearTimeout($toast.data('timer'));
            $toast.data('timer', setTimeout(function () {
                $toast.prop('hidden', true);
            }, 3500));
        }

        function copyText(text, doneMessage) {
            function fallback() {
                var $area = $('<textarea class="mxs-offscreen" readonly>').val(text).appendTo($root),
                    ok = false;

                $area[0].select();
                try {
                    ok = document.execCommand('copy');
                } catch (e) {
                    ok = false;
                }
                $area.remove();
                toast(ok ? doneMessage : $t('Copy failed. Select the text and copy it manually.'), !ok);
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    toast(doneMessage);
                }, fallback);
            } else {
                fallback();
            }
        }

        function download(fileName, content, type) {
            var blob = new Blob([content], {type: type}),
                url = URL.createObjectURL(blob),
                link = document.createElement('a');

            link.href = url;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () {
                URL.revokeObjectURL(url);
            }, 1000);
        }

        // ── Filtering, sorting, paging ──────────────────────────────────────

        function filtered() {
            var query = state.query.toLowerCase(),
                list = reports.filter(function (report) {
                    if (state.chart && report.chart !== state.chart) {
                        return false;
                    }
                    if (!query) {
                        return true;
                    }

                    return [report.title, report.nlp, report.sql, report.tables.join(' '), '#' + report.id]
                        .join('\n').toLowerCase().indexOf(query) !== -1;
                });

            return list.sort(compare);
        }

        function compare(a, b) {
            switch (state.sort) {
                case 'oldest':
                    return String(a.created).localeCompare(String(b.created)) || a.id - b.id;
                case 'run':
                    return String(b.last_run || '').localeCompare(String(a.last_run || '')) || b.id - a.id;
                case 'runs':
                    return b.runs - a.runs || String(b.last_run || '').localeCompare(String(a.last_run || ''));
                case 'title':
                    return a.title.localeCompare(b.title, undefined, {sensitivity: 'base', numeric: true});
                default:
                    return String(b.created).localeCompare(String(a.created)) || b.id - a.id;
            }
        }

        // ── KPI strip ───────────────────────────────────────────────────────

        function kpiCard(label, iconHtml, body, foot) {
            return '<article class="mxs-kpi">'
                + '<div class="mxs-kpi__head"><span class="mxs-kpi__label">' + esc(label) + '</span>' + iconHtml + '</div>'
                + '<div class="mxs-kpi__body">' + body + '</div>'
                + '<div class="mxs-kpi__foot">' + foot + '</div>'
                + '</article>';
        }

        function renderKpis() {
            var counts = {},
                runs = 0,
                timed = 0,
                totalMs = 0,
                top = null,
                parts = [],
                html;

            reports.forEach(function (report) {
                counts[report.chart] = (counts[report.chart] || 0) + 1;
                runs += report.recent_runs;
                timed += report.recent_timed;
                totalMs += report.recent_ms;
                if (report.runs > 0 && (!top || report.runs > top.runs
                    || (report.runs === top.runs && String(report.last_run) > String(top.last_run)))) {
                    top = report;
                }
            });

            Object.keys(config.chartTypes).forEach(function (chart) {
                if (counts[chart]) {
                    parts.push(numberFmt.format(counts[chart]) + ' ' + chartLabel(chart));
                }
            });

            html = kpiCard(
                $t('Total Saved Reports'),
                '<span class="mxs-kpi__icon">' + icon('bookmark') + '</span>',
                '<span class="mxs-kpi__value">' + numberFmt.format(reports.length) + '</span>'
                    + '<span class="mxs-kpi__unit">' + esc(reports.length === 1 ? $t('Active Definition') : $t('Active Definitions')) + '</span>',
                '<span class="mxd-dot mxd-dot--brand"></span><span class="mxs-kpi__note">'
                    + esc(parts.length ? parts.join(' · ') : $t('No saved reports yet')) + '</span>'
            );

            html += kpiCard(
                $t('Saved Report Executions'),
                '<span class="mxs-kpi__icon">' + icon('bolt') + '</span>',
                config.logging
                    ? '<span class="mxs-kpi__value">' + numberFmt.format(runs) + '</span>'
                        + '<span class="mxs-kpi__unit mxs-kpi__unit--brand">' + esc(fmt('Runs · last %1 days', config.recentDays)) + '</span>'
                    : '<span class="mxs-kpi__value mxs-kpi__value--muted">—</span>'
                        + '<span class="mxs-kpi__unit">' + esc($t('Query logging is off')) + '</span>',
                config.logging
                    ? '<span class="mxs-kpi__note">' + esc($t('Avg run duration:')) + '</span>'
                        + '<span class="mxs-kpi__chip">' + (timed ? numberFmt.format(Math.round(totalMs / timed)) + 'ms' : '—') + '</span>'
                    : '<span class="mxs-kpi__note">' + esc($t('Enable "Log All Queries" to track runs.')) + '</span>'
            );

            html += kpiCard(
                $t('Top Velocity Query'),
                top ? '<span class="mxs-kpi__pill">' + esc(fmt(top.runs === 1 ? '%1 Run' : '%1 Runs', numberFmt.format(top.runs))) + '</span>'
                    : '<span class="mxs-kpi__icon">' + icon('trending_up') + '</span>',
                top ? '<button type="button" class="mxs-kpi__title" data-action="inspect" data-id="' + top.id + '" title="' + esc(top.title) + '">'
                        + esc(top.title) + '</button>'
                        + '<span class="mxs-kpi__quote" title="' + esc(top.nlp) + '">“' + esc(top.nlp) + '”</span>'
                    : '<span class="mxs-kpi__title mxs-kpi__title--muted">' + esc($t('No runs yet')) + '</span>'
                        + '<span class="mxs-kpi__quote">' + esc(config.logging ? $t('Run a saved report to see it here.') : $t('Runs are tracked in the query log.')) + '</span>',
                top ? '<span class="mxd-ico mxd-ico--sm mxd-text-brand" aria-hidden="true">' + CHART_ICONS[top.chart] + '</span>'
                        + '<span class="mxs-kpi__note">' + esc(fmt('%1 visualization', chartLabel(top.chart))) + '</span>'
                    : '<span class="mxs-kpi__note">&nbsp;</span>'
            );

            html += kpiCard(
                $t('Isolation & Policy'),
                '<span class="mxs-kpi__icon mxs-kpi__icon--brand">' + icon('verified_user') + '</span>',
                '<span class="mxs-kpi__headline">' + esc($t('Owner-Only Scoped')) + '</span>',
                '<span class="mxd-ico mxd-ico--xs" aria-hidden="true">shield</span><span class="mxs-kpi__note">'
                    + esc(config.dedicated
                        ? fmt('Dedicated read-only connection · %1s timeout', config.queryTimeout)
                        : fmt('READ ONLY transactions · %1s timeout', config.queryTimeout))
                    + '</span>'
            );

            $el('kpis').html(html);
        }

        // ── Filters ─────────────────────────────────────────────────────────

        function renderChartFilter() {
            var counts = {},
                $select = $el('chart-filter'),
                html;

            reports.forEach(function (report) {
                counts[report.chart] = (counts[report.chart] || 0) + 1;
            });

            html = '<option value="">' + esc(fmt('All Types (%1)', reports.length)) + '</option>';
            Object.keys(config.chartTypes).forEach(function (chart) {
                html += '<option value="' + esc(chart) + '">' + esc(chartLabel(chart)) + ' (' + (counts[chart] || 0) + ')</option>';
            });

            $select.html(html).val(state.chart);
        }

        function renderExportLabel() {
            var count = selectedIds().length;

            $el('export-label').text(count
                ? fmt('Export Selected (%1)', count)
                : $t('Bulk Export (JSON/CSV)'));
        }

        // ── Table ───────────────────────────────────────────────────────────

        function chartPill(chart) {
            return '<span class="mxs-format mxs-format--' + esc(chart) + '">' + esc(chartLabel(chart).toUpperCase()) + '</span>';
        }

        function lastRunCell(report) {
            var created = report.created ? fmt('Created %1', dateFmt.format(new Date(report.created))) : '';

            if (!report.last_run) {
                return '<div class="mxs-when mxs-when--never">' + icon('schedule', 'xs') + esc($t('Never run')) + '</div>'
                    + '<span class="mxs-meta">' + esc(created) + '</span>';
            }

            return '<div class="mxs-when" title="' + esc(dateTimeFmt.format(new Date(report.last_run))) + '">'
                + icon(report.last_status === 'error' ? 'error' : 'schedule', 'xs')
                + esc(relativeTime(report.last_run))
                + (report.last_status === 'error' ? ' <span class="mxs-failed">' + esc($t('Failed')) + '</span>' : '')
                + '</div><span class="mxs-meta">' + esc(created) + '</span>';
        }

        function latencyCell(report) {
            var runs = report.runs ? fmt(report.runs === 1 ? '%1 run' : '%1 runs', numberFmt.format(report.runs)) : '';

            return '<span class="mxs-latency">' + (report.last_ms !== null ? numberFmt.format(report.last_ms) + 'ms' : '—') + '</span>'
                + (runs ? '<span class="mxs-meta">' + esc(runs) + '</span>' : '');
        }

        function guardPill(report) {
            return report.guard.ok
                ? '<span class="mxs-guardpill mxs-guardpill--ok" title="' + esc($t('The SQL passes the read-only query guard.')) + '">'
                    + '<span class="mxs-guardpill__dot"></span>' + esc($t('Validated')) + '</span>'
                : '<span class="mxs-guardpill mxs-guardpill--bad" title="' + esc(report.guard.message) + '">'
                    + '<span class="mxs-guardpill__dot"></span>' + esc($t('Blocked')) + '</span>';
        }

        function rowHtml(report) {
            var active = report.id === state.activeId,
                runnable = config.canRun && report.guard.ok,
                tables = report.tables.length
                    ? ' · ' + $t('Table:') + ' ' + report.tables[0] + (report.tables.length > 1 ? ' +' + (report.tables.length - 1) : '')
                    : '';

            return '<tr data-id="' + report.id + '"' + (active ? ' class="is-active"' : '') + '>'
                + '<td class="mxs-col-check"><input type="checkbox" class="mxs-check" data-role="row-check"'
                    + (state.selected[report.id] ? ' checked' : '')
                    + ' aria-label="' + esc(fmt('Select %1', report.title)) + '"></td>'
                + '<td class="mxs-col-title"><div class="mxs-title">'
                    + '<span class="mxs-title__bar" aria-hidden="true"></span>'
                    + '<div class="mxs-title__text">'
                    + '<button type="button" class="mxs-title__name" data-action="inspect" title="' + esc($t('Inspect SQL')) + '">' + esc(report.title).replace(/_/g, '_<wbr>') + '</button>'
                    + '<span class="mxs-meta" title="' + esc(report.tables.join(', ')) + '">' + esc(fmt('ID: #%1', report.id) + tables) + '</span>'
                    + '</div></div></td>'
                + '<td class="mxs-col-prompt">'
                    + '<div class="mxs-prompt">' + icon('auto_awesome') + '<span>' + esc(report.nlp) + '</span></div>'
                    + '<div class="mxs-sqlline" title="' + esc(oneLine(report.sql)) + '">' + esc(oneLine(report.sql)) + '</div>'
                + '</td>'
                + '<td class="mxs-c">' + chartPill(report.chart) + '</td>'
                + '<td class="mxs-nowrap">' + lastRunCell(report) + '</td>'
                + '<td class="mxs-r mxs-nowrap">' + latencyCell(report) + '</td>'
                + '<td>' + guardPill(report) + '</td>'
                + '<td class="mxs-r mxs-col-actions"><div class="mxs-rowactions">'
                    + '<button type="button" class="mxs-run' + (active ? ' mxs-run--solid' : '') + '" data-action="run"'
                        + (runnable ? '' : ' disabled title="' + esc(report.guard.ok ? $t('Running reports is not available.') : report.guard.message) + '"')
                        + '>' + icon('play_arrow', 'xs') + esc($t('Run')) + '</button>'
                    + '<button type="button" class="mxs-edit" data-action="edit">' + esc($t('Edit')) + '</button>'
                    + '<button type="button" class="mxs-iconbtn mxs-iconbtn--sm" data-action="export"'
                        + (runnable ? '' : ' disabled')
                        + ' title="' + esc(fmt('Export query data (CSV, up to %1 rows)', config.maxRows)) + '"'
                        + ' aria-label="' + esc($t('Export query data')) + '">' + icon('file_download') + '</button>'
                    + '<button type="button" class="mxs-iconbtn mxs-iconbtn--sm" data-action="menu" aria-haspopup="true"'
                        + ' aria-label="' + esc($t('More actions')) + '">' + icon('more_vert') + '</button>'
                + '</div></td>'
                + '</tr>';
        }

        function renderPager(total, pages, from, to) {
            var html = '',
                page;

            $el('pager-info').html(total
                ? fmt('Showing %1 to %2 of %3 saved AI reports', '<strong>' + from, to + '</strong>', '<strong>' + total + '</strong>')
                : esc($t('No reports to show')));

            if (pages > 1) {
                html += '<button type="button" class="mxs-pagebtn" data-page="' + (state.page - 1) + '"'
                    + (state.page === 1 ? ' disabled' : '') + ' aria-label="' + esc($t('Previous page')) + '">' + icon('chevron_left') + '</button>';
                for (page = 1; page <= pages; page++) {
                    if (page === 1 || page === pages || Math.abs(page - state.page) <= 1) {
                        html += '<button type="button" class="mxs-pagebtn' + (page === state.page ? ' is-active' : '') + '" data-page="' + page + '"'
                            + (page === state.page ? ' aria-current="page"' : '') + '>' + page + '</button>';
                    } else if (Math.abs(page - state.page) === 2) {
                        html += '<span class="mxs-pager__gap">…</span>';
                    }
                }
                html += '<button type="button" class="mxs-pagebtn" data-page="' + (state.page + 1) + '"'
                    + (state.page === pages ? ' disabled' : '') + ' aria-label="' + esc($t('Next page')) + '">' + icon('chevron_right') + '</button>';
            }

            $el('pages').html(html);
        }

        function renderTable() {
            var list = filtered(),
                total = list.length,
                pages = Math.max(1, Math.ceil(total / state.perPage)),
                start,
                rows,
                checkedOnPage;

            state.page = Math.min(Math.max(1, state.page), pages);
            start = (state.page - 1) * state.perPage;
            rows = list.slice(start, start + state.perPage);

            $el('rows').html(rows.map(rowHtml).join(''));
            $root.find('.mxs-tablewrap').prop('hidden', !total);

            $el('empty').prop('hidden', !!total);
            if (!total) {
                $el('empty-title').text(reports.length ? $t('No reports match your filters') : $t('No saved reports yet'));
                $el('empty-text').text(reports.length
                    ? $t('Try another keyword or chart format.')
                    : $t('Ask a question in Ask AI, then use "Save as Report" to keep it here and re-run it any time.'));
                $el('empty-cta').prop('hidden', !!reports.length);
                $el('empty-reset').prop('hidden', !reports.length);
            }

            renderPager(total, pages, start + 1, start + rows.length);

            checkedOnPage = rows.filter(function (report) {
                return state.selected[report.id];
            }).length;
            $el('check-all')
                .prop('checked', rows.length > 0 && checkedOnPage === rows.length)
                .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < rows.length)
                .prop('disabled', !rows.length);

            renderBulk();
        }

        function renderBulk() {
            var count = selectedIds().length;

            $el('bulk').prop('hidden', !count);
            $el('bulk-count').text(fmt(count === 1 ? '%1 report selected' : '%1 reports selected', count));
            renderExportLabel();
        }

        // ── SQL inspection ──────────────────────────────────────────────────

        /** Escaped, highlighted SQL. Single-line SQL gets a line break before each main clause. */
        function highlightSql(sql) {
            var html = '',
                last = 0,
                breakLines = sql.indexOf('\n') === -1,
                previousWord = '',
                match,
                token,
                upper,
                isFunction;

            SQL_TOKEN.lastIndex = 0;
            while ((match = SQL_TOKEN.exec(sql)) !== null) {
                token = match[0];
                html += esc(sql.slice(last, match.index));
                last = match.index + token.length;

                if (/^['"]/.test(token)) {
                    html += '<span class="mxs-tok-str">' + esc(token) + '</span>';
                } else if (/^(--|#|\/\*)/.test(token)) {
                    html += '<span class="mxs-tok-com">' + esc(token) + '</span>';
                } else if (/^`/.test(token)) {
                    html += '<span class="mxs-tok-id">' + esc(token) + '</span>';
                } else if (/^\d/.test(token)) {
                    html += '<span class="mxs-tok-num">' + esc(token) + '</span>';
                } else {
                    upper = token.toUpperCase();
                    isFunction = /^\s*\(/.test(sql.slice(last));

                    if (SQL_KEYWORDS.indexOf(upper) !== -1 && !(isFunction && FUNCTION_KEYWORDS.indexOf(upper) !== -1)) {
                        if (breakLines && match.index > 0 && CLAUSE_STARTS.indexOf(upper) !== -1
                            && !(upper === 'JOIN' && JOIN_PREFIXES.indexOf(previousWord) !== -1)
                        ) {
                            html = html.replace(/[ \t]+$/, '') + '\n';
                        }
                        html += '<span class="mxs-tok-kw">' + esc(token) + '</span>';
                    } else if (isFunction) {
                        html += '<span class="mxs-tok-fn">' + esc(token) + '</span>';
                    } else {
                        html += esc(token);
                    }
                    previousWord = upper;
                    continue;
                }
                previousWord = '';
            }

            return html + esc(sql.slice(last));
        }

        function renderInspect() {
            var report = findReport(state.activeId),
                runnable,
                fileName;

            if (!report) {
                $el('inspect').prop('hidden', true).empty();
                return;
            }

            runnable = config.canRun && report.guard.ok;
            fileName = (report.title.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'saved_report') + '.sql';

            $el('inspect').prop('hidden', false).html(
                '<header class="mxs-inspect__head">'
                    + '<div class="mxs-inspect__title">'
                        + '<span class="mxs-inspect__icon">' + icon('code', 'md') + '</span>'
                        + '<div>'
                            + '<div class="mxs-inspect__titlerow"><span class="mxd-h3">' + esc($t('Selected Query Inspection:')) + '</span>'
                            + '<span class="mxs-inspect__name">' + esc(report.title) + '</span></div>'
                            + '<p class="mxd-sub">“' + esc(report.nlp) + '”</p>'
                        + '</div>'
                    + '</div>'
                    + '<div class="mxs-inspect__actions">'
                        + '<span class="mxs-limit">' + icon('speed', 'xs') + esc(fmt('LIMIT %1 Enforced', numberFmt.format(config.maxRows))) + '</span>'
                        + '<button type="button" class="mxs-btn mxs-btn--primary" data-action="run" data-id="' + report.id + '"'
                            + (runnable ? '' : ' disabled') + '>'
                            + icon('auto_fix_high') + '<span>' + esc($t('Open in Ask AI Studio')) + '</span></button>'
                    + '</div>'
                + '</header>'
                + '<div class="mxs-code">'
                    + '<div class="mxs-code__bar">'
                        + '<div class="mxs-code__file"><i></i><i></i><i></i><span>' + esc(fileName) + '</span></div>'
                        + '<button type="button" class="mxs-code__copy" data-action="copy" data-id="' + report.id + '">'
                            + icon('content_copy', 'xs') + esc($t('Copy SQL')) + '</button>'
                    + '</div>'
                    + '<pre class="mxs-code__pre"><code>' + highlightSql(report.sql) + '</code></pre>'
                + '</div>'
                + '<div class="mxs-inspect__meta">'
                    + '<div>' + icon('database', 'sm') + '<span>' + esc($t('Tables:')) + ' <strong>'
                        + esc(report.tables.length ? report.tables.join(', ') : '—') + '</strong></span></div>'
                    + '<div>' + (report.guard.ok
                        ? '<span class="mxd-ico mxd-ico--sm mxd-text-green" aria-hidden="true">security</span><span>'
                            + esc($t('SQL Guard:')) + ' <strong>' + esc($t('0 Violations')) + '</strong></span>'
                        : '<span class="mxd-ico mxd-ico--sm mxd-text-red" aria-hidden="true">gpp_bad</span><span title="' + esc(report.guard.message) + '">'
                            + esc($t('SQL Guard:')) + ' <strong>' + esc(report.guard.message) + '</strong></span>')
                    + '</div>'
                    + '<div>' + icon('table_rows', 'sm') + '<span>' + esc($t('Last Result:')) + ' <strong>'
                        + esc(report.last_run && report.last_status === 'success'
                            ? fmt(report.last_rows === 1 ? '%1 row' : '%1 rows', numberFmt.format(report.last_rows))
                                + (report.last_ms !== null ? ' · ' + numberFmt.format(report.last_ms) + 'ms' : '')
                            : (report.last_run ? $t('Failed') : $t('Not run yet')))
                        + '</strong></span></div>'
                    + '<div>' + icon('schedule', 'sm') + '<span>' + esc($t('Last Updated:')) + ' <strong>'
                        + esc(report.updated ? relativeTime(report.updated) : '—') + '</strong></span></div>'
                + '</div>'
            );
        }

        function inspect(id, scroll) {
            state.activeId = parseInt(id, 10);
            renderTable();
            renderInspect();

            if (scroll) {
                $el('inspect')[0].scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }

        // ── Audit log ───────────────────────────────────────────────────────

        function renderAudit() {
            var entries = config.auditLog || [];

            if (!config.logging) {
                $el('audit-list').html('<div class="mxs-notice">' + icon('info', 'md')
                    + '<span>' + esc($t('Query logging is turned off, so no audit trail is kept. Enable "Log All Queries" in the AI Reporting configuration.')) + '</span></div>');
                return;
            }
            if (!entries.length) {
                $el('audit-list').html('<p class="mxd-empty">' + esc($t('No AI questions logged yet.')) + '</p>');
                return;
            }

            $el('audit-list').html('<ol class="mxs-audit">' + entries.map(function (entry) {
                return '<li class="mxs-audit__item">'
                    + '<span class="mxs-audit__status mxs-audit__status--' + entry.status + '">'
                        + icon(entry.status === 'success' ? 'check_circle' : 'error', 'sm') + '</span>'
                    + '<div class="mxs-audit__text">'
                        + '<p class="mxs-audit__question">' + esc(entry.question || $t('(empty question)')) + '</p>'
                        + (entry.report ? '<span class="mxs-audit__tag">' + icon('bookmark', 'xs') + esc(entry.report) + '</span>' : '')
                        + (entry.status === 'error' && entry.error ? '<p class="mxs-audit__error">' + esc(entry.error) + '</p>' : '')
                        + '<span class="mxs-meta">'
                            + esc((entry.time ? dateTimeFmt.format(new Date(entry.time)) : '')
                                + (entry.ms !== null ? ' · ' + numberFmt.format(entry.ms) + 'ms' : '')
                                + (entry.status === 'success' ? ' · ' + fmt(entry.rows === 1 ? '%1 row' : '%1 rows', numberFmt.format(entry.rows)) : ''))
                        + '</span>'
                    + '</div>'
                    + '</li>';
            }).join('') + '</ol>');
        }

        // ── Overlays (menus, drawer, modals) ────────────────────────────────

        function closeMenus() {
            $el('row-menu').prop('hidden', true);
            $el('export-menu').prop('hidden', true);
            $el('export-toggle').attr('aria-expanded', 'false');
            menuReportId = null;
        }

        function openOverlay(role, focusRole) {
            closeMenus();
            lastFocus = document.activeElement;
            $el(role).prop('hidden', false);
            $('body').addClass('mxs-noscroll');
            setTimeout(function () {
                var $focus = focusRole ? $el(focusRole) : $el(role).find('button').first();

                $focus.trigger('focus');
            }, 30);
        }

        function closeOverlays() {
            $root.find('.mxs-overlay').prop('hidden', true);
            $('body').removeClass('mxs-noscroll');
            if (lastFocus && document.body.contains(lastFocus)) {
                lastFocus.focus();
            }
            lastFocus = null;
        }

        function openRowMenu($button, id) {
            var $menu = $el('row-menu'),
                offset = $button.offset(),
                rootOffset = $root.offset();

            if (menuReportId === id && !$menu.prop('hidden')) {
                closeMenus();
                return;
            }

            closeMenus();
            menuReportId = id;
            $menu.prop('hidden', false).css({
                top: offset.top - rootOffset.top + $button.outerHeight() + 4,
                left: Math.max(8, offset.left - rootOffset.left + $button.outerWidth() - $menu.outerWidth())
            });
            $menu.find('button').first().trigger('focus');
        }

        // ── Actions ─────────────────────────────────────────────────────────

        function run(report) {
            if (!config.canRun || !report.guard.ok) {
                return;
            }

            try {
                window.sessionStorage.setItem(SAVED_RUN_KEY, JSON.stringify({
                    report_id: report.id,
                    chart: report.chart,
                    nlp: report.nlp
                }));
            } catch (e) {
                toast($t('Your browser blocked session storage, so the report cannot be opened in Ask AI.'), true);
                return;
            }

            window.location.href = config.askAiUrl;
        }

        function exportData(report) {
            if (!config.canRun || !report.guard.ok) {
                return;
            }

            toast(fmt('Running "%1" and preparing the CSV…', report.title));
            window.location.href = config.exportUrl + (config.exportUrl.indexOf('?') === -1 ? '?' : '&')
                + 'report_id=' + encodeURIComponent(report.id);
        }

        function openEdit(report) {
            var formats = '';

            editId = report.id;
            Object.keys(config.chartTypes).forEach(function (chart) {
                formats += '<label class="mxs-formatopt">'
                    + '<input type="radio" name="mxs-edit-chart" value="' + esc(chart) + '"' + (chart === report.chart ? ' checked' : '') + '>'
                    + '<span>' + icon(CHART_ICONS[chart]) + esc(chartLabel(chart)) + '</span>'
                    + '</label>';
            });

            $el('edit-title').val(report.title);
            $el('edit-formats').html(formats);
            $el('edit-question').text(report.nlp);
            $el('edit-error').prop('hidden', true).text('');
            $el('edit-save').prop('disabled', false);
            openOverlay('edit', 'edit-title');
        }

        function saveEdit() {
            var report = findReport(editId),
                title = $.trim($el('edit-title').val()),
                chart = $el('edit-formats').find('input:checked').val() || 'table';

            if (!report) {
                closeOverlays();
                return;
            }
            if (!title) {
                $el('edit-error').text($t('Please enter a report title.')).prop('hidden', false);
                $el('edit-title').trigger('focus');
                return;
            }

            $el('edit-save').prop('disabled', true);
            $.ajax({
                url: config.updateUrl,
                type: 'POST',
                dataType: 'json',
                data: {report_id: report.id, title: title, chart_type: chart, form_key: config.formKey, isAjax: true}
            }).done(function (response) {
                if (response && response.success) {
                    report.title = response.report.title;
                    report.chart = response.report.chart;
                    report.updated = new Date().toISOString();
                    closeOverlays();
                    renderAll();
                    toast(response.message || $t('Report updated.'));
                } else {
                    $el('edit-error').text((response && response.message) || $t('The report could not be updated.')).prop('hidden', false);
                }
            }).fail(function () {
                $el('edit-error').text($t('The report could not be updated. Please try again.')).prop('hidden', false);
            }).always(function () {
                $el('edit-save').prop('disabled', false);
            });
        }

        function askDelete(ids) {
            var report = ids.length === 1 ? findReport(ids[0]) : null;

            confirmIds = ids;
            $el('confirm-text').text(report
                ? fmt('"%1" will be permanently deleted. This cannot be undone.', report.title)
                : fmt('%1 saved reports will be permanently deleted. This cannot be undone.', ids.length));
            $el('confirm-error').prop('hidden', true).text('');
            $el('confirm-ok').prop('disabled', false);
            openOverlay('confirm', 'confirm-ok');
        }

        function doDelete() {
            $el('confirm-ok').prop('disabled', true);
            $.ajax({
                url: config.deleteUrl,
                type: 'POST',
                dataType: 'json',
                data: {ids: confirmIds, form_key: config.formKey, isAjax: true}
            }).done(function (response) {
                var deleted = (response && response.deleted) || [];

                if (deleted.length) {
                    reports = reports.filter(function (report) {
                        return deleted.indexOf(report.id) === -1;
                    });
                    deleted.forEach(function (id) {
                        delete state.selected[id];
                    });
                    if (deleted.indexOf(state.activeId) !== -1) {
                        state.activeId = null;
                    }
                }

                if (response && response.success) {
                    closeOverlays();
                    renderAll();
                    toast(response.message || $t('Deleted.'));
                } else {
                    renderAll();
                    $el('confirm-error').text((response && response.message) || $t('The report could not be deleted.')).prop('hidden', false);
                    $el('confirm-ok').prop('disabled', false);
                }
            }).fail(function () {
                $el('confirm-error').text($t('The report could not be deleted. Please try again.')).prop('hidden', false);
                $el('confirm-ok').prop('disabled', false);
            });
        }

        /** Report definitions (not results): the selection, else every report matching the filters. */
        function exportDefinitions(format, list) {
            var stamp = new Date().toISOString().slice(0, 10),
                rows = list.map(function (report) {
                    return {
                        id: report.id,
                        title: report.title,
                        question: report.nlp,
                        sql: report.sql,
                        chart_format: report.chart,
                        tables: report.tables.join(', '),
                        guard: report.guard.ok ? 'validated' : 'blocked',
                        runs: report.runs,
                        last_run: report.last_run,
                        created_at: report.created,
                        updated_at: report.updated
                    };
                }),
                csv;

            if (!rows.length) {
                toast($t('There are no reports to export.'), true);
                return;
            }

            if (format === 'json') {
                download('saved_ai_reports_' + stamp + '.json', JSON.stringify(rows, null, 2), 'application/json');
            } else {
                csv = [Object.keys(rows[0])].concat(rows.map(function (row) {
                    return Object.keys(row).map(function (key) {
                        return row[key];
                    });
                })).map(function (cells) {
                    return cells.map(function (value) {
                        value = value === null || value === undefined ? '' : String(value);
                        if (/^[=+\-@\t\r]/.test(value) && isNaN(Number(value))) {
                            value = '\'' + value;
                        }

                        return '"' + value.replace(/"/g, '""') + '"';
                    }).join(',');
                }).join('\r\n');
                download('saved_ai_reports_' + stamp + '.csv', '﻿' + csv, 'text/csv;charset=utf-8');
            }

            toast(fmt(rows.length === 1 ? 'Exported %1 report definition.' : 'Exported %1 report definitions.', rows.length));
        }

        function exportList() {
            var ids = selectedIds();

            return ids.length ? reports.filter(function (report) {
                return state.selected[report.id];
            }) : filtered();
        }

        function renderAll() {
            if (state.activeId === null || !findReport(state.activeId)) {
                state.activeId = filtered().length ? filtered()[0].id : (reports.length ? reports[0].id : null);
            }
            renderKpis();
            renderChartFilter();
            renderTable();
            renderInspect();
        }

        // ── Events ──────────────────────────────────────────────────────────

        $el('search').on('input', function () {
            state.query = $.trim($(this).val());
            state.page = 1;
            renderTable();
        });

        $el('chart-filter').on('change', function () {
            state.chart = $(this).val();
            state.page = 1;
            renderTable();
        });

        $el('sort').on('change', function () {
            state.sort = $(this).val();
            state.page = 1;
            renderTable();
        });

        $el('per-page').on('change', function () {
            state.perPage = parseInt($(this).val(), 10) || 10;
            state.page = 1;
            renderTable();
        });

        $el('pages').on('click', '[data-page]', function () {
            state.page = parseInt($(this).data('page'), 10);
            renderTable();
            $root.find('.mxs-tablecard')[0].scrollIntoView({block: 'nearest'});
        });

        $el('empty-reset').on('click', function () {
            state.query = '';
            state.chart = '';
            $el('search').val('');
            $el('chart-filter').val('');
            renderTable();
        });

        $el('refresh').on('click', function () {
            $(this).addClass('is-spinning').prop('disabled', true);
            window.location.reload();
        });

        $el('check-all').on('change', function () {
            var checked = this.checked,
                start = (state.page - 1) * state.perPage;

            filtered().slice(start, start + state.perPage).forEach(function (report) {
                if (checked) {
                    state.selected[report.id] = true;
                } else {
                    delete state.selected[report.id];
                }
            });
            renderTable();
        });

        $el('rows').on('change', '[data-role="row-check"]', function () {
            var id = parseInt($(this).closest('tr').data('id'), 10);

            if (this.checked) {
                state.selected[id] = true;
            } else {
                delete state.selected[id];
            }
            renderTable();
        });

        // Row click (outside controls) selects the report for inspection
        $el('rows').on('click', 'tr', function (event) {
            if ($(event.target).closest('button, a, input, label').length) {
                return;
            }
            inspect($(this).data('id'), false);
        });

        $root.on('click', '[data-action]', function (event) {
            var $button = $(this),
                action = $button.data('action'),
                report = findReport($button.data('id') || $button.closest('tr').data('id'));

            if (!report) {
                return;
            }
            event.preventDefault();

            switch (action) {
                case 'inspect':
                    inspect(report.id, true);
                    break;
                case 'run':
                    run(report);
                    break;
                case 'edit':
                    openEdit(report);
                    break;
                case 'export':
                    exportData(report);
                    break;
                case 'copy':
                    copyText(report.sql, $t('SQL copied to clipboard.'));
                    break;
                case 'menu':
                    event.stopPropagation();
                    openRowMenu($button, report.id);
                    break;
            }
        });

        $el('row-menu').on('click', '[data-menu]', function (event) {
            var report = findReport(menuReportId),
                action = $(this).data('menu');

            event.stopPropagation();
            closeMenus();
            if (!report) {
                return;
            }

            if (action === 'inspect') {
                inspect(report.id, true);
            } else if (action === 'copy') {
                copyText(report.sql, $t('SQL copied to clipboard.'));
            } else if (action === 'question') {
                copyText(report.nlp, $t('Question copied to clipboard.'));
            } else if (action === 'delete') {
                askDelete([report.id]);
            }
        });

        $el('export-toggle').on('click', function (event) {
            var $menu = $el('export-menu'),
                open = $menu.prop('hidden');

            event.stopPropagation();
            closeMenus();
            $menu.prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            if (open) {
                $menu.find('button').first().trigger('focus');
            }
        });

        $el('export-menu').on('click', '[data-export]', function (event) {
            event.stopPropagation();
            closeMenus();
            exportDefinitions($(this).data('export'), exportList());
        });

        $el('bulk').on('click', '[data-bulk]', function () {
            var action = $(this).data('bulk'),
                ids = selectedIds();

            if (action === 'clear') {
                state.selected = {};
                renderTable();
            } else if (action === 'delete') {
                if (ids.length) {
                    askDelete(ids);
                }
            } else {
                exportDefinitions(action, exportList());
            }
        });

        $el('export-all').on('click', function () {
            exportDefinitions('json', reports.slice().sort(compare));
        });

        $el('audit-open').on('click', function () {
            renderAudit();
            openOverlay('audit', 'audit-close');
        });

        $root.on('click', '[data-role="audit-close"], [data-role="modal-close"]', function () {
            closeOverlays();
        });

        // Click on the dimmed backdrop closes the drawer or modal
        $root.on('mousedown', '.mxs-overlay', function (event) {
            if (event.target === this) {
                closeOverlays();
            }
        });

        $el('edit-form').on('submit', function (event) {
            event.preventDefault();
            saveEdit();
        });

        $el('confirm-ok').on('click', doDelete);

        $(document).on('click.mxs', function (event) {
            if (!$(event.target).closest('.mxs-menu, [data-role="export-toggle"]').length) {
                closeMenus();
            }
        }).on('keydown.mxs', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            if (!$el('row-menu').prop('hidden') || !$el('export-menu').prop('hidden')) {
                closeMenus();
            } else if ($root.find('.mxs-overlay:not([hidden])').length) {
                closeOverlays();
            }
        });

        $(window).on('resize.mxs', closeMenus);

        renderAll();
    };
});
