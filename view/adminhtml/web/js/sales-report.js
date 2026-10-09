/**
 * Meetanshi AIReporting — Sales & Revenue Intelligence
 *
 * Renders every section from the range data (first from the page, then from reports/salesData):
 * KPI cards, ticker, revenue velocity and order lifecycle charts, the 30-day trajectory, weekday
 * distribution, region / shipping / promotion tables and the rule-based copilot summary. Wires
 * the range filter, CSV exports (reports/salesExport), print-to-PDF, Copy SQL and AI follow-ups
 * (chat/send).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery', 'chartjs'], function ($, Chart) {
    'use strict';

    var BRAND = '#ee672f',
        NAVY = '#213145',
        SLATE = '#545f73',
        BAR_SOFT = '#dce9ff',
        STATE_COLORS = {
            complete: BRAND,
            processing: '#8da2c9',
            new: '#bec6e0',
            holded: '#f59e0b',
            payment_review: '#0ea5e9',
            pending_payment: '#fcd34d',
            closed: '#ba1a1a',
            canceled: '#94a3b8'
        },
        FALLBACK_COLORS = ['#545c72', '#f4a27f', '#14b8a6', '#a855f7', '#64748b'],
        UNIT_WORDS = {hour: 'Hourly', day: 'Daily', month: 'Monthly'},
        PROMO_STATUS = {
            active: ['Active', 'brand'],
            automatic: ['Automatic', 'navy'],
            scheduled: ['Scheduled', 'amber'],
            expired: ['Expired', 'grey'],
            inactive: ['Inactive', 'grey']
        };

    return function (config, element) {
        var $root = $(element),
            data = config.data,
            charts = {},
            loading = false,
            showAllRegions = false,
            moneyFmt = new Intl.NumberFormat(undefined, {style: 'currency', currency: config.currency}),
            moneyWhole = new Intl.NumberFormat(undefined, {
                style: 'currency', currency: config.currency, maximumFractionDigits: 0
            }),
            moneyCompact = new Intl.NumberFormat(undefined, {
                style: 'currency', currency: config.currency, notation: 'compact', maximumFractionDigits: 1
            }),
            numberFmt = new Intl.NumberFormat(undefined, {maximumFractionDigits: 2}),
            pctFmt = new Intl.NumberFormat(undefined, {maximumFractionDigits: 1});

        // ── Helpers ─────────────────────────────────────────────────────────

        function $el(role) {
            return $root.find('[data-role="' + role + '"]');
        }

        function esc(value) {
            return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
        }

        function money(value) {
            return moneyFmt.format(Number(value) || 0);
        }

        function num(value) {
            return numberFmt.format(Number(value) || 0);
        }

        function pct(value) {
            return pctFmt.format(Number(value) || 0) + '%';
        }

        function share(part, whole) {
            return whole > 0 ? part / whole * 100 : 0;
        }

        function plural(count, one, many) {
            return num(count) + ' ' + (Number(count) === 1 ? one : many);
        }

        function icon(name, size) {
            return '<span class="mxd-ico mxd-ico--' + (size || 'sm') + '" aria-hidden="true">' + name + '</span>';
        }

        function badge(text, tone, title) {
            return '<span class="mxc-badge mxc-badge--' + (tone || 'brand') + '"'
                + (title ? ' title="' + esc(title) + '"' : '') + '>' + esc(text) + '</span>';
        }

        function signed(change) {
            return (change > 0 ? '+' : '') + pct(change);
        }

        /** "Sep 24" from "2026-09-24" (store-local, no timezone shift). */
        function shortDate(ymd) {
            var parts = String(ymd || '').split('-');

            return parts.length === 3
                ? new Date(+parts[0], parts[1] - 1, +parts[2]).toLocaleDateString(undefined, {month: 'short', day: 'numeric'})
                : String(ymd || '');
        }

        function rangeName() {
            return data.range.key === 'custom' ? 'Custom Range'
                : $.trim($el('range').find('[data-range="' + data.range.key + '"]').text().replace(/\s*\(\d{4}\)$/, ''));
        }

        function exportUrl(params) {
            var query = $.param(params || {});

            return config.exportUrl + (query ? (config.exportUrl.indexOf('?') === -1 ? '?' : '&') + query : '');
        }

        function rangeParams() {
            return data.range.key === 'custom'
                ? {range: 'custom', start: data.range.start, end: data.range.end}
                : {range: data.range.key};
        }

        function destroyChart(name) {
            if (charts[name]) {
                charts[name].destroy();
                delete charts[name];
            }
        }

        function axisTicks(extra) {
            return $.extend({color: '#586377', font: {family: 'JetBrains Mono', size: 10}}, extra || {});
        }

        function peakIndex(series) {
            var index = -1;

            series.forEach(function (row, i) {
                if (row.revenue > 0 && (index === -1 || row.revenue > series[index].revenue)) {
                    index = i;
                }
            });

            return index;
        }

        // ── KPI cards and ticker ────────────────────────────────────────────

        function kpiCard(options) {
            return '<div class="mxc-kpi mxr-kpi">'
                + '<div class="mxc-kpi__head"><span class="mxc-kpi__label">' + esc(options.label) + '</span>'
                + '<span class="mxc-kpi__icon mxc-kpi__icon--' + (options.tone || 'brand') + '">' + icon(options.icon, 'md') + '</span></div>'
                + '<div class="mxc-kpi__value"><span class="mxc-metric">' + esc(options.value) + '</span>'
                + (options.unit ? '<span class="mxc-kpi__unit">' + esc(options.unit) + '</span>' : '')
                + (options.badge || '') + '</div>'
                + '<p class="mxr-kpi__foot"' + (options.footTitle ? ' title="' + esc(options.footTitle) + '"' : '') + '>'
                + options.foot + '</p>'
                + '<div class="mxr-kpibar" title="' + esc(options.barTitle || '') + '"><span class="mxr-kpibar__fill mxr-kpibar__fill--'
                + (options.barTone || 'brand') + '" style="width:' + barWidth(options.bar).toFixed(1) + '%"></span></div>'
                + '</div>';
        }

        function barWidth(value) {
            value = Number(value) || 0;

            return value > 0 ? Math.max(1.5, Math.min(100, value)) : 0;
        }

        function changeBadge(change, current, what) {
            if (change === null || change === undefined) {
                return current > 0 ? badge('New', 'brand', 'Nothing in the previous period of the same length') : '';
            }

            return '<span class="mxr-change mxr-change--' + (change < 0 ? 'down' : 'up') + '" title="'
                + esc(what + ' vs ' + shortDate(data.previous.start) + ' – ' + shortDate(data.previous.end)) + '">'
                + icon(change < 0 ? 'trending_down' : 'trending_up', 'xs') + esc(signed(change)) + '</span>';
        }

        function renderKpis() {
            var t = data.totals,
                top = data.top_order,
                excluded = t.pending_payment + t.canceled,
                orderDiff = t.orders - data.previous.orders,
                rateTone = t.refund_rate <= 2 ? ['Healthy', 'brand'] : (t.refund_rate <= 5 ? ['Watch', 'amber'] : ['High', 'red']);

            $el('kpis').html([
                kpiCard({
                    label: 'Total Net Revenue', icon: 'payments',
                    value: money(t.revenue),
                    badge: changeBadge(t.revenue_change, t.revenue, 'Net revenue'),
                    foot: 'Net of ' + esc(money(t.refunded)) + ' refunds across ' + esc(plural(t.orders, 'order', 'orders')),
                    bar: share(t.revenue, t.gross),
                    barTitle: pct(share(t.revenue, t.gross)) + ' of gross revenue kept after refunds'
                }),
                kpiCard({
                    label: 'Order Volume', icon: 'shopping_cart', tone: 'slate',
                    value: num(t.orders), unit: t.orders === 1 ? 'Order' : 'Orders',
                    badge: data.previous.orders || t.orders
                        ? '<span class="mxr-change mxr-change--' + (orderDiff < 0 ? 'down' : 'up') + '" title="'
                            + esc(num(data.previous.orders) + ' orders in the previous period') + '">'
                            + icon(orderDiff < 0 ? 'arrow_downward' : 'arrow_upward', 'xs') + esc(num(Math.abs(orderDiff))) + ' Net</span>'
                        : '',
                    foot: excluded
                        ? esc(plural(t.pending_payment, 'pending payment', 'pending payment')) + ' / '
                            + esc(plural(t.canceled, 'canceled order', 'canceled orders')) + ' not counted'
                        : 'No pending-payment or canceled orders',
                    bar: share(t.orders, t.all_orders),
                    barTone: 'navy',
                    barTitle: pct(share(t.orders, t.all_orders)) + ' of all orders placed are counted as sales'
                }),
                kpiCard({
                    label: 'Avg. Order Value (AOV)', icon: 'analytics', tone: 'slate',
                    value: money(t.aov),
                    unit: '(Gross ' + money(t.gross_aov) + ')',
                    badge: changeBadge(t.aov_change, t.aov, 'Average order value'),
                    foot: top
                        ? 'Highest single ticket: <a href="' + esc(config.orderUrl.replace('__ID__', top.order_id)) + '" target="_blank" rel="noopener">'
                            + esc(money(top.amount)) + '</a>' + (top.place ? ' (' + esc(top.place) + ')' : '')
                        : 'No orders in this range',
                    footTitle: top ? 'Order #' + top.increment_id : '',
                    bar: top && top.amount > 0 ? share(t.gross_aov, top.amount) : 0,
                    barTone: 'navy',
                    barTitle: 'Average gross order as a share of the highest order'
                }),
                kpiCard({
                    label: 'Refunds & Credit Memos', icon: 'currency_exchange', tone: 'red',
                    value: money(t.memo_refunded),
                    badge: t.gross > 0 || t.memos
                        ? badge(pct(t.refund_rate) + ' Rate · ' + rateTone[0], rateTone[1], 'Credit memo total as a share of gross revenue')
                        : '',
                    foot: t.memos
                        ? esc(plural(t.memos, 'credit memo', 'credit memos')) + ' created in this range'
                        : 'No credit memos created in this range',
                    bar: Math.min(100, t.refund_rate * 10),
                    barTone: 'red',
                    barTitle: 'Refund rate (full bar = 10%)'
                })
            ].join(''));
        }

        function renderTicker() {
            var t = data.totals;

            $el('ticker').html(
                '<div>' + icon('sell') + '<span class="mxc-ticker__label" title="Cart price rules and coupon codes">Discounts Applied:</span>'
                + '<span class="mxd-mono mxd-text-red"><strong>' + esc(t.discount > 0 ? '-' + money(t.discount) : money(0)) + '</strong></span></div>'
                + '<i class="mxc-ticker__sep"></i>'
                + '<div>' + icon('account_balance') + '<span class="mxc-ticker__label">Tax Collected:</span>'
                + '<span class="mxd-mono"><strong>' + esc(money(t.tax)) + '</strong></span></div>'
                + '<i class="mxc-ticker__sep"></i>'
                + '<div>' + icon('local_shipping') + '<span class="mxc-ticker__label">Shipping Charged:</span>'
                + '<span class="mxd-mono"><strong>' + esc(money(t.shipping)) + '</strong></span></div>'
            );
            $el('latency').text('SQL exec: ' + num(data.query_ms) + 'ms · sales_order');
        }

        // ── Velocity chart ──────────────────────────────────────────────────

        function renderTrend() {
            var series = data.series,
                peak = peakIndex(series),
                canvas = $el('trend-chart')[0];

            $el('trend-title').text('Revenue & Order Volume Velocity (' + rangeName() + ')');
            $el('trend-sub').text(peak === -1
                ? UNIT_WORDS[data.unit] + ' net revenue and orders · no sales in ' + data.range.label
                : UNIT_WORDS[data.unit] + ' net revenue and orders · peak ' + series[peak].label + ' (' + money(series[peak].revenue) + ')');

            $el('trend-empty').prop('hidden', peak !== -1);

            destroyChart('trend');
            charts.trend = new Chart(canvas, {
                data: {
                    labels: series.map(function (row) { return row.label; }),
                    datasets: [
                        {
                            type: 'line',
                            label: 'Orders',
                            data: series.map(function (row) { return row.orders; }),
                            yAxisID: 'orders',
                            borderColor: SLATE,
                            backgroundColor: SLATE,
                            borderWidth: 2,
                            pointRadius: series.length > 31 ? 0 : 2,
                            pointHoverRadius: 4,
                            tension: 0.35,
                            order: 1
                        },
                        {
                            type: 'bar',
                            label: 'Net Revenue',
                            data: series.map(function (row) { return row.revenue; }),
                            yAxisID: 'revenue',
                            backgroundColor: series.map(function (row, i) { return i === peak ? BRAND : BAR_SOFT; }),
                            hoverBackgroundColor: series.map(function (row, i) { return i === peak ? '#ea5b0c' : '#bfd3f7'; }),
                            borderRadius: 4,
                            maxBarThickness: 36,
                            order: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {mode: 'index', intersect: false},
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            callbacks: {
                                label: function (item) {
                                    return ' ' + item.dataset.label + ': '
                                        + (item.dataset.yAxisID === 'revenue' ? money(item.raw) : num(item.raw));
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {display: false},
                            ticks: axisTicks({
                                maxRotation: 0,
                                autoSkip: true,
                                autoSkipPadding: 8,
                                color: function (context) {
                                    return context.index === peak ? BRAND : '#594139';
                                }
                            })
                        },
                        revenue: {
                            position: 'left',
                            beginAtZero: true,
                            suggestedMax: peak === -1 ? 100 : undefined,
                            border: {display: false},
                            grid: {color: '#e5eeff'},
                            ticks: axisTicks({
                                callback: function (value) { return moneyCompact.format(value); }
                            })
                        },
                        orders: {
                            position: 'right',
                            beginAtZero: true,
                            suggestedMax: peak === -1 ? 5 : undefined,
                            border: {display: false},
                            grid: {display: false},
                            ticks: axisTicks({precision: 0})
                        }
                    }
                }
            });
        }

        // ── Order lifecycle (all orders placed) ─────────────────────────────

        function stateColor(row, i) {
            return STATE_COLORS[row.state] || FALLBACK_COLORS[i % FALLBACK_COLORS.length];
        }

        function renderStatuses() {
            var rows = data.statuses,
                total = rows.reduce(function (sum, row) { return sum + row.orders; }, 0);

            $el('status-total').text(num(total));
            $el('statuses').html(rows.length ? rows.map(function (row, i) {
                return '<li' + (row.counted ? '' : ' class="is-excluded" title="Not counted in sales figures"') + '>'
                    + '<span class="mxr-statuslist__name"><i style="background:' + stateColor(row, i) + '"></i>'
                    + esc(row.label) + (row.counted ? '' : ' <small>(not counted)</small>') + '</span>'
                    + '<span class="mxr-statuslist__count">' + esc(num(row.orders)) + ' (' + esc(pct(share(row.orders, total))) + ')</span>'
                    + '<span class="mxr-statuslist__value">' + esc(money(row.value)) + '</span></li>';
            }).join('') : '<li class="mxr-empty">No orders were placed in this range.</li>');

            destroyChart('status');
            charts.status = new Chart($el('status-chart')[0], {
                type: 'doughnut',
                data: {
                    labels: rows.length ? rows.map(function (row) { return row.label; }) : ['No orders'],
                    datasets: [{
                        data: rows.length ? rows.map(function (row) { return row.orders; }) : [1],
                        backgroundColor: rows.length ? rows.map(stateColor) : ['#e5eeff'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            enabled: rows.length > 0,
                            callbacks: {
                                label: function (item) {
                                    return ' ' + item.label + ': ' + plural(item.raw, 'order', 'orders')
                                        + ' (' + pct(share(item.raw, total)) + ')';
                                }
                            }
                        }
                    }
                }
            });
        }

        // ── Last 30 days ────────────────────────────────────────────────────

        function renderDaily() {
            var series = data.daily,
                peak = peakIndex(series),
                total = series.reduce(function (sum, row) { return sum + row.revenue; }, 0),
                orders = series.reduce(function (sum, row) { return sum + row.orders; }, 0),
                canvas = $el('daily-chart')[0],
                context = canvas.getContext('2d'),
                gradient = context.createLinearGradient(0, 0, 0, 260);

            gradient.addColorStop(0, 'rgba(238, 103, 47, 0.28)');
            gradient.addColorStop(1, 'rgba(238, 103, 47, 0)');

            if (peak === -1) {
                $el('daily-sub').text('No sales in the last 30 days');
                $el('daily-peak').prop('hidden', true);
            } else {
                $el('daily-sub').text(money(total) + ' net revenue from ' + plural(orders, 'order', 'orders')
                    + ' · peak ' + shortDate(series[peak].key) + ' (' + money(series[peak].revenue) + ' single day)');
                $el('daily-peak').prop('hidden', false).text(shortDate(series[peak].key) + ': Peak Spike');
            }

            destroyChart('daily');
            charts.daily = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: series.map(function (row) { return shortDate(row.key); }),
                    datasets: [{
                        label: 'Net Revenue',
                        data: series.map(function (row) { return row.revenue; }),
                        borderColor: BRAND,
                        backgroundColor: gradient,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: series.map(function (row, i) { return i === peak ? 5 : 0; }),
                        pointBackgroundColor: '#fff',
                        pointBorderColor: NAVY,
                        pointBorderWidth: 2,
                        pointHoverRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {mode: 'index', intersect: false},
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            callbacks: {
                                label: function (item) {
                                    return ' ' + money(item.raw) + ' · ' + plural(series[item.dataIndex].orders, 'order', 'orders');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {display: false},
                            ticks: axisTicks({
                                maxRotation: 0,
                                autoSkip: true,
                                autoSkipPadding: 14,
                                color: function (ctx) { return ctx.index === peak ? BRAND : '#594139'; }
                            })
                        },
                        y: {
                            beginAtZero: true,
                            border: {display: false},
                            grid: {color: '#e5eeff'},
                            ticks: axisTicks({callback: function (value) { return moneyCompact.format(value); }})
                        }
                    }
                }
            });
        }

        // ── Weekdays ────────────────────────────────────────────────────────

        function renderWeekdays() {
            var rows = data.weekdays.slice().sort(function (a, b) { return b.revenue - a.revenue || b.orders - a.orders; }),
                max = rows.length ? rows[0].revenue : 0,
                total = rows.reduce(function (sum, row) { return sum + row.revenue; }, 0),
                best = max > 0 ? rows[0] : null,
                dayBefore,
                hour = data.peak_hour;

            $el('weekdays').html(rows.map(function (row, i) {
                var width = max > 0 ? row.revenue / max * 100 : 0;

                return '<div class="mxr-weekday' + (i === 0 && best ? ' is-top' : '') + '">'
                    + '<div class="mxr-weekday__line"><span class="mxr-weekday__name">' + esc(row.day_name)
                    + (i === 0 && best ? ' <span class="mxd-ico mxd-ico--xs" aria-hidden="true">star</span>' : '') + '</span>'
                    + '<span class="mxr-weekday__value">' + esc(money(row.revenue)) + ' (' + esc(plural(row.orders, 'order', 'orders')) + ')</span></div>'
                    + '<div class="mxr-weekday__track"><span style="width:' + width.toFixed(1) + '%;opacity:'
                    + (i === 0 ? 1 : Math.max(0.35, 0.9 - i * 0.1)).toFixed(2) + '"></span></div>'
                    + '</div>';
            }).join(''));

            if (!best) {
                $el('weekday-hint').prop('hidden', true);
                return;
            }
            dayBefore = data.weekdays[(best.day_num + 5) % 7].day_name;
            $el('weekday-hint').prop('hidden', false).html(icon('tips_and_updates', 'md') + '<span><strong>Optimal Email Trigger:</strong> '
                + esc(best.day_name) + ' brings ' + esc(pct(share(best.revenue, total))) + ' of net revenue'
                + (hour !== null ? ' and orders peak around ' + esc(String(hour).padStart(2, '0') + ':00') : '')
                + '. Send campaigns on ' + esc(dayBefore) + ' evening to land before the ' + esc(best.day_name) + ' peak.</span>');
        }

        // ── Regions, shipping, promotions ───────────────────────────────────

        function renderRegions() {
            var rows = data.regions,
                total = rows.reduce(function (sum, row) { return sum + row.revenue; }, 0),
                visible = showAllRegions ? rows : rows.slice(0, config.regionPreview);

            $el('regions').html(visible.length ? visible.map(function (row, i) {
                var part = share(row.revenue, total);

                return '<tr><td><span class="mxr-place"><i class="mxr-place__dot mxr-place__dot--' + (i === 0 ? 'brand' : (i < 3 ? 'navy' : 'grey'))
                    + '"></i><strong>' + esc(row.place) + '</strong></span></td>'
                    + '<td class="mxc-r">' + esc(num(row.orders)) + '</td>'
                    + '<td class="mxc-r mxc-nowrap"><strong>' + esc(money(row.revenue)) + '</strong></td>'
                    + '<td><span class="mxr-share"><span class="mxr-share__track"><span style="width:' + part.toFixed(1) + '%"></span></span>'
                    + '<span class="mxd-mono">' + esc(pct(part)) + '</span></span></td>'
                    + '<td class="mxc-r mxd-mono">' + esc(money(row.orders ? row.revenue / row.orders : 0)) + '</td>'
                    + '<td class="mxc-r mxd-mono">' + esc(money(row.tax)) + '</td></tr>';
            }).join('') : '<tr><td colspan="6" class="mxr-empty">No orders in this range.</td></tr>');

            $el('regions-toggle').prop('hidden', rows.length <= config.regionPreview);
            $el('regions-toggle-label').text(showAllRegions ? 'Show Top ' + config.regionPreview : 'View All Regions (' + rows.length + ')');
        }

        function renderShipping() {
            var rows = data.shipping,
                totalOrders = rows.reduce(function (sum, row) { return sum + row.orders; }, 0),
                top = rows[0];

            $el('shipping').html(rows.length ? rows.map(function (row) {
                var physical = row.orders - row.virtual_orders,
                    fulfilled = physical > 0 ? share(row.shipped_orders, physical) : null,
                    tone = fulfilled === null ? 'grey' : (fulfilled >= 90 ? 'brand' : (fulfilled >= 50 ? 'amber' : 'grey'));

                return '<tr><td><strong>' + esc(row.description || 'No shipping (virtual)') + '</strong>'
                    + (row.method ? '<span class="mxr-sub">' + esc(row.method) + '</span>' : '') + '</td>'
                    + '<td class="mxc-r">' + esc(num(row.orders)) + '</td>'
                    + '<td class="mxc-r mxc-nowrap"><strong>' + esc(money(row.revenue)) + '</strong></td>'
                    + '<td class="mxc-r mxd-mono">' + esc(money(row.orders ? row.shipping / row.orders : 0)) + '</td>'
                    + '<td class="mxc-r">' + (fulfilled === null
                        ? badge('Virtual', 'grey', 'No physical items to ship')
                        : badge(pct(fulfilled) + ' Shipped', tone, num(row.shipped_orders) + ' of ' + num(physical) + ' orders have a shipment'))
                    + '</td></tr>';
            }).join('') : '<tr><td colspan="5" class="mxr-empty">No orders in this range.</td></tr>');

            $el('shipping-hint').prop('hidden', !top).html(top
                ? '<span>' + esc((top.description || 'No shipping') + ' carries ' + pct(share(top.orders, totalOrders))
                    + ' of orders · ' + money(top.shipping) + ' shipping charged') + '</span>'
                    + '<a class="mxd-link" href="' + esc(config.carriersUrl) + '" target="_blank" rel="noopener">Configure Carriers</a>'
                : '');
        }

        function renderPromotions() {
            var rows = data.promotions,
                roi = data.totals.promo_roi;

            $el('roi').html(roi !== null
                ? '<span class="mxr-roi__label">Promotional ROI:</span>'
                    + badge(num(roi) + 'x Revenue / Discount Dollar', 'red',
                        money(data.totals.discounted_revenue) + ' net revenue from ' + plural(data.totals.discounted_orders, 'discounted order', 'discounted orders')
                        + ' for ' + money(data.totals.discount) + ' in discounts')
                    + '<a class="mxd-link" href="' + esc(config.rulesUrl) + '" target="_blank" rel="noopener">Manage Rules</a>'
                : '<a class="mxd-link" href="' + esc(config.rulesUrl) + '" target="_blank" rel="noopener">Manage Cart Price Rules</a>');

            $el('promotions').html(rows.length ? rows.map(function (row) {
                var status = PROMO_STATUS[row.status] || ['Active', 'brand'];

                return '<tr><td>' + (row.code
                        ? '<span class="mxr-coupon">' + icon('sell', 'sm') + esc(row.code) + '</span>'
                        : '<span class="mxr-coupon mxr-coupon--auto" title="Applied without a coupon code">' + icon('bolt', 'sm') + 'Auto-applied</span>')
                    + '</td>'
                    + '<td><a class="mxr-rule" href="' + esc(config.ruleUrl.replace('__ID__', row.rule_id)) + '" target="_blank" rel="noopener">'
                    + esc(row.name) + '</a><span class="mxr-sub">' + esc(row.action) + '</span></td>'
                    + '<td class="mxc-r">' + esc(num(row.orders)) + '</td>'
                    + '<td class="mxc-r mxd-mono' + (row.discount > 0 ? ' mxd-text-red' : '') + '">'
                    + esc(row.discount > 0 ? '-' + money(row.discount) : money(0)) + '</td>'
                    + '<td class="mxc-r mxc-nowrap"><strong>' + esc(money(row.revenue)) + '</strong></td>'
                    + '<td class="mxc-r mxd-mono">' + esc(money(row.aov)) + '</td>'
                    + '<td class="mxc-r">' + badge(status[0], status[1]) + '</td></tr>';
            }).join('') : '<tr><td colspan="7" class="mxr-empty">No cart price rules or coupons were applied to orders in this range.</td></tr>');
        }

        // ── Copilot summary ─────────────────────────────────────────────────

        function renderCopilot() {
            var t = data.totals,
                region = data.regions[0] || null,
                regionTotal = data.regions.reduce(function (sum, row) { return sum + row.revenue; }, 0),
                weekdays = data.weekdays.slice().sort(function (a, b) { return b.revenue - a.revenue; }),
                best = weekdays.length && weekdays[0].revenue > 0 ? weekdays[0] : null,
                findings = [],
                actions = [];

            if (!t.orders) {
                findings.push('No sales were recorded in ' + esc(data.range.label) + '.');
            } else {
                findings.push('Net revenue reached <strong>' + esc(money(t.revenue)) + '</strong> from <strong>'
                    + esc(plural(t.orders, 'order', 'orders')) + '</strong>'
                    + (t.revenue_change !== null
                        ? ', <strong class="mxc-hl">' + esc(signed(t.revenue_change)) + '</strong> vs the previous period of the same length'
                        : '') + '.');
                if (region) {
                    findings.push('<strong class="mxc-hl2">' + esc(region.place) + '</strong> brings <strong class="mxc-hl">'
                        + esc(pct(share(region.revenue, regionTotal))) + '</strong> of net revenue'
                        + (best ? ', and <strong class="mxc-hl2">' + esc(best.day_name) + '</strong> is the strongest weekday' : '') + '.');
                }
                findings.push(t.memos
                    ? 'Only <strong>' + esc(plural(t.memos, 'credit memo', 'credit memos')) + ' (' + esc(money(t.memo_refunded))
                        + ')</strong> ' + (t.memos === 1 ? 'was' : 'were') + ' logged, a ' + esc(pct(t.refund_rate)) + ' refund rate.'
                    : 'No credit memos were logged in this range.');
            }

            if (t.refund_rate > 5) {
                actions.push('review the products and payment methods behind the <strong>' + esc(pct(t.refund_rate)) + ' refund rate</strong>');
            }
            if (t.pending_payment > 0) {
                actions.push('follow up on <strong>' + esc(plural(t.pending_payment, 'order', 'orders')) + ' stuck in pending payment</strong>');
            }
            if (best) {
                actions.push('schedule retention emails for <strong>' + esc(data.weekdays[(best.day_num + 5) % 7].day_name)
                    + ' evening</strong> to capture the ' + esc(best.day_name) + ' peak');
            }
            if (t.promo_roi !== null && t.promo_roi < 3) {
                actions.push('tighten discount rules (only <strong>' + esc(num(t.promo_roi)) + 'x</strong> revenue per discount dollar)');
            }

            $el('insight').html('<p>' + findings.join(' ') + '</p>'
                + (actions.length ? '<p><span class="mxc-copilot__label">Recommended action:</span> ' + actions.join('; ') + '.</p>' : ''));
            $el('runtime').text('Rows scanned: ' + num(t.all_orders) + ' · Time: ' + num(data.query_ms) + 'ms');
            $el('sql-text').text(data.sql).attr('title', data.sql);
            $el('ask-region-label').text(region ? 'Analyze ' + region.place + ' cohort' : 'Analyze top region cohort');
            $el('ask-region').prop('disabled', !region);
        }

        // ── Copy SQL ────────────────────────────────────────────────────────

        function copied() {
            $el('sql-copy-label').text('Copied');
            setTimeout(function () {
                $el('sql-copy-label').text('Copy Raw SQL');
            }, 1500);
        }

        /** Clipboard without the async API (plain-HTTP admin): a hidden textarea and execCommand. */
        function copyWithTextarea() {
            var $area = $('<textarea readonly>').val(data.sql).css({position: 'fixed', top: '-1000px'}).appendTo('body');

            $area[0].select();
            try {
                document.execCommand('copy');
                copied();
            } catch (e) {
                window.prompt('Copy the SQL:', data.sql);
            }
            $area.remove();
        }

        function copySql() {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(data.sql).then(copied, copyWithTextarea);
            } else {
                copyWithTextarea();
            }
        }

        // ── AI follow-up ────────────────────────────────────────────────────

        function formatAnswer(text) {
            return esc(text)
                .replace(/^#{1,6}\s+(.+)$/gm, '<strong>$1</strong>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/`(.+?)`/g, '<code>$1</code>')
                .replace(/^\s*[-*]\s+(.+)$/gm, '<li>$1</li>')
                .replace(/(?:<li>.*<\/li>\n?)+/g, function (list) {
                    return '<ul>' + list.replace(/\n/g, '') + '</ul>';
                })
                .replace(/\n/g, '<br>');
        }

        function openAsk(focus) {
            $el('ask-panel').prop('hidden', false);
            $el('ask-toggle').attr('aria-expanded', 'true');
            $el('copilot')[0].scrollIntoView({behavior: 'smooth', block: 'center'});
            if (focus) {
                $el('ask-input').trigger('focus');
            }
        }

        function ask(question) {
            var $answer = $el('answer'),
                $submit = $el('ask-submit');

            question = $.trim(question || '');
            if (!question || $submit.prop('disabled')) {
                return;
            }
            openAsk(false);
            if (!config.aiEnabled || !config.aiConfigured) {
                $answer.prop('hidden', false).html('<p class="mxc-answer__error">The AI provider is not configured. '
                    + 'Set it up in Stores > Configuration > Meetanshi > AI Reporting.</p>');
                return;
            }

            $el('ask-input').val(question);
            $submit.prop('disabled', true);
            $answer.prop('hidden', false).html('<p class="mxc-answer__q">' + icon('person', 'xs') + ' ' + esc(question)
                + '</p><p class="mxc-answer__loading">' + icon('progress_activity', 'sm') + ' Thinking… local models can take a minute.</p>');

            $.ajax({
                url: config.chatUrl,
                type: 'POST',
                dataType: 'json',
                data: {message: question, form_key: config.formKey, isAjax: true},
                timeout: 300000
            }).done(function (response) {
                var html = '<p class="mxc-answer__q">' + icon('person', 'xs') + ' ' + esc(question) + '</p>';

                if (!response || !response.success) {
                    html += '<p class="mxc-answer__error">' + esc((response && response.message) || 'Something went wrong.') + '</p>';
                } else {
                    html += '<div class="mxc-answer__text">' + formatAnswer(response.answer) + '</div>';
                    if (response.assumption) {
                        html += '<p class="mxc-answer__assumption">Interpreted as: ' + esc(response.assumption) + '</p>';
                    }
                    if (response.sql) {
                        html += '<div class="mxc-answer__meta"><span>' + esc(num(response.rows) + ' rows · '
                            + num(response.time_ms) + ' ms') + '</span>'
                            + '<button type="button" class="mxc-linkbtn" data-role="toggle-sql">Show SQL</button>'
                            + '<a class="mxc-linkbtn" href="' + esc(config.askAiUrl) + '">Open Ask AI</a></div>'
                            + '<pre class="mxc-answer__sql" hidden>' + esc(response.sql) + '</pre>';
                    }
                }
                $answer.html(html);
            }).fail(function (xhr, status) {
                $answer.html('<p class="mxc-answer__error">' + (status === 'timeout'
                    ? 'The AI took too long to answer. Try a simpler question.'
                    : 'Request failed (' + esc(xhr.status) + '). Please try again.') + '</p>');
            }).always(function () {
                $submit.prop('disabled', false);
            });
        }

        /** "between 2026-01-01 and 2026-10-08" for questions about the selected range. */
        function rangePhrase() {
            return 'between ' + data.range.start + ' and ' + data.range.end;
        }

        // ── Data loading ────────────────────────────────────────────────────

        function setActiveRange(key) {
            $el('range').find('[data-range]').each(function () {
                $(this).toggleClass('is-active', $(this).data('range') === key);
            });
        }

        function showError(message) {
            $el('error').text(message).prop('hidden', false);
        }

        function load(params) {
            if (loading) {
                return;
            }
            loading = true;
            $root.addClass('is-loading');
            $el('error').prop('hidden', true);

            $.ajax({
                url: config.dataUrl,
                type: 'GET',
                dataType: 'json',
                data: params
            }).done(function (response) {
                if (response && response.success) {
                    data = response.data;
                    showAllRegions = false;
                    render();
                } else {
                    showError((response && response.message) || 'Could not load the report data.');
                }
            }).fail(function (xhr) {
                showError('Could not load the report data (HTTP ' + xhr.status + ').');
            }).always(function () {
                loading = false;
                $root.removeClass('is-loading');
                setActiveRange(data.range.key);
            });
        }

        function render() {
            $el('range-label').text(data.range.label);
            setActiveRange(data.range.key);
            renderKpis();
            renderTicker();
            renderTrend();
            renderStatuses();
            renderDaily();
            renderWeekdays();
            renderRegions();
            renderShipping();
            renderPromotions();
            renderCopilot();
        }

        function closeCsvMenu() {
            $el('csv-menu').prop('hidden', true);
            $el('csv-toggle').attr('aria-expanded', 'false');
        }

        // ── Events ──────────────────────────────────────────────────────────

        $el('range').on('click', '[data-range]', function () {
            var key = $(this).data('range'),
                $custom = $el('custom-range');

            if (key === 'custom') {
                $custom.prop('hidden', !$custom.prop('hidden'));
                $(this).attr('aria-expanded', String(!$custom.prop('hidden')));
                $el('custom-start').val($el('custom-start').val() || data.range.start);
                $el('custom-end').val($el('custom-end').val() || data.range.end);
                return;
            }
            $custom.prop('hidden', true);
            if (key !== data.range.key) {
                setActiveRange(key);
                load({range: key});
            }
        });

        $el('custom-apply').on('click', function () {
            var start = $el('custom-start').val(),
                end = $el('custom-end').val();

            if (!start || !end) {
                return;
            }
            $el('custom-range').prop('hidden', true);
            $el('custom-toggle').attr('aria-expanded', 'false');
            setActiveRange('custom');
            load({range: 'custom', start: start, end: end});
        });

        $el('csv-toggle').on('click', function (event) {
            var open = $el('csv-menu').prop('hidden');

            event.stopPropagation();
            $el('csv-menu').prop('hidden', !open);
            $(this).attr('aria-expanded', String(open));
        });

        $el('csv-menu').on('click', '[data-export]', function () {
            closeCsvMenu();
            window.location.href = exportUrl($.extend({type: $(this).data('export')}, rangeParams()));
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('.mxr-dropdown').length) {
                closeCsvMenu();
            }
        }).on('keydown', function (event) {
            if (event.key === 'Escape') {
                closeCsvMenu();
            }
        });

        $el('regions-toggle').on('click', function () {
            showAllRegions = !showAllRegions;
            renderRegions();
        });

        $el('print').on('click', function () {
            window.print();
        });

        $el('sql-copy').on('click', copySql);

        $el('ask-open').on('click', function () {
            openAsk(true);
        });

        $el('ask-toggle').on('click', function () {
            var open = $el('ask-panel').prop('hidden');

            $el('ask-panel').prop('hidden', !open);
            $(this).attr('aria-expanded', String(open));
            if (open) {
                $el('ask-input').trigger('focus');
            }
        });

        $el('ask-region').on('click', function () {
            var region = data.regions[0];

            if (region) {
                ask('Top 10 customers shipping to ' + (region.region || region.country_name) + ' by net revenue for orders placed '
                    + rangePhrase() + ', with order count and average order value');
            }
        });

        $el('ask-compare').on('click', function () {
            ask('Compare net revenue, order count and average order value for orders placed ' + rangePhrase()
                + ' with orders placed between ' + data.previous.start + ' and ' + data.previous.end);
        });

        $el('ask-form').on('submit', function (event) {
            event.preventDefault();
            ask($el('ask-input').val());
        });

        $root.on('click', '.mxc-chip', function () {
            ask($(this).data('question'));
        });

        $el('answer').on('click', '[data-role="toggle-sql"]', function () {
            var $sql = $el('answer').find('.mxc-answer__sql'),
                show = $sql.prop('hidden');

            $sql.prop('hidden', !show);
            $(this).text(show ? 'Hide SQL' : 'Show SQL');
        });

        // Redraw charts at print width (the PDF), then back
        window.addEventListener('beforeprint', function () {
            Object.keys(charts).forEach(function (name) { charts[name].resize(); });
        });

        render();
        // Charts use the page fonts: draw again once they are ready
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                renderTrend();
                renderDaily();
            });
        }
    };
});
