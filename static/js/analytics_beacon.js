
(function () {
    'use strict';

    if (window.MacAnalytics) {
        return;
    }

    var SS = (typeof window.MAC_ANA_SS !== 'undefined') ? parseInt(window.MAC_ANA_SS, 10) : 0;
    var VISITOR_COOKIE = 'mac_ana_vid';
    var SESSION_KEY = 'mac_ana_sk';
    var startAt = Date.now();
    var basePath = (window.maccms && window.maccms.path) ? window.maccms.path : '/';

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
    }

    function rnd() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
    }

    function visitorId() {
        var v = getCookie(VISITOR_COOKIE);
        if (!v) {
            v = 'v_' + rnd();
            setCookie(VISITOR_COOKIE, v, 3650);
        }
        return v;
    }

    function sessionKey() {
        var s = getCookie(SESSION_KEY);
        if (!s) {
            s = sessionStorage.getItem(SESSION_KEY);
        }
        if (!s) {
            s = 's_' + rnd();
            sessionStorage.setItem(SESSION_KEY, s);
        }
        return s;
    }

    function currentPath() {
        return location.pathname + location.search;
    }

    function post(url, payload, keepalive) {
        var full = basePath.replace(/\/$/, '') + url;
        var body = JSON.stringify(payload);
        if (keepalive && navigator.sendBeacon) {
            navigator.sendBeacon(full, new Blob([body], { type: 'application/json' }));
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', full, true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=UTF-8');
        xhr.send(body);
    }

    function reportLeave() {
        var stayMs = Math.max(0, Date.now() - startAt);
        post('/api.php/analytics/event', {
            event_code: 'page_leave',
            session_key: sessionKey(),
            visitor_id: visitorId(),
            path: currentPath(),
            props: { stay_ms: stayMs, path: currentPath() },
            ts: Math.floor(Date.now() / 1000)
        }, true);
    }

    function reportPageviewFull() {
        post('/api.php/analytics/pageview', {
            session_key: sessionKey(),
            visitor_id: visitorId(),
            path: currentPath(),
            prev_path: document.referrer || '',
            stay_ms: 0,
            ts: Math.floor(Date.now() / 1000)
        }, false);
    }

    if (SS !== 1) {
        reportPageviewFull();
    }

    window.addEventListener('beforeunload', reportLeave);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            reportLeave();
        }
    });

    window.MacAnalytics = {
        event: function (code, props) {
            post('/api.php/analytics/event', {
                event_code: code,
                session_key: sessionKey(),
                visitor_id: visitorId(),
                props: props || {},
                ts: Math.floor(Date.now() / 1000)
            }, false);
        }
    };
})();
