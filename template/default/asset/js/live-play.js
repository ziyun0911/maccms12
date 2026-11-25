/*
 * 直播播放页逻辑（MacCMS v12 / DPlayer）
 *
 * 线路统一走 /liveproxy.php 同源代理：上游 m3u8 不给 CORS 头，Hls.js 的 XHR 会被浏览器拦掉。
 * 类型判定必须用原始 URL（N），因为代理地址不带 .m3u8 后缀；auto 线路的代理地址
 * 额外以 m3u8 结尾，让 DPlayer 的 auto 后缀判定仍能落到 hls。pic 不走代理。
 *
 * 2026-11-12：
 *  - hls.js 调参：liveSyncDurationCount:1 适配上游只有 3 个分片的短窗口，提高分片/清单重试。
 *  - 恢复播放失败提示（轻量提示条，不再用全屏遮罩）。
 *  - fatal error / <video> error / 18 秒未起播 → 自动切下一条线路；手动操作会重置重试计数。
 *  - 销毁旧播放器期间抑制 error：DPlayer.destroy() 会让 Hls 抛 error，避免误判为播放失败。
 */
(function (f, u) {
    'use strict';

    var l = null;
    var a = {
        liveId: 0, cateId: 0, listReq: 0,
        categories: [], channels: [], detail: null,
        lineIndex: 0, poster: null, page: null,
        failTimer: 0, playOk: false, suppress: false, gen: 0,
        autoTries: 0, switching: false
    };

    function p() {
        return typeof f.maccms !== 'undefined' && f.maccms.path
            ? String(f.maccms.path).replace(/\/+$/, '') : '';
    }

    function o(e) {
        var t = p();
        return (t ? t + '/' : '/') + 'api.php/' + e;
    }

    function g(e, t) {
        if (f.MAC && MAC.Lang && typeof MAC.Lang.get === 'function') {
            var n = MAC.Lang.get(e);
            if (n) return n;
        }
        return t || '';
    }

    function b(e) {
        if (f.MacCatalogMedia && typeof f.MacCatalogMedia.normalizePicUrl === 'function') {
            return f.MacCatalogMedia.normalizePicUrl(e);
        }
        if (!e) return '';
        var t = String(e).trim();
        if (/^https?:\/\//i.test(t) || t.indexOf('//') === 0) return t;
        var n = p();
        return t.charAt(0) === '/' ? n + t : (n ? n + '/' : '/') + t.replace(/^\/*/, '');
    }

    function M(e, t) {
        var n = e.getAttribute('data-play-url-pattern') || '';
        return n.indexOf('__ID__') !== -1
            ? n.replace('__ID__', String(t))
            : (p() || '') + '/index.php/live/play?id=' + t;
    }

    function y(e) {
        return fetch(e, { credentials: 'same-origin' }).then(function (t) { return t.json(); });
    }

    function P(e) {
        return e.querySelector('#live_dplayer_cnt') || e.querySelector('.live-play__dplayer');
    }

    function C() {
        clearTimeout(a.failTimer);
        if (l && typeof l.destroy === 'function') {
            try { l.destroy(); } catch (t) {}
        }
        l = null;
        a.poster = null;
        var e = u.getElementById('live_dplayer_cnt');
        if (e) e.innerHTML = '';
    }

    /* 轻量失败提示条：不遮挡画面；bad=false 时移除。 */
    function v(e, bad, msg) {
        e = e || a.page;
        var wrap = e && e.querySelector('.live-play__player-wrap');
        if (!wrap) return;
        var tip = wrap.querySelector('.live-play__err');
        if (!bad) {
            if (tip && tip.parentNode) tip.parentNode.removeChild(tip);
            return;
        }
        if (!tip) {
            tip = u.createElement('div');
            tip.className = 'live-play__err';
            var txt = u.createElement('span');
            txt.className = 'live-play__err-text';
            tip.appendChild(txt);
            var btn = u.createElement('button');
            btn.type = 'button';
            btn.textContent = g('live/retry', '重试');
            btn.addEventListener('click', function () {
                a.autoTries = 0;
                a.playOk = false;
                var list = (a.detail && a.detail.live_url_list) || [];
                var cur = list[a.lineIndex] || list[0];
                if (cur && cur.url) _(e, cur.url);
            });
            tip.appendChild(btn);
            wrap.appendChild(tip);
        }
        var t = tip.querySelector('.live-play__err-text');
        if (t) t.textContent = msg || g('live/load_fail', '加载失败，请稍后重试');
    }

    /* 播放失败：按线路顺序自动切换，转满一圈后停止并提示。 */
    function F(e) {
        e = e || a.page;
        if (!e || a.suppress || a.switching) return;
        clearTimeout(a.failTimer);
        var list = (a.detail && a.detail.live_url_list) || [];
        if (list.length < 2) {
            v(e, true, '当前线路加载失败，请点击「重试」或「如加载失败请刷新」');
            return;
        }
        if (a.autoTries >= list.length) {
            v(e, true, '所有线路均加载失败，请稍后重试');
            return;
        }
        var from = a.lineIndex;
        var to = (a.lineIndex + 1) % list.length;
        a.autoTries++;
        a.switching = true;
        v(e, true, '线路' + (from + 1) + '加载失败，正在切换到线路' + (to + 1) + '…');
        a.lineIndex = to;
        E(e, list, to);
        setTimeout(function () {
            a.switching = false;
            _(e, list[to].url);
        }, 300);
    }

    function N(e, t) {
        var n = String(e || '').toLowerCase(), i = String(t || '').toLowerCase();
        return i === 'flv' || n.indexOf('.flv') > -1 ? { type: 'flv', live: true }
            : i === 'dash' || n.indexOf('.mpd') > -1 ? { type: 'dash', live: true }
            : n.indexOf('magnet:') === 0 ? { type: 'webtorrent', live: false }
            : i === 'hls' || i === 'm3u8' || n.indexOf('.m3u8') > -1 ? { type: 'hls', live: true }
            : { type: 'auto', live: true };
    }

    function A() {
        return !a.detail || !a.detail.live_pic ? '' : b(a.detail.live_pic);
    }

    function I(e) {
        var t = a.detail && a.detail.live_play_from;
        var n = N(e, t), s = String(e || '');
        var r = /^https?:\/\//i.test(s)
            ? '/liveproxy.php?url=' + encodeURIComponent(s) + (n.type === 'auto' ? '&_fmt=m3u8' : '')
            : e;
        return { url: r, type: n.type, live: n.live };
    }

    /* 台标作为容器内的限尺寸居中层，不用 video.poster（会被 contain 放大到铺满）。 */
    function W(n) {
        var d = n.querySelector('.live-play__poster');
        if (d) d.remove();
        var pic = A();
        if (!pic) return;
        d = u.createElement('div');
        d.className = 'live-play__poster';
        var i = u.createElement('img');
        i.src = pic;
        i.alt = '';
        i.decoding = 'async';
        i.onerror = function () { d.classList.add('is-empty'); };
        d.appendChild(i);
        n.appendChild(d);
        a.poster = d;
    }

    function X() {
        if (a.poster) {
            a.poster.classList.add('is-hidden');
            a.poster = null;
        }
    }

    /* 真正可播时隐藏台标、清除失败提示、取消起播超时。 */
    function S(e) {
        if (!l || typeof l.on !== 'function') return;
        function ok() {
            a.playOk = true;
            clearTimeout(a.failTimer);
            X();
            v(e, false);
        }
        l.on('canplay', ok);
        l.on('loadeddata', ok);
        l.on('playing', ok);
    }

    function q(e, t) {
        var n = P(e);
        if (!n || !t || typeof f.DPlayer !== 'function') {
            v(e, true, '播放器初始化失败，请刷新页面重试');
            return;
        }
        // 销毁旧播放器会触发 Hls 的 error，先抑制，避免误判成播放失败
        a.suppress = true;
        a.gen++;
        var gen = a.gen;
        C();
        a.page = e;
        a.playOk = false;
        var i = I(t);
        try {
            l = new f.DPlayer({
                container: n,
                autoplay: true,
                screenshot: false,
                live: i.live,
                video: i,
                contextmenu: [],
                // DPlayer 的 option 合并是浅合并，必须带齐其它插件的空配置
                pluginOptions: {
                    hls: {
                        // 上游 playlist 只有 3 个分片，按 1 片同步才不会一直等 buffer
                        liveSyncDurationCount: 1,
                        liveMaxLatencyDurationCount: 3,
                        maxBufferLength: 12,
                        fragLoadingMaxRetry: 6,
                        fragLoadingRetryDelay: 500,
                        // 必须小于代理侧 curl 超时(60s)，否则 hls.js 先取消请求会写出 500
                        fragLoadingTimeOut: 50000,
                        manifestLoadingMaxRetry: 4,
                        manifestLoadingRetryDelay: 500,
                        manifestLoadingTimeOut: 20000,
                        levelLoadingMaxRetry: 4
                    },
                    flv: {}, dash: {}, webtorrent: {}
                }
            });
            W(n);
            S(e);

            var hls = l.plugins && l.plugins.hls;
            if (hls && f.Hls && f.Hls.Events && typeof hls.on === 'function') {
                hls.on(f.Hls.Events.ERROR, function (evt, data) {
                    if (data && data.fatal) F(e);
                });
            }
            var video = l.video;
            if (video && typeof video.addEventListener === 'function') {
                video.addEventListener('error', function () { F(e); });
            }

            clearTimeout(a.failTimer);
            a.failTimer = setTimeout(function () {
                if (!a.playOk && a.gen === gen) F(e);
            }, 22000);

            if (typeof l.play === 'function') {
                var c = l.play();
                c && typeof c.catch === 'function' && c.catch(function () {});
            }
            setTimeout(function () { if (a.gen === gen) a.suppress = false; }, 800);
        } catch (r) {
            l = null;
            a.suppress = false;
            v(e, true, '播放器初始化失败，正在尝试其他线路…');
            F(e);
        }
    }

    /* 切换线路/刷新统一销毁并重建播放器：DPlayer.switchVideo 内部先 pause() 且不自动播放，
       外层紧跟的 play() 会被随后的 pause 打断（AbortError）；重建走与首播相同的 autoplay 路径。 */
    function D(e, t) {
        if (!t) { v(e, true, '线路地址为空'); return; }
        q(e, t);
    }

    function _(e, t) { D(e, t); }

    function E(e, t, n) {
        var i = e.querySelector('.live-play__lines');
        if (!i) return;
        i.innerHTML = '';
        if (!t || !t.length) { i.classList.add('mac-hide'); return; }
        i.classList.remove('mac-hide');
        t.forEach(function (c, r) {
            var d = u.createElement('button');
            d.type = 'button';
            d.className = 'live-play__line' + (r === n ? ' is-active' : '');
            d.textContent = c.name || g('live/line_hd', 'HD');
            d.addEventListener('click', function () {
                a.lineIndex = r;
                a.autoTries = 0;      // 手动选线路后重新给满自动重试次数
                a.playOk = false;
                E(e, t, r);
                _(e, c.url);
            });
            i.appendChild(d);
        });
    }

    function O(e, t) {
        var n = e.querySelector('.live-play__title');
        if (n) n.textContent = t.live_name || '';
        var i = u.querySelector('.head_inner_mobile_toolbar .hd_tit');
        if (i && t.live_name) i.textContent = t.live_name;
        if (t.live_name) {
            u.title = t.live_name + ' - ' + (f.maccms && maccms.site_name ? maccms.site_name : '');
        }
    }

    function x(e, t, n) {
        var i = e.querySelector('.live-play__tabs');
        if (!i) return;
        i.innerHTML = '';
        a.categories.forEach(function (c) {
            var r = u.createElement('button');
            r.type = 'button';
            r.className = 'live-play__tab' + (String(c.cate_id) === String(t) ? ' is-active' : '');
            r.setAttribute('role', 'tab');
            r.textContent = c.cate_name || '';
            r.addEventListener('click', function () { n(c.cate_id); });
            i.appendChild(r);
        });
    }

    function T(e) {
        var t = e.querySelector('.live-play__channel-list');
        if (!t) return;
        t.innerHTML = '';
        var n = g('live/now_playing', '正在播放');
        a.channels.forEach(function (i) {
            var c = u.createElement('li');
            c.className = 'live-play__channel' + (String(i.live_id) === String(a.liveId) ? ' is-active' : '');
            var r = u.createElement('button');
            r.type = 'button';
            r.className = 'live-play__channel-btn';
            var d = u.createElement('span');
            d.className = 'live-play__channel-logo';
            if (i.live_pic) {
                var s = u.createElement('img');
                s.src = b(i.live_pic);
                s.alt = '';
                s.loading = 'lazy';
                s.onerror = function () {
                    var z = this;
                    if (z.getAttribute('data-pf') !== '1') {
                        z.setAttribute('data-pf', '1');
                        var mm = /[?&]url=([^&]+)/.exec(z.getAttribute('src') || '');
                        if (mm) {
                            try {
                                var raw = decodeURIComponent(mm[1]);
                                if (raw) { z.src = raw; return; }
                            } catch (err) {}
                        }
                    }
                    z.style.display = 'none';
                };
                d.appendChild(s);
            }
            var h = u.createElement('span');
            h.className = 'live-play__channel-name';
            h.textContent = i.live_name || '';
            r.appendChild(d);
            r.appendChild(h);
            if (String(i.live_id) === String(a.liveId)) {
                var m = u.createElement('span');
                m.className = 'live-play__channel-badge';
                m.textContent = n;
                r.appendChild(m);
            }
            r.addEventListener('click', function () {
                if (String(i.live_id) !== String(a.liveId)) f.location.href = M(e, i.live_id);
            });
            c.appendChild(r);
            t.appendChild(c);
        });
    }

    /* 选台器按频道编号自然升序：CCTV-1..17 → CETV-1..4 → 其余按 live_id。
       API 的 orderby=sort 是 live_sort desc，会把央视整组倒过来，故渲染前统一排序。 */
    function K(i) {
        var n = String(i.live_name || '');
        var m = /^CCTV-(\d+)/i.exec(n), e = /^CETV-(\d+)/i.exec(n);
        var s = Number(i.live_sort) || 0;
        return m ? [0, parseInt(m[1], 10), s] : e ? [1, parseInt(e[1], 10), s] : [2, 0, s];
    }

    function Q(x, y) {
        var A = K(x), B = K(y), j;
        for (j = 0; j < A.length; j++) if (A[j] !== B[j]) return A[j] - B[j];
        return (Number(x.live_id) || 0) - (Number(y.live_id) || 0);
    }

    function L(e, t) {
        var r = ++a.listReq, n = 200, i = [];
        function c(s) {
            var url = o('live/get_list') + '?cate_id=' + encodeURIComponent(t)
                + '&limit=' + n + '&offset=' + s * n + '&orderby=sort&pic_w=40&pic_h=40';
            return y(url).then(function (ret) {
                if (r !== a.listReq || !ret || Number(ret.code) !== 1) return;
                var m = ret.list || [];
                if (!m.length) return;
                i = i.concat(m).sort(Q);
                a.channels = i;
                T(e);
                var h = Number(ret.pagecount) || 0;
                if (s + 1 < h) return c(s + 1);
            });
        }
        return c(0);
    }

    function k(e, t) {
        return y(o('live/get_detail') + '?live_id=' + encodeURIComponent(t) + '&pic_w=300&pic_h=180')
            .then(function (n) {
                if (!n || Number(n.code) !== 1 || !n.info) {
                    v(e, true, '直播信息加载失败，请刷新页面重试');
                    return;
                }
                var prevCateId = a.cateId;
                a.detail = n.info;
                a.cateId = n.info.cate_id || a.cateId;
                a.lineIndex = 0;
                a.autoTries = 0;
                O(e, n.info);
                // 自定义 HTML 嵌入（如冰豆直播 iframe 调用）：直接渲染 live_content，不走 DPlayer。
                // 注意：不能在 AJAX 后执行原始 document.write（会重写整个文档），故改为动态创建 iframe 元素。
                if (n.info.live_play_from === 'html' && n.info.live_content) {
                    var cnt = P(e);
                    if (cnt) {
                        cnt.innerHTML = '';
                        var raw = n.info.live_content;
                        var im = /<iframe[^>]*\ssrc=["']([^"']+)["']/i.exec(raw);
                        if (im) {
                            var wm = /var\s+w\s*=\s*['"]?(\d+)/i.exec(raw);
                            var hm = /var\s+h\s*=\s*['"]?(\d+)/i.exec(raw);
                            var sm = /scrolling=["']?(\w+)/i.exec(raw);
                            var fr = u.createElement('iframe');
                            fr.src = im[1];
                            fr.width = wm ? wm[1] : 960;
                            fr.height = hm ? hm[1] : 520;
                            if (sm) fr.scrolling = sm[1];
                            fr.frameBorder = '0';
                            fr.setAttribute('marginwidth', '0');
                            fr.setAttribute('marginheight', '0');
                            fr.style.width = '100%';
                            fr.style.height = '100%';
                            fr.style.border = '0';
                            fr.style.minHeight = '480px';
                            cnt.appendChild(fr);
                        } else {
                            cnt.innerHTML = raw; // 兜底：原样输出
                        }
                        // html 类型无多线路，隐藏线路栏
                        var lb = e.querySelector('.live-play__lines-bar');
                        if (lb) lb.style.display = 'none';
                        var lc = e.querySelector('.live-play__lines');
                        if (lc) lc.style.display = 'none';
                    }
                    return;
                }
                var i = n.info.live_url_list || [];
                E(e, i, 0);
                if (i.length && i[0].url) _(e, i[0].url);
                else v(e, true, '该频道暂无可用播放线路');
                if (String(prevCateId) !== String(a.cateId)) U(e, a.cateId);
            })
            .catch(function () { v(e, true, '直播信息加载失败，请刷新页面重试'); });
    }

    function H(e) {
        e.querySelectorAll('.js-live-refresh').forEach(function (t) {
            t.addEventListener('click', function () {
                a.autoTries = 0;
                a.playOk = false;
                if (a.detail) {
                    var n = a.detail.live_url_list || [], i = n[a.lineIndex] || n[0];
                    if (i && i.url) _(e, i.url);
                }
            });
        });
    }

    /* 分类接口只负责取回列表，不决定选中哪个 tab —— get_detail 可能还没返回。 */
    function U(e) {
        return y(o('live/get_category')).then(function (t) {
            if (!t || Number(t.code) !== 1 || !t.list || !t.list.length) return;
            a.categories = t.list;
        }).catch(function () {});
    }

    /* 详情与分类都到齐后才渲染选台器，以当前频道真实 cate_id 为准。 */
    function V(e) {
        if (!a.categories.length) return;
        var cur = null, i;
        for (i = 0; i < a.categories.length; i++) {
            if (String(a.categories[i].cate_id) === String(a.cateId)) {
                cur = a.categories[i].cate_id;
                break;
            }
        }
        if (cur === null) cur = a.categories[0].cate_id;
        a.cateId = cur;
        function pick(c) {
            a.cateId = c;
            x(e, c, pick);
            L(e, c);
        }
        x(e, cur, pick);
        L(e, cur);
    }

    function z(e) {
        a.liveId = parseInt(e.getAttribute('data-live-id'), 10) || 0;
        if (a.liveId < 1) {
            var t = /[?&]id=(\d+)/.exec(f.location.search);
            if (t) a.liveId = parseInt(t[1], 10);
        }
        H(e);
        if (a.liveId < 1 || typeof f.DPlayer !== 'function') {
            v(e, true, '播放器加载失败，请刷新页面重试');
            return;
        }
        Promise.all([k(e, a.liveId), U(e)]).then(function () { V(e); });
    }

    u.addEventListener('DOMContentLoaded', function () {
        var e = u.getElementById('live_play_page');
        if (e) z(e);
    });
    f.addEventListener('pagehide', C);
})(window, document);
