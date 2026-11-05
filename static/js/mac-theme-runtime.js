
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

    function langParamUrl(lang) {
        var search = win.location.search || '';
        if (!(/[?&]lang=/i).test(search)) {
            return null;
        }
        return win.location.pathname + search.replace(/([?&]lang=)[^&#]*/i, '$1' + encodeURIComponent(lang)) + (win.location.hash || '');
    }

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
        if (prevContentLang === contentLang) {
            return;
        }
        setCookie(COOKIE_NAME, contentLang, COOKIE_DAYS);
        // mac_get_content_lang() 里 ?lang= 的优先级高于 cookie，只改 cookie 会 reload 回原语言
        var pinned = langParamUrl(contentLang);
        if (pinned !== null) {
            win.location.replace(pinned);
            return;
        }
        win.location.reload();
    });

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
