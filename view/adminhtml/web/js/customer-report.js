/**
 * Meetanshi AIReporting — Customer Intelligence & Retention Analytics
 *
 * Renders every section from the view model's data: the period part (KPI cards, ticker,
 * acquisition chart) reloads from reports/customerData when the range changes; the all-time part
 * (CLV tiers, RFM matrix, velocity, top customers, copilot insight) comes with the page. Also wires
 * the tables (segment filter, search, paging), the CSV exports, print-to-PDF and AI follow-ups
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
        PAGE_SIZE = 10,
        RFM_ROWS = 50,
        TIER_COLORS = [BRAND, NAVY, '#f4a27f', '#ffd5c2', '#cbd5e1'],
        SEGMENT_GROUPS = [
            {key: 'champions', label: 'Champions', members: ['Champions'], tone: 'brand'},
            {key: 'loyal', label: 'Loyal', members: ['Loyal'], tone: 'navy'},
            {key: 'promising', label: 'Promising / New', members: ['Promising', 'New Customer'], tone: 'amber'},
            {key: 'risk', label: 'At Risk / Lost', members: ['At Risk', 'Lost'], tone: 'red'}
        ],
        SEGMENT_TONES = {
            'Champions': 'brand',
            'Loyal': 'navy',
            'Promising': 'amber',
            'New Customer': 'grey',
            'At Risk': 'red',
            'Lost': 'red'
        },
        UNIT_WORDS = {hour: 'Hourly', day: 'Daily', month: 'Monthly'};

    return function (config, element) {
        var $root = $(element),
            period = config.period,
            life = config.lifetime,
            charts = {},
            tierMode = 'buyers',
            rfmGroup = '',
            loading = false,
            table = {query: '', segment: '', page: 1},
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

        /** "Sep 28, 2026" from "2026-09-28 14:03:00" (store-local, no timezone shift). */
        function longDate(dateTime) {
            var parts = String(dateTime || '').substr(0, 10).split('-');

            return parts.length === 3
                ? new Date(+parts[0], parts[1] - 1, +parts[2]).toLocaleDateString(undefined, {
                    month: 'short', day: 'numeric', year: 'numeric'
                })
                : '—';
        }

        function daysAgo(days) {
            days = Number(days) || 0;

            return days === 0 ? 'Today' : (days === 1 ? '1 day ago' : num(days) + ' days ago');
        }

        function initials(name, email) {
            var source = $.trim(name || '') || $.trim(email || ''),
                parts = source.split(/[\s@._-]+/).filter(Boolean);

            if (!parts.length) {
                return '?';
            }
            return (parts.length > 1 ? parts[0].charAt(0) + parts[1].charAt(0) : parts[0].substr(0, 2)).toUpperCase();
        }

        function displayName(buyer) {
            return $.trim(buyer.customer_name || '') || buyer.customer_email;
        }

        function isLapsed(days) {
            return Number(days) > life.lapsed_after_days;
        }

        function tierRange(tier) {
            if (tier.max === null) {
                return moneyWhole.format(tier.min) + '+';
            }
            if (tier.min === null) {
                return 'Under ' + moneyWhole.format(tier.max);
            }
            return moneyWhole.format(tier.min) + '–' + moneyWhole.format(tier.max - 1);
        }

        function exportUrl(params) {
            var query = $.param(params || {});

            return config.exportUrl + (query ? (config.exportUrl.indexOf('?') === -1 ? '?' : '&') + query : '');
        }

        function customerUrl(buyer) {
            return buyer.customer_id ? config.customerUrl.replace('__ID__', buyer.customer_id) : '';
        }

        function destroyChart(name) {
            if (charts[name]) {
                charts[name].destroy();
                delete charts[name];
            }
        }

        function badge(text, tone, title) {
            return '<span class="mxc-badge mxc-badge--' + (tone || 'brand') + '"'
                + (title ? ' title="' + esc(title) + '"' : '') + '>' + esc(text) + '</span>';
        }

        function avatar(buyer) {
            return '<span class="mxc-avatar mxc-avatar--' + (SEGMENT_TONES[buyer.segment] || 'grey') + '">'
                + esc(initials(buyer.customer_name, buyer.customer_email)) + '</span>';
        }

        function profile(buyer) {
            var url = customerUrl(buyer);

            return '<div class="mxc-profile">' + avatar(buyer) + '<span class="mxc-profile__text">'
                + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(displayName(buyer)) + '</a>'
                    : '<strong>' + esc(displayName(buyer)) + '</strong>')
                + '<span class="mxc-profile__email">' + esc(buyer.customer_email) + '</span></span></div>';
        }

        function segmentBadge(buyer, withDays) {
            var text = buyer.segment;

            if (withDays && (buyer.segment === 'At Risk' || buyer.segment === 'Lost')) {
                text += ' (' + num(buyer.recency_days) + 'd)';
            }
            return badge(text, SEGMENT_TONES[buyer.segment] || 'grey');
        }

        // ── KPI cards and ticker (selected range) ───────────────────────────

        function kpiCard(options) {
            return '<div class="mxc-kpi">'
                + '<div class="mxc-kpi__head"><span class="mxc-kpi__label">' + esc(options.label) + '</span>'
                + '<span class="mxc-kpi__icon mxc-kpi__icon--' + (options.tone || 'brand') + '">' + icon(options.icon, 'md') + '</span></div>'
                + '<div class="mxc-kpi__value"><span class="mxc-metric">' + esc(options.value) + '</span>'
                + (options.unit ? '<span class="mxc-kpi__unit">' + esc(options.unit) + '</span>' : '')
                + (options.badge || '') + '</div>'
                + '<div class="mxc-kpi__foot">' + icon(options.footIcon, 'sm') + '<span>' + esc(options.foot) + '</span></div></div>';
        }

        function changeBadge(change, buyers) {
            if (change === null || change === undefined) {
                return buyers > 0 ? badge('New', 'brand', 'No buyers in the previous period of the same length') : '';
            }
            return badge((change > 0 ? '+' : '') + pct(change) + ' vs prev.', change < 0 ? 'amber' : 'brand',
                num(period.summary.previous_buyers) + ' buyers in the previous period of the same length');
        }

        function renderKpis() {
            var s = period.summary,
                top = s.top_buyer;

            $el('kpis').html([
                kpiCard({
                    label: 'Total Customers', icon: 'groups',
                    value: num(s.buyers), unit: s.buyers === 1 ? 'buyer' : 'buyers',
                    badge: changeBadge(s.buyers_change, s.buyers),
                    footIcon: 'check_circle',
                    foot: num(s.registered_buyers) + ' registered · ' + plural(s.guest_buyers, 'guest buyer', 'guest buyers')
                }),
                kpiCard({
                    label: 'New Customers', icon: 'person_add', tone: 'slate',
                    value: num(s.new_registrations), unit: 'registered',
                    badge: s.new_registrations > 0
                        ? badge(pct(s.registration_conversion) + ' ordered', 'brand',
                            num(s.registrations_ordered) + ' of the new accounts placed an order in this period')
                        : '',
                    footIcon: 'calendar_month',
                    foot: plural(s.first_time_buyers, 'first-time buyer', 'first-time buyers') + ' in this period'
                }),
                kpiCard({
                    label: 'Repeat Purchase Rate', icon: 'repeat',
                    value: pct(s.repeat_rate),
                    badge: s.buyers > 0
                        ? badge(pct(s.multi_order_rate) + ' multi-order', 'brand',
                            num(s.multi_order_buyers) + ' buyers ordered more than once within this period')
                        : '',
                    footIcon: 'loyalty',
                    foot: num(s.repeat_buyers) + ' of ' + plural(s.buyers, 'buyer has', 'buyers have') + ' 2+ orders to date'
                }),
                kpiCard({
                    label: 'Avg Customer Lifetime Value', icon: 'payments',
                    value: money(s.avg_ltv),
                    badge: top ? badge('Top: ' + money(top.value), 'brand') : '',
                    footIcon: 'star',
                    foot: top ? ($.trim(top.name) || top.email) + ' — highest spender' : 'No buyers in this period'
                })
            ].join(''));
        }

        function renderTicker() {
            var s = period.summary,
                churn = share(life.lapsed_buyers, life.totals.buyer_count),
                risk = churn <= 25 ? ['Low Risk', 'brand'] : (churn <= 50 ? ['Moderate', 'amber'] : ['High Risk', 'red']);

            $el('ticker').html(
                '<div><span class="mxc-ticker__label">Customer Spend:</span><span class="mxd-mono mxd-text-brand"><strong>'
                + esc(money(s.spend)) + '</strong></span></div>'
                + '<i class="mxc-ticker__sep"></i>'
                + '<div title="Share of all buyers with no order in the last ' + esc(life.lapsed_after_days) + ' days">'
                + '<span class="mxc-ticker__label">Churn Risk Index:</span>'
                + badge(pct(churn) + ' ' + risk[0], risk[1]) + '</div>'
                + '<i class="mxc-ticker__sep"></i>'
                + '<div><span class="mxc-ticker__label">Avg Orders per Buyer:</span><span class="mxd-mono"><strong>'
                + esc(num(s.avg_orders_per_buyer)) + ' orders</strong></span></div>'
            );
            $el('latency').text('SQL exec: ' + num(period.query_ms) + 'ms period · ' + num(life.query_ms)
                + 'ms all-time · customer_entity + sales_order');
        }

        // ── Acquisition chart (selected range) ──────────────────────────────

        function renderAcquisition() {
            var acq = period.acquisition,
                series = acq.series,
                s = period.summary,
                peak = null,
                peakIndex = -1,
                previous,
                change,
                callout,
                canvas = $el('acq-chart')[0],
                rangeName = period.range.key === 'custom' ? 'Custom Range'
                    : $.trim($el('range').find('[data-range="' + period.range.key + '"]').text().replace(/\s*\(\d{4}\)$/, ''));

            $el('acq-title').text('Customer Acquisition & Retention Dynamics (' + rangeName + ')');
            $el('acq-sub').text(UNIT_WORDS[acq.unit] + ' breakdown of first-time vs returning buyers · ' + period.range.label);

            series.forEach(function (row, i) {
                if (row.returning_customers > 0 && (peak === null || row.returning_customers > peak.returning_customers)) {
                    peak = row;
                    peakIndex = i;
                }
            });

            if (!s.buyers) {
                callout = '<div>' + icon('info', 'md') + '<span>No orders were placed in this period.</span></div>';
            } else if (peak) {
                previous = peakIndex > 0 ? series[peakIndex - 1] : null;
                change = previous && previous.returning_customers > 0
                    ? share(peak.returning_customers - previous.returning_customers, previous.returning_customers) : null;

                callout = '<div>' + icon('trending_up', 'md') + '<span>Returning buyers peaked in <strong>' + esc(peak.label)
                    + '</strong> at <strong>' + esc(num(peak.returning_customers)) + '</strong>'
                    + (change !== null
                        ? ' (<strong>' + esc((change > 0 ? '+' : '') + pct(change)) + '</strong> vs ' + esc(previous.label) + ')'
                        : '')
                    + '.</span></div>';
            } else {
                callout = '<div>' + icon('person_add', 'md') + '<span>Every buyer in this period was a <strong>first-time</strong> buyer.</span></div>';
            }
            $el('acq-callout').html(callout + '<span class="mxd-mono mxd-text-brand" title="Buyers whose first order was before this period">'
                + 'Returning: ' + esc(pct(s.returning_rate)) + '</span>');

            destroyChart('acq');
            charts.acq = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: series.map(function (row) { return row.label; }),
                    datasets: [
                        {
                            label: 'New Customers',
                            data: series.map(function (row) { return row.new_customers; }),
                            backgroundColor: BRAND,
                            borderRadius: 3,
                            maxBarThickness: 18
                        },
                        {
                            label: 'Returning Customers',
                            data: series.map(function (row) { return row.returning_customers; }),
                            backgroundColor: NAVY,
                            borderRadius: 3,
                            maxBarThickness: 18
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
                                    return ' ' + item.dataset.label + ': ' + num(item.raw);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {display: false},
                            ticks: {
                                color: '#594139',
                                font: {family: 'JetBrains Mono', size: 10},
                                maxRotation: 0,
                                autoSkip: true,
                                autoSkipPadding: 8
                            }
                        },
                        y: {
                            beginAtZero: true,
                            border: {display: false},
                            grid: {color: '#e5eeff'},
                            ticks: {color: '#586377', font: {family: 'JetBrains Mono', size: 10}, precision: 0}
                        }
                    }
                }
            });
        }

        // ── CLV tiers (all-time) ────────────────────────────────────────────

        function renderTiers() {
            var tiers = life.tiers,
                totalBuyers = tiers.reduce(function (sum, t) { return sum + t.buyers; }, 0),
                totalRevenue = tiers.reduce(function (sum, t) { return sum + t.revenue; }, 0),
                values = tiers.map(function (t) { return tierMode === 'revenue' ? Math.max(0, t.revenue) : t.buyers; }),
                hasData = values.some(function (v) { return v > 0; });

            $el('avg-ltv').text(moneyWhole.format(life.totals.avg_ltv || 0));
            $el('tiers').html(tiers.map(function (tier, i) {
                var buyerShare = share(tier.buyers, totalBuyers),
                    revenueShare = share(tier.revenue, totalRevenue);

                return '<div class="mxc-tier' + (tier.buyers ? '' : ' is-empty') + '">'
                    + '<div class="mxc-tier__name"><i class="mxc-dot" style="background:' + TIER_COLORS[i] + '"></i><div>'
                    + '<strong>' + esc(tierRange(tier)) + '</strong> <span class="mxd-muted">(Tier ' + tier.tier + ' ' + esc(tier.name) + ')</span>'
                    + '<small>' + esc(plural(tier.buyers, 'buyer', 'buyers') + ' · ' + pct(buyerShare) + ' share') + '</small></div></div>'
                    + '<div class="mxc-tier__nums"><strong class="mxd-mono">' + esc(money(tier.revenue)) + '</strong>'
                    + '<small class="' + (i === 0 && tier.buyers ? 'mxd-text-brand' : '') + '">' + esc(pct(revenueShare)) + ' of revenue</small></div>'
                    + '</div>';
            }).join(''));

            destroyChart('tiers');
            charts.tiers = new Chart($el('tier-chart')[0], {
                type: 'doughnut',
                data: {
                    labels: tiers.map(function (t) { return 'Tier ' + t.tier + ' ' + t.name + ' (' + tierRange(t) + ')'; }),
                    datasets: [{
                        data: hasData ? values : [1],
                        backgroundColor: hasData ? TIER_COLORS : ['#e5eeff'],
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
                                    return ' ' + (tierMode === 'revenue' ? money(item.raw) : plural(item.raw, 'buyer', 'buyers'));
                                }
                            }
                        }
                    }
                }
            });
        }

        // ── RFM matrix (all-time) ───────────────────────────────────────────

        function segmentStats(group) {
            return life.segments.reduce(function (stats, segment) {
                if (group.members.indexOf(segment.segment) !== -1) {
                    stats.buyers += segment.buyers;
                    stats.revenue += segment.revenue;
                }
                return stats;
            }, {buyers: 0, revenue: 0});
        }

        function activeGroup() {
            return SEGMENT_GROUPS.filter(function (group) { return group.key === rfmGroup; })[0] || null;
        }

        function renderSegments() {
            var total = life.segments.reduce(function (sum, s) { return sum + s.buyers; }, 0);

            $el('segments').html(SEGMENT_GROUPS.map(function (group) {
                var stats = segmentStats(group);

                return '<button type="button" class="mxc-segpill mxc-segpill--' + group.tone
                    + (group.key === rfmGroup ? ' is-active' : '') + '" data-group="' + group.key + '" aria-pressed="'
                    + (group.key === rfmGroup) + '">'
                    + '<span class="mxc-segpill__label">' + esc(group.label) + '</span>'
                    + '<span class="mxc-segpill__count">' + esc(plural(stats.buyers, 'buyer', 'buyers')) + ' (' + esc(pct(share(stats.buyers, total))) + ')</span>'
                    + '<span class="mxc-segpill__rev">' + esc(money(stats.revenue)) + '</span></button>';
            }).join(''));
        }

        function rfmChip(score, kind) {
            var tone = score >= 4 ? 'hi' : (kind === 'r' && score <= 2 ? 'risk' : 'lo');

            return '<span class="mxc-score mxc-score--' + tone + '" title="' + kind.toUpperCase() + ' score ' + score + ' of 5">' + score + '</span>';
        }

        function rowActions(buyer) {
            var url = customerUrl(buyer);

            return '<div class="mxc-rowactions">'
                + (url
                    ? '<a class="mxc-iconbtn" href="' + esc(url) + '" target="_blank" rel="noopener" title="View Magento customer">' + icon('visibility') + '</a>'
                    : '<span class="mxc-iconbtn is-disabled" title="Guest checkout — no customer account">' + icon('visibility_off') + '</span>')
                + '<a class="mxc-iconbtn" href="mailto:' + esc(encodeURIComponent(buyer.customer_email).replace(/%40/g, '@'))
                + '" title="Email ' + esc(buyer.customer_email) + '">' + icon('mail') + '</a></div>';
        }

        function rfmBuyers() {
            var group = activeGroup();

            return life.buyers.filter(function (buyer) {
                return !group || group.members.indexOf(buyer.segment) !== -1;
            });
        }

        function renderRfmRows() {
            var group = activeGroup(),
                rows = rfmBuyers(),
                visible = rows.slice(0, RFM_ROWS),
                inSegment = group ? segmentStats(group).buyers : life.totals.buyer_count,
                note;

            $el('rfm-rows').html(visible.length ? visible.map(function (buyer) {
                var lapsed = isLapsed(buyer.recency_days);

                return '<tr' + (lapsed ? ' class="is-risk"' : '') + '>'
                    + '<td>' + profile(buyer) + '</td>'
                    + '<td class="mxc-nowrap' + (lapsed ? ' mxd-text-red' : '') + '">' + esc(daysAgo(buyer.recency_days)) + '</td>'
                    + '<td class="mxc-r mxd-mono"><strong>' + esc(num(buyer.orders)) + '</strong></td>'
                    + '<td class="mxc-r mxd-mono"><strong>' + esc(money(buyer.lifetime_value)) + '</strong></td>'
                    + '<td class="mxc-c"><div class="mxc-scores">' + rfmChip(buyer.r_score, 'r') + rfmChip(buyer.f_score, 'f')
                    + rfmChip(buyer.m_score, 'm') + '</div></td>'
                    + '<td>' + segmentBadge(buyer, false) + '</td>'
                    + '<td class="mxc-c">' + rowActions(buyer) + '</td></tr>';
            }).join('') : '<tr><td colspan="7" class="mxd-empty">'
                + (life.buyers.length ? 'No buyers in this segment among the top ' + num(life.buyers_limit) + '.' : 'No buyers yet.')
                + '</td></tr>');

            note = 'Showing ' + num(visible.length) + ' of ' + plural(inSegment, 'buyer', 'buyers')
                + (group ? ' in ' + group.label : '') + ', by lifetime value.';
            if (inSegment > visible.length) {
                note += ' The export includes every buyer.';
            }
            $el('rfm-note').text(note);
        }

        // ── Repeat & churn velocity (all-time) ──────────────────────────────

        function renderVelocity() {
            var repeat = life.repeat,
                cycle = Number(life.reorder_cycle_days) || 0,
                vip = life.vip,
                w = life.winback,
                html,
                usual,
                remindDay = cycle > 0 ? Math.max(1, Math.round(cycle * 0.8)) : 0;

            html = '<div class="mxc-vblock">'
                + '<div class="mxc-vblock__row"><span class="mxc-vblock__title">Repeat Buyer Proportion</span>'
                + '<span class="mxc-vblock__big mxd-text-brand">' + esc(pct(repeat.repeat_rate)) + '</span></div>'
                + '<div class="mxc-progress"><span style="width:' + Math.min(100, Number(repeat.repeat_rate) || 0) + '%"></span></div>'
                + '<div class="mxc-vblock__row mxc-vblock__row--small"><span>' + esc(plural(repeat.repeat_customers, 'repeat purchaser', 'repeat purchasers'))
                + '</span><span>' + esc(plural(repeat.one_time_customers, 'one-time buyer', 'one-time buyers')) + '</span></div></div>';

            html += '<div class="mxc-vgrid">'
                + '<div class="mxc-vblock"><span class="mxc-kpi__label">Re-order Cycle Time</span>'
                + '<div class="mxc-vblock__value"><strong>' + esc(cycle > 0 ? num(cycle) : '—') + '</strong>' + (cycle > 0 ? '<span>days</span>' : '') + '</div>'
                + '<small class="mxd-text-brand">' + esc(cycle > 0 ? 'Avg gap between a buyer\'s orders' : 'Not enough repeat orders yet') + '</small></div>'
                + '<div class="mxc-vblock"><span class="mxc-kpi__label">VIP Concentration</span>'
                + '<div class="mxc-vblock__value"><strong class="mxd-text-brand">' + esc(pct(vip.share)) + '</strong></div>'
                + '<small>' + esc('Top ' + num(vip.top_n) + ' buyers = ' + money(vip.top_revenue)) + '</small></div></div>';

            if (w) {
                usual = w.cycle_days || cycle;
                html += '<div class="mxc-alert">'
                    + '<div class="mxc-alert__title">' + icon('warning', 'md') + '<strong>High Inactivity / Churn Alert</strong></div>'
                    + '<p>Customer <strong>' + esc($.trim(w.customer_name) || w.customer_email) + '</strong> has not placed an order in <strong>'
                    + esc(plural(w.inactive_days, 'day', 'days')) + '</strong> (last order: ' + esc(longDate(w.last_order_at)) + ') — '
                    + esc(plural(w.orders, 'order', 'orders')) + ', ' + esc(money(w.lifetime_value)) + ' lifetime.</p>'
                    + '<div class="mxc-alert__foot"><span class="mxd-mono">'
                    + esc(usual > 0 ? 'Risk: ' + numberFmt.format(Math.round(w.inactive_days / usual * 10) / 10) + '× usual re-order cycle'
                        : 'Lapsed: ' + num(w.inactive_days - life.lapsed_after_days) + ' days past the ' + life.lapsed_after_days + '-day mark')
                    + '</span><span class="mxc-alert__links">'
                    + '<a class="mxc-alert__mail" href="mailto:' + esc(encodeURIComponent(w.customer_email).replace(/%40/g, '@'))
                    + '" title="Email ' + esc(w.customer_email) + '">' + icon('mail') + '</a>'
                    + '<a class="mxc-alert__btn" href="' + esc(config.couponUrl) + '" target="_blank" rel="noopener" '
                    + 'title="Create a cart price rule (coupon) for a win-back offer">Create Win-Back Offer →</a></span></div></div>';
            } else {
                html += '<div class="mxc-alert mxc-alert--ok"><div class="mxc-alert__title">' + icon('verified', 'md')
                    + '<strong>No lapsed buyers</strong></div><p>Every buyer has ordered in the last '
                    + esc(life.lapsed_after_days) + ' days.</p></div>';
            }

            html += '<div class="mxc-rule"><span>' + icon('auto_awesome', 'sm') + ' '
                + esc(remindDay ? 'Suggestion: send a re-order reminder around day ' + remindDay + ' after an order'
                    : 'Suggestion: reward second orders to build a repeat cycle')
                + '</span><button type="button" class="mxd-link" data-role="ask-rule">Ask AI →</button></div>';

            $el('velocity').html(html);
        }

        // ── Top customers (all-time) ────────────────────────────────────────

        function renderSegmentFilter() {
            var $select = $el('top-segment'),
                counts = {};

            life.buyers.forEach(function (buyer) {
                counts[buyer.segment] = (counts[buyer.segment] || 0) + 1;
            });
            $select.find('option:not(:first)').remove();
            Object.keys(SEGMENT_TONES).forEach(function (segment) {
                if (counts[segment]) {
                    $select.append($('<option>').val(segment).text(segment + ' (' + counts[segment] + ')'));
                }
            });
            if (!counts[table.segment]) {
                table.segment = '';
            }
            $select.val(table.segment);
        }

        function filteredBuyers() {
            var query = table.query.toLowerCase();

            return life.buyers.filter(function (buyer) {
                return (!table.segment || buyer.segment === table.segment)
                    && (!query || String(buyer.customer_name || '').toLowerCase().indexOf(query) !== -1
                        || String(buyer.customer_email).toLowerCase().indexOf(query) !== -1);
            });
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

        function renderTop() {
            var rows = filteredBuyers(),
                pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE)),
                first,
                visible,
                guests,
                buttons = [];

            table.page = Math.min(Math.max(1, table.page), pages);
            first = (table.page - 1) * PAGE_SIZE;
            visible = rows.slice(first, first + PAGE_SIZE);
            guests = rows.filter(function (buyer) { return !buyer.customer_id; }).length;

            $el('top-sub').text(life.totals.buyer_count > life.buyers.length
                ? 'Top ' + num(life.buyers.length) + ' of ' + num(life.totals.buyer_count) + ' buyers by net lifetime value — the export includes every match'
                : 'Every buyer by net lifetime value, with order cadence and preferred category');

            $el('top-rows').html(visible.length ? visible.map(function (buyer, i) {
                var lapsed = isLapsed(buyer.recency_days);

                return '<tr' + (lapsed ? ' class="is-risk"' : '') + '>'
                    + '<td class="mxc-c mxd-mono mxd-muted">' + (first + i + 1) + '</td>'
                    + '<td>' + profile(buyer) + '</td>'
                    + '<td><span class="mxc-acct">' + (buyer.customer_id ? 'Registered' : 'Guest') + '</span></td>'
                    + '<td class="mxc-r mxd-mono"><strong>' + esc(num(buyer.orders)) + '</strong></td>'
                    + '<td class="mxc-r mxd-mono">' + esc(money(buyer.avg_order_value)) + '</td>'
                    + '<td class="mxc-r mxd-mono"><strong class="' + (buyer.tier === 1 ? 'mxd-text-brand' : '') + '">'
                    + esc(money(buyer.lifetime_value)) + '</strong></td>'
                    + '<td class="mxd-mono' + (lapsed ? ' mxd-text-red' : '') + '" title="' + esc(daysAgo(buyer.recency_days)) + '">'
                    + esc(String(buyer.last_order_at || '').substr(0, 10)) + '</td>'
                    + '<td class="mxd-muted">' + esc(buyer.category || '—') + '</td>'
                    + '<td class="mxc-c">' + segmentBadge(buyer, true) + '</td></tr>';
            }).join('') : '<tr><td colspan="9" class="mxd-empty">'
                + (life.buyers.length ? 'No customers match the filter.' : 'No buyers yet.') + '</td></tr>');

            $el('pager-info').text('Showing ' + (rows.length ? first + 1 : 0) + ' to ' + (first + visible.length) + ' of '
                + plural(rows.length, 'customer', 'customers') + (guests ? ' (' + plural(guests, 'guest checkout', 'guest checkouts') + ')' : ''));

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

        // ── Copilot insight ─────────────────────────────────────────────────

        function renderCopilot() {
            var s = period.summary,
                totalRevenue = life.segments.reduce(function (sum, seg) { return sum + seg.revenue; }, 0),
                champions = segmentStats(SEGMENT_GROUPS[0]),
                growth = life.tiers[1],
                w = life.winback,
                findings = [],
                actions = [];

            if (life.totals.buyer_count) {
                findings.push('Across all time, <strong class="mxc-hl">' + esc(pct(life.repeat.repeat_rate)) + ' of buyers</strong> ('
                    + esc(num(life.repeat.repeat_customers)) + ' of ' + esc(num(life.repeat.total_customers))
                    + ') have ordered more than once.');
                if (champions.buyers) {
                    findings.push('<strong class="mxc-hl2">Champions</strong> (' + esc(plural(champions.buyers, 'buyer', 'buyers'))
                        + ') generate <strong class="mxc-hl">' + esc(pct(share(champions.revenue, totalRevenue))) + ' of customer revenue</strong> ('
                        + esc(money(champions.revenue)) + ').');
                } else if (life.vip.top_n) {
                    findings.push('The top ' + esc(num(life.vip.top_n)) + ' buyers generate <strong class="mxc-hl">'
                        + esc(pct(life.vip.share)) + ' of customer revenue</strong>.');
                }
            }
            findings.push('In ' + esc(period.range.label) + ', ' + esc(plural(s.buyers, 'buyer', 'buyers')) + ' placed '
                + esc(plural(s.orders, 'order', 'orders')) + ' worth <strong>' + esc(money(s.spend)) + '</strong>'
                + (s.buyers ? '; ' + esc(num(s.first_time_buyers)) + ' were first-time buyers.' : '.'));

            if (growth && growth.buyers) {
                actions.push('offer loyalty incentives to the ' + esc(plural(growth.buyers, 'buyer', 'buyers')) + ' in the <strong>Growth tier ('
                    + esc(tierRange(growth)) + ')</strong> to move them into VIP');
            }
            if (w) {
                actions.push('start a win-back sequence for <strong>' + esc($.trim(w.customer_name) || w.customer_email)
                    + '</strong> (inactive ' + esc(plural(w.inactive_days, 'day', 'days')) + ')'
                    + (life.lapsed_buyers > 1 ? ' and the other ' + esc(num(life.lapsed_buyers - 1)) + ' lapsed buyers' : ''));
            }

            $el('insight').html('<p>' + findings.join(' ') + '</p>'
                + (actions.length ? '<p><span class="mxc-copilot__label">Recommended action:</span> '
                    + actions.join(', and ') + '.</p>' : ''));
            $el('runtime').text('Runtime: ' + num(period.query_ms + life.query_ms) + 'ms · '
                + plural(life.totals.buyer_count, 'buyer', 'buyers') + ' scored');
            $el('sql-text').text(life.sql).attr('title', life.sql);
        }

        function copied() {
            $el('sql-copy-label').text('Copied');
            setTimeout(function () {
                $el('sql-copy-label').text('Copy Raw SQL');
            }, 1500);
        }

        /** Clipboard without the async API (plain-HTTP admin): a hidden textarea and execCommand. */
        function copyWithTextarea() {
            var $area = $('<textarea readonly>').val(life.sql).css({position: 'fixed', top: '-1000px'}).appendTo('body');

            $area[0].select();
            try {
                document.execCommand('copy');
                copied();
            } catch (e) {
                window.prompt('Copy the SQL:', life.sql);
            }
            $area.remove();
        }

        function copySql() {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(life.sql).then(copied, copyWithTextarea);
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

            $.ajax({
                url: config.dataUrl,
                type: 'GET',
                dataType: 'json',
                data: params
            }).done(function (response) {
                if (response && response.success) {
                    period = response.data;
                    renderPeriod();
                } else {
                    showError((response && response.message) || 'Could not load the report data.');
                }
            }).fail(function (xhr) {
                showError('Could not load the report data (HTTP ' + xhr.status + ').');
            }).always(function () {
                loading = false;
                $root.removeClass('is-loading');
                setActiveRange(period.range.key);
            });
        }

        function showError(message) {
            $el('error').text(message).prop('hidden', false);
        }

        function renderPeriod() {
            $el('range-label').text(period.range.label);
            setActiveRange(period.range.key);
            renderKpis();
            renderTicker();
            renderAcquisition();
            renderCopilot();
        }

        function renderLifetime() {
            renderTiers();
            renderSegments();
            renderRfmRows();
            renderVelocity();
            renderSegmentFilter();
            renderTop();
        }

        // ── Events ──────────────────────────────────────────────────────────

        $el('range').on('click', '[data-range]', function () {
            var key = $(this).data('range'),
                $custom = $el('custom-range');

            if (key === 'custom') {
                $custom.prop('hidden', !$custom.prop('hidden'));
                $(this).attr('aria-expanded', String(!$custom.prop('hidden')));
                $el('custom-start').val($el('custom-start').val() || period.range.start);
                $el('custom-end').val($el('custom-end').val() || period.range.end);
                return;
            }
            $custom.prop('hidden', true);
            if (key !== period.range.key) {
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

        $el('tier-mode').on('click', 'button', function () {
            tierMode = $(this).data('mode');
            $(this).addClass('is-active').siblings().removeClass('is-active');
            renderTiers();
        });

        $el('segments').on('click', '[data-group]', function () {
            var key = $(this).data('group');

            rfmGroup = rfmGroup === key ? '' : key;
            renderSegments();
            renderRfmRows();
        });

        $el('rfm-export').on('click', function () {
            var group = activeGroup();

            window.location.href = exportUrl(group ? {segments: group.members.join(',')} : {});
        });

        $el('top-search').on('input', function () {
            table.query = $.trim($(this).val());
            table.page = 1;
            renderTop();
        });

        $el('top-segment').on('change', function () {
            table.segment = $(this).val();
            table.page = 1;
            renderTop();
        });

        $el('pager').on('click', '[data-page]', function () {
            table.page = Number($(this).data('page'));
            renderTop();
        });

        $el('top-export').on('click', function () {
            var params = {};

            if (table.segment) {
                params.segments = table.segment;
            }
            if (table.query) {
                params.q = table.query;
            }
            window.location.href = exportUrl(params);
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

        $el('ask-form').on('submit', function (event) {
            event.preventDefault();
            ask($el('ask-input').val());
        });

        $root.on('click', '.mxc-chip', function () {
            ask($(this).data('question'));
        });

        $el('velocity').on('click', '[data-role="ask-rule"]', function () {
            var cycle = Number(life.reorder_cycle_days) || 0;

            ask(cycle > 0
                ? 'Which customers placed their last order between ' + Math.max(1, Math.round(cycle * 0.8)) + ' and '
                    + life.lapsed_after_days + ' days ago? Show name, email, last order date and lifetime net revenue.'
                : 'Which customers have placed exactly one order? Show name, email, order date and order total.');
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

        renderPeriod();
        renderLifetime();
        // Charts use the page fonts: draw again once they are ready
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                renderAcquisition();
            });
        }
    };
});
