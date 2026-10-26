
var MonitorCharts = (function () {
    'use strict';

    var I18N = (typeof MONITOR_I18N !== 'undefined') ? MONITOR_I18N : {};
    var TEXT = {
        no_data: I18N.no_data || 'No data',
        load_failed: I18N.load_failed || 'Failed to load'
    };

    var PALETTE = [
        '#1E9FFF', '#FF5722', '#5FB878', '#FFB800',
        '#A233C6', '#01AAED', '#009688', '#FF69B4'
    ];

    var DASHES = [
        null,
        [8, 4],
        [2, 3],
        [12, 3, 2, 3],
        [6, 2],
        [1, 3],
        [10, 5],
        [4, 2, 1, 2]
    ];

    var syncKey = uPlot.sync('monitor');

    var charts = [];

    function fmtNum(v, unit) {
        if (v === null || v === undefined) {
            return '--';
        }
        var s = (Math.round(v * 100) / 100).toString();
        return unit ? s + unit : s;
    }

    function tsFormat(ts) {
        var d = new Date(ts * 1000);
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function create(el, opt) {
        var node = document.querySelector(el);
        if (!node) {
            return null;
        }
        opt = opt || {};
        var labels = opt.labels || [];
        var unit = opt.unit || '';
        var height = opt.height || 220;

        var series = [{
            label: 'time',
            value: function (u, v) { return v == null ? '--' : tsFormat(v); }
        }];

        for (var i = 0; i < labels.length; i++) {
            series.push({
                label: labels[i],
                stroke: PALETTE[i % PALETTE.length],
                dash: DASHES[i % DASHES.length],
                fill: opt.stacked ? PALETTE[i % PALETTE.length] + '55' : null,
                width: 1.5,
                points: { show: false },
                spanGaps: false,
                value: function (u, v) { return fmtNum(v, unit); }
            });
        }

        var u = new uPlot({
            width: node.clientWidth || 600,
            height: height,
            series: series,
            cursor: {
                sync: { key: syncKey.key },
                drag: { x: true, y: false }
            },
            scales: { x: { time: true } },
            axes: [
                { grid: { stroke: '#eee' }, stroke: '#666' },
                { grid: { stroke: '#eee' }, stroke: '#666', size: 55 }
            ],
            legend: { live: true }
        }, [[]], node);

        var empty = document.createElement('div');
        empty.className = 'mon-chart-empty';
        empty.textContent = TEXT.no_data;
        empty.setAttribute('role', 'status');
        empty.style.cssText = 'display:none;position:absolute;top:0;left:0;right:0;bottom:0;'
            + 'align-items:center;justify-content:center;color:#666;font-size:13px;'
            + 'background:rgba(255,255,255,.85);pointer-events:none;';
        node.style.position = 'relative';
        node.appendChild(empty);

        var chart = {
            u: u,
            node: node,
            setData: function (data) {
                u.setData(data);
                empty.style.display = hasAnyPoint(data) ? 'none' : 'flex';
            },
            showError: function (msg) {
                empty.textContent = msg;
                empty.style.display = 'flex';
            },
            resize: function () {
                u.setSize({ width: node.clientWidth || 600, height: height });
            }
        };
        charts.push(chart);
        return chart;
    }

    function hasAnyPoint(data) {
        if (!data || data.length < 2) {
            return false;
        }
        for (var s = 1; s < data.length; s++) {
            var col = data[s];
            for (var i = 0; i < col.length; i++) {
                if (col[i] !== null && col[i] !== undefined) {
                    return true;
                }
            }
        }
        return false;
    }

    function sparkline(el, color) {
        var node = document.querySelector(el);
        if (!node) {
            return null;
        }
        var u = new uPlot({
            width: node.clientWidth || 160,
            height: 40,
            legend: { show: false },
            cursor: { show: false },
            scales: { x: { time: true } },
            axes: [{ show: false }, { show: false }],
            series: [
                {},
                {
                    stroke: color || PALETTE[0],
                    fill: (color || PALETTE[0]) + '22',
                    width: 1.5,
                    points: { show: false },
                    spanGaps: false
                }
            ]
        }, [[], []], node);

        return {
            u: u,
            setData: function (data) { u.setData(data); }
        };
    }

    function load(keys, range, cb) {
        var url = MONITOR_SERIES_URL
            + (MONITOR_SERIES_URL.indexOf('?') >= 0 ? '&' : '?')
            + 'keys=' + encodeURIComponent(keys.join(','))
            + '&range=' + encodeURIComponent(range);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (res) {
                if (res.code !== 1) {
                    cb(null, res.msg || TEXT.load_failed);
                    return;
                }
                cb(res.data, null);
            })
            .catch(function (e) { cb(null, TEXT.load_failed + ': ' + String(e.message || e)); });
    }

    window.addEventListener('resize', function () {
        for (var i = 0; i < charts.length; i++) {
            charts[i].resize();
        }
    });

    return {
        create: create,
        sparkline: sparkline,
        load: load,
        palette: PALETTE
    };
})();
