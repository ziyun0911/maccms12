/* 悬浮原播放器：滚动越过播放器后，把同一个播放器 DOM 固定成角落小窗继续播放。
   不复制、不重载 iframe，因此播放进度与音量状态都保持连续。 */
(function (w, d) {
    'use strict';

    var FLOAT_CLS = 'is-mac-float-player-on';
    var ANCHOR_CLS = 'mac-float-player-anchor';
    var FULLSCREEN_CLS = 'is-mac-web-fullscreen';
    var PAGE_FLOAT_CLS = 'mac-float-player-on';
    var FLOAT_TOP_VAR = '--mac-float-top';
    var MOBILE_WIDTH = 820;

    function rootEl() {
        return d.querySelector('#play_page .player_video');
    }

    function readRatio() {
        var ratio = 56.25;
        var node = d.getElementById('vod-play-float-config');
        if (node) {
            try {
                var cfg = JSON.parse(node.textContent || '{}');
                if (cfg && isFinite(cfg.ratio) && cfg.ratio > 0 && cfg.ratio <= 200) {
                    ratio = parseFloat(cfg.ratio);
                }
            } catch (e) {}
        }
        return ratio;
    }

    function viewportWidth() {
        return w.innerWidth || d.documentElement.clientWidth || 1024;
    }

    function viewportHeight() {
        return w.innerHeight || d.documentElement.clientHeight || 768;
    }

    function floatSize(ratio) {
        var vw = viewportWidth();
        var width = vw < MOBILE_WIDTH ? Math.round(Math.min(190, vw * 0.42)) : Math.round(Math.min(340, vw * 0.28));
        return {width: width, height: Math.round(width * ratio / 100)};
    }

    function hasPlayer(el) {
        return !!(el.querySelector('iframe') || el.querySelector('video') || el.querySelector('.MacPlayer'));
    }

    function nativeFullscreen() {
        return !!(d.fullscreenElement || d.webkitFullscreenElement || d.mozFullScreenElement || d.msFullscreenElement);
    }

    // 祖先带 transform/filter 等会改写 fixed 的包含块，小窗会贴到错误位置，此时直接放弃悬浮
    function fixedSafe(el) {
        var node = el.parentNode;
        while (node && node.nodeType === 1) {
            var st = w.getComputedStyle(node);
            if (!st) {
                node = node.parentNode;
                continue;
            }
            var blocked = st.transform !== 'none'
                || st.perspective !== 'none'
                || (st.filter && st.filter !== 'none')
                || (st.backdropFilter && st.backdropFilter !== 'none')
                || (st.webkitBackdropFilter && st.webkitBackdropFilter !== 'none')
                || (st.willChange || '').indexOf('transform') !== -1
                || (st.contain || '').indexOf('layout') !== -1;
            if (blocked) {
                return false;
            }
            node = node.parentNode;
        }
        return true;
    }

    function start(el) {
        var ratio = readRatio();
        // 占位块常驻流内：不悬浮时高度为 0，悬浮时补足播放器原本腾出的空间，页面不跳动
        var anchor = d.createElement('div');
        anchor.className = ANCHOR_CLS;
        el.parentNode.insertBefore(anchor, el);

        var floating = false;
        var dismissed = false;
        var flowHeight = 0;
        var canFloat = fixedSafe(el);

        function applyBox() {
            var size = floatSize(ratio);
            el.style.width = size.width + 'px';
            el.style.height = size.height + 'px';
            // 右下角的固定挂件（快捷菜单等）要顶到小窗之上，交给 CSS 用这个变量避让
            // 上限保证挂件整体留在视口内：ratio 配到 200 时小窗可能高出视口
            var vh = viewportHeight();
            var top = Math.round(vh - el.getBoundingClientRect().top);
            if (top > vh - 100) {
                top = Math.max(vh - 100, 0);
            }
            d.documentElement.style.setProperty(FLOAT_TOP_VAR, (top > 0 ? top : 0) + 'px');
        }

        function engage() {
            floating = true;
            anchor.style.height = flowHeight + 'px';
            // class 必须先加上：fixed 生效后才能量到小窗的真实位置
            el.classList.add(FLOAT_CLS);
            applyBox();
            d.documentElement.classList.add(PAGE_FLOAT_CLS);
        }

        function release() {
            floating = false;
            el.classList.remove(FLOAT_CLS);
            el.style.width = '';
            el.style.height = '';
            anchor.style.height = '0px';
            d.documentElement.classList.remove(PAGE_FLOAT_CLS);
            d.documentElement.style.removeProperty(FLOAT_TOP_VAR);
        }

        function slotBottom() {
            // 悬浮后播放器脱离文档流，原始位置只能靠占位块还原
            if (floating) {
                return anchor.getBoundingClientRect().bottom;
            }
            var rect = el.getBoundingClientRect();
            if (rect.height > 0) {
                flowHeight = Math.round(rect.height);
            }
            return rect.bottom;
        }

        function update() {
            if (!canFloat || el.classList.contains(FULLSCREEN_CLS) || nativeFullscreen()) {
                if (floating) {
                    release();
                }
                return;
            }
            if (slotBottom() >= 0) {
                if (floating) {
                    release();
                }
                dismissed = false;
                return;
            }
            if (!dismissed && !floating && hasPlayer(el)) {
                engage();
            }
        }

        // 用 setTimeout 而非 rAF 节流：后台标签页里 rAF 不执行，切回页面时悬浮状态会滞后
        var pending = 0;
        function schedule() {
            if (pending) {
                return;
            }
            pending = w.setTimeout(function () {
                pending = 0;
                update();
            }, 60);
        }

        w.addEventListener('scroll', schedule, {passive: true, capture: true});
        w.addEventListener('resize', function () {
            if (floating) {
                applyBox();
            }
            update();
        }, {passive: true});
        w.addEventListener('orientationchange', schedule);

        var closeBtn = d.getElementById('macFloatPlayerClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                dismissed = true;
                if (floating) {
                    release();
                }
            });
        }

        update();
        w.setTimeout(schedule, 600);
        w.MacPlayFloatPlayer = {update: update, engaged: function () {
            return floating;
        }};
    }

    function boot() {
        var el = rootEl();
        if (el && el.parentNode) {
            start(el);
        }
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
