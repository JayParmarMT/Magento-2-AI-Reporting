/**
 * Meetanshi AIReporting — zoom and pan for time-series charts (Chart.js, category x axis)
 *
 * Adds a toolbar above the chart: zoom out / in, move left / right, show everything, and a label
 * with the visible window ("Mar 1, 2025 – Jun 30, 2025 · 122 of 1,750 days"). On the chart: drag
 * across it to zoom into that stretch, Ctrl/⌘ + scroll to zoom around the pointer, double-click to
 * show everything. No plugin or extra download: the window is the x scale's min / max.
 *
 * Usage: var zoom = chartZoom.create($canvasWrapper, {unit: 'days', describe: function (i) {…}});
 *        after each new Chart(): zoom.bind(chart, key) — the window is kept while key stays the same.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */
define(['jquery'], function ($) {
    'use strict';

    var MIN_POINTS = 5,
        STYLE_ID = 'mxz-styles',
        CSS = [
            '.mxz-bar{align-items:center;display:flex;flex-wrap:wrap;gap:8px 12px;justify-content:space-between;margin:0 0 8px;}',
            '.mxz-bar__left{align-items:center;display:flex;flex-wrap:wrap;gap:8px 12px;min-width:0;}',
            '.mxz-range{color:#64748b;font:500 11px/1.4 "JetBrains Mono",monospace;}',
            '.mxz-range strong{color:#1e293b;font-weight:600;}',
            '.mxz-tools{display:inline-flex;gap:2px;background:#f1f5f9;border-radius:8px;padding:2px;}',
            '.mxd .mxz-tools button{align-items:center;background:transparent;border:0;border-radius:6px;box-shadow:none;color:#334155;',
            'cursor:pointer;display:inline-flex;height:26px;justify-content:center;margin:0;min-height:0;padding:0;width:28px;}',
            '.mxd .mxz-tools button:hover:not(:disabled){background:#fff;color:#ee672f;}',
            '.mxd .mxz-tools button:disabled{cursor:default;opacity:.35;}',
            '.mxd .mxz-tools button:focus-visible{outline:2px solid #ee672f;outline-offset:1px;}',
            '.mxz-tools .mxd-ico{font-size:18px;}',
            '.mxz-area{position:relative;}',
            '.mxz-area canvas{cursor:crosshair;}',
            '.mxz-select{background:rgba(238,103,47,.14);border-left:1px solid #ee672f;border-right:1px solid #ee672f;',
            'pointer-events:none;position:absolute;z-index:2;}',
            '.mxz-hint{color:#94a3b8;font-size:11px;}'
        ].join('');

    function injectStyles() {
        if (!document.getElementById(STYLE_ID)) {
            $('<style>', {id: STYLE_ID}).text(CSS).appendTo('head');
        }
    }

    function format(value) {
        return new Intl.NumberFormat().format(value);
    }

    function ChartZoom($area, options) {
        var self = this;

        injectStyles();
        this.$area = $area.addClass('mxz-area');
        this.options = $.extend({unit: 'points', describe: null, onChange: null}, options || {});
        this.chart = null;
        this.key = null;
        this.window = null;
        this.drag = null;

        this.$bar = $(
            '<div class="mxz-bar" role="toolbar" aria-label="Chart zoom">'
            + '<div class="mxz-bar__left"><span data-zoom-slot></span><span class="mxz-range" data-zoom-range aria-live="polite"></span></div>'
            + '<div class="mxz-bar__left"><span class="mxz-hint">Drag across the chart to zoom · Ctrl + scroll</span>'
            + '<span class="mxz-tools">'
            + this.button('out', 'zoom_out', 'Zoom out')
            + this.button('in', 'zoom_in', 'Zoom in')
            + this.button('left', 'chevron_left', 'Move earlier')
            + this.button('right', 'chevron_right', 'Move later')
            + this.button('reset', 'zoom_out_map', 'Show everything')
            + '</span></div></div>'
        ).insertBefore($area);
        this.$select = $('<div class="mxz-select" hidden></div>').appendTo($area);

        this.$bar.on('click', '[data-zoom]', function () {
            var action = $(this).data('zoom');

            if (action === 'in') {
                self.zoom(0.5);
            } else if (action === 'out') {
                self.zoom(2);
            } else if (action === 'left') {
                self.pan(-1);
            } else if (action === 'right') {
                self.pan(1);
            } else {
                self.reset();
            }
        });

        $area.on('mousedown', 'canvas', function (event) {
            var area = self.chart && self.chart.chartArea,
                x = event.offsetX;

            if (event.button !== 0 || !area || x < area.left || x > area.right
                || event.offsetY < area.top || event.offsetY > area.bottom) {
                return;
            }
            event.preventDefault();
            self.drag = {start: x, end: x, left: this.getBoundingClientRect().left, offset: this.offsetLeft};
            self.$select.css({left: self.drag.offset + x, width: 0, top: this.offsetTop + area.top, height: area.bottom - area.top})
                .prop('hidden', false);
        });
        $(document).on('mousemove', function (event) {
            var area;

            if (!self.drag) {
                return;
            }
            area = self.chart.chartArea;
            self.drag.end = Math.min(Math.max(event.clientX - self.drag.left, area.left), area.right);
            self.$select.css({
                left: self.drag.offset + Math.min(self.drag.start, self.drag.end),
                width: Math.abs(self.drag.end - self.drag.start)
            });
        }).on('mouseup', function () {
            var drag = self.drag,
                scale;

            if (!drag) {
                return;
            }
            self.drag = null;
            self.$select.prop('hidden', true);
            if (Math.abs(drag.end - drag.start) >= 8 && self.chart) {
                scale = self.chart.scales.x;
                self.setWindow(
                    scale.getValueForPixel(Math.min(drag.start, drag.end)),
                    scale.getValueForPixel(Math.max(drag.start, drag.end))
                );
            }
        });
        $area.on('wheel', 'canvas', function (event) {
            var original = event.originalEvent;

            if (!self.chart || !(original.ctrlKey || original.metaKey)) {
                return;
            }
            event.preventDefault();
            self.zoom(original.deltaY < 0 ? 0.75 : 1.33, self.chart.scales.x.getValueForPixel(original.offsetX));
        });
        $area.on('dblclick', 'canvas', function () {
            self.reset();
        });
    }

    ChartZoom.prototype.button = function (action, icon, label) {
        return '<button type="button" data-zoom="' + action + '" title="' + label + '" aria-label="' + label + '">'
            + '<span class="mxd-ico" aria-hidden="true">' + icon + '</span></button>';
    };

    /** Element in the toolbar where a page can put its own controls (e.g. Day / Week / Month). */
    ChartZoom.prototype.slot = function () {
        return this.$bar.find('[data-zoom-slot]');
    };

    /**
     * Attach a freshly created chart. The zoom window is kept while key is unchanged
     * (e.g. switching datasets), and reset when it changes (new range or granularity).
     */
    ChartZoom.prototype.bind = function (chart, key) {
        this.chart = chart;
        if (key !== this.key) {
            this.key = key;
            this.window = null;
        }
        this.apply();
    };

    ChartZoom.prototype.setUnit = function (unit) {
        this.options.unit = unit;
    };

    ChartZoom.prototype.count = function () {
        return this.chart ? this.chart.data.labels.length : 0;
    };

    /** Visible [first, last] label indexes. */
    ChartZoom.prototype.bounds = function () {
        return this.window ? this.window.slice() : [0, Math.max(0, this.count() - 1)];
    };

    ChartZoom.prototype.isZoomed = function () {
        return this.window !== null;
    };

    ChartZoom.prototype.setWindow = function (first, last) {
        var n = this.count(),
            span;

        first = Math.round(Number(first) || 0);
        last = Math.round(Number(last) || 0);
        span = Math.max(MIN_POINTS - 1, last - first);
        if (n <= MIN_POINTS || span >= n - 1) {
            this.window = null;
        } else {
            first = Math.max(0, Math.min(first, n - 1 - span));
            this.window = [first, first + span];
        }
        this.apply();
    };

    /** factor < 1 zooms in, > 1 out; anchor (label index) stays where it is. */
    ChartZoom.prototype.zoom = function (factor, anchor) {
        var b = this.bounds(),
            span = b[1] - b[0],
            next = span * factor,
            at = anchor === undefined || anchor === null || isNaN(anchor) ? (b[0] + b[1]) / 2 : anchor,
            ratio = span > 0 ? (at - b[0]) / span : 0.5,
            first = at - ratio * next;

        this.setWindow(first, first + next);
    };

    ChartZoom.prototype.pan = function (direction) {
        var b = this.bounds(),
            step = Math.max(1, Math.round((b[1] - b[0]) / 2)) * direction;

        if (this.window) {
            this.setWindow(b[0] + step, b[1] + step);
        }
    };

    ChartZoom.prototype.reset = function () {
        this.window = null;
        this.apply();
    };

    ChartZoom.prototype.apply = function () {
        var x;

        if (!this.chart) {
            return;
        }
        x = this.chart.options.scales.x;
        if (this.window) {
            x.min = this.window[0];
            x.max = this.window[1];
        } else {
            delete x.min;
            delete x.max;
        }
        // A running animation (e.g. the chart's entry animation) would move the points back afterwards
        this.chart.stop();
        this.chart.update('none');
        this.updateBar();
        if (typeof this.options.onChange === 'function') {
            this.options.onChange(this.bounds(), this.isZoomed());
        }
    };

    ChartZoom.prototype.updateBar = function () {
        var n = this.count(),
            b = this.bounds(),
            describe = this.options.describe || function (i) { return String(i + 1); },
            zoomable = n > MIN_POINTS,
            text;

        text = !n ? ''
            : this.window
                ? '<strong>' + $('<i>').text(describe(b[0]) + ' – ' + describe(b[1])).html() + '</strong> · '
                    + format(b[1] - b[0] + 1) + ' of ' + format(n) + ' ' + this.options.unit
                : 'All ' + format(n) + ' ' + this.options.unit;
        this.$bar.find('[data-zoom-range]').html(text);
        this.$bar.find('[data-zoom="in"]').prop('disabled', !zoomable || (b[1] - b[0]) <= MIN_POINTS - 1);
        this.$bar.find('[data-zoom="out"], [data-zoom="reset"]').prop('disabled', !this.window);
        this.$bar.find('[data-zoom="left"]').prop('disabled', !this.window || b[0] === 0);
        this.$bar.find('[data-zoom="right"]').prop('disabled', !this.window || b[1] === n - 1);
        this.$bar.find('.mxz-hint').prop('hidden', !zoomable);
    };

    return {
        create: function ($area, options) {
            return new ChartZoom($area, options);
        }
    };
});
