/**
 * Meetanshi AIReporting — AI Analytics & Executive Dashboard
 *
 * Renders every section from the dashboard data (first from the page, then from
 * dashboard/refreshData), and wires the date range, live refresh, export, product table
 * (filter, category, paging, CSV) and the AI assistant (chat/send).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery', 'chartjs'], function ($, Chart) {
    'use strict';

    var BRAND = '#ee672f',
        GREEN = '#10b981',
        PAGE_SIZE = 5,
        LIVE_INTERVAL = 60000,
        STATUS_COLORS = {
            complete: '#10b981',
            pending: '#ee672f',
            pending_payment: '#fb923c',
            processing: '#f59e0b',
            holded: '#8b5cf6',
            payment_review: '#0ea5e9',
            closed: '#ef4444',
            canceled: '#94a3b8',
            fraud: '#dc2626'
        },
        FALLBACK_COLORS = ['#6366f1', '#14b8a6', '#e11d48', '#64748b', '#a855f7', '#84cc16'],
        CATEGORY_COLORS = [BRAND, '#f2865e', '#f59e0b', GREEN, '#cbd5e1'];

    return function (config, element) {
        var $root = $(element),
            data = config.data,
            charts = {},
            trendMode = 'both',
            liveTimer = null,
            loading = false,
            table = {query: '', category: '', page: 1},
            moneyFmt = new Intl.NumberFormat(undefined, {style: 'currency', currency: config.currency}),
            moneyWhole = new Intl.NumberFormat(undefined, {
                style: 'currency', currency: config.currency, maximumFractionDigits: 0
            }),
            moneyCompact = new Intl.NumberFormat(undefined, {
                style: 'currency', currency: config.currency, notation: 'compact', maximumFractionDigits: 1
            }),
            numberFmt = new Intl.NumberFormat(undefined, {maximumFractionDigits: 2});

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

        function icon(name, size) {
            return '<span class="mxd-ico mxd-ico--' + (size || 'sm') + '" aria-hidden="true">' + name + '</span>';
        }

        /** "Sep 24" from "2026-09-24" (no timezone shift). */
        function shortDate(ymd) {
            var parts = String(ymd).split('-');

            return new Date(+parts[0], parts[1] - 1, +parts[2])
                .toLocaleDateString(undefined, {month: 'short', day: 'numeric'});
        }

        function monthLabel(ymd) {
            var parts = String(ymd).split('-');

            return new Date(+parts[0], parts[1] - 1, 1)
                .toLocaleDateString(undefined, {month: 'short', year: 'numeric'});
        }

        function changeBadge(change) {
            var value = Number(change) || 0,
                cls = value > 0 ? 'up' : (value < 0 ? 'down' : 'flat'),
                text = (value > 0 ? '+' : '') + new Intl.NumberFormat(undefined, {
                    maximumFractionDigits: Math.abs(value) >= 1000 ? 0 : 1
                }).format(value) + '%';

            return '<span class="mxd-trend mxd-trend--' + cls + '" title="vs previous period of the same length">'
                + icon(value > 0 ? 'trending_up' : (value < 0 ? 'trending_down' : 'trending_flat'), 'xs')
                + ' ' + esc(text) + '</span>';
        }

        function destroyChart(name) {
            if (charts[name]) {
                charts[name].destroy();
                delete charts[name];
            }
        }

        // ── KPI strip ───────────────────────────────────────────────────────

        function kpiCard(options) {
            return '<div class="mxd-kpi' + (options.accent ? ' mxd-kpi--accent' : '') + '">'
                + '<div class="mxd-kpi__head"><span class="mxd-kpi__label">' + esc(options.label) + '</span>'
                + '<span class="mxd-kpi__icon mxd-kpi__icon--' + (options.tone || 'brand') + '">' + icon(options.icon) + '</span></div>'
                + '<div class="mxd-kpi__value"><span class="mxd-metric' + (options.valueClass ? ' ' + options.valueClass : '') + '">'
                + esc(options.value) + '</span>' + (options.unit ? '<span class="mxd-kpi__unit">' + esc(options.unit) + '</span>' : '')
                + '</div><div class="mxd-kpi__foot">' + (options.left || '')
                + '<span class="mxd-kpi__note" title="' + esc(options.note || '') + '">' + esc(options.note || '') + '</span></div></div>';
        }

        function renderKpis() {
            var k = data.kpis,
                health = Number(k.stock_healthy_pct) || 0,
                healthState = health >= 90 ? ['Healthy', 'green', 'mxd-text-green']
                    : (health >= 70 ? ['Watch', 'amber', 'mxd-text-amber'] : ['At risk', 'red', 'mxd-text-red']),
                top = k.top_product;

            $el('kpis').html([
                kpiCard({
                    label: 'Total Net Revenue', icon: 'payments', accent: true,
                    value: money(k.total_revenue), left: changeBadge(k.total_revenue_change),
                    note: k.refunded > 0 ? 'Net (' + money(k.refunded) + ' refunded)' : 'Net, no refunds'
                }),
                kpiCard({
                    label: 'Total Orders', icon: 'shopping_cart', value: num(k.total_orders), unit: 'orders',
                    left: changeBadge(k.total_orders_change), note: num(k.complete_orders) + ' complete'
                }),
                kpiCard({
                    label: 'Avg Order Value', icon: 'credit_card', tone: 'slate', value: money(k.avg_order_value),
                    left: changeBadge(k.avg_order_value_change),
                    note: k.best_aov_day ? 'Best: ' + k.best_aov_day.day + ' ' + money(k.best_aov_day.aov) : ''
                }),
                kpiCard({
                    label: 'Active Customers', icon: 'group', value: num(k.active_customers), unit: 'buyers',
                    left: changeBadge(k.active_customers_change), note: num(k.repeat_buyers) + ' repeat buyers'
                }),
                kpiCard({
                    label: 'Units Sold', icon: 'inventory', tone: 'slate', value: num(k.products_sold), unit: 'items',
                    left: '<span class="mxd-kpi__meta">' + esc(num(k.skus_sold) + ' SKUs moved') + '</span>',
                    note: top ? 'Top: ' + top.name + ' (' + num(top.qty) + ')' : ''
                }),
                kpiCard({
                    label: 'Catalog Health', icon: 'verified', tone: healthState[1],
                    value: num(health) + '%', valueClass: healthState[2],
                    unit: num(Number(k.low_stock) + Number(k.out_of_stock)) + ' out/low',
                    left: '<span class="mxd-pill mxd-pill--' + healthState[1] + '">' + healthState[0] + '</span>',
                    note: moneyCompact.format(Number(k.inventory_value) || 0) + ' inv'
                })
            ].join(''));
        }

        // ── Revenue & order trend ───────────────────────────────────────────

        /** Draws the peak-day callout next to the highest revenue point. */
        var peakPlugin = {
            id: 'mxdPeak',
            afterDatasetsDraw: function (chart) {
                var peak = data.trend_stats && data.trend_stats.peak_revenue,
                    meta = chart.getDatasetMeta(0),
                    index,
                    point,
                    ctx = chart.ctx,
                    lines,
                    width = 0,
                    boxX,
                    boxY,
                    area = chart.chartArea;

                if (!peak || meta.hidden || !chart.isDatasetVisible(0)) {
                    return;
                }
                index = data.revenue_trend.findIndex(function (day) {
                    return day.date === peak.date;
                });
                point = meta.data[index];
                if (!point) {
                    return;
                }

                ctx.save();
                ctx.fillStyle = BRAND;
                ctx.strokeStyle = '#fff';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(point.x, point.y, 5, 0, Math.PI * 2);
                ctx.fill();
                ctx.stroke();

                lines = [
                    [shortDate(peak.date) + ': Peak day', '600 11px "IBM Plex Sans", sans-serif', '#fff'],
                    [money(peak.revenue) + ' revenue', '500 10px "IBM Plex Sans", sans-serif', '#fdba74'],
                    [peak.orders + ' orders processed', '400 10px "IBM Plex Sans", sans-serif', '#6ee7b7']
                ];
                lines.forEach(function (line) {
                    ctx.font = line[1];
                    width = Math.max(width, ctx.measureText(line[0]).width);
                });
                width += 16;
                boxX = Math.min(Math.max(point.x - width / 2, area.left), area.right - width);
                boxY = Math.max(point.y - 62, area.top);
                if (boxY + 50 > point.y - 6 && point.y > area.top + 60) {
                    boxY = point.y - 62;
                }

                ctx.fillStyle = '#1e293b';
                ctx.beginPath();
                if (ctx.roundRect) {
                    ctx.roundRect(boxX, boxY, width, 50, 6);
                } else {
                    ctx.rect(boxX, boxY, width, 50);
                }
                ctx.fill();
                lines.forEach(function (line, i) {
                    ctx.font = line[1];
                    ctx.fillStyle = line[2];
                    ctx.fillText(line[0], boxX + 8, boxY + 16 + i * 14);
                });
                ctx.restore();
            }
        };

        function renderTrend() {
            var trend = data.revenue_trend || [],
                stats = data.trend_stats || {},
                canvas = $el('trend-chart')[0],
                gradient,
                ctx;

            $el('trend-sub').text('Daily net revenue and order count across ' + num(data.date_range.days)
                + (Number(data.date_range.days) === 1 ? ' calendar day' : ' calendar days') + ' (store time)');
            $el('trend-stats').html(
                '<div><span class="mxd-label">Peak Daily Revenue</span><span class="mxd-stat">'
                + (stats.peak_revenue ? esc(money(stats.peak_revenue.revenue))
                    + ' <small class="mxd-text-brand">(' + esc(shortDate(stats.peak_revenue.date)) + ')</small>' : '—')
                + '</span></div>'
                + '<div><span class="mxd-label">Max Daily Order Run</span><span class="mxd-stat">'
                + (stats.peak_orders ? esc(num(stats.peak_orders.orders) + ' orders')
                    + ' <small>(' + esc(shortDate(stats.peak_orders.date)) + ')</small>' : '—')
                + '</span></div>'
                + '<div><span class="mxd-label">Store Timezone</span><span class="mxd-stat">' + esc(stats.timezone || '')
                + ' <small>(' + esc(stats.utc_offset || '') + ')</small></span></div>'
            );

            destroyChart('trend');
            ctx = canvas.getContext('2d');
            gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentNode.clientHeight || 288);
            gradient.addColorStop(0, 'rgba(238, 103, 47, 0.32)');
            gradient.addColorStop(1, 'rgba(238, 103, 47, 0)');

            charts.trend = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: trend.map(function (day) {
                        return day.date;
                    }),
                    datasets: [{
                        label: 'Net Revenue',
                        data: trend.map(function (day) {
                            return Number(day.revenue) || 0;
                        }),
                        borderColor: BRAND,
                        backgroundColor: gradient,
                        fill: true,
                        borderWidth: 2.5,
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        yAxisID: 'y',
                        hidden: trendMode === 'orders'
                    }, {
                        label: 'Orders',
                        data: trend.map(function (day) {
                            return Number(day.orders) || 0;
                        }),
                        borderColor: GREEN,
                        backgroundColor: GREEN,
                        borderDash: [4, 4],
                        borderWidth: 2,
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        yAxisID: 'y1',
                        hidden: trendMode === 'revenue'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {duration: 400},
                    interaction: {mode: 'index', intersect: false},
                    layout: {padding: {top: 8}},
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 10,
                            callbacks: {
                                title: function (items) {
                                    return items.length ? shortDate(items[0].label) : '';
                                },
                                label: function (item) {
                                    return item.datasetIndex === 0
                                        ? ' Net revenue: ' + money(item.parsed.y)
                                        : ' Orders: ' + num(item.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {display: false},
                            border: {display: false},
                            ticks: {
                                autoSkip: false,
                                maxRotation: 0,
                                color: '#594139',
                                font: {family: '"JetBrains Mono", monospace', size: 11},
                                callback: function (value, index) {
                                    var date = trend[index] && trend[index].date;

                                    if (!date) {
                                        return '';
                                    }
                                    // Only the first day of each month, unless the range is short
                                    if (trend.length <= 14) {
                                        return shortDate(date);
                                    }
                                    return (index === 0 && trend.length < 40) || date.slice(8) === '01' ? monthLabel(date) : '';
                                }
                            }
                        },
                        y: {
                            display: trendMode !== 'orders',
                            beginAtZero: true,
                            grid: {color: '#eef2f7'},
                            border: {display: false},
                            ticks: {
                                color: '#8c7168',
                                font: {family: '"JetBrains Mono", monospace', size: 10},
                                callback: function (value) {
                                    return moneyCompact.format(value);
                                }
                            }
                        },
                        y1: {
                            display: trendMode !== 'revenue',
                            position: 'right',
                            beginAtZero: true,
                            grid: {display: trendMode === 'orders', color: '#eef2f7'},
                            border: {display: false},
                            ticks: {
                                color: '#8c7168',
                                precision: 0,
                                font: {family: '"JetBrains Mono", monospace', size: 10}
                            }
                        }
                    }
                },
                plugins: [peakPlugin]
            });
        }

        // ── Orders by status ────────────────────────────────────────────────

        function statusColor(code, index) {
            return STATUS_COLORS[code] || FALLBACK_COLORS[index % FALLBACK_COLORS.length];
        }

        function renderStatus() {
            var rows = data.orders_by_status || [],
                total = rows.reduce(function (sum, row) {
                    return sum + (Number(row.orders) || 0);
                }, 0);

            $el('status-total').text(num(total));
            $el('status-list').html(rows.length ? rows.map(function (row, i) {
                var share = total ? (Number(row.orders) / total * 100) : 0;

                return '<div class="mxd-statusrow">'
                    + '<span class="mxd-statusrow__name"><i class="mxd-dot" style="background:' + statusColor(row.code, i) + '"></i>'
                    + esc(row.status) + '</span>'
                    + '<span class="mxd-statusrow__nums"><span class="mxd-label">' + esc(num(row.orders) + ' (' + share.toFixed(1) + '%)')
                    + '</span><strong>' + esc(money(row.revenue)) + '</strong></span></div>';
            }).join('') : '<p class="mxd-empty">No orders in this period.</p>');

            destroyChart('status');
            charts.status = new Chart($el('status-chart')[0], {
                type: 'doughnut',
                data: {
                    labels: rows.map(function (row) {
                        return row.status;
                    }),
                    datasets: [{
                        data: total ? rows.map(function (row) {
                            return Number(row.orders) || 0;
                        }) : [1],
                        backgroundColor: total ? rows.map(function (row, i) {
                            return statusColor(row.code, i);
                        }) : ['#f1f5f9'],
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
                            enabled: total > 0,
                            backgroundColor: '#1e293b',
                            callbacks: {
                                label: function (item) {
                                    return ' ' + item.label + ': ' + num(item.parsed) + ' orders';
                                }
                            }
                        }
                    }
                }
            });
        }

        // ── Categories ──────────────────────────────────────────────────────

        function renderCategories() {
            var rows = (data.revenue_by_category || []).slice(0, 5);

            $el('categories').html(rows.length ? rows.map(function (row, i) {
                var share = Math.min(100, Number(row.share) || 0);

                return '<div class="mxd-bar">'
                    + '<div class="mxd-bar__text"><span class="mxd-bar__name">' + esc(row.category_name) + '</span>'
                    + '<span class="mxd-bar__value">' + esc(money(row.revenue))
                    + ' <small class="' + (i === 0 ? 'mxd-text-brand' : '') + '">(' + esc(num(row.share)) + '%)</small></span></div>'
                    + '<div class="mxd-bar__track"><div class="mxd-bar__fill" style="width:' + share + '%;background:'
                    + CATEGORY_COLORS[i] + '"></div></div></div>';
            }).join('') : '<p class="mxd-empty">No category sales in this period.</p>');
            $el('category-count').text(num(data.category_count || 0) + ' categories with sales');
        }

        // ── Customer segments ───────────────────────────────────────────────

        function renderSegments() {
            var bySegment = {},
                buyers = 0,
                revenue = 0,
                repeat,
                vip,
                newcomers,
                insight;

            (data.customer_segments || []).forEach(function (row) {
                bySegment[row.segment] = row;
                buyers += Number(row.customers) || 0;
                revenue += Number(row.revenue) || 0;
            });
            vip = bySegment.VIP || {customers: 0, transactions: 0, revenue: 0};
            newcomers = bySegment.New || {customers: 0, transactions: 0, revenue: 0};
            repeat = buyers ? Math.round((buyers - newcomers.customers) / buyers * 100) : 0;

            $el('repeat-rate').html(icon('verified_user', 'xs') + ' ' + esc(repeat + '% repeat rate'));
            $el('segments-sub').text('Buyers in this period by number of orders — ' + num(buyers)
                + ' purchasers, guests included');

            $el('segments').html([
                ['VIP', 'VIP Segment (6+ orders)', 'vip'],
                ['Returning', 'Returning (2–5 orders)', 'ret'],
                ['New', 'First-Time Checkout', 'new']
            ].map(function (segment) {
                var row = bySegment[segment[0]] || {customers: 0, transactions: 0, revenue: 0},
                    share = revenue ? (row.revenue / revenue * 100) : 0;

                return '<div class="mxd-segment mxd-segment--' + segment[2] + '">'
                    + '<div class="mxd-segment__badge">' + segment[2].toUpperCase() + '</div>'
                    + '<div class="mxd-segment__text"><strong>' + esc(segment[1]) + '</strong><span>'
                    + esc(num(row.customers) + (row.customers === 1 ? ' buyer' : ' buyers') + ' • '
                        + num(row.transactions) + (row.transactions === 1 ? ' transaction' : ' total transactions'))
                    + '</span></div>'
                    + '<div class="mxd-segment__value"><strong>' + esc(money(row.revenue)) + '</strong><span>'
                    + esc(share.toFixed(1) + '% of total revenue') + '</span></div></div>';
            }).join(''));

            if (revenue && vip.revenue / revenue >= 0.5) {
                insight = ['Concentration Risk Alert', (vip.revenue / revenue * 100).toFixed(1) + '% of net revenue comes from '
                    + num(vip.customers) + ' VIP ' + (vip.customers === 1 ? 'buyer' : 'buyers')
                    + '. Consider a loyalty tier or a cart price rule that rewards repeat customers in Marketing > Cart Price Rules.'];
            } else if (buyers && newcomers.customers / buyers >= 0.5) {
                insight = ['Repeat Purchase Opportunity', Math.round(newcomers.customers / buyers * 100)
                    + '% of buyers ordered only once. A second-order coupon or win-back email could lift repeat purchases.'];
            } else if (buyers) {
                insight = ['Balanced Customer Mix', 'No single segment brings in more than half of net revenue.'];
            } else {
                insight = ['No buyers yet', 'There are no paid orders in this period.'];
            }
            $el('segment-insight').html(icon('lightbulb', 'md') + '<div><strong>' + esc(insight[0]) + '</strong><span>'
                + esc(insight[1]) + '</span></div>');
        }

        // ── Day of week ─────────────────────────────────────────────────────

        function renderDayOfWeek() {
            var rows = data.revenue_by_dow || [],
                values = rows.map(function (row) {
                    return Number(row.revenue) || 0;
                }),
                max = Math.max.apply(null, values.concat([0])),
                ranked = values.slice().sort(function (a, b) {
                    return b - a;
                }),
                peakIndex = max > 0 ? values.indexOf(max) : -1,
                peakWindow = data.peak_window,
                hour;

            $el('dow').html(rows.map(function (row, i) {
                var value = values[i],
                    tone = i === peakIndex ? 'peak' : (value > 0 && value === ranked[1] ? 'second' : 'base'),
                    height = max ? Math.max(4, Math.round(value / max * 155)) : 4;

                return '<div class="mxd-column mxd-column--' + tone + '" title="' + esc(row.day_name + ': '
                    + money(value) + ', ' + num(row.orders) + ' orders') + '">'
                    + '<span class="mxd-column__value">' + esc(moneyWhole.format(value)) + '</span>'
                    + '<div class="mxd-column__bar" style="height:' + height + 'px"></div>'
                    + '<span class="mxd-column__day">' + esc(String(row.day_name).slice(0, 3)) + '</span></div>';
            }).join(''));

            if (peakIndex >= 0) {
                $el('dow-peak').text(rows[peakIndex].day_name + 's Peak').prop('hidden', false);
            } else {
                $el('dow-peak').prop('hidden', true);
            }

            if (peakWindow) {
                hour = function (h) {
                    return (h < 10 ? '0' : '') + h + ':00';
                };
                $el('peak-window').html('<span>Peak ordering hour:</span><strong>' + esc(peakWindow.day_name + ' '
                    + hour(peakWindow.hour) + '–' + hour((peakWindow.hour + 1) % 24)) + '</strong><small>'
                    + esc(num(peakWindow.orders) + ' orders, store time') + '</small>');
            } else {
                $el('peak-window').html('<span>Peak ordering hour:</span><strong>—</strong>');
            }
        }

        // ── Product table ───────────────────────────────────────────────────

        function filteredProducts() {
            var query = table.query.toLowerCase();

            return (data.products || []).filter(function (row) {
                return (!table.category || row.category === table.category)
                    && (!query || String(row.product_name).toLowerCase().indexOf(query) !== -1
                        || String(row.sku).toLowerCase().indexOf(query) !== -1);
            });
        }

        function stockPill(row) {
            if (row.in_stock === null) {
                return '<span class="mxd-pill mxd-pill--grey" title="Stock is not tracked for this product type">Not tracked</span>';
            }
            if (!row.in_stock || row.stock_qty <= 0) {
                return '<span class="mxd-pill mxd-pill--red"><i class="mxd-dot"></i> Out of stock</span>';
            }
            if (row.stock_qty <= 10) {
                return '<span class="mxd-pill mxd-pill--amber"><i class="mxd-dot"></i> ' + esc(num(row.stock_qty)) + ' left</span>';
            }
            return '<span class="mxd-pill mxd-pill--green"><i class="mxd-dot"></i> ' + esc(num(row.stock_qty)) + ' in stock</span>';
        }

        function renderCategoryFilter() {
            var $select = $el('product-category'),
                current = table.category,
                names = {};

            (data.products || []).forEach(function (row) {
                if (row.category) {
                    names[row.category] = true;
                }
            });
            $select.find('option:not(:first)').remove();
            Object.keys(names).sort().forEach(function (name) {
                $select.append($('<option>').val(name).text(name));
            });
            if (!names[current]) {
                table.category = '';
            }
            $select.val(table.category);
        }

        function renderProducts() {
            var rows = filteredProducts(),
                pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE)),
                first,
                visible,
                buttons = [],
                from,
                to;

            table.page = Math.min(Math.max(1, table.page), pages);
            first = (table.page - 1) * PAGE_SIZE;
            visible = rows.slice(first, first + PAGE_SIZE);

            $el('products').html(visible.length ? visible.map(function (row, i) {
                var url = row.product_id ? config.productUrl.replace('__ID__', row.product_id) : '';

                return '<tr>'
                    + '<td class="mxd-c mxd-rank">' + (first + i + 1) + '</td>'
                    + '<td><div class="mxd-product"><span class="mxd-product__icon">' + icon('inventory_2', 'md') + '</span>'
                    + '<span class="mxd-product__text">'
                    + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(row.product_name) + '</a>'
                        : '<strong>' + esc(row.product_name) + '</strong>')
                    + '<span class="mxd-mono mxd-muted">' + esc(row.sku) + '</span></span></div></td>'
                    + '<td>' + (row.category ? '<span class="mxd-chiplabel">' + esc(row.category) + '</span>' : '<span class="mxd-muted">—</span>') + '</td>'
                    + '<td class="mxd-r"><strong>' + esc(num(row.qty_sold)) + '</strong></td>'
                    + '<td class="mxd-r mxd-text-green"><strong>' + esc(money(row.revenue)) + '</strong></td>'
                    + '<td class="mxd-r mxd-muted">' + esc(money(row.avg_price)) + '</td>'
                    + '<td class="mxd-c">' + stockPill(row) + '</td>'
                    + '<td class="mxd-r"><div class="mxd-rowactions">'
                    + (url ? '<a class="mxd-iconbtn" href="' + esc(url) + '" target="_blank" rel="noopener" title="Open in Magento catalog">'
                        + icon('open_in_new', 'md') + '</a>' : '')
                    + '<button type="button" class="mxd-iconbtn mxd-iconbtn--ai" data-ask-sku="' + esc(row.sku)
                    + '" title="Ask the AI assistant about this product">' + icon('auto_awesome', 'md') + '</button>'
                    + '</div></td></tr>';
            }).join('') : '<tr><td colspan="8" class="mxd-empty">'
                + (data.products && data.products.length ? 'No products match the filter.' : 'No products sold in this period.')
                + '</td></tr>');

            from = rows.length ? first + 1 : 0;
            to = first + visible.length;
            $el('pager-info').html('Showing <strong>' + from + '–' + to + '</strong> of <strong>' + num(rows.length)
                + '</strong> SKUs sold');

            buttons.push('<button type="button" class="mxd-pagebtn" data-page="' + (table.page - 1) + '"'
                + (table.page <= 1 ? ' disabled' : '') + ' aria-label="Previous page">' + icon('chevron_left') + '</button>');
            pageWindow(table.page, pages).forEach(function (page) {
                buttons.push('<button type="button" class="mxd-pagebtn' + (page === table.page ? ' is-active' : '')
                    + '" data-page="' + page + '">' + page + '</button>');
            });
            buttons.push('<button type="button" class="mxd-pagebtn" data-page="' + (table.page + 1) + '"'
                + (table.page >= pages ? ' disabled' : '') + ' aria-label="Next page">' + icon('chevron_right') + '</button>');
            $el('pager').html(buttons.join(''));
        }

        /** Up to 5 page numbers around the current page. */
        function pageWindow(page, pages) {
            var start = Math.max(1, Math.min(page - 2, pages - 4)),
                list = [],
                i;

            for (i = start; i <= Math.min(pages, start + 4); i++) {
                list.push(i);
            }
            return list;
        }

        function exportProducts() {
            var rows = filteredProducts(),
                lines = [['Rank', 'Product', 'SKU', 'Category', 'Units Sold', 'Net Revenue', 'Avg Realized Price', 'Stock']],
                csv,
                link;

            rows.forEach(function (row, i) {
                lines.push([i + 1, row.product_name, row.sku, row.category, row.qty_sold, row.revenue, row.avg_price,
                    row.stock_qty === null ? '' : row.stock_qty]);
            });
            csv = lines.map(function (line) {
                return line.map(function (cell) {
                    var text = cell === null || cell === undefined ? '' : String(cell);

                    // Neutralise spreadsheet formulas and quote every cell
                    if (/^[=+\-@\t\r]/.test(text)) {
                        text = "'" + text;
                    }
                    return '"' + text.replace(/"/g, '""') + '"';
                }).join(',');
            }).join('\r\n');

            link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8'}));
            link.download = 'top_products_' + data.date_range.start + '_' + data.date_range.end + '.csv';
            document.body.appendChild(link);
            link.click();
            setTimeout(function () {
                URL.revokeObjectURL(link.href);
                link.remove();
            }, 0);
        }

        // ── AI assistant ────────────────────────────────────────────────────

        function formatAnswer(text) {
            var html = esc(text);

            html = html.replace(/^#{1,6}\s+(.+)$/gm, '<strong>$1</strong>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/`(.+?)`/g, '<code>$1</code>')
                .replace(/^\s*[-*]\s+(.+)$/gm, '<li>$1</li>')
                .replace(/(?:<li>.*<\/li>\n?)+/g, function (list) {
                    return '<ul>' + list.replace(/\n/g, '') + '</ul>';
                })
                .replace(/\n/g, '<br>');

            return html;
        }

        function ask(question) {
            var $answer = $el('answer'),
                $submit = $el('ask-submit');

            question = $.trim(question || '');
            if (!question || $submit.prop('disabled')) {
                return;
            }
            if (!config.aiEnabled || !config.aiConfigured) {
                $answer.prop('hidden', false).html('<p class="mxd-answer__error">The AI provider is not configured. '
                    + 'Set it up in Stores > Configuration > Meetanshi > AI Reporting.</p>');
                return;
            }

            $el('ask-input').val(question);
            $submit.prop('disabled', true);
            $answer.prop('hidden', false).html('<p class="mxd-answer__q">' + icon('person', 'xs') + ' ' + esc(question)
                + '</p><p class="mxd-answer__loading">' + icon('progress_activity', 'sm') + ' Thinking… local models can take a minute.</p>');

            $.ajax({
                url: config.chatUrl,
                type: 'POST',
                dataType: 'json',
                data: {message: question, form_key: config.formKey, isAjax: true},
                timeout: 300000
            }).done(function (response) {
                var html = '<p class="mxd-answer__q">' + icon('person', 'xs') + ' ' + esc(question) + '</p>';

                if (!response || !response.success) {
                    html += '<p class="mxd-answer__error">' + esc((response && response.message) || 'Something went wrong.') + '</p>';
                } else {
                    html += '<div class="mxd-answer__text">' + formatAnswer(response.answer) + '</div>';
                    if (response.assumption) {
                        html += '<p class="mxd-answer__assumption">Interpreted as: ' + esc(response.assumption) + '</p>';
                    }
                    if (response.sql) {
                        html += '<div class="mxd-answer__meta"><span>' + esc(num(response.rows) + ' rows · '
                            + num(response.time_ms) + ' ms') + '</span>'
                            + '<button type="button" class="mxd-linkbtn" data-role="toggle-sql">Show SQL</button>'
                            + '<a class="mxd-linkbtn" href="' + esc(config.askAiUrl) + '">Open Ask AI</a></div>'
                            + '<pre class="mxd-answer__sql" hidden>' + esc(response.sql) + '</pre>';
                    }
                }
                $answer.html(html);
            }).fail(function (xhr, status) {
                $answer.html('<p class="mxd-answer__error">' + (status === 'timeout'
                    ? 'The AI took too long to answer. Try a simpler question.'
                    : 'Request failed (' + esc(xhr.status) + '). Please try again.') + '</p>');
            }).always(function () {
                $submit.prop('disabled', false);
            });
        }

        // ── Data loading ────────────────────────────────────────────────────

        function rangeParams() {
            var params = {date_range: $el('range').val()};

            if (params.date_range === 'custom') {
                params.custom_start = $el('custom-start').val();
                params.custom_end = $el('custom-end').val();
            }
            return params;
        }

        function updateExportLink() {
            var url = config.exportUrl;

            $el('export').attr('href', url + (url.indexOf('?') === -1 ? '?' : '&') + $.param(rangeParams()));
        }

        function load() {
            if (loading) {
                return;
            }
            loading = true;
            $root.addClass('is-loading');
            $el('error').prop('hidden', true);

            $.ajax({
                url: config.refreshUrl,
                type: 'GET',
                dataType: 'json',
                data: rangeParams()
            }).done(function (response) {
                if (response && response.success) {
                    data = response.data;
                    renderAll();
                    $el('live').attr('title', 'Last updated ' + new Date().toLocaleTimeString()
                        + (liveTimer ? ' — refreshing every 60 seconds' : ''));
                } else {
                    showError((response && response.message) || 'Could not load the dashboard data.');
                }
            }).fail(function (xhr) {
                showError('Could not load the dashboard data (HTTP ' + xhr.status + ').');
            }).always(function () {
                loading = false;
                $root.removeClass('is-loading');
            });
        }

        function showError(message) {
            $el('error').text(message).prop('hidden', false);
        }

        function renderAll() {
            $el('range-label').text(data.date_range.label);
            renderKpis();
            renderTrend();
            renderStatus();
            renderCategories();
            renderSegments();
            renderDayOfWeek();
            renderCategoryFilter();
            renderProducts();
            updateExportLink();
        }

        // ── Events ──────────────────────────────────────────────────────────

        $el('range').on('change', function () {
            var custom = $(this).val() === 'custom';

            $el('custom-range').prop('hidden', !custom);
            if (custom) {
                $el('custom-start').val($el('custom-start').val() || data.date_range.start);
                $el('custom-end').val($el('custom-end').val() || data.date_range.end);
                return;
            }
            table.page = 1;
            load();
        });

        $el('custom-apply').on('click', function () {
            if (!$el('custom-start').val() || !$el('custom-end').val()) {
                return;
            }
            $el('custom-range').prop('hidden', true);
            table.page = 1;
            load();
        });

        $el('live').on('click', function () {
            var $button = $(this);

            if (liveTimer) {
                clearInterval(liveTimer);
                liveTimer = null;
                $button.removeClass('is-live').attr('aria-pressed', 'false').attr('title', 'Refresh every 60 seconds');
                return;
            }
            liveTimer = setInterval(load, LIVE_INTERVAL);
            $button.addClass('is-live').attr('aria-pressed', 'true');
            load();
        });

        $el('trend-mode').on('click', 'button', function () {
            trendMode = $(this).data('mode');
            $(this).addClass('is-active').siblings().removeClass('is-active');
            renderTrend();
        });

        $el('product-search').on('input', function () {
            table.query = $.trim($(this).val());
            table.page = 1;
            renderProducts();
        });

        $el('product-category').on('change', function () {
            table.category = $(this).val();
            table.page = 1;
            renderProducts();
        });

        $el('pager').on('click', '[data-page]', function () {
            table.page = Number($(this).data('page'));
            renderProducts();
        });

        $el('product-export').on('click', exportProducts);

        $el('products').on('click', '[data-ask-sku]', function () {
            var sku = String($(this).data('askSku'));

            ask('How many units of SKU ' + sku + ' were sold each month in the last 6 months, and what was its net revenue?');
            $el('assistant')[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        });

        $el('ask-form').on('submit', function (event) {
            event.preventDefault();
            ask($el('ask-input').val());
        });

        $root.on('click', '.mxd-chip', function () {
            ask($(this).data('question'));
        });

        $el('answer').on('click', '[data-role="toggle-sql"]', function () {
            var $sql = $el('answer').find('.mxd-answer__sql'),
                show = $sql.prop('hidden');

            $sql.prop('hidden', !show);
            $(this).text(show ? 'Hide SQL' : 'Show SQL');
        });

        // Charts use the page fonts: draw once they are ready
        renderAll();
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                renderTrend();
            });
        }
    };
});
