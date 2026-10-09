/**
 * Meetanshi AIReporting — Ask AI & Copilot Analytics
 *
 * Studio: question → query/execute (the server generates, checks and runs read-only SQL; the
 * browser never sends SQL) → table / bar / trend / pie, CSV, save as report, SQL inspector.
 * Copilot: chat/send with server-side history, plus an optional result summary computed here
 * from the rows on screen (nothing is sent to the AI for it).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery', 'chartjs'], function ($, Chart) {
    'use strict';

    var PALETTE = ['#ee672f', '#10b981', '#f59e0b', '#6366f1', '#0ea5e9', '#ef4444', '#14b8a6', '#a855f7', '#84cc16', '#64748b'],
        VIEWS = ['table', 'bar', 'line', 'pie'],
        PREFS_KEY = 'meetanshi-aireporting-askai',
        SAVED_RUN_KEY = 'ai_run_saved_report',
        MONEY_PATTERN = /(revenue|total|amount|price|sales|spent|spend|value|cost|subtotal|grand|discount|refund|tax|shipping|aov|paid)/i,
        QTY_PATTERN = /(qty|quantity|stock|units|count|orders|items|number|num_)/i,
        // Stock on hand ("stock_qty", "qty", "salable_qty"), not quantities sold or ordered
        STOCK_PATTERN = /(stock|salable|available|inventory|on_hand|^qty$|^quantity$|_qty$)/i,
        MOVED_PATTERN = /(sold|order|invoic|refund|ship|cancel|return|sales)/i,
        STOCK_STATUS_PATTERN = /(stock_?status|is_in_stock)/i,
        LOW_STOCK_QTY = 10,
        CRITICAL_STOCK_QTY = 5,
        ENTITY_TITLES = {
            product_id: 'Open the product in Magento',
            customer_id: 'Open the customer in Magento',
            order_id: 'Open the order in Magento'
        },
        ID_PATTERN = /(^id$|_id$|^entity_id$|increment_id)/i,
        STATUS_PATTERN = /(status|state)$/i,
        LABEL_PATTERN = /(name|title|label|sku|email|product|customer|category|method|code|status|month|day|date|week|year)/i;

    return function (config, element) {
        var $root = $(element),
            prefs = loadPrefs(),
            state = {
                question: '',
                columns: [],
                rows: [],
                sql: '',
                token: '',
                view: VIEWS.indexOf(prefs.view) !== -1 ? prefs.view : 'table',
                page: 1,
                pageSize: [7, 10, 25, 50].indexOf(Number(prefs.pageSize)) !== -1 ? Number(prefs.pageSize) : 10,
                summary: prefs.summary !== false,
                attach: false,
                x: null,
                ys: [],
                kinds: {}
            },
            chart = null,
            running = false,
            chatting = false,
            suggestIndex = -1,
            moneyFmt = new Intl.NumberFormat(undefined, {style: 'currency', currency: config.currency}),
            numberFmt = new Intl.NumberFormat(undefined, {maximumFractionDigits: 2});

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

        function isNumeric(value) {
            return value !== null && value !== '' && !isNaN(parseFloat(value)) && isFinite(value);
        }

        function money(value) {
            return moneyFmt.format(Number(value) || 0);
        }

        function num(value) {
            return numberFmt.format(Number(value) || 0);
        }

        function humanize(column) {
            return String(column).replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
        }

        function loadPrefs() {
            try {
                return JSON.parse(window.localStorage.getItem(PREFS_KEY) || '{}') || {};
            } catch (e) {
                return {};
            }
        }

        function savePrefs() {
            try {
                window.localStorage.setItem(PREFS_KEY, JSON.stringify({
                    view: prefs.view, pageSize: state.pageSize, summary: state.summary, tab: prefs.tab
                }));
            } catch (e) {
                // Storage unavailable (private window): preferences just are not remembered
            }
        }

        function toast(message, isError) {
            var $toast = $el('toast');

            $toast.text(message).toggleClass('is-error', !!isError).prop('hidden', false);
            clearTimeout($toast.data('timer'));
            $toast.data('timer', setTimeout(function () {
                $toast.prop('hidden', true);
            }, 3500));
        }

        function nowLabel() {
            return new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        }

        function formatAnswer(text) {
            return esc(text)
                .replace(/^#{1,6}\s+(.+)$/gm, '<strong>$1</strong>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/`(.+?)`/g, '<code>$1</code>')
                .replace(/^\s*[-*]\s+(.+)$/gm, '<li>$1</li>')
                .replace(/^\s*\d+\.\s+(.+)$/gm, '<li>$1</li>')
                .replace(/(?:<li>.*<\/li>\n?)+/g, function (list) {
                    return '<ul>' + list.replace(/\n/g, '') + '</ul>';
                })
                .replace(/\n{2,}/g, '<br><br>')
                .replace(/\n/g, '<br>');
        }

        // ── Column analysis and formatting ──────────────────────────────────

        function analyseColumns(columns, rows) {
            var kinds = {};

            columns.forEach(function (column) {
                var values = rows.map(function (row) {
                        return row[column];
                    }).filter(function (value) {
                        return value !== null && value !== '';
                    }),
                    numeric = values.length > 0 && values.every(isNumeric),
                    id = ID_PATTERN.test(column);

                kinds[column] = {
                    numeric: numeric && !id,
                    id: id,
                    money: numeric && !id && MONEY_PATTERN.test(column) && !QTY_PATTERN.test(column.replace(/total_?qty|qty/i, 'qty')),
                    stock: numeric && !id && STOCK_PATTERN.test(column) && !MOVED_PATTERN.test(column),
                    sku: /sku/i.test(column),
                    status: STATUS_PATTERN.test(column) && !numeric
                };
            });
            return kinds;
        }

        function formatCell(column, value) {
            var kind = state.kinds[column] || {},
                cls = 'mxq-' + (value === null ? 'null' : 'v');

            if (value === null || value === undefined || value === '') {
                return '<td class="mxq-muted">—</td>';
            }
            if (kind.money) {
                return '<td class="mxq-r mxq-money">' + esc(money(value)) + '</td>';
            }
            if (kind.stock) {
                return '<td class="mxq-r mxq-mono' + (Number(value) <= LOW_STOCK_QTY ? ' mxq-low' : '') + '">' + esc(num(value)) + '</td>';
            }
            if (kind.numeric) {
                return '<td class="mxq-r">' + esc(num(value)) + '</td>';
            }
            if (kind.id || kind.sku) {
                return '<td class="mxq-mono mxq-muted">' + esc(value) + '</td>';
            }
            if (kind.status) {
                return '<td><span class="mxq-status mxq-status--' + esc(String(value).toLowerCase().replace(/[^a-z_]/g, ''))
                    + '">' + esc(value) + '</span></td>';
            }
            return '<td class="' + cls + '">' + esc(value) + '</td>';
        }

        function labelColumn() {
            return state.columns.find(function (column) {
                return !state.kinds[column].numeric && !state.kinds[column].id && /(name|title|label)/i.test(column);
            }) || state.columns.find(function (column) {
                return !state.kinds[column].numeric && !state.kinds[column].id && LABEL_PATTERN.test(column);
            }) || state.columns.find(function (column) {
                return !state.kinds[column].numeric && !state.kinds[column].id;
            }) || null;
        }

        function valueColumns() {
            var money = state.columns.filter(function (column) {
                    return state.kinds[column].money;
                }),
                numeric = state.columns.filter(function (column) {
                    return state.kinds[column].numeric;
                });

            return money.concat(numeric.filter(function (column) {
                return money.indexOf(column) === -1;
            }));
        }

        function hasData() {
            return state.rows.length > 0 && state.columns.length > 0;
        }

        /** The one stock-on-hand column of the result, unless the result already has a stock status. */
        function stockColumn() {
            var stock = state.columns.filter(function (column) {
                return state.kinds[column].stock;
            });

            return stock.length === 1 && !state.columns.some(function (column) {
                return STOCK_STATUS_PATTERN.test(column);
            }) ? stock[0] : null;
        }

        function stockLevel(value) {
            var qty = Number(value);

            if (qty <= 0) {
                return {label: 'Out of Stock', tone: 'out', icon: 'block'};
            }
            if (qty <= CRITICAL_STOCK_QTY) {
                return {label: 'Critical', tone: 'critical', icon: 'warning'};
            }
            if (qty <= LOW_STOCK_QTY) {
                return {label: 'Low', tone: 'low', icon: 'warning'};
            }
            return {label: 'In Stock', tone: 'ok', icon: ''};
        }

        /** Admin link for the first entity id the row carries (product, customer, order). */
        function entityLink(row) {
            var urls = config.entityUrls || {},
                keys = Object.keys(urls),
                i;

            for (i = 0; i < keys.length; i++) {
                if (/^\d+$/.test(String(row[keys[i]] === null || row[keys[i]] === undefined ? '' : row[keys[i]]))) {
                    return {url: urls[keys[i]].replace('__ID__', row[keys[i]]), title: ENTITY_TITLES[keys[i]]};
                }
            }
            return null;
        }

        // ── Results: meta, table, chart ─────────────────────────────────────

        function renderMeta(response) {
            var pills = [
                '<span class="mxq-pill mxq-pill--strong">' + icon('timer', 'xs') + ' Executed in <strong>'
                    + esc(num(response.execution_time_ms)) + 'ms</strong></span>',
                '<span class="mxq-pill">' + icon('table_rows', 'xs') + ' ' + esc(num(response.row_count)) + ' '
                    + (Number(response.row_count) === 1 ? 'record' : 'records') + ' returned'
                    + (Number(response.row_count) >= config.maxRows ? ' (row limit)' : '') + '</span>',
                '<span class="mxq-pill mxq-pill--lock" title="Runs in a READ ONLY transaction with a ' + esc(config.queryTimeout)
                    + ' s timeout">' + icon('lock', 'xs') + ' Read-only transaction</span>'
            ];

            if (response.repaired) {
                pills.push('<span class="mxq-pill mxq-pill--amber" title="The first SQL failed; the AI corrected it once">'
                    + icon('build', 'xs') + ' Self-corrected</span>');
            }
            pills.push('<span class="mxq-tz">Timezone: <strong>' + esc(config.timezone) + '</strong></span>');
            $el('meta').html(pills.join(''));
        }

        function renderTable() {
            var pages = Math.max(1, Math.ceil(state.rows.length / state.pageSize)),
                first,
                visible,
                buttons = [],
                labelCol = labelColumn(),
                stockCol = stockColumn();

            state.page = Math.min(Math.max(1, state.page), pages);
            first = (state.page - 1) * state.pageSize;
            visible = state.rows.slice(first, first + state.pageSize);

            $el('thead').html('<tr><th class="mxq-c">#</th>' + state.columns.map(function (column) {
                var kind = state.kinds[column];

                return '<th class="' + (kind.numeric ? 'mxq-r' : '') + '" title="' + esc(column) + '">' + esc(humanize(column)) + '</th>'
                    + (column === stockCol ? '<th class="mxq-c">Stock Status</th>' : '');
            }).join('') + '<th class="mxq-c">Actions</th></tr>');

            $el('tbody').html(visible.map(function (row, i) {
                var label = labelCol ? row[labelCol] : '',
                    link = entityLink(row);

                return '<tr><td class="mxq-c mxq-mono mxq-muted">' + (first + i + 1) + '</td>'
                    + state.columns.map(function (column) {
                        var level;

                        if (column !== stockCol) {
                            return formatCell(column, row[column]);
                        }
                        level = row[column] === null || row[column] === '' ? null : stockLevel(row[column]);
                        return formatCell(column, row[column]) + '<td class="mxq-c">' + (level
                            ? '<span class="mxq-stock mxq-stock--' + level.tone + '">' + (level.icon ? icon(level.icon, 'xs') : '')
                                + esc(level.label) + '</span>'
                            : '<span class="mxq-muted">—</span>') + '</td>';
                    }).join('')
                    + '<td class="mxq-c"><div class="mxq-rowactions">'
                    + (link ? '<a class="mxd-iconbtn" href="' + esc(link.url) + '" target="_blank" rel="noopener" title="'
                        + esc(link.title) + '">' + icon('open_in_new', 'sm') + '</a>' : '')
                    + '<button type="button" class="mxd-iconbtn mxq-rowask" data-row="' + (first + i)
                    + '" title="' + esc('Ask the Copilot about ' + (label || 'this row')) + '">' + icon('auto_awesome', 'sm')
                    + '</button></div></td></tr>';
            }).join(''));

            $el('pager-info').text('Showing rows ' + (state.rows.length ? first + 1 : 0) + '–' + (first + visible.length)
                + ' of ' + num(state.rows.length));
            buttons.push('<button type="button" data-page="' + (state.page - 1) + '"' + (state.page <= 1 ? ' disabled' : '') + '>Prev</button>');
            pageWindow(state.page, pages).forEach(function (page) {
                buttons.push('<button type="button" data-page="' + page + '"' + (page === state.page ? ' class="is-active"' : '') + '>' + page + '</button>');
            });
            buttons.push('<button type="button" data-page="' + (state.page + 1) + '"' + (state.page >= pages ? ' disabled' : '') + '>Next</button>');
            $el('pager').html(buttons.join(''));
        }

        function pageWindow(page, pages) {
            var start = Math.max(1, Math.min(page - 2, pages - 4)),
                list = [],
                i;

            for (i = start; i <= Math.min(pages, start + 4); i++) {
                list.push(i);
            }
            return list;
        }

        function fillAxisSelects() {
            var $x = $el('x-axis'),
                $y = $el('y-axis');

            $x.html(state.columns.map(function (column) {
                return '<option value="' + esc(column) + '">' + esc(humanize(column)) + '</option>';
            }).join(''));
            $y.html(state.columns.map(function (column) {
                return '<option value="' + esc(column) + '">' + esc(humanize(column)) + '</option>';
            }).join(''));
            $y.attr('size', Math.min(4, Math.max(2, state.columns.length)));

            if (!state.x || state.columns.indexOf(state.x) === -1) {
                state.x = labelColumn() || state.columns[0];
            }
            state.ys = state.ys.filter(function (column) {
                return state.columns.indexOf(column) !== -1 && column !== state.x;
            });
            if (!state.ys.length) {
                state.ys = valueColumns().filter(function (column) {
                    return column !== state.x;
                }).slice(0, 3);
            }
            $x.val(state.x);
            $y.val(state.ys);
        }

        function renderChart() {
            var type = state.view,
                ys = type === 'pie' ? state.ys.slice(0, 1) : state.ys,
                labels,
                datasets,
                moneyAxis;

            if (chart) {
                chart.destroy();
                chart = null;
            }
            fillAxisSelects();
            ys = type === 'pie' ? state.ys.slice(0, 1) : state.ys;
            if (!ys.length) {
                $el('chart-sub').text('No numeric column to chart. Showing the table instead.');
                return false;
            }

            labels = state.rows.map(function (row) {
                return String(row[state.x] === null || row[state.x] === undefined ? '' : row[state.x]);
            });
            moneyAxis = ys.every(function (column) {
                return state.kinds[column] && state.kinds[column].money;
            });
            datasets = ys.map(function (column, index) {
                var color = PALETTE[index % PALETTE.length];

                return {
                    label: humanize(column),
                    data: state.rows.map(function (row) {
                        return parseFloat(row[column]) || 0;
                    }),
                    backgroundColor: type === 'pie' ? state.rows.map(function (row, i) {
                        return PALETTE[i % PALETTE.length];
                    }) : (type === 'line' ? color + '22' : color + 'D9'),
                    borderColor: type === 'pie' ? '#fff' : color,
                    borderWidth: type === 'pie' ? 2 : (type === 'line' ? 2.5 : 0),
                    borderRadius: type === 'bar' ? 4 : 0,
                    fill: type === 'line' && ys.length === 1,
                    tension: 0.35,
                    pointRadius: type === 'line' ? 2 : 0
                };
            });

            $el('chart-title').text(state.question || 'Saved report');
            $el('chart-sub').text(ys.map(humanize).join(', ') + ' by ' + humanize(state.x)
                + (moneyAxis ? ' · values in ' + config.currency : ''));

            chart = new Chart($el('chart')[0], {
                type: type,
                data: {labels: labels, datasets: datasets},
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {duration: 350},
                    plugins: {
                        legend: {
                            display: type === 'pie' || datasets.length > 1,
                            position: type === 'pie' ? 'right' : 'top',
                            labels: {font: {family: '"IBM Plex Sans", sans-serif', size: 12}, boxWidth: 12}
                        },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            callbacks: {
                                label: function (item) {
                                    var column = ys[item.datasetIndex] || ys[0],
                                        value = type === 'pie' ? item.parsed : item.parsed.y;

                                    return ' ' + item.dataset.label + ': '
                                        + (state.kinds[column] && state.kinds[column].money ? money(value) : num(value));
                                }
                            }
                        }
                    },
                    scales: type === 'pie' ? {} : {
                        x: {
                            grid: {display: false},
                            ticks: {font: {family: '"JetBrains Mono", monospace', size: 11}, color: '#594139', maxRotation: 45, autoSkip: true}
                        },
                        y: {
                            beginAtZero: true,
                            grid: {color: '#eef2f7'},
                            border: {display: false},
                            ticks: {
                                font: {family: '"JetBrains Mono", monospace', size: 10},
                                color: '#8c7168',
                                callback: function (value) {
                                    return moneyAxis ? money(value).replace(/\.00$/, '') : num(value);
                                }
                            }
                        }
                    }
                }
            });
            return true;
        }

        function renderView() {
            var chartable = hasData() && valueColumns().length > 0 && state.columns.length > 1;

            $el('views').find('button').each(function () {
                var view = $(this).data('view');

                $(this).toggleClass('is-active', view === state.view)
                    .prop('disabled', view !== 'table' && !chartable);
            });

            if (!hasData()) {
                $el('table-view').prop('hidden', true);
                $el('chart-view').prop('hidden', true);
                $el('no-data').prop('hidden', false);
                return;
            }
            $el('no-data').prop('hidden', true);

            if (state.view === 'table' || !chartable || !renderChart()) {
                $el('chart-view').prop('hidden', true);
                $el('table-view').prop('hidden', false);
                renderTable();
                return;
            }
            $el('table-view').prop('hidden', true);
            $el('chart-view').prop('hidden', false);
        }

        // ── SQL inspector ───────────────────────────────────────────────────

        var SQL_KEYWORDS = /\b(SELECT|FROM|WHERE|AND|OR|NOT|IN|IS|NULL|AS|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|GROUP|BY|ORDER|HAVING|LIMIT|OFFSET|DISTINCT|UNION|ALL|CASE|WHEN|THEN|ELSE|END|WITH|ASC|DESC|BETWEEN|LIKE|EXISTS|INTERVAL|USING)\b/i,
            SQL_TOKENS = /('(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.)*"|`[^`]*`)|(\b\d+(?:\.\d+)?\b)|(\b[A-Z_]+\b)(?=\s*\()|(\b[A-Za-z_]+\b)/g;

        var SQL_CLAUSE = /^(?:(?:LEFT |RIGHT |INNER |CROSS )?(?:OUTER )?JOIN|FROM|WHERE|GROUP BY|ORDER BY|HAVING|LIMIT|UNION(?: ALL)?)\b/i,
            SQL_CONDITION = /^(?:AND|OR)\b/i;

        /**
         * Layout for SQL that arrives on one line: one selected column per line, each top-level clause
         * on its own line, AND / OR conditions indented. Subqueries and quoted text stay as they are.
         */
        function prettySql(sql) {
            var out = '',
                depth = 0,
                quote = '',
                selectList = false,
                i = 0,
                ch,
                rest,
                match,
                head = /^\s*SELECT(\s+DISTINCT)?\s+/i.exec(sql);

            if (sql.indexOf('\n') !== -1) {
                return sql;
            }
            if (head) {
                out = 'SELECT' + (head[1] ? ' DISTINCT' : '') + '\n    ';
                i = head[0].length;
                selectList = true;
            }
            while (i < sql.length) {
                ch = sql.charAt(i);
                if (quote) {
                    out += ch;
                    if (ch === '\\' && i + 1 < sql.length) {
                        out += sql.charAt(i + 1);
                        i += 2;
                        continue;
                    }
                    quote = ch === quote ? '' : quote;
                    i++;
                    continue;
                }
                if (ch === "'" || ch === '"' || ch === '`') {
                    quote = ch;
                } else if (ch === '(') {
                    depth++;
                } else if (ch === ')') {
                    depth = Math.max(0, depth - 1);
                } else if (depth === 0 && ch === ',' && selectList) {
                    out += ',\n    ';
                    i = i + 1 + /^\s*/.exec(sql.slice(i + 1))[0].length;
                    continue;
                } else if (depth === 0 && /\s/.test(ch)) {
                    rest = sql.slice(i).replace(/^\s+/, '');
                    match = SQL_CLAUSE.exec(rest) || SQL_CONDITION.exec(rest);
                    if (match) {
                        selectList = false;
                        out = out.replace(/\s+$/, '') + (SQL_CONDITION.test(match[0]) ? '\n  ' : '\n') + match[0];
                        i = sql.length - rest.length + match[0].length;
                        continue;
                    }
                }
                out += ch;
                i++;
            }
            return out;
        }

        function highlightSql(sql) {
            var html = '',
                last = 0;

            sql.replace(SQL_TOKENS, function (match, string, number, fn, word, offset) {
                html += esc(sql.slice(last, offset));
                if (string) {
                    html += '<span class="mxq-sql-str">' + esc(match) + '</span>';
                } else if (number) {
                    html += '<span class="mxq-sql-num">' + esc(match) + '</span>';
                } else if (fn) {
                    html += '<span class="mxq-sql-fn">' + esc(match) + '</span>';
                } else if (SQL_KEYWORDS.test(word)) {
                    html += '<span class="mxq-sql-kw">' + esc(match) + '</span>';
                } else {
                    html += esc(match);
                }
                last = offset + match.length;
                return match;
            });
            return html + esc(sql.slice(last));
        }

        function tablesUsed(sql) {
            var found = [],
                pattern = /\b(?:FROM|JOIN)\s+`?([a-z0-9_]+)`?/gi,
                match;

            while ((match = pattern.exec(sql)) !== null) {
                if (found.indexOf(match[1]) === -1) {
                    found.push(match[1]);
                }
            }
            return found;
        }

        function renderSql(response) {
            var tables = tablesUsed(state.sql),
                notes = ['Read-only transaction', 'max ' + num(config.maxRows) + ' rows', config.queryTimeout + ' s timeout'];

            $el('sql-code').html(highlightSql(prettySql(state.sql)));
            $el('sql-tables').text(tables.slice(0, 3).join(' + ') + (tables.length > 3 ? ' + ' + (tables.length - 3) + ' more' : ''))
                .prop('hidden', !tables.length);
            if (response.repaired) {
                notes.push('corrected once after a database error');
            }
            $el('sql-foot').html('<span>' + esc(notes.join(' · ')) + '</span><span>' + esc(response.report_id
                ? 'Saved report' : 'Generated by ' + config.provider + ' · ' + config.model) + '</span>');
        }

        // ── Running questions ───────────────────────────────────────────────

        function setRunning(on, message) {
            running = on;
            $el('run').prop('disabled', on);
            if (on) {
                $el('results').prop('hidden', true);
                $el('state').prop('hidden', false).addClass('is-loading').html(
                    '<span class="mxq-state__icon">' + icon('progress_activity', 'lg') + '</span><strong>' + esc(message)
                    + '</strong><span>' + esc('Generating SQL with ' + config.provider + ' · ' + config.model
                        + '. Local models can take a minute.') + '</span>'
                );
            } else {
                $el('state').removeClass('is-loading');
            }
        }

        function showStateMessage(iconName, title, text, tone) {
            $el('results').prop('hidden', true);
            $el('state').prop('hidden', false).attr('data-tone', tone || '').html(
                '<span class="mxq-state__icon">' + icon(iconName, 'lg') + '</span><strong>' + esc(title)
                + '</strong><span>' + text + '</span>'
            );
        }

        function execute(data, question) {
            if (running) {
                return;
            }
            setRunning(true, question ? 'Answering: ' + question : 'Running saved report…');

            $.ajax({
                url: config.executeUrl,
                type: 'POST',
                dataType: 'json',
                data: $.extend({form_key: config.formKey}, data),
                timeout: 300000
            }).done(function (response) {
                setRunning(false);
                if (response && response.success) {
                    showResults(response, question || state.question, !!data.report_id);
                } else if (response && response.direct_answer) {
                    showStateMessage('chat', 'Answered without a query', 'The Copilot replied in the panel on the right.', 'info');
                    appendUser(question);
                    appendBot('<div class="mxq-msg__text">' + formatAnswer(response.direct_answer) + '</div>', {kicker: 'Copilot'});
                } else {
                    showStateMessage('error', 'The question could not be answered', esc((response && response.message)
                        || 'An error occurred.'), 'error');
                }
            }).fail(function (xhr, status) {
                setRunning(false);
                showStateMessage('error', 'Request failed', esc(status === 'timeout'
                    ? 'The AI took too long. Try a simpler question or increase the AI timeout.'
                    : 'HTTP ' + xhr.status + '. Please try again.'), 'error');
            });
        }

        function runQuestion(question) {
            question = $.trim(question || $el('question').val());
            if (!question) {
                $el('question').trigger('focus');
                return;
            }
            $el('question').val(question);
            closeSuggest();
            state.question = question;
            execute({query: question}, question);
        }

        function showResults(response, question, isSaved) {
            state.question = question || '';
            state.columns = response.columns || [];
            state.rows = response.rows || [];
            state.sql = response.sql_query || '';
            state.token = response.query_token || '';
            state.page = 1;
            state.x = null;
            state.ys = [];
            state.kinds = analyseColumns(state.columns, state.rows);

            $el('state').prop('hidden', true).attr('data-tone', '');
            $el('results').prop('hidden', false);
            renderMeta(response);
            if (response.assumption) {
                $el('assumption').html(icon('info', 'sm') + ' <span>Interpreted as: ' + esc(response.assumption) + '</span>').prop('hidden', false);
            } else {
                $el('assumption').prop('hidden', true).empty();
            }
            renderView();
            renderSql($.extend({report_id: isSaved}, response));

            if (state.summary && !isSaved) {
                appendUser(state.question);
                appendSummary();
            }
            state.attach = !!state.question;
            renderAttach();
        }

        // ── Copilot ─────────────────────────────────────────────────────────

        function scrollThread() {
            var thread = $el('thread')[0];

            thread.scrollTop = thread.scrollHeight;
        }

        function appendUser(text, about) {
            $el('thread').append('<div class="mxq-msg mxq-msg--user"><div class="mxq-msg__bubble">' + esc(text)
                + (about ? '<span class="mxq-msg__about">' + icon('attach_file', 'xs') + esc(about) + '</span>' : '')
                + '</div></div>');
            scrollThread();
        }

        function appendBot(innerHtml, options) {
            options = options || {};
            $el('thread').append('<div class="mxq-msg mxq-msg--bot"><div class="mxq-msg__card' + (options.error ? ' is-error' : '') + '">'
                + (options.kicker ? '<div class="mxq-msg__kicker">' + icon(options.kickerIcon || 'auto_awesome') + esc(options.kicker) + '</div>' : '')
                + innerHtml
                + '<div class="mxq-msg__time">' + esc(nowLabel() + (options.footnote ? ' • ' + options.footnote : '')) + '</div>'
                + '</div></div>');
            scrollThread();
        }

        /** The "attached" Studio result: typed follow-ups are sent with the Studio question as context. */
        function renderAttach() {
            var on = !!(state.attach && state.question);

            $el('chat-attach').prop('disabled', !state.question).toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false')
                .attr('title', state.question ? (on ? 'Follow-ups refer to the Studio result. Click to detach.'
                    : 'Ask about the Studio result') : 'Ask about the Studio result (run a question first)');
            $el('attached').prop('hidden', !on);
            $el('attached-text').text(on ? 'Re: ' + state.question : '');
        }

        /**
         * @param {string} text
         * @param {boolean} [withContext] send the Studio question along, for follow-ups such as "which customers bought it?"
         */
        function sendChat(text, withContext) {
            var message;

            text = $.trim(text || '');
            if (!text || chatting) {
                return;
            }
            if (!config.aiConfigured) {
                appendUser(text);
                appendBot('<p>The AI provider is not configured. Set it up under Configure Engine below.</p>', {error: true});
                return;
            }
            message = withContext && state.attach && state.question
                ? 'Follow-up to the question "' + state.question.slice(0, 200) + '": ' + text
                : text;
            chatting = true;
            $el('chat-input').val('');
            $el('chat-send').prop('disabled', true);
            appendUser(text, message !== text ? state.question : '');
            $el('typing').prop('hidden', false);
            scrollThread();

            $.ajax({
                url: config.chatUrl,
                type: 'POST',
                dataType: 'json',
                data: {message: message, form_key: config.formKey, isAjax: true},
                timeout: 300000
            }).done(function (response) {
                var html;

                if (!response || !response.success) {
                    appendBot('<p>' + esc((response && response.message) || 'Something went wrong.') + '</p>', {error: true});
                    return;
                }
                html = '<div class="mxq-msg__text">' + formatAnswer(response.answer) + '</div>';
                if (response.assumption) {
                    html += '<p class="mxq-msg__assumption">' + icon('info', 'xs') + ' Interpreted as: ' + esc(response.assumption) + '</p>';
                }
                if (response.sql) {
                    html += '<div class="mxq-msg__actions">'
                        + '<button type="button" class="mxq-action" data-run-question="' + esc(text) + '">' + icon('table_chart', 'xs') + ' Open in Studio</button>'
                        + '<button type="button" class="mxq-action mxq-action--ghost" data-toggle-sql>' + icon('code', 'xs') + ' Show SQL</button>'
                        + '</div><pre class="mxq-msg__sql" hidden>' + highlightSql(prettySql(response.sql)) + '</pre>';
                }
                appendBot(html, {
                    kicker: response.sql ? 'Answer from your store data' : 'Copilot',
                    kickerIcon: response.sql ? 'insights' : 'chat',
                    footnote: response.sql ? num(response.rows) + ' rows · ' + num(response.time_ms) + ' ms · read-only query' : ''
                });
            }).fail(function (xhr, status) {
                appendBot('<p>' + esc(status === 'timeout' ? 'The AI took too long to answer. Try a simpler question.'
                    : 'Request failed (HTTP ' + xhr.status + ').') + '</p>', {error: true});
            }).always(function () {
                chatting = false;
                $el('typing').prop('hidden', true);
                $el('chat-send').prop('disabled', false);
            });
        }

        /** A summary of the result on screen, computed here (nothing is sent to the AI). */
        function appendSummary() {
            var label = labelColumn(),
                values = valueColumns(),
                value = values[0],
                html,
                total,
                top,
                share,
                low = [],
                stockCol,
                followUps = [],
                productLike = label && /(name|product|sku|title)/i.test(label);

            if (!hasData()) {
                appendBot('<p>The query for “' + esc(state.question) + '” ran, but returned no rows.</p>',
                    {kicker: 'Result Summary', kickerIcon: 'insights', footnote: 'computed in your browser'});
                return;
            }

            if (state.rows.length === 1) {
                html = '<p>' + state.columns.slice(0, 5).map(function (column) {
                    var v = state.rows[0][column],
                        kind = state.kinds[column];

                    return esc(humanize(column)) + ': <strong>' + esc(v === null ? '—' : (kind.money ? money(v) : (kind.numeric ? num(v) : v))) + '</strong>';
                }).join('<br>') + '</p>';
            } else if (value) {
                total = state.rows.reduce(function (sum, row) {
                    return sum + (parseFloat(row[value]) || 0);
                }, 0);
                top = state.rows.reduce(function (best, row) {
                    return (parseFloat(row[value]) || 0) > (parseFloat(best[value]) || 0) ? row : best;
                }, state.rows[0]);
                share = total ? (parseFloat(top[value]) || 0) / total * 100 : 0;
                html = '<p>These <strong>' + num(state.rows.length) + '</strong> rows add up to <strong>'
                    + esc(state.kinds[value].money ? money(total) : num(total)) + '</strong> ' + esc(humanize(value)) + '.'
                    + (label ? ' <strong>' + esc(top[label]) + '</strong>'
                        + (top.sku && label !== 'sku' ? ' (' + esc(top.sku) + ')' : '')
                        + ' leads with ' + esc(state.kinds[value].money ? money(top[value]) : num(top[value]))
                        + ', ' + share.toFixed(1) + '% of the total.' : '') + '</p>';
            } else {
                html = '<p><strong>' + num(state.rows.length) + '</strong> rows returned for “' + esc(state.question) + '”.</p>';
            }

            stockCol = stockColumn();
            if (stockCol && label) {
                state.rows.filter(function (row) {
                    return row[stockCol] !== null && row[stockCol] !== '' && Number(row[stockCol]) <= LOW_STOCK_QTY;
                }).sort(function (a, b) {
                    return Number(a[stockCol]) - Number(b[stockCol]);
                }).forEach(function (row) {
                    if (low.length < 2) {
                        low.push(row);
                    }
                });
                if (low.length) {
                    html += '<div class="mxq-msg__alert">' + icon('priority_high', 'sm') + '<div><strong>Inventory bottlenecks:</strong> '
                        + low.map(function (row) {
                            return '<strong>' + esc(row[label]) + '</strong> has only ' + esc(num(row[stockCol])) + ' in stock';
                        }).join('; ') + '.</div></div>';
                }
            }

            html += '<div class="mxq-msg__actions">'
                + (state.view === 'table' && values.length && state.columns.length > 1
                    ? '<button type="button" class="mxq-action" data-switch-view="bar">' + icon('bar_chart', 'xs') + ' Switch to Bar Chart</button>'
                    : '<button type="button" class="mxq-action" data-switch-view="table">' + icon('table_chart', 'xs') + ' Show as Table</button>')
                + (low.length
                    ? '<button type="button" class="mxq-action mxq-action--primary" data-chat="' + esc('How many units of '
                        + (low[0].sku || low[0][label]) + ' were sold per day over the last 30 days, and how many days of stock are left?')
                        + '">' + icon('schedule', 'xs') + ' Forecast Stockout</button>'
                    : '')
                + (state.token ? '<button type="button" class="mxq-action' + (low.length ? '' : ' mxq-action--primary')
                    + '" data-open-save>' + icon('bookmark_add', 'xs') + ' Save as Report</button>' : '')
                + '</div>';

            appendBot(html, {
                kicker: 'Executive Summary • ' + new Date().toLocaleDateString(undefined, {month: 'long', year: 'numeric'}),
                kickerIcon: 'insights',
                footnote: 'computed in your browser from the rows on the left'
            });

            if (label && top) {
                followUps.push('How did ' + top[label] + ' perform month by month this year?');
                followUps.push(productLike ? 'Which customers bought ' + top[label] + ' the most?'
                    : 'Compare these results with the previous period');
                $el('thread').append('<div class="mxq-followups"><span>Suggested follow-ups:</span>' + followUps.map(function (q) {
                    return '<button type="button" data-chat="' + esc(q) + '">→ “' + esc(q) + '”</button>';
                }).join('') + '</div>');
                scrollThread();
            }
        }

        // ── Suggestions (Ctrl + Space) ──────────────────────────────────────

        function openSuggest() {
            var words = $.trim($el('question').val()).toLowerCase().split(/\s+/).filter(Boolean),
                items = config.suggestions.filter(function (text) {
                    var lower = text.toLowerCase();

                    return words.every(function (word) {
                        return lower.indexOf(word) !== -1;
                    });
                }).slice(0, 8);

            suggestIndex = -1;
            if (!items.length) {
                items = config.suggestions.slice(0, 8);
            }
            $el('suggest').html(items.map(function (text) {
                return '<li role="option" data-question="' + esc(text) + '">' + icon('north_west', 'xs') + esc(text) + '</li>';
            }).join('')).prop('hidden', false);
        }

        function closeSuggest() {
            $el('suggest').prop('hidden', true).empty();
            suggestIndex = -1;
        }

        function moveSuggest(step) {
            var $items = $el('suggest').find('li');

            if (!$items.length) {
                return;
            }
            suggestIndex = (suggestIndex + step + $items.length) % $items.length;
            $items.removeClass('is-active').eq(suggestIndex).addClass('is-active');
        }

        // ── Save as report ──────────────────────────────────────────────────

        function openSave() {
            if (!state.token) {
                toast('Run a question first.', true);
                return;
            }
            $el('save-title').val(state.question.slice(0, 80));
            $el('save-hint').text('Saved with the ' + ({table: 'table', bar: 'bar chart', line: 'trend chart', pie: 'pie chart'})[state.view]
                + ' view. You can run it again from Saved Reports.');
            $el('save-modal').prop('hidden', false);
            $el('save-title').trigger('focus').trigger('select');
        }

        function closeSave() {
            $el('save-modal').prop('hidden', true);
        }

        function confirmSave() {
            var title = $.trim($el('save-title').val());

            if (!title) {
                $el('save-title').addClass('is-invalid').trigger('focus');
                return;
            }
            $el('save-confirm').prop('disabled', true);
            $.ajax({
                url: config.saveUrl,
                type: 'POST',
                dataType: 'json',
                data: {title: title, query_token: state.token, chart_type: state.view, form_key: config.formKey}
            }).done(function (response) {
                if (response && response.success) {
                    closeSave();
                    toast(response.message || 'Report saved.');
                    $el('saved-count').text(Number($el('saved-count').text()) + 1);
                } else {
                    toast((response && response.message) || 'Could not save the report.', true);
                }
            }).fail(function () {
                toast('Could not save the report.', true);
            }).always(function () {
                $el('save-confirm').prop('disabled', false);
            });
        }

        function downloadCsv() {
            var lines = [state.columns],
                csv,
                link;

            state.rows.forEach(function (row) {
                lines.push(state.columns.map(function (column) {
                    return row[column];
                }));
            });
            csv = lines.map(function (line) {
                return line.map(function (cell) {
                    var text = cell === null || cell === undefined ? '' : String(cell);

                    if (/^[=+\-@\t\r]/.test(text)) {
                        text = "'" + text;
                    }
                    return '"' + text.replace(/"/g, '""') + '"';
                }).join(',');
            }).join('\r\n');

            link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8'}));
            link.download = 'ai_report_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(link);
            link.click();
            setTimeout(function () {
                URL.revokeObjectURL(link.href);
                link.remove();
            }, 0);
        }

        // ── Tabs and options ────────────────────────────────────────────────

        function setTab(tab) {
            prefs.tab = tab === 'stream' ? 'stream' : 'studio';
            $root.toggleClass('is-stream', prefs.tab === 'stream');
            $el('tabs').find('[data-tab]').each(function () {
                var active = $(this).data('tab') === prefs.tab;

                $(this).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
            });
            savePrefs();
            if (prefs.tab === 'studio' && chart) {
                chart.resize();
            }
        }

        function syncOptions() {
            $el('opt-view').filter('[value="' + (prefs.view || 'table') + '"]').prop('checked', true);
            $el('opt-page-size').val(String(state.pageSize));
            $el('opt-summary').prop('checked', state.summary);
        }

        // ── Events ──────────────────────────────────────────────────────────

        $el('ask-form').on('submit', function (event) {
            var $active = $el('suggest').find('li.is-active');

            event.preventDefault();
            if ($active.length) {
                $el('question').val($active.data('question'));
            }
            runQuestion();
        });

        $el('question').on('keydown', function (event) {
            var open = !$el('suggest').prop('hidden');

            if (event.ctrlKey && (event.code === 'Space' || event.key === ' ')) {
                event.preventDefault();
                if (open) {
                    closeSuggest();
                } else {
                    openSuggest();
                }
            } else if (open && event.key === 'ArrowDown') {
                event.preventDefault();
                moveSuggest(1);
            } else if (open && event.key === 'ArrowUp') {
                event.preventDefault();
                moveSuggest(-1);
            } else if (event.key === 'Escape') {
                closeSuggest();
            }
        }).on('input', function () {
            if (!$el('suggest').prop('hidden')) {
                openSuggest();
            }
        });

        $el('suggest').on('mousedown', 'li', function (event) {
            event.preventDefault();
            runQuestion($(this).data('question'));
        });

        $root.on('click', '[data-question]', function () {
            if ($(this).is('li')) {
                return;
            }
            $root.find('.mxq-prompt').removeClass('is-active');
            $(this).filter('.mxq-prompt').addClass('is-active');
            runQuestion($(this).data('question'));
        });

        $el('more-toggle').on('click', function () {
            var open = $el('more').prop('hidden');

            $el('more').prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false').toggleClass('is-open', open);
        });

        $el('options-toggle').on('click', function (event) {
            var open = $el('options').prop('hidden');

            event.stopPropagation();
            syncOptions();
            $el('options').prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('[data-role="options"], [data-role="options-toggle"]').length) {
                $el('options').prop('hidden', true);
                $el('options-toggle').attr('aria-expanded', 'false');
            }
            if (!$(event.target).closest('[data-role="ask-form"]').length) {
                closeSuggest();
            }
        });

        $el('options').on('change', 'input, select', function () {
            prefs.view = $el('opt-view').filter(':checked').val() || 'table';
            state.pageSize = Number($el('opt-page-size').val()) || 10;
            state.summary = $el('opt-summary').prop('checked');
            savePrefs();
            if (hasData() && state.view === 'table') {
                state.page = 1;
                renderTable();
            }
        });

        $el('views').on('click', 'button', function () {
            state.view = $(this).data('view');
            renderView();
        });

        $el('x-axis').on('change', function () {
            state.x = $(this).val();
            renderView();
        });

        $el('y-axis').on('change', function () {
            state.ys = $(this).val() || [];
            renderView();
        });

        $el('pager').on('click', '[data-page]', function () {
            state.page = Number($(this).data('page'));
            renderTable();
        });

        $el('tbody').on('click', '.mxq-rowask', function () {
            var row = state.rows[Number($(this).data('row'))] || {},
                label = labelColumn();

            sendChat(row.sku ? 'Show the monthly sales of SKU ' + row.sku + ' this year'
                : 'Tell me more about ' + (label ? row[label] : 'this result') + ' (from: ' + state.question + ')');
        });

        $el('download').on('click', downloadCsv);
        $el('save').on('click', openSave);
        $root.on('click', '[data-role="save-cancel"]', closeSave);
        $el('save-confirm').on('click', confirmSave);
        $el('save-title').on('keydown', function (event) {
            $(this).removeClass('is-invalid');
            if (event.key === 'Enter') {
                confirmSave();
            } else if (event.key === 'Escape') {
                closeSave();
            }
        });

        $el('copy-sql').on('click', function (event) {
            event.stopPropagation();
            navigator.clipboard.writeText(state.sql).then(function () {
                $el('copy-label').text('Copied!');
                setTimeout(function () {
                    $el('copy-label').text('Copy SQL');
                }, 1800);
            });
        });

        $el('sql-toggle').on('click keydown', function (event) {
            var open;

            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            open = $el('sql-body').prop('hidden');
            $el('sql-body').prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $el('sql').toggleClass('is-collapsed', !open);
        });

        $el('tabs').on('click', '[data-tab]', function () {
            setTab($(this).data('tab'));
        });

        $el('chat-form').on('submit', function (event) {
            event.preventDefault();
            sendChat($el('chat-input').val(), true);
        });

        $el('chat-attach').on('click', function () {
            state.attach = !state.attach;
            renderAttach();
            $el('chat-input').trigger('focus');
        });

        $el('attach-remove').on('click', function () {
            state.attach = false;
            renderAttach();
        });

        $root.on('click', '[data-chat]', function () {
            sendChat($(this).data('chat'));
        });

        $el('thread').on('click', '[data-switch-view]', function () {
            state.view = $(this).data('switchView');
            renderView();
            setTab('studio');
        }).on('click', '[data-open-save]', openSave)
            .on('click', '[data-run-question]', function () {
                setTab('studio');
                runQuestion($(this).data('runQuestion'));
            }).on('click', '[data-toggle-sql]', function () {
                var $sql = $(this).closest('.mxq-msg__card').find('.mxq-msg__sql'),
                    show = $sql.prop('hidden');

                $sql.prop('hidden', !show);
                $(this).html(icon('code', 'xs') + (show ? ' Hide SQL' : ' Show SQL'));
            });

        $el('chat-reset').on('click', function () {
            $el('thread').children().not('[data-role="welcome"]').remove();
            $.post(config.chatUrl, {reset: 1, form_key: config.formKey, isAjax: true});
            toast('New conversation started.');
        });

        // ── Start ───────────────────────────────────────────────────────────

        setTab(prefs.tab);
        syncOptions();

        // A saved report opened from the Saved Reports page
        (function () {
            var saved;

            try {
                saved = JSON.parse(window.sessionStorage.getItem(SAVED_RUN_KEY) || 'null');
                window.sessionStorage.removeItem(SAVED_RUN_KEY);
            } catch (e) {
                saved = null;
            }
            if (saved && parseInt(saved.report_id, 10) > 0) {
                state.question = saved.nlp || '';
                state.view = VIEWS.indexOf(saved.chart) !== -1 ? saved.chart : 'table';
                $el('question').val(state.question);
                setTab('studio');
                execute({report_id: parseInt(saved.report_id, 10)}, state.question);
            } else {
                state.view = prefs.view && VIEWS.indexOf(prefs.view) !== -1 ? prefs.view : 'table';
            }
        }());
    };
});
