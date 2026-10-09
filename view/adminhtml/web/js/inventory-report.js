/**
 * Meetanshi AIReporting — Inventory & Stock Intelligence
 *
 * Renders every section from the view model's data and reloads it from reports/inventoryData when
 * the range changes or "Sync MSI" is pressed: KPI cards, stock tiers (donut), top sellers, the SKU
 * table (tabs, search, source/category/tier filters, sorting, paging), the copilot insight and the
 * SQL inspector. Also wires the CSV exports (open tab, clearance list, reorder plan), print-to-PDF
 * and AI follow-ups (chat/send).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery', 'chartjs'], function ($, Chart) {
    'use strict';

    var BRAND = '#ee672f',
        STATUS = {
            out: {label: 'Out of Stock', tone: 'red'},
            low: {label: 'Low Stock', tone: 'amber'},
            depleting: {label: 'Depleting', tone: 'amber'},
            idle: {label: 'Idle Capital', tone: 'grey'},
            ok: {label: 'In Stock', tone: 'green'}
        },
        STATUS_ORDER = {out: 0, low: 1, depleting: 2, idle: 3, ok: 4},
        TIER_COLORS = {high: BRAND, optimal: '#545f73', buffer: '#9fb3d1', critical: '#f2a59c', out: '#ba1a1a'},
        RANGE_SHORT = {today: 'Today', week: 'WTD', month: 'MTD', year: 'YTD', custom: 'Range'},
        DEFAULT_SORT = {
            all: ['value', 'desc'],
            risk: ['available', 'asc'],
            idle: ['value', 'desc'],
            turnover: ['turnover', 'desc']
        },
        TAB_COLUMNS = {
            all: ['product', 'source', 'available', 'reserved', 'price', 'value', 'sold', 'cover', 'status', 'actions'],
            risk: ['product', 'source', 'available', 'reserved', 'price', 'value', 'sold', 'cover', 'status', 'actions'],
            idle: ['product', 'source', 'available', 'price', 'value', 'lastSold', 'status', 'actions'],
            turnover: ['product', 'available', 'sold', 'velocity', 'turnover', 'annual', 'cover', 'status', 'actions']
        },
        ICONS = [
            [/bag|backpack|luggage|duffle|messenger|tote/i, 'backpack'],
            [/watch/i, 'watch'],
            [/hoodie|jacket|tee|shirt|tops?\b|tank|bra\b|sweat|pullover|pant|shorts?\b|bottoms?\b|legging|capri/i, 'checkroom'],
            [/shoe|sneaker|boot/i, 'steps'],
            [/ball|fitness|equipment|yoga|strap|roller|kettlebell|dumbbell|sport|bottle|gear/i, 'fitness_center'],
            [/video|download|ebook/i, 'download']
        ];

    return function (config, element) {
        var $root = $(element),
            data = config.data,
            th = config.thresholds,
            charts = {},
            loading = false,
            table = {tab: 'all', query: '', source: '', category: '', tier: '', sort: 'value', dir: 'desc', page: 1, perPage: 10},
            printPerPage = null,
            moneyFmt = new Intl.NumberFormat(undefined, {style: 'currency', currency: config.currency}),
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

        /** "$154,230<small>.00</small>" */
        function moneyHtml(value) {
            var whole = '',
                cents = '';

            if (!moneyFmt.formatToParts) {
                return esc(money(value));
            }
            moneyFmt.formatToParts(Number(value) || 0).forEach(function (part) {
                if (part.type === 'decimal' || part.type === 'fraction') {
                    cents += part.value;
                } else {
                    whole += part.value;
                }
            });
            return esc(whole) + (cents ? '<span class="mxi-kpi__cents">' + esc(cents) + '</span>' : '');
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

        /** Units per day: more decimals for slow sellers. */
        function rate(value) {
            value = Number(value) || 0;

            return new Intl.NumberFormat(undefined, {
                maximumFractionDigits: value >= 10 ? 0 : (value >= 1 ? 1 : 2)
            }).format(value);
        }

        function times(value) {
            return numberFmt.format(Number(value) || 0) + '×';
        }

        function icon(name, size) {
            return '<span class="mxd-ico mxd-ico--' + (size || 'sm') + '" aria-hidden="true">' + name + '</span>';
        }

        /** "Aug 18, 2026" from "2026-08-18 03:51:12" (store-local, no timezone shift). */
        function shortDate(dateTime) {
            var parts = String(dateTime || '').substr(0, 10).split('-');

            return parts.length === 3
                ? new Date(+parts[0], parts[1] - 1, +parts[2]).toLocaleDateString(undefined, {
                    month: 'short', day: 'numeric', year: 'numeric'
                })
                : '—';
        }

        function joinList(items) {
            items = items.filter(Boolean);

            return items.length > 1 ? items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1] : (items[0] || '');
        }

        function rangeShort() {
            return RANGE_SHORT[data.range.key] || 'Range';
        }

        function productUrl(row) {
            return config.productUrl.replace('__ID__', row.product_id);
        }

        function productIcon(row) {
            var text = row.name + ' ' + row.category,
                match = ICONS.filter(function (entry) { return entry[0].test(text); })[0];

            return match ? match[1] : 'inventory_2';
        }

        function sourceName(code) {
            var source = data.sources.filter(function (s) { return s.code === code; })[0];

            return source ? source.name : code;
        }

        function findRow(sku) {
            return data.rows.filter(function (row) { return row.sku === sku; })[0] || null;
        }

        function destroyChart(name) {
            if (charts[name]) {
                charts[name].destroy();
                delete charts[name];
            }
        }

        function rangeParams() {
            var params = {range: data.range.key};

            if (data.range.key === 'custom') {
                params.start = data.range.start;
                params.end = data.range.end;
            }
            return params;
        }

        function exportUrl(params) {
            var query = $.param(params || {});

            return config.exportUrl + (query ? (config.exportUrl.indexOf('?') === -1 ? '?' : '&') + query : '');
        }

        /** [quantity range, name] of a stock tier. */
        function tierParts(key) {
            return {
                high: ['101+', 'High Reserve'],
                optimal: ['51 – 100', 'Optimal'],
                buffer: [(th.low + 1) + ' – 50', 'Buffer Range'],
                critical: ['1 – ' + th.low, 'Critical Low'],
                out: ['Out of Stock', 'Depleted']
            }[key];
        }

        function tierLabel(key) {
            var parts = tierParts(key);

            return parts[0] + ' (' + parts[1] + ')';
        }

        /** Days-of-cover text and tone: red under the critical mark, amber under the healthy band, blue above it. */
        function coverInfo(row) {
            var days = row.cover_days,
                rounded,
                tone;

            if (days === null) {
                if (row.idle) {
                    return {
                        text: row.idle_days === null ? 'Never sold' : 'Idle > ' + th.idleDays + 'd',
                        short: row.idle_days === null ? 'never sold' : 'idle',
                        tone: 'grey',
                        title: row.idle_days === null ? 'No recorded sale' : 'Last sold ' + plural(row.idle_days, 'day', 'days') + ' ago'
                    };
                }
                return {text: 'No sales', short: 'no sales', tone: 'grey', title: 'No units sold in ' + data.range.label};
            }
            rounded = Math.round(days);
            tone = days < th.criticalCover ? 'red'
                : (days < th.healthyCover[0] ? 'amber' : (days <= th.healthyCover[1] ? 'green' : 'blue'));

            return {
                text: (rounded > 999 ? '999+' : num(rounded)) + (rounded === 1 ? ' Day' : ' Days'),
                short: (rounded > 999 ? '999+' : num(rounded)) + 'd cover',
                tone: tone,
                title: num(days) + ' days of cover at ' + rate(row.velocity) + ' units/day (' + data.range.label + ')'
            };
        }

        function statusPill(row) {
            var status = STATUS[row.status];

            return '<span class="mxi-pill mxi-pill--' + status.tone + '"><i></i>' + esc(status.label) + '</span>';
        }

        // ── KPI cards ───────────────────────────────────────────────────────

        function kpi(options) {
            var link = options.tab
                ? ' data-goto-tab="' + options.tab + '" role="button" tabindex="0" title="' + esc(options.linkTitle) + '"' : '';

            return '<div class="mxi-kpi' + (options.tab ? ' mxi-kpi--link' : '') + '"' + link + '>'
                + '<div class="mxi-kpi__head"><span class="mxi-kpi__label">' + esc(options.label) + '</span>'
                + '<span class="mxi-kpi__icon mxi-kpi__icon--' + (options.iconTone || 'slate') + '">' + icon(options.icon, 'md') + '</span></div>'
                + '<div class="mxi-kpi__value' + (options.valueTone ? ' mxi-kpi__value--' + options.valueTone : '') + '">'
                + options.valueHtml + '</div>'
                + '<div class="mxi-kpi__foot mxi-kpi__foot--' + (options.footTone || 'plain') + '">' + options.footHtml + '</div></div>';
        }

        function renderKpis() {
            var s = data.summary,
                depletion = share(s.out_of_stock, s.skus),
                lowFoot;

            if (s.critical_stock) {
                lowFoot = ['red', icon('warning', 'xs') + '<span>' + esc(plural(s.critical_stock, 'SKU', 'SKUs')) + ' at 5 units or fewer</span>'];
            } else if (s.depleting) {
                lowFoot = ['amber', icon('schedule', 'xs') + '<span>' + esc(num(s.depleting)) + ' more under '
                    + th.criticalCover + ' days of cover</span>'];
            } else {
                lowFoot = ['grey', '<span>Zero critical replenishment alerts</span>'];
            }

            $el('kpis').html([
                kpi({
                    label: 'Total Active SKUs', icon: 'category',
                    valueHtml: '<span class="mxi-metric">' + esc(num(s.skus)) + '</span><span class="mxi-kpi__unit">SKUs</span>',
                    footTone: 'split',
                    footHtml: '<span>In Stock: <strong>' + esc(num(s.in_stock)) + '</strong></span>'
                        + '<span title="Products with status Disabled">Disabled: <strong>' + esc(num(s.disabled)) + '</strong></span>'
                }),
                kpi({
                    label: 'Total In-Stock Value', icon: 'account_balance_wallet', iconTone: 'brand',
                    valueHtml: '<span class="mxi-metric">' + moneyHtml(s.stock_value) + '</span>',
                    footTone: 'green',
                    footHtml: icon('inventory', 'xs') + '<span title="Units on hand' + (config.msi ? '; reserved = held by open orders' : '')
                        + '"><strong>' + esc(num(s.units_on_hand)) + '</strong> on hand'
                        + (config.msi ? ' · ' + esc(num(s.units_reserved)) + ' reserved' : '') + '</span>'
                }),
                kpi({
                    label: 'Out of Stock', icon: s.out_of_stock ? 'error' : 'check_circle',
                    iconTone: s.out_of_stock ? 'red' : 'green',
                    valueTone: s.out_of_stock ? 'red' : 'green',
                    valueHtml: '<span class="mxi-metric">' + esc(num(s.out_of_stock)) + '</span><span class="mxi-kpi__unit">SKUs</span>',
                    footTone: s.out_of_stock ? (depletion < 5 ? 'amber' : 'red') : 'green',
                    footHtml: '<span>' + esc(pct(depletion)) + ' Depletion · '
                        + (s.out_of_stock ? (depletion < 5 ? 'Watch' : 'Critical') : 'Optimal') + '</span>',
                    tab: 'risk', linkTitle: 'Show low stock & depletion'
                }),
                kpi({
                    label: 'Low Stock (≤ ' + th.low + ')', icon: 'inventory', iconTone: s.low_stock ? 'amber' : 'slate',
                    valueTone: s.low_stock ? 'amber' : '',
                    valueHtml: '<span class="mxi-metric">' + esc(num(s.low_stock)) + '</span><span class="mxi-kpi__unit">SKUs</span>',
                    footTone: lowFoot[0],
                    footHtml: lowFoot[1],
                    tab: 'risk', linkTitle: 'Show low stock & depletion'
                }),
                kpi({
                    label: 'Stock Turnover (' + rangeShort() + ')', icon: 'published_with_changes', iconTone: 'brand',
                    valueHtml: '<span class="mxi-metric">' + esc(numberFmt.format(s.turnover)) + '</span>'
                        + '<span class="mxi-kpi__unit mxd-text-brand">×</span>',
                    footTone: 'split',
                    footHtml: '<span>Run-rate:</span><strong class="mxd-mono" title="Turnover scaled to 365 days">'
                        + esc(times(s.turnover_annualized)) + ' Annualized</strong>',
                    tab: 'turnover', linkTitle: 'Show turnover & run-rate'
                })
            ].join(''));
        }

        // ── Stock tiers ─────────────────────────────────────────────────────

        function renderTiers() {
            var s = data.summary,
                inStock = share(s.in_stock, s.skus),
                values = data.tiers.map(function (tier) { return tier.skus; }),
                hasData = s.skus > 0;

            $el('donut-center').html('<strong>' + esc(num(s.skus)) + '</strong><span>Active SKUs</span>'
                + '<em class="mxi-pill mxi-pill--' + (inStock >= 100 ? 'green' : (inStock >= 95 ? 'amber' : 'red')) + '">'
                + esc(pct(inStock)) + ' In Stock</em>');
            $el('valuation').text(money(s.stock_value));

            $el('tiers').html(data.tiers.map(function (tier) {
                var active = table.tier === tier.key;

                return '<button type="button" class="mxi-tier' + (tier.skus ? '' : ' is-empty') + (active ? ' is-active' : '')
                    + '" data-tier="' + tier.key + '" aria-pressed="' + active + '"' + (tier.skus || active ? '' : ' disabled')
                    + ' title="' + esc(num(tier.available) + ' units available · ' + money(tier.value) + ' stock value') + '">'
                    + '<span class="mxi-tier__name"><i style="background:' + TIER_COLORS[tier.key] + '"></i>'
                    + '<span>' + esc(tierParts(tier.key)[0]) + '<small>' + esc(tierParts(tier.key)[1]) + '</small></span></span>'
                    + '<span class="mxi-tier__nums"><strong>' + esc(plural(tier.skus, 'SKU', 'SKUs')) + '</strong>'
                    + '<small>(' + esc(pct(share(tier.skus, s.skus))) + ')</small></span></button>';
            }).join(''));

            destroyChart('tiers');
            charts.tiers = new Chart($el('tier-chart')[0], {
                type: 'doughnut',
                data: {
                    labels: data.tiers.map(function (tier) { return tierLabel(tier.key); }),
                    datasets: [{
                        data: hasData ? values : [1],
                        backgroundColor: hasData ? data.tiers.map(function (tier) { return TIER_COLORS[tier.key]; }) : ['#e5eeff'],
                        borderWidth: 0,
                        hoverOffset: hasData ? 4 : 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {display: false},
                        tooltip: {
                            enabled: hasData,
                            callbacks: {
                                label: function (item) {
                                    return ' ' + plural(item.raw, 'SKU', 'SKUs') + ' (' + pct(share(item.raw, s.skus)) + ')';
                                }
                            }
                        }
                    }
                }
            });
        }

        // ── Top sellers ─────────────────────────────────────────────────────

        function renderSellers() {
            var sellers = data.top_sellers;

            $el('sellers-sub').text('Units sold in ' + data.range.label + ' against stock available now');
            $el('view-runrates').html(esc('View All ' + num(data.counts.turnover) + ' SKU Run-Rates') + ' ' + icon('arrow_forward', 'xs'))
                .prop('hidden', !data.counts.turnover);

            if (!sellers.length) {
                $el('sellers').html('<p class="mxd-empty">No units sold in this range.</p>');
                return;
            }
            $el('sellers').html(sellers.map(function (row) {
                var stock = Math.max(0, row.available),
                    soldShare = share(row.sold, row.sold + stock),
                    info = coverInfo(row);

                return '<div class="mxi-seller">'
                    + '<div class="mxi-seller__top">'
                    + '<div class="mxi-seller__name"><a href="' + esc(productUrl(row)) + '" target="_blank" rel="noopener" title="'
                    + esc(row.name) + '">' + esc(row.name) + '</a><span class="mxi-skuchip">' + esc(row.sku) + '</span></div>'
                    + '<div class="mxi-seller__nums"><strong class="mxd-text-brand">' + esc(num(row.sold)) + ' sold</strong>'
                    + '<span class="mxd-muted">/ ' + esc(num(stock)) + ' stock</span>'
                    + '<span class="mxi-cover mxi-cover--' + info.tone + '" title="' + esc(info.title) + '">' + esc(info.short) + '</span></div>'
                    + '</div>'
                    + '<div class="mxi-bar" role="img" aria-label="' + esc(num(row.sold) + ' sold, ' + num(stock) + ' available') + '">'
                    + '<span class="mxi-bar__sold" style="width:' + soldShare.toFixed(2) + '%"></span>'
                    + '<span class="mxi-bar__stock" style="width:' + (100 - soldShare).toFixed(2) + '%"></span></div>'
                    + '</div>';
            }).join(''));
        }

        // ── SKU table ───────────────────────────────────────────────────────

        function columns() {
            var all = {
                product: {label: 'Product & SKU', sort: 'name'},
                source: {label: 'Source Hub', title: 'Quantity on hand per enabled MSI source'},
                available: {label: 'Available Qty', sort: 'available', cls: 'r', title: 'On hand minus reserved: what can still be sold'},
                reserved: {label: 'Reserved', sort: 'reserved', cls: 'r wide', title: 'Held by orders not shipped yet (MSI reservations)'},
                price: {label: 'Unit Price', sort: 'price', cls: 'r wide', title: 'List price, base currency'},
                value: {label: 'Stock Value', sort: 'value', cls: 'r', title: 'Units on hand × list price'},
                sold: {label: rangeShort() + ' Sold', sort: 'sold', cls: 'r', title: 'Net units sold in ' + data.range.label},
                velocity: {label: 'Avg / Day', sort: 'velocity', cls: 'r', title: 'Average units sold per day in the range'},
                turnover: {label: 'Turnover', sort: 'turnover', cls: 'r', title: 'Units sold in the range ÷ units on hand'},
                annual: {label: 'Annualized', sort: 'turnover', cls: 'r', title: 'Turnover scaled to 365 days'},
                cover: {label: 'Days Supply', sort: 'cover_days', cls: 'c', title: 'Available ÷ average daily sales in the range'},
                lastSold: {label: 'Last Sold', sort: 'idle_days', cls: 'c'},
                status: {label: 'Stock Status', sort: 'status', cls: 'c'},
                actions: {label: 'Actions', cls: 'r'}
            };

            return TAB_COLUMNS[table.tab].filter(function (key) {
                return config.msi || (key !== 'source' && key !== 'reserved');
            }).map(function (key) {
                return $.extend({key: key}, all[key]);
            });
        }

        /** "mxi-r mxi-wide" from "r wide": alignment, and columns that fold into a neighbour below 1440 px. */
        function cellClass(col) {
            return (col.cls || '').split(' ').filter(Boolean).map(function (name) { return 'mxi-' + name; }).join(' ');
        }

        function sortValue(row, key) {
            switch (key) {
                case 'name':
                    return String(row.name).toLowerCase();
                case 'status':
                    return STATUS_ORDER[row.status];
                case 'cover_days':
                case 'idle_days':
                    return row[key] === null ? Infinity : row[key];
                case 'turnover':
                    return row.turnover === null ? -1 : row.turnover;
                default:
                    return Number(row[key]) || 0;
            }
        }

        function compareRows(a, b) {
            var x = sortValue(a, table.sort),
                y = sortValue(b, table.sort),
                result = x < y ? -1 : (x > y ? 1 : 0);

            if (result) {
                return table.dir === 'desc' ? -result : result;
            }
            return a.sku < b.sku ? -1 : (a.sku > b.sku ? 1 : 0);
        }

        function inTab(row, tab) {
            return tab === 'risk' ? row.risk : (tab === 'idle' ? row.idle : (tab === 'turnover' ? row.sold > 0 : true));
        }

        function hasFilters() {
            return !!(table.query || table.source || table.category || table.tier);
        }

        function filteredRows() {
            var query = table.query.toLowerCase(),
                category = Number(table.category) || 0;

            return data.rows.filter(function (row) {
                return inTab(row, table.tab)
                    && (!table.tier || row.tier === table.tier)
                    && (!table.source || (row.sources && row.sources[table.source] !== undefined))
                    && (!category || row.category_ids.indexOf(category) !== -1)
                    && (!query || String(row.name).toLowerCase().indexOf(query) !== -1
                        || String(row.sku).toLowerCase().indexOf(query) !== -1);
            }).sort(compareRows);
        }

        function productCell(row) {
            return '<div class="mxi-product"><span class="mxi-thumb">' + icon(productIcon(row), 'md') + '</span>'
                + '<span class="mxi-product__text"><a href="' + esc(productUrl(row)) + '" target="_blank" rel="noopener" title="'
                + esc('Edit ' + row.name + ' in Magento') + '">' + esc(row.name) + '</a>'
                + '<small>' + esc(row.sku) + (row.category ? ' · ' + esc(row.category) : '')
                + (row.enabled ? '' : ' · <span class="mxd-text-red">Disabled</span>') + '</small></span></div>';
        }

        function sourceCell(row) {
            var codes = Object.keys(row.sources || {}).sort(function (a, b) {
                return (b === table.source) - (a === table.source);
            });

            if (!codes.length) {
                return '<span class="mxd-muted">—</span>';
            }
            return '<div class="mxi-sources">' + codes.slice(0, 2).map(function (code) {
                return '<span class="mxi-source' + (code === table.source ? ' is-active' : '') + '" title="'
                    + esc(sourceName(code) + ': ' + num(row.sources[code]) + ' on hand') + '">'
                    + esc(code) + ' · ' + esc(num(row.sources[code])) + '</span>';
            }).join('') + (codes.length > 2 ? '<span class="mxi-source">+' + (codes.length - 2) + '</span>' : '') + '</div>';
        }

        function coverCell(row) {
            var info = coverInfo(row);

            return '<span class="mxi-cover mxi-cover--' + info.tone + '" title="' + esc(info.title) + '">' + esc(info.text) + '</span>';
        }

        function actionsCell(row) {
            return '<div class="mxi-rowactions">'
                + '<a class="mxi-iconbtn" href="' + esc(productUrl(row)) + '" target="_blank" rel="noopener" title="Edit product in Magento">'
                + icon('edit') + '</a>'
                + (row.idle
                    ? '<a class="mxi-iconbtn mxi-iconbtn--ai" href="' + esc(config.couponUrl) + '" target="_blank" rel="noopener" '
                        + 'title="Create a clearance promotion (cart price rule)">' + icon('campaign') + '</a>'
                    : '')
                + '<button type="button" class="mxi-iconbtn mxi-iconbtn--ai" data-ask-sku="' + esc(row.sku) + '" title="'
                + esc(row.idle ? 'Ask Copilot about this idle SKU\'s sales history' : 'Ask Copilot for this SKU\'s sales trend (reorder check)')
                + '">' + icon('auto_awesome') + '</button></div>';
        }

        function cell(column, row) {
            switch (column.key) {
                case 'product':
                    return productCell(row);
                case 'source':
                    return sourceCell(row);
                case 'available':
                    return '<strong class="mxd-mono' + (row.available <= 0 ? ' mxd-text-red'
                        : (row.available <= th.low ? ' mxd-text-amber' : '')) + '">' + esc(num(row.available)) + '</strong>'
                        + (config.msi && TAB_COLUMNS[table.tab].indexOf('reserved') !== -1
                            ? '<small class="mxi-narrow">' + esc(num(row.reserved)) + ' reserved</small>' : '');
                case 'reserved':
                    return '<span class="mxd-mono mxd-muted">' + esc(num(row.reserved)) + '</span>';
                case 'price':
                    return '<span class="mxd-mono">' + esc(money(row.price)) + '</span>';
                case 'value':
                    return '<strong class="mxd-mono">' + esc(money(row.value)) + '</strong>'
                        + (TAB_COLUMNS[table.tab].indexOf('price') !== -1
                            ? '<small class="mxi-narrow">@ ' + esc(money(row.price)) + '</small>' : '');
                case 'sold':
                    return row.sold > 0
                        ? '<strong class="mxd-mono mxd-text-brand">' + esc(plural(row.sold, 'unit', 'units')) + '</strong>'
                        : '<span class="mxd-mono mxd-muted">0 units</span>';
                case 'velocity':
                    return '<span class="mxd-mono">' + esc(rate(row.velocity)) + '</span>';
                case 'turnover':
                    return row.turnover === null ? '<span class="mxd-muted">—</span>'
                        : '<strong class="mxd-mono">' + esc(times(row.turnover)) + '</strong>';
                case 'annual':
                    return row.turnover === null ? '<span class="mxd-muted">—</span>'
                        : '<span class="mxd-mono">' + esc(times(row.turnover * 365 / Math.max(1, data.range.days))) + '</span>';
                case 'cover':
                    return coverCell(row);
                case 'lastSold':
                    return row.last_sold_at
                        ? '<span class="mxi-lastsold"><span class="mxd-mono">' + esc(shortDate(row.last_sold_at)) + '</span><small>'
                            + esc(plural(row.idle_days, 'day', 'days')) + ' ago</small></span>'
                        : '<span class="mxd-muted">Never</span>';
                case 'status':
                    return statusPill(row);
                case 'actions':
                    return actionsCell(row);
            }
            return '';
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

        function pagerButton(page, label, disabled, title) {
            return '<button type="button" class="mxi-pagebtn" data-page="' + page + '"' + (disabled ? ' disabled' : '')
                + (title ? ' title="' + title + '" aria-label="' + title + '"' : '') + '>' + label + '</button>';
        }

        function emptyMessage() {
            if (!data.rows.length) {
                return 'No stock-managed products found.';
            }
            if (hasFilters()) {
                return 'No SKUs match the filters.';
            }
            return {
                risk: 'No SKU is out of stock, at ' + th.low + ' units or fewer, or under ' + th.criticalCover + ' days of cover.',
                idle: 'Every in-stock SKU has sold in the last ' + th.idleDays + ' days.',
                turnover: 'No units sold in this range.'
            }[table.tab] || 'No SKUs.';
        }

        function renderTabs() {
            $el('tabs').find('[data-tab]').each(function () {
                var tab = $(this).data('tab'),
                    count = data.counts[tab] || 0,
                    active = tab === table.tab;

                $(this).toggleClass('is-active', active).attr('aria-selected', String(active));
                $(this).find('.mxi-tab__count').text(num(count))
                    .attr('class', 'mxi-tab__count' + (active ? ' is-active' : '')
                        + (tab === 'risk' && count ? ' is-red' : '') + (tab === 'idle' && count ? ' is-strong' : ''));
            });
            $el('catalog-total').html('Catalog Total: <strong>' + esc(plural(data.counts.all, 'Item', 'Items')) + '</strong>');
        }

        function renderTable() {
            var cols = columns(),
                rows = filteredRows(),
                perPage = table.perPage || rows.length || 1,
                pages = Math.max(1, Math.ceil(rows.length / perPage)),
                first,
                visible,
                loaded = data.rows.filter(function (row) { return inTab(row, table.tab); }).length,
                buttons = [];

            table.page = Math.min(Math.max(1, table.page), pages);
            first = (table.page - 1) * perPage;
            visible = rows.slice(first, first + perPage);

            renderTabs();

            $el('thead').html('<tr>' + cols.map(function (col) {
                var sorted = col.sort && table.sort === col.sort;

                return '<th class="' + cellClass(col) + '"' + (col.title ? ' title="' + esc(col.title) + '"' : '')
                    + (sorted ? ' aria-sort="' + (table.dir === 'asc' ? 'ascending' : 'descending') + '"' : '') + '>'
                    + (col.sort
                        ? '<button type="button" class="mxi-sort' + (sorted ? ' is-sorted' : '') + '" data-sort="' + col.sort + '">'
                            + '<span>' + esc(col.label) + '</span>'
                            + (sorted ? icon(table.dir === 'asc' ? 'arrow_upward' : 'arrow_downward', 'xs') : '') + '</button>'
                        : esc(col.label))
                    + '</th>';
            }).join('') + '</tr>');

            $el('rows').html(visible.length ? visible.map(function (row) {
                return '<tr class="mxi-row-' + row.status + '">' + cols.map(function (col) {
                    return '<td class="' + cellClass(col) + '">' + cell(col, row) + '</td>';
                }).join('') + '</tr>';
            }).join('') : '<tr><td colspan="' + cols.length + '" class="mxd-empty">' + esc(emptyMessage()) + '</td></tr>');

            $el('pager-info').html(rows.length
                ? 'Showing <strong>' + num(first + 1) + ' – ' + num(first + visible.length) + '</strong> of <strong>'
                    + esc(plural(rows.length, 'SKU', 'SKUs')) + '</strong>' + (hasFilters() ? ' (filtered)' : '')
                : 'No SKUs');

            buttons.push(pagerButton(1, icon('first_page', 'md'), table.page <= 1, 'First page'));
            buttons.push(pagerButton(table.page - 1, icon('chevron_left', 'md'), table.page <= 1, 'Previous page'));
            pageWindow(table.page, pages).forEach(function (page) {
                buttons.push('<button type="button" class="mxi-pagebtn mxi-pagebtn--num' + (page === table.page ? ' is-active' : '')
                    + '" data-page="' + page + '">' + page + '</button>');
            });
            buttons.push(pagerButton(table.page + 1, icon('chevron_right', 'md'), table.page >= pages, 'Next page'));
            buttons.push(pagerButton(pages, icon('last_page', 'md'), table.page >= pages, 'Last page'));
            $el('pager').html(buttons.join(''));

            $el('table-note').prop('hidden', loaded >= (data.counts[table.tab] || 0))
                .text('Showing the first ' + num(loaded) + ' of ' + num(data.counts[table.tab]) + ' SKUs in this tab on the page; '
                    + 'the CSV export includes all of them.');

            $el('tier-chip').prop('hidden', !table.tier)
                .html(table.tier ? esc('Tier: ' + tierLabel(table.tier)) + ' ' + icon('close', 'xs') : '');
            $el('tiers').find('[data-tier]').each(function () {
                var active = $(this).data('tier') === table.tier;

                $(this).toggleClass('is-active', active).attr('aria-pressed', String(active));
            });
        }

        function renderFilters() {
            var $source = $el('source'),
                $category = $el('category');

            $source.find('option:not(:first)').remove();
            data.sources.forEach(function (source) {
                $source.append($('<option>').val(source.code).text(source.name + ' (' + source.code + ')'));
            });
            if (!data.sources.some(function (s) { return s.code === table.source; })) {
                table.source = '';
            }
            $source.val(table.source);

            $category.find('option:not(:first)').remove();
            data.categories.forEach(function (category) {
                $category.append($('<option>').val(String(category.id)).text(category.name + ' (' + category.skus + ')'));
            });
            if (!data.categories.some(function (c) { return String(c.id) === table.category; })) {
                table.category = '';
            }
            $category.val(table.category);
        }

        function setTab(tab) {
            table.tab = tab;
            table.sort = DEFAULT_SORT[tab][0];
            table.dir = DEFAULT_SORT[tab][1];
            table.page = 1;
            renderTable();
        }

        function scrollToTable() {
            $el('table-card')[0].scrollIntoView({behavior: 'smooth', block: 'start'});
        }

        // ── Copilot insight ─────────────────────────────────────────────────

        function renderCopilot() {
            var s = data.summary,
                inStock = share(s.in_stock, s.skus),
                top = data.top_sellers[0],
                fastest = data.rows.filter(function (row) {
                    return row.risk && row.status !== 'out' && row.cover_days !== null;
                }).sort(function (a, b) { return a.cover_days - b.cover_days; })[0],
                idle = data.rows.filter(function (row) { return row.idle; })
                    .sort(function (a, b) { return b.value - a.value; }),
                reorder = data.rows.filter(function (row) {
                    return row.velocity > 0 && row.cover_days !== null && row.cover_days < th.healthyCover[0];
                }).length,
                html;

            if (!s.skus) {
                $el('insight').html('<p>No stock-managed products were found, so there is nothing to analyse yet.</p>');
            } else {
                html = '<p><strong>Executive Inventory Health:</strong> '
                    + (s.out_of_stock ? 'In-stock rate is ' : 'Catalog health is optimal at ')
                    + '<span class="mxi-hl ' + (s.out_of_stock ? 'mxi-hl--amber' : 'mxi-hl--green') + '">' + esc(pct(inStock))
                    + ' in-stock rate</span> across ' + esc(plural(s.skus, 'active SKU', 'active SKUs')) + ' with <strong>'
                    + esc(money(s.stock_value)) + '</strong> of capital deployed at list price ('
                    + esc(num(s.units_on_hand)) + ' units on hand'
                    + (config.msi ? ', ' + esc(num(s.units_reserved)) + ' reserved for open orders' : '') + ').';
                if (s.risk) {
                    html += ' <strong class="mxi-hl mxi-hl--brand">' + esc(plural(s.risk, 'SKU needs', 'SKUs need'))
                        + ' attention</strong>: ' + esc(joinList([
                            s.out_of_stock ? num(s.out_of_stock) + ' out of stock' : '',
                            s.low_stock ? num(s.low_stock) + ' at ' + th.low + ' units or fewer' : '',
                            s.depleting ? num(s.depleting) + ' under ' + th.criticalCover + ' days of cover' : ''
                        ]))
                        + (fastest ? '; <em>' + esc(fastest.name) + '</em> runs out first (~' + esc(num(Math.round(fastest.cover_days)))
                            + ' days at the current pace).' : '.');
                } else {
                    html += ' No near-term stockouts detected (≤ ' + th.low + ' units or under ' + th.criticalCover + ' days of cover).';
                }
                html += '</p>';

                if (s.idle) {
                    html += '<p class="mxi-copilot__small"><strong class="mxi-copilot__label">Capital Optimization Alert:</strong> '
                        + esc(plural(s.idle, 'SKU', 'SKUs'))
                        + (idle.length ? ' (including <em>' + idle.slice(0, 2).map(function (row) { return esc(row.name); }).join('</em> and <em>')
                            + '</em>)' : '')
                        + (s.idle === 1 ? ' has' : ' have') + ' had no sale in over ' + th.idleDays + ' days, tying up <strong>'
                        + esc(money(s.idle_value)) + '</strong> (' + esc(pct(share(s.idle_value, s.stock_value)))
                        + ' of stock value) in idle capital. Recommend a clearance promotion or bundle to accelerate turnover.</p>';
                }

                html += '<p class="mxi-copilot__small">' + (top
                    ? 'In ' + esc(data.range.label) + ', ' + esc(plural(s.units_sold, 'unit', 'units')) + ' sold across '
                        + esc(plural(s.selling, 'SKU', 'SKUs')) + '; top seller <strong>' + esc(top.name) + '</strong> ('
                        + esc(plural(top.sold, 'unit', 'units')) + (top.cover_days !== null ? ', ' + esc(coverInfo(top).short) : '') + ').'
                        + (reorder ? ' ' + esc(plural(reorder, 'selling SKU is', 'selling SKUs are')) + ' below '
                            + th.healthyCover[0] + ' days of cover — the PO reorder plan lists them.' : '')
                        + (s.overstock ? ' ' + esc(plural(s.overstock, 'SKU holds', 'SKUs hold')) + ' more than '
                            + th.healthyCover[1] + ' days of cover at this pace.' : '')
                    : 'No units were sold in ' + esc(data.range.label) + '.') + '</p>';

                $el('insight').html(html);
            }

            $el('runtime').text('Runtime: ' + num(data.query_ms) + 'ms');
            $el('query-time').html(icon('speed', 'xs') + ' Query time: ' + esc(num(data.query_ms)) + 'ms');
            $el('snapshot').text('Snapshot: ' + data.generated_at + ' · ' + data.range.label);
            $el('sql-text').text(formatSql(data.sql));

            $el('export-clearance').attr('href', exportUrl($.extend({tab: 'clearance'}, rangeParams())));
            setCount($el('export-clearance'), s.idle);
            $el('export-reorder').attr('href', exportUrl($.extend({tab: 'reorder'}, rangeParams())));
            setCount($el('export-reorder'), reorder);
        }

        function setCount($button, count) {
            var $count = $button.find('.mxi-btncount');

            if (!$count.length) {
                $count = $('<span class="mxi-btncount">').appendTo($button);
            }
            $count.text(num(count));
        }

        function formatSql(sql) {
            return String(sql || '').replace(/ (LEFT JOIN|JOIN|FROM|WHERE|GROUP BY|ORDER BY) /g, '\n$1 ');
        }

        function copied() {
            $el('sql-copy-label').text('Copied');
            setTimeout(function () {
                $el('sql-copy-label').text('Copy SQL');
            }, 1500);
        }

        /** Clipboard without the async API (plain-HTTP admin): a hidden textarea and execCommand. */
        function copyWithTextarea(text) {
            var $area = $('<textarea readonly>').val(text).css({position: 'fixed', top: '-1000px'}).appendTo('body');

            $area[0].select();
            try {
                document.execCommand('copy');
                copied();
            } catch (e) {
                window.prompt('Copy the SQL:', text);
            }
            $area.remove();
        }

        function copySql() {
            var text = formatSql(data.sql);

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(copied, function () { copyWithTextarea(text); });
            } else {
                copyWithTextarea(text);
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
                $answer.prop('hidden', false).html('<p class="mxi-answer__error">The AI provider is not configured. '
                    + 'Set it up in Stores > Configuration > Meetanshi > AI Reporting.</p>');
                return;
            }

            $el('ask-input').val(question);
            $submit.prop('disabled', true);
            $answer.prop('hidden', false).html('<p class="mxi-answer__q">' + icon('person', 'xs') + ' ' + esc(question)
                + '</p><p class="mxi-answer__loading">' + icon('progress_activity', 'sm') + ' Thinking… local models can take a minute.</p>');

            $.ajax({
                url: config.chatUrl,
                type: 'POST',
                dataType: 'json',
                data: {message: question, form_key: config.formKey, isAjax: true},
                timeout: 300000
            }).done(function (response) {
                var html = '<p class="mxi-answer__q">' + icon('person', 'xs') + ' ' + esc(question) + '</p>';

                if (!response || !response.success) {
                    html += '<p class="mxi-answer__error">' + esc((response && response.message) || 'Something went wrong.') + '</p>';
                } else {
                    html += '<div class="mxi-answer__text">' + formatAnswer(response.answer) + '</div>';
                    if (response.assumption) {
                        html += '<p class="mxi-answer__assumption">Interpreted as: ' + esc(response.assumption) + '</p>';
                    }
                    if (response.sql) {
                        html += '<div class="mxi-answer__meta"><span>' + esc(num(response.rows) + ' rows · '
                            + num(response.time_ms) + ' ms') + '</span>'
                            + '<button type="button" class="mxi-linkbtn" data-role="toggle-sql">Show SQL</button>'
                            + '<a class="mxi-linkbtn" href="' + esc(config.askAiUrl) + '">Open Ask AI</a></div>'
                            + '<pre class="mxi-answer__sql" hidden>' + esc(response.sql) + '</pre>';
                    }
                }
                $answer.html(html);
            }).fail(function (xhr, status) {
                $answer.html('<p class="mxi-answer__error">' + (status === 'timeout'
                    ? 'The AI took too long to answer. Try a simpler question.'
                    : 'Request failed (' + esc(xhr.status) + '). Please try again.') + '</p>');
            }).always(function () {
                $submit.prop('disabled', false);
            });
        }

        function askAboutSku(row) {
            ask(row.idle
                ? 'When was SKU \'' + row.sku + '\' (' + row.name + ') last ordered, and how many units of it were sold in each of the last 12 months?'
                : 'For SKU \'' + row.sku + '\' (' + row.name + '), show the units sold per month over the last 6 months and its current stock quantity.');
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
                    table.page = 1;
                    renderAll();
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

        function renderAll() {
            $el('range-label').text(data.range.label);
            setActiveRange(data.range.key);
            renderKpis();
            renderTiers();
            renderSellers();
            renderFilters();
            renderTable();
            renderCopilot();
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

        $el('refresh').on('click', function () {
            load(rangeParams());
        });

        $el('kpis').on('click keydown', '[data-goto-tab]', function (event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            setTab($(this).data('goto-tab'));
            scrollToTable();
        });

        $el('tiers').on('click', '[data-tier]', function () {
            var key = $(this).data('tier');

            table.tier = table.tier === key ? '' : key;
            if (table.tier && table.tab !== 'all') {
                setTab('all');
            } else {
                table.page = 1;
                renderTable();
            }
            if (table.tier) {
                scrollToTable();
            }
        });

        $el('tier-chip').on('click', function () {
            table.tier = '';
            table.page = 1;
            renderTable();
        });

        $el('view-runrates').on('click', function () {
            setTab('turnover');
            scrollToTable();
        });

        $el('tabs').on('click', '[data-tab]', function () {
            setTab($(this).data('tab'));
        });

        $el('thead').on('click', '[data-sort]', function () {
            var key = $(this).data('sort');

            if (table.sort === key) {
                table.dir = table.dir === 'asc' ? 'desc' : 'asc';
            } else {
                table.sort = key;
                table.dir = key === 'name' || key === 'status' || key === 'cover_days' ? 'asc' : 'desc';
            }
            table.page = 1;
            renderTable();
        });

        $el('search').on('input', function () {
            table.query = $.trim($(this).val());
            table.page = 1;
            renderTable();
        });

        $el('source').on('change', function () {
            table.source = $(this).val();
            table.page = 1;
            renderTable();
        });

        $el('category').on('change', function () {
            table.category = $(this).val();
            table.page = 1;
            renderTable();
        });

        $el('per-page').on('change', function () {
            table.perPage = Number($(this).val()) || 0;
            table.page = 1;
            renderTable();
        });

        $el('pager').on('click', '[data-page]', function () {
            table.page = Number($(this).data('page'));
            renderTable();
        });

        $el('rows').on('click', '[data-ask-sku]', function () {
            var row = findRow(String($(this).data('ask-sku')));

            if (row) {
                askAboutSku(row);
            }
        });

        $el('export').on('click', function () {
            var params = $.extend({tab: table.tab}, rangeParams());

            if (table.query) {
                params.q = table.query;
            }
            if (table.source) {
                params.source = table.source;
            }
            if (table.category) {
                params.category = table.category;
            }
            if (table.tier) {
                params.tier = table.tier;
            }
            window.location.href = exportUrl(params);
        });

        $el('print').on('click', function () {
            window.print();
        });

        $el('sql-toggle').on('click', function () {
            var open = $el('sql-panel').prop('hidden');

            $el('sql-panel').prop('hidden', !open);
            $(this).attr('aria-expanded', String(open));
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

        $el('ask-form').on('submit', function (event) {
            event.preventDefault();
            ask($el('ask-input').val());
        });

        $root.on('click', '.mxi-chip', function () {
            ask($(this).data('question'));
        });

        $el('answer').on('click', '[data-role="toggle-sql"]', function () {
            var $sql = $el('answer').find('.mxi-answer__sql'),
                show = $sql.prop('hidden');

            $sql.prop('hidden', !show);
            $(this).text(show ? 'Hide SQL' : 'Show SQL');
        });

        // The PDF lists every row of the open tab; charts are redrawn at print width, then back
        window.addEventListener('beforeprint', function () {
            printPerPage = table.perPage;
            table.perPage = 0;
            renderTable();
            Object.keys(charts).forEach(function (name) { charts[name].resize(); });
        });
        window.addEventListener('afterprint', function () {
            if (printPerPage !== null) {
                table.perPage = printPerPage;
                printPerPage = null;
                renderTable();
            }
        });

        renderAll();
    };
});
