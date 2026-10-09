/**
 * Meetanshi AIReporting — Product Performance & Catalog Intelligence
 *
 * Renders every section from the view model's data (first from the page, then from
 * reports/productData when the range changes): KPI cards, ticker, top 10, product types, price
 * bands, categories, the tabbed product table (filters, paging, CSV via reports/productExport) and
 * the rule-based copilot summary with AI follow-ups (chat/send).
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery', 'chartjs'], function ($, Chart) {
    'use strict';

    var BRAND = '#ee672f',
        NAVY = '#213145',
        TYPE_COLORS = [BRAND, NAVY, '#f4a27f', '#0284c7', '#cbd5e1', '#94a3b8', '#ffd5c2'],
        TYPE_LABELS = {
            simple: 'Simple Products',
            configurable: 'Configurable',
            bundle: 'Bundle',
            grouped: 'Grouped',
            virtual: 'Virtual',
            downloadable: 'Downloadable',
            giftcard: 'Gift Card'
        },
        VELOCITY = {
            restock: {label: 'Restock Urgency', stars: '', icon: 'warning', tone: 'red'},
            high: {label: 'High Velocity', stars: '★★★', tone: 'brand'},
            medium: {label: 'Medium Velocity', stars: '★★', tone: 'slate'},
            low: {label: 'Low Velocity', stars: '★', tone: 'muted'}
        },
        TAB_NOUNS = {revenue: 'selling SKUs', qty: 'selling SKUs', worst: 'low-velocity SKUs', dead: 'unsold SKUs'};

    return function (config, element) {
        var $root = $(element),
            data = config.data,
            charts = {},
            topMode = 'revenue',
            loading = false,
            asking = false,
            table = {tab: 'revenue', query: '', category: '', stock: '', page: 1, perPage: 10},
            moneyFmt = new Intl.NumberFormat(undefined, {style: 'currency', currency: config.currency}),
            moneyWhole = new Intl.NumberFormat(undefined, {
                style: 'currency', currency: config.currency, maximumFractionDigits: 0
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

        function typeLabel(type) {
            return TYPE_LABELS[type] || String(type || 'Other').replace(/^\w/, function (c) {
                return c.toUpperCase();
            });
        }

        function bandLabel(band) {
            if (band.min === null) {
                return 'Under ' + moneyWhole.format(band.max);
            }
            if (band.max === null) {
                return moneyWhole.format(band.min) + '+';
            }
            return moneyWhole.format(band.min) + ' – ' + moneyWhole.format(band.max);
        }

        function productUrl(row) {
            return row.product_id ? config.productUrl.replace('__ID__', row.product_id) : '';
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
            return config.exportUrl + (config.exportUrl.indexOf('?') === -1 ? '?' : '&') + $.param(params);
        }

        function stockLevel(row) {
            var qty = row.stock_qty;

            if (qty === null || qty === undefined) {
                return 'untracked';
            }
            if (qty <= 0) {
                return 'out';
            }
            return qty <= config.lowStockQty ? 'low' : 'in';
        }

        // ── KPI cards and ticker ────────────────────────────────────────────

        function kpiCard(options) {
            return '<div class="mxc-kpi' + (options.alert ? ' mxp-kpi--alert' : '') + '">'
                + '<div class="mxc-kpi__head"><span class="mxc-kpi__label">' + esc(options.label) + '</span>'
                + (options.badge || '') + '</div>'
                + '<div class="mxc-kpi__value"><span class="mxc-metric">' + esc(options.value) + '</span>'
                + '<span class="mxc-kpi__unit">' + esc(options.unit) + '</span></div>'
                + '<div class="mxp-kpi__foot">' + options.foot + '</div></div>';
        }

        function renderKpis() {
            var c = data.catalog,
                t = data.totals,
                dead = data.dead,
                change = t.units_change;

            $el('kpis').html([
                kpiCard({
                    label: 'Total Products', value: num(c.total), unit: 'SKUs',
                    badge: c.created > 0 ? badge('+' + num(c.created) + ' new', 'brand', 'Products created in this period') : '',
                    foot: '<span>' + esc(num(c.enabled) + ' enabled') + '</span><span>' + esc(num(c.disabled) + ' disabled') + '</span>'
                }),
                kpiCard({
                    label: 'Active SKUs Sold', value: num(t.sold_products), unit: 'SKUs',
                    badge: badge(pct(t.sell_through) + ' sell-through', 'slate',
                        num(t.sold_products) + ' of ' + num(t.sellable) + ' sellable products sold at least once'),
                    foot: '<span>' + esc('Across ' + plural(t.orders, 'order', 'orders')) + '</span>'
                        + '<span class="mxd-text-brand">' + esc(plural(dead.count, 'SKU', 'SKUs') + ' idle') + '</span>'
                }),
                kpiCard({
                    label: 'Total Units Sold', value: num(t.units), unit: 'Units',
                    badge: change === null || change === undefined
                        ? (t.units > 0 ? badge('New', 'brand', 'No units sold in the previous period of the same length') : '')
                        : badge((change > 0 ? '+' : '') + pct(change) + ' vs prev.', change < 0 ? 'amber' : 'brand',
                            num(t.previous_units) + ' units in the previous period of the same length'),
                    foot: '<span>' + esc('Avg basket: ' + num(t.avg_basket) + ' items') + '</span>'
                        + '<span>' + esc('Net revenue ' + moneyWhole.format(t.revenue)) + '</span>'
                }),
                kpiCard({
                    label: 'Zero-Sales / Dead Stock', value: num(dead.count), unit: 'Products', alert: dead.count > 0,
                    badge: badge(pct(share(dead.count, t.sellable)) + ' idle', dead.count > 0 ? 'red' : 'grey',
                        'Sellable products with no sale in this period'),
                    foot: '<span class="mxd-text-red"><strong>' + esc(moneyWhole.format(dead.stock_value) + ' tied up') + '</strong></span>'
                        + '<span>' + esc('in unsold stock (list price)') + '</span>'
                })
            ].join(''));
        }

        function renderTicker() {
            var top = data.products[0];

            $el('ticker').html(
                '<div>' + icon('star', 'sm') + '<span class="mxc-ticker__label">Top Grossing SKU:</span>'
                + (top ? '<span><strong>' + esc(top.product_name) + '</strong> (' + esc(top.sku) + ' · ' + esc(money(top.revenue)) + ')</span>'
                    : '<span>No sales in this period</span>') + '</div>'
                + '<i class="mxc-ticker__sep"></i>'
                + '<div>' + icon('donut_large', 'sm') + '<span class="mxc-ticker__label">Catalog Concentration:</span>'
                + '<span>Top 5 SKUs generate <strong class="mxd-text-brand">' + esc(pct(data.totals.top5_share)) + '</strong> of net revenue</span></div>'
            );
            $el('latency').text('SQL exec: ' + num(data.query_ms) + 'ms · catalog_product_entity + sales_order_item');
        }

        // ── Top 10, product types, price bands ──────────────────────────────

        function renderTop10() {
            var rows = data.products.slice(),
                key = topMode === 'qty' ? 'qty_sold' : 'revenue',
                max,
                first,
                second;

            if (topMode === 'qty') {
                rows.sort(function (a, b) {
                    return b.qty_sold - a.qty_sold || b.revenue - a.revenue;
                });
            }
            rows = rows.slice(0, 10);
            max = rows.length ? rows[0][key] : 0;

            $el('top-sub').text('Ranked by ' + (topMode === 'qty' ? 'net units sold' : 'net revenue') + ' · ' + data.range.label);
            $el('top10').html(rows.length ? rows.map(function (row, i) {
                var url = productUrl(row);

                return '<div class="mxp-bar">'
                    + '<div class="mxp-bar__text"><span class="mxp-bar__name"><span class="mxp-bar__rank">#' + (i + 1) + '</span>'
                    + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(row.product_name) + '</a>'
                        : '<strong>' + esc(row.product_name) + '</strong>')
                    + '<span class="mxp-sku">' + esc(row.sku) + '</span></span>'
                    + '<span class="mxp-bar__nums"><small>' + esc(plural(row.qty_sold, 'unit', 'units')) + '</small><strong>'
                    + esc(money(row.revenue)) + '</strong></span></div>'
                    + '<div class="mxp-bar__track"><span class="mxp-bar__fill' + (i < 3 ? '' : ' is-soft') + '" style="width:'
                    + Math.max(2, share(row[key], max)) + '%"></span></div></div>';
            }).join('') : '<p class="mxd-empty">No products sold in this period.</p>');

            first = rows[0];
            second = rows[1];
            $el('benchmark').text(first && second && second[key] > 0
                ? 'Scale benchmark: ' + first.product_name + ' is ' + numberFmt.format(Math.round(first[key] / second[key] * 10) / 10)
                    + '× the 2nd place item'
                : '');
            $el('view-all-label').text('View all ' + plural(data.product_count, 'selling SKU', 'selling SKUs'));
            $el('view-all').prop('hidden', !data.product_count);
        }

        function renderTypes() {
            var types = data.types,
                total = types.reduce(function (sum, t) {
                    return sum + Math.max(0, t.revenue);
                }, 0),
                hasData = total > 0;

            $el('type-total').text(moneyWhole.format(total));
            $el('types').html(types.length ? types.map(function (type, i) {
                return '<div class="mxp-legend__row"><span class="mxp-legend__name"><i class="mxc-dot" style="background:'
                    + TYPE_COLORS[i % TYPE_COLORS.length] + '"></i>' + esc(typeLabel(type.product_type)) + '</span>'
                    + '<span class="mxp-legend__nums"><strong>' + esc(pct(share(Math.max(0, type.revenue), total))) + '</strong>'
                    + '<small>' + esc(money(type.revenue) + ' · ' + plural(type.products, 'SKU', 'SKUs')) + '</small></span></div>';
            }).join('') : '<p class="mxd-empty">No sales in this period.</p>');

            destroyChart('types');
            charts.types = new Chart($el('type-chart')[0], {
                type: 'doughnut',
                data: {
                    labels: types.map(function (t) {
                        return typeLabel(t.product_type);
                    }),
                    datasets: [{
                        data: hasData ? types.map(function (t) {
                            return Math.max(0, t.revenue);
                        }) : [1],
                        backgroundColor: hasData ? TYPE_COLORS : ['#e5eeff'],
                        borderWidth: 0
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
                                    return ' ' + money(item.raw);
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderBands() {
            var bands = data.bands,
                last = 2,
                totalUnits = 0,
                best = null,
                maxUnits = 0;

            bands.forEach(function (band, i) {
                totalUnits += band.units;
                if (band.units > 0) {
                    last = Math.max(last, i);
                }
                if (band.units > maxUnits) {
                    maxUnits = band.units;
                    best = band;
                }
            });
            bands = bands.slice(0, last + 1);

            $el('bands').html(bands.map(function (band) {
                var sweet = band === best;

                return '<div class="mxp-band' + (sweet ? ' is-sweet' : '') + '">'
                    + '<div class="mxp-band__text"><span>' + esc(bandLabel(band)) + (sweet ? ' ' + icon('local_fire_department', 'xs') : '')
                    + '</span><span class="mxp-band__nums">' + esc(plural(band.units, 'unit', 'units') + ' · ' + moneyWhole.format(band.revenue)
                        + (sweet && totalUnits ? ' (' + pct(share(band.units, totalUnits)) + ')' : '')) + '</span></div>'
                    + '<div class="mxp-band__track"><span style="width:' + share(band.units, maxUnits) + '%"></span></div></div>';
            }).join(''));

            $el('sweet-spot').text(best ? 'Sweet Spot: ' + bandLabel(best) : '').prop('hidden', !best);
            $el('band-signal').html(best
                ? icon('insights', 'sm') + '<span><strong>Velocity signal:</strong> most units sell at <strong class="mxd-text-brand">'
                    + esc(bandLabel(best)) + '</strong> — ' + esc(plural(best.units, 'unit', 'units') + ' across '
                    + plural(best.products, 'product', 'products') + ' (' + pct(share(best.units, totalUnits)) + ' of units).') + '</span>'
                : '').prop('hidden', !best);
        }

        // ── Categories ──────────────────────────────────────────────────────

        function renderCategories() {
            var cats = data.categories,
                total = data.totals.revenue,
                topVol = null,
                topMargin = null;

            cats.forEach(function (cat, i) {
                if (i > 0 && (topVol === null || cat.units > topVol.units)) {
                    topVol = cat;
                }
                cat.margin = cat.cost !== null && cat.revenue > 0 ? (cat.revenue - cat.cost) / cat.revenue * 100 : null;
                if (cat.margin !== null && (topMargin === null || cat.margin > topMargin.margin)) {
                    topMargin = cat;
                }
            });
            if (topVol && cats[0] && topVol.units <= cats[0].units) {
                topVol = null;
            }

            $el('categories').html(cats.length ? cats.map(function (cat, i) {
                var tag = i === 0 ? badge('Primary', 'brand')
                    : (cat.low_stock > 0 ? badge('Stock Warning', 'red', plural(cat.low_stock, 'product', 'products')
                        + ' at or below ' + config.lowStockQty + ' units')
                        : (cat === topVol ? badge('Top Vol', 'navy') : (cat === topMargin ? badge('Top Margin', 'amber') : '')));

                return '<div class="mxp-cat' + (cat.low_stock > 0 ? ' is-warning' : '') + '">'
                    + '<div class="mxp-cat__head"><strong>' + esc(cat.category_name) + '</strong>' + tag + '</div>'
                    + '<div class="mxp-cat__value">' + esc(money(cat.revenue)) + '</div>'
                    + '<div class="mxp-cat__sub">' + esc(pct(share(cat.revenue, total)) + ' revenue · ' + plural(cat.units, 'unit', 'units') + ' sold') + '</div>'
                    + '<div class="mxp-cat__track"><span class="is-revenue" style="width:' + Math.min(100, share(cat.revenue, total)) + '%"></span>'
                    + '<span class="is-units" style="width:' + Math.min(100, share(cat.units, data.totals.units)) + '%"></span></div>'
                    + '<div class="mxp-cat__foot">'
                    + (cat.margin !== null
                        ? '<span>Gross Margin: <strong>' + esc(pct(cat.margin)) + '</strong></span>'
                        : '<span title="Order lines carry no product cost, so margin cannot be computed">' + esc(plural(cat.products, 'product', 'products') + ' · ' + plural(cat.orders, 'order', 'orders')) + '</span>')
                    + (cat.low_stock > 0 ? '<span class="mxd-text-red">' + esc(num(cat.low_stock) + ' low stock') + '</span>' : '')
                    + '</div></div>';
            }).join('') : '<p class="mxd-empty">No category sales in this period.</p>');
        }

        // ── Product table ───────────────────────────────────────────────────

        function tabRows() {
            var rows;

            if (table.tab === 'dead') {
                return data.dead.rows;
            }
            if (table.tab === 'worst') {
                return data.worst;
            }
            rows = data.products.slice();
            if (table.tab === 'qty') {
                rows.sort(function (a, b) {
                    return b.qty_sold - a.qty_sold || b.revenue - a.revenue;
                });
            }
            return rows;
        }

        function filteredRows() {
            var query = table.query.toLowerCase();

            return tabRows().filter(function (row) {
                var level = stockLevel(row);

                return (!table.category || row.category === table.category)
                    && (!table.stock || (table.stock === 'restock' ? row.velocity === 'restock' : level === table.stock))
                    && (!query || (row.product_name + ' ' + row.sku + ' ' + row.category).toLowerCase().indexOf(query) !== -1);
            });
        }

        function renderCategoryFilter() {
            var $select = $el('category'),
                names = {};

            tabRows().forEach(function (row) {
                if (row.category) {
                    names[row.category] = true;
                }
            });
            $select.find('option:not(:first)').remove();
            Object.keys(names).sort().forEach(function (name) {
                $select.append($('<option>').val(name).text(name));
            });
            if (!names[table.category]) {
                table.category = '';
            }
            $select.val(table.category);
        }

        function inventoryCell(row) {
            var level = stockLevel(row);

            if (level === 'untracked') {
                return '<span class="mxp-stock mxp-stock--none">Not tracked</span>';
            }
            if (level === 'out') {
                return '<span class="mxp-stock mxp-stock--out">Out of stock</span>';
            }
            return '<span class="mxp-stock mxp-stock--' + level + '">' + esc(num(row.stock_qty)) + ' in stock</span>';
        }

        function velocityCell(row) {
            var v = VELOCITY[row.velocity] || VELOCITY.low,
                cover = row.days_of_cover === null || row.days_of_cover === undefined ? null
                    : (row.days_of_cover > 365 ? '365+ days' : num(row.days_of_cover) + ' days');

            return '<span class="mxp-velocity mxp-velocity--' + v.tone + '" title="' + esc(cover ? 'Stock cover at this pace: ' + cover
                    : 'Stock not tracked') + '">' + esc(v.label) + (v.stars ? ' <i>' + v.stars + '</i>' : '')
                + (v.icon ? ' ' + icon(v.icon, 'xs') : '') + '</span>';
        }

        function productCell(row) {
            var url = productUrl(row);

            return '<div class="mxp-prod">' + (url
                ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(row.product_name) + '</a>'
                : '<strong>' + esc(row.product_name) + '</strong>')
                + '<span class="mxp-prod__sku">' + esc(row.sku) + '</span></div>';
        }

        function actionsCell(row) {
            var url = productUrl(row);

            return '<div class="mxc-rowactions">'
                + (url ? '<a class="mxc-iconbtn" href="' + esc(url) + '" target="_blank" rel="noopener" title="View the product in Magento">'
                    + icon('visibility') + '</a>' : '')
                + '<button type="button" class="mxc-iconbtn mxp-ask-row" data-sku="' + esc(row.sku) + '" title="'
                + esc(row.velocity === 'restock' ? 'Ask the Copilot how much to reorder' : 'Ask the Copilot about this product') + '">'
                + icon(row.velocity === 'restock' ? 'add_shopping_cart' : 'auto_awesome') + '</button></div>';
        }

        function renderTable() {
            var dead = table.tab === 'dead',
                rows = filteredRows(),
                pages = Math.max(1, Math.ceil(rows.length / table.perPage)),
                first,
                visible,
                buttons = [],
                total = dead ? data.dead.count : (table.tab === 'worst' ? data.worst.length : data.product_count),
                listed = tabRows().length;

            table.page = Math.min(Math.max(1, table.page), pages);
            first = (table.page - 1) * table.perPage;
            visible = rows.slice(first, first + table.perPage);

            $el('thead').html(dead
                ? '<tr><th class="mxc-c">#</th><th>Product &amp; SKU</th><th>Category</th><th>Type</th><th>Created</th>'
                    + '<th class="mxc-r">List Price</th><th class="mxc-c">Inventory Status</th><th class="mxc-r">Stock Value</th>'
                    + '<th class="mxc-c">Actions</th></tr>'
                : '<tr><th class="mxc-c">#</th><th>Product &amp; SKU</th><th>Category</th><th>Type</th><th class="mxc-r">Qty Sold</th>'
                    + '<th class="mxc-r">Net Revenue</th><th class="mxc-r">Avg Realized Price</th><th class="mxc-r">Orders</th>'
                    + '<th class="mxc-c">Inventory Status</th><th>Velocity Rating</th><th class="mxc-c">Actions</th></tr>');

            $el('tbody').html(visible.length ? visible.map(function (row, i) {
                var cells = '<td class="mxc-c mxd-mono mxd-muted">' + (first + i + 1) + '</td>'
                    + '<td>' + productCell(row) + '</td>'
                    + '<td class="mxp-muted">' + esc(row.category || '—') + '</td>'
                    + '<td><span class="mxc-acct">' + esc(typeLabel(row.product_type).replace(' Products', '')) + '</span></td>';

                if (dead) {
                    cells += '<td class="mxd-mono">' + esc(String(row.created_at || '').substr(0, 10)) + '</td>'
                        + '<td class="mxc-r mxd-mono">' + esc(money(row.price)) + '</td>'
                        + '<td class="mxc-c">' + inventoryCell(row) + '</td>'
                        + '<td class="mxc-r mxd-mono"><strong class="mxd-text-red">' + esc(money(row.stock_value)) + '</strong></td>';
                } else {
                    cells += '<td class="mxc-r mxd-mono"><strong>' + esc(num(row.qty_sold)) + '</strong></td>'
                        + '<td class="mxc-r mxd-mono"><strong class="mxd-text-brand">' + esc(money(row.revenue)) + '</strong></td>'
                        + '<td class="mxc-r mxd-mono">' + esc(money(row.avg_price)) + '</td>'
                        + '<td class="mxc-r mxd-mono">' + esc(num(row.order_count)) + '</td>'
                        + '<td class="mxc-c">' + inventoryCell(row) + '</td>'
                        + '<td>' + velocityCell(row) + '</td>';
                }
                return '<tr' + (row.velocity === 'restock' || (dead && row.stock_value > 0) ? ' class="is-risk"' : '') + '>'
                    + cells + '<td class="mxc-c">' + actionsCell(row) + '</td></tr>';
            }).join('') : '<tr><td colspan="11" class="mxd-empty">' + esc(!listed
                ? (dead ? 'Every sellable product sold in this period.' : 'No products sold in this period.')
                : (table.stock === 'restock' && !table.query && !table.category
                    ? 'No product needs a restock: every product that sold has at least ' + config.coverDays
                        + ' days of stock at its current pace (or does not track stock).'
                    : 'No products match the filters.')) + '</td></tr>');

            $el('pager-info').html('Showing <strong>' + (rows.length ? first + 1 : 0) + ' – ' + (first + visible.length)
                + '</strong> of <strong>' + esc(num(rows.length)) + '</strong> ' + esc(TAB_NOUNS[table.tab])
                + (total > listed ? esc(' (top ' + num(listed) + ' of ' + num(total) + ' listed — the export includes all)') : ''));

            buttons.push('<button type="button" class="mxc-pagebtn" data-page="' + (table.page - 1) + '"'
                + (table.page <= 1 ? ' disabled' : '') + '>Previous</button>');
            pageWindow(table.page, pages).forEach(function (page) {
                buttons.push('<button type="button" class="mxc-pagebtn' + (page === table.page ? ' is-active' : '')
                    + '" data-page="' + page + '">' + page + '</button>');
            });
            buttons.push('<button type="button" class="mxc-pagebtn" data-page="' + (table.page + 1) + '"'
                + (table.page >= pages ? ' disabled' : '') + '>Next</button>');
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

        function renderTabs() {
            $el('tabs').find('[data-tab]').each(function () {
                var active = $(this).data('tab') === table.tab;

                $(this).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
            });
            $el('tabs').find('[data-count="revenue"], [data-count="qty"]').text(num(data.product_count));
            $el('tabs').find('[data-count="worst"]').text(num(data.worst.length));
            $el('tabs').find('[data-count="dead"]').text(num(data.dead.count));
            $el('promo').prop('hidden', table.tab !== 'dead');
        }

        function setTab(tab, filters) {
            table.tab = tab;
            table.page = 1;
            if (filters) {
                table.stock = filters.stock || '';
                table.query = '';
                table.category = '';
                $el('search').val('');
                $el('stock').val(table.stock);
            }
            renderTabs();
            renderCategoryFilter();
            renderTable();
        }

        function scrollToTable() {
            $el('table-card')[0].scrollIntoView({behavior: 'smooth', block: 'start'});
        }

        // ── Copilot summary ─────────────────────────────────────────────────

        function renderSummary() {
            var t = data.totals,
                rows = data.products,
                cats = data.categories,
                top3 = rows.slice(0, 3).reduce(function (sum, row) {
                    return sum + row.revenue;
                }, 0),
                restock = rows.filter(function (row) {
                    return row.velocity === 'restock';
                })[0],
                parts = [];

            if (!rows.length) {
                parts.push('No products sold in ' + esc(data.range.label) + '.');
            } else {
                parts.push('Catalog sales are led by the <strong class="mxd-text-brand">' + esc(cats.slice(0, 2).map(function (c) {
                    return c.category_name;
                }).join(' & ') || 'top') + '</strong> categories, and the top 3 SKUs account for <strong class="mxd-text-brand">'
                    + esc(pct(share(top3, t.revenue))) + '</strong> of net revenue.');
                parts.push(restock
                    ? '<strong class="mxd-text-red">Warning:</strong> ' + esc(restock.product_name) + ' (<code>' + esc(restock.sku) + '</code>) '
                        + (restock.stock_qty <= 0 ? 'is out of stock' : 'has ' + esc(plural(restock.stock_qty, 'unit', 'units')) + ' left — about '
                            + esc(num(restock.days_of_cover)) + ' days of cover at this pace') + '.'
                    : 'No product that sold is under ' + config.coverDays + ' days of stock cover.');
            }
            if (data.dead.count) {
                parts.push('<strong class="mxd-text-red">' + esc(plural(data.dead.count, 'sellable SKU', 'sellable SKUs')) + '</strong> had no sale in '
                    + esc(data.range.label) + ', holding <strong>' + esc(money(data.dead.stock_value)) + '</strong> of stock at list price.');
            }

            $el('insight').html(icon('trending_up', 'md') + '<p>' + parts.join(' ') + '</p>');
            $el('act-dead-label').text('Plan Promotion for ' + plural(data.dead.count, 'Dead-Stock SKU', 'Dead-Stock SKUs'));
            $el('act-dead').prop('disabled', !data.dead.count);
            $el('runtime').text('Runtime: ' + num(data.query_ms) + 'ms');
            $el('sql-text').text(data.sql);
        }

        function copySql() {
            var done = function () {
                $el('sql-copy-label').text('Copied');
                setTimeout(function () {
                    $el('sql-copy-label').text('Copy Raw SQL');
                }, 1500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(data.sql).then(done, function () {
                    window.prompt('Copy the SQL:', data.sql);
                });
                return;
            }
            // Plain-HTTP admin: no async clipboard API
            var $area = $('<textarea readonly>').val(data.sql).css({position: 'fixed', top: '-1000px'}).appendTo('body');

            $area[0].select();
            try {
                document.execCommand('copy');
                done();
            } catch (e) {
                window.prompt('Copy the SQL:', data.sql);
            }
            $area.remove();
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
            $el('summary')[0].scrollIntoView({behavior: 'smooth', block: 'center'});
            if (focus) {
                $el('ask-input').trigger('focus');
            }
        }

        function ask(question) {
            var $answer = $el('answer'),
                $submit = $el('ask-submit');

            question = $.trim(question || '');
            if (!question || asking) {
                return;
            }
            openAsk(false);
            if (!config.aiEnabled || !config.aiConfigured) {
                $answer.prop('hidden', false).html('<p class="mxp-answer__error">The AI provider is not configured. '
                    + 'Set it up in Stores > Configuration > Meetanshi > AI Reporting.</p>');
                return;
            }

            asking = true;
            $el('ask-input').val(question);
            $submit.prop('disabled', true);
            $answer.prop('hidden', false).html('<p class="mxp-answer__q">' + icon('person', 'xs') + ' ' + esc(question)
                + '</p><p class="mxp-answer__loading">' + icon('progress_activity', 'sm') + ' Thinking… local models can take a minute.</p>');

            $.ajax({
                url: config.chatUrl,
                type: 'POST',
                dataType: 'json',
                data: {message: question, form_key: config.formKey, isAjax: true},
                timeout: 300000
            }).done(function (response) {
                var html = '<p class="mxp-answer__q">' + icon('person', 'xs') + ' ' + esc(question) + '</p>';

                if (!response || !response.success) {
                    html += '<p class="mxp-answer__error">' + esc((response && response.message) || 'Something went wrong.') + '</p>';
                } else {
                    html += '<div class="mxp-answer__text">' + formatAnswer(response.answer) + '</div>';
                    if (response.assumption) {
                        html += '<p class="mxp-answer__assumption">Interpreted as: ' + esc(response.assumption) + '</p>';
                    }
                    if (response.sql) {
                        html += '<div class="mxp-answer__meta"><span>' + esc(num(response.rows) + ' rows · ' + num(response.time_ms) + ' ms')
                            + '</span><button type="button" class="mxc-linkbtn" data-role="toggle-sql">Show SQL</button>'
                            + '<a class="mxc-linkbtn" href="' + esc(config.askAiUrl) + '">Open Ask AI</a></div>'
                            + '<pre class="mxp-answer__sql" hidden>' + esc(response.sql) + '</pre>';
                    }
                }
                $answer.html(html);
            }).fail(function (xhr, status) {
                $answer.html('<p class="mxp-answer__error">' + (status === 'timeout'
                    ? 'The AI took too long to answer. Try a simpler question.'
                    : 'Request failed (' + esc(xhr.status) + '). Please try again.') + '</p>');
            }).always(function () {
                asking = false;
                $submit.prop('disabled', false);
            });
        }

        // ── Data loading ────────────────────────────────────────────────────

        function setActiveRange(key) {
            $el('range').find('[data-range]').each(function () {
                $(this).toggleClass('is-active', $(this).data('range') === key);
            });
        }

        function load(params) {
            if (loading) {
                return;
            }
            loading = true;
            $root.addClass('is-loading');
            $el('error').prop('hidden', true);

            $.ajax({url: config.dataUrl, type: 'GET', dataType: 'json', data: params}).done(function (response) {
                if (response && response.success) {
                    data = response.data;
                    table.page = 1;
                    renderAll();
                } else {
                    $el('error').text((response && response.message) || 'Could not load the report data.').prop('hidden', false);
                }
            }).fail(function (xhr) {
                $el('error').text('Could not load the report data (HTTP ' + xhr.status + ').').prop('hidden', false);
            }).always(function () {
                loading = false;
                $root.removeClass('is-loading');
                setActiveRange(data.range.key);
            });
        }

        function renderAll() {
            $el('range-label').text(data.range.label);
            setActiveRange(data.range.key);
            $el('export-all').attr('href', exportUrl($.extend({tab: 'revenue'}, rangeParams())));
            renderKpis();
            renderTicker();
            renderTop10();
            renderTypes();
            renderBands();
            renderCategories();
            renderTabs();
            renderCategoryFilter();
            renderTable();
            renderSummary();
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

        $el('top-mode').on('click', 'button', function () {
            topMode = $(this).data('mode');
            $(this).addClass('is-active').siblings().removeClass('is-active');
            renderTop10();
        });

        $el('view-all').on('click', function () {
            setTab('revenue', {});
            scrollToTable();
        });

        $el('tabs').on('click', '[data-tab]', function () {
            setTab($(this).data('tab'));
        });

        $el('search').on('input', function () {
            table.query = $.trim($(this).val());
            table.page = 1;
            renderTable();
        });

        $el('category').on('change', function () {
            table.category = $(this).val();
            table.page = 1;
            renderTable();
        });

        $el('stock').on('change', function () {
            table.stock = $(this).val();
            table.page = 1;
            renderTable();
        });

        $el('per-page').on('change', function () {
            table.perPage = Number($(this).val()) || 10;
            table.page = 1;
            renderTable();
        });

        $el('pager').on('click', '[data-page]', function () {
            table.page = Number($(this).data('page'));
            renderTable();
        });

        $el('table-export').on('click', function () {
            var params = $.extend({tab: table.tab}, rangeParams());

            if (table.query) {
                params.q = table.query;
            }
            if (table.category) {
                params.category = table.category;
            }
            if (table.stock) {
                params.stock = table.stock;
            }
            window.location.href = exportUrl(params);
        });

        $el('tbody').on('click', '.mxp-ask-row', function () {
            var sku = String($(this).data('sku')),
                row = tabRows().filter(function (r) {
                    return r.sku === sku;
                })[0] || {};

            ask(row.velocity === 'restock'
                ? 'How many units of SKU ' + sku + ' were sold per week over the last 8 weeks, and how many should I reorder for 30 days of stock?'
                : 'How many units of SKU ' + sku + ' were sold each month this year, and what was its net revenue?');
        });

        $el('act-restock').on('click', function () {
            setTab('revenue', {stock: 'restock'});
            scrollToTable();
        });

        $el('act-dead').on('click', function () {
            setTab('dead', {});
            scrollToTable();
        });

        $el('act-price').on('click', function () {
            ask('Compare units sold and net revenue by product price band (under $25, $25-$50, $50-$100, $100 and above) for '
                + data.range.label.replace(/^[^(]*\(|\)$/g, '') + ' versus the previous period of the same length.');
        });

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

        $root.on('click', '.mxp-chip', function () {
            ask($(this).data('question'));
        });

        $el('answer').on('click', '[data-role="toggle-sql"]', function () {
            var $sql = $el('answer').find('.mxp-answer__sql'),
                show = $sql.prop('hidden');

            $sql.prop('hidden', !show);
            $(this).text(show ? 'Hide SQL' : 'Show SQL');
        });

        $el('sql-copy').on('click', copySql);

        $el('print').on('click', function () {
            window.print();
        });

        window.addEventListener('beforeprint', function () {
            Object.keys(charts).forEach(function (name) {
                charts[name].resize();
            });
        });

        renderAll();
    };
});
