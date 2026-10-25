/*!
 * mac-theme-runtime.js —— maccms12 前端内容级多语言切换器
 *
 * 移植自官方 maccms10-master（template/_shared/kit/shared/js/mac-theme-runtime.js）
 * 的内容语言切换段，做成自包含版本：maccms12 的默认/conch 模板未加载官方整份
 * runtime，此处只保留"内容级多语言"所需的职责：
 *
 *   1) 点击任何带 data-mac-lang 的元素（值＝内容语言代码，如 en-us / ja-jp；
 *      兼容官方 zh / en 短码，分别映射为 zh-cn / en-us）时，
 *   2) 写入 cookie mac_content_lang（30 天），
 *   3) 整页刷新，让服务端按 cookie 重新渲染内容级多语言译文。
 *
 * 服务端约定（app/common.php mac_get_content_lang()）：
 *   - cookie 名 mac_content_lang，值为允许列表（系统设置>前台内容语言）内的代码；
 *   - 不在允许列表的值会被回退为默认语言，cookie 值大小写不敏感；
 *   - 也支持 ?lang=代码 的 URL 参数（服务端会顺手写 cookie）。
 *
 * 模板接入：在需要切换语言的入口放
 *   <a href="javascript:;" data-mac-lang="en-us">English</a>
 * 或直接 <a href="?lang=en-us">English</a>（两者等效，本文件处理前者）。
 */
(function (win, doc) {
    'use strict';

    var COOKIE_NAME = 'mac_content_lang';
    var COOKIE_DAYS = 30;

    function getCookie(name) {
        var m = doc.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var d = new Date();
            d.setTime(d.getTime() + days * 864e5);
            expires = '; expires=' + d.toUTCString();
        }
        doc.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/';
    }

    function normalize(code) {
        code = String(code || '').toLowerCase().trim();
        if (code === 'zh' || code === 'cn' || code === 'zh-cn') {
            return 'zh-cn';
        }
        if (code === 'en') {
            return 'en-us';
        }
        return code;
    }

    // 语言切换 hook：任何带 data-mac-lang 的元素（值＝语言代码，或官方 zh/en 短码）
    doc.addEventListener('click', function (ev) {
        var el = ev.target && ev.target.closest ? ev.target.closest('[data-mac-lang]') : null;
        if (!el) {
            return;
        }
        ev.preventDefault();
        var contentLang = normalize(el.getAttribute('data-mac-lang'));
        if (!contentLang) {
            return;
        }
        var prevContentLang = getCookie(COOKIE_NAME) || '';
        if (prevContentLang !== contentLang) {
            setCookie(COOKIE_NAME, contentLang, COOKIE_DAYS);
            win.location.reload();
        }
    });

    // 同步跨标签页切换（另一页写了同一 cookie 时由 storage 事件带起），
    // 与官方 runtime 的 storage 同步思路一致
    win.addEventListener('storage', function (ev) {
        if (ev.key !== COOKIE_NAME || ev.newValue == null) {
            return;
        }
        if (ev.newValue !== (getCookie(COOKIE_NAME) || '')) {
            setCookie(COOKIE_NAME, ev.newValue, COOKIE_DAYS);
            win.location.reload();
        }
    });
})(window, document);
