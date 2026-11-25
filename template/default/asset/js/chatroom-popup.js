(function () {
    'use strict';

    var root = document.getElementById('macChatPopup');
    if (!root) {
        return;
    }

    var win = root.querySelector('.mac-chat-pop__win');
    var head = root.querySelector('[data-chat-drag]');
    var roomNameEl = root.querySelector('[data-chat-room-name]');
    var msgsEl = root.querySelector('[data-chat-msgs]');
    var roomsEl = root.querySelector('[data-chat-rooms]');
    var input = root.querySelector('[data-chat-input]');
    var sendBtn = root.querySelector('[data-chat-send]');
    var loginEl = root.querySelector('[data-chat-login]');
    var errEl = root.querySelector('[data-chat-err]');

    var POS_KEY = 'mac_chat_pop_pos_v1';
    var POLL_MS = 5000;
    var uid = parseInt(root.getAttribute('data-uid'), 10) || 0;
    var api = absUrl(root.getAttribute('data-api'));
    var loginUrl = absUrl(root.getAttribute('data-login'));
    var pageUrl = absUrl(root.getAttribute('data-page'));

    var state = {
        open: false,
        vodId: 0,
        lastId: 0,
        seen: {},
        timer: null
    };

    function absUrl(u) {
        u = String(u || '');
        if (/^https?:\/\//i.test(u)) {
            return u;
        }
        if (u.charAt(0) !== '/') {
            u = '/' + u.replace(/^\.?\//, '');
        }
        return u;
    }

    function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 820px)').matches;
    }

    function setErr(text) {
        if (errEl) {
            errEl.textContent = text || '';
        }
    }

    function raiseLoginLayer() {
        var n = 0;
        var iv = window.setInterval(function () {
            var pop = document.querySelector('.mac_pop');
            var bg = document.querySelector('.mac_pop_bg');
            if (pop && pop.style) {
                pop.style.setProperty('z-index', '100130', 'important');
            }
            if (bg && bg.style) {
                bg.style.setProperty('z-index', '100125', 'important');
            }
            if (++n > 50) {
                window.clearInterval(iv);
            }
        }, 100);
    }

    function openLogin() {
        try {
            if (window.MAC && MAC.User && typeof MAC.User.Login === 'function') {
                MAC.User.Login();
                raiseLoginLayer();
                return;
            }
        } catch (e) {}
        var back = window.location.pathname + window.location.search;
        var joiner = loginUrl.indexOf('?') >= 0 ? '&' : '?';
        window.location.href = loginUrl + joiner + 'redirect=' + encodeURIComponent(back);
    }

    function scrollBottom() {
        if (msgsEl) {
            msgsEl.scrollTop = msgsEl.scrollHeight;
        }
    }

    function showFoot(visible, canSend) {
        if (input) {
            input.hidden = !visible || !canSend;
        }
        if (sendBtn) {
            sendBtn.hidden = !visible || !canSend;
        }
        if (loginEl) {
            loginEl.hidden = !visible || canSend;
            if (loginUrl) {
                var back = window.location.pathname + window.location.search;
                var joiner = loginUrl.indexOf('?') >= 0 ? '&' : '?';
                loginEl.setAttribute('href', loginUrl + joiner + 'redirect=' + encodeURIComponent(back));
            }
        }
    }

    function emptyMsgs(text) {
        if (!msgsEl) {
            return;
        }
        msgsEl.innerHTML = '';
        var d = document.createElement('div');
        d.className = 'mac-chat-pop__empty';
        d.textContent = text;
        msgsEl.appendChild(d);
    }

    function appendRows(rows) {
        if (!msgsEl || !rows || !rows.length) {
            return;
        }
        var empty = msgsEl.querySelector('.mac-chat-pop__empty');
        if (empty && empty.parentNode) {
            empty.parentNode.removeChild(empty);
        }

        for (var i = 0; i < rows.length; i++) {
            var m = rows[i] || {};
            var id = parseInt(m.chat_id, 10) || 0;
            if (id && state.seen[id]) {
                continue;
            }
            if (id) {
                state.seen[id] = 1;
            }

            var self = uid > 0 && parseInt(m.user_id, 10) === uid;
            var item = document.createElement('div');
            item.className = 'mac-chat-msg' + (self ? ' is-self' : '');

            var avatar = document.createElement('span');
            avatar.className = 'mac-chat-msg__avatar';
            avatar.setAttribute('aria-hidden', 'true');
            var name = String(m.user_name || '游客');
            avatar.textContent = name.charAt(0) || '游';

            var main = document.createElement('div');
            main.className = 'mac-chat-msg__main';

            var meta = document.createElement('div');
            meta.className = 'mac-chat-msg__meta';

            var nm = document.createElement('span');
            nm.className = 'mac-chat-msg__name';
            nm.textContent = name;

            var tm = document.createElement('time');
            tm.className = 'mac-chat-msg__time';
            tm.textContent = String(m.chat_time_text || '');

            meta.appendChild(nm);
            meta.appendChild(tm);

            if (!self && id) {
                var rp = document.createElement('button');
                rp.type = 'button';
                rp.className = 'mac-chat-msg__report';
                rp.setAttribute('data-chat-report', String(id));
                rp.textContent = '举报';
                meta.appendChild(rp);
            }

            var bubble = document.createElement('div');
            bubble.className = 'mac-chat-msg__bubble';
            bubble.textContent = String(m.chat_content || '');

            main.appendChild(meta);
            main.appendChild(bubble);
            item.appendChild(avatar);
            item.appendChild(main);
            msgsEl.appendChild(item);

            if (id > state.lastId) {
                state.lastId = id;
            }
        }
        scrollBottom();
    }

    function stopPoll() {
        if (state.timer) {
            window.clearInterval(state.timer);
            state.timer = null;
        }
    }

    function startPoll() {
        stopPoll();
        if (state.vodId <= 0) {
            return;
        }
        state.timer = window.setInterval(function () {
            if (!state.open || !state.vodId) {
                return;
            }
            if (document.visibilityState === 'hidden') {
                return;
            }
            poll();
        }, POLL_MS);
    }

    function poll() {
        if (!state.vodId) {
            return;
        }
        fetch(api + '/chatroom/get_list?vod_id=' + state.vodId + '&after_id=' + state.lastId + '&limit=50', {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && res.code === 1 && res.info && res.info.rows) {
                appendRows(res.info.rows);
            }
        }).catch(function () {});
    }

    function enterRoom(vodId, name) {
        state.vodId = vodId;
        state.lastId = 0;
        state.seen = {};
        if (msgsEl) {
            msgsEl.innerHTML = '';
        }
        if (roomsEl) {
            roomsEl.hidden = true;
            roomsEl.innerHTML = '';
        }
        if (roomNameEl) {
            roomNameEl.textContent = name || '';
        }
        setErr('');
        showFoot(true, uid > 0);

        fetch(api + '/chatroom/rooms?vod_id=' + vodId + '&limit=1', {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && res.code === 1 && res.info && res.info.room) {
                if (roomNameEl) {
                    roomNameEl.textContent = res.info.room.vod_name || '';
                }
            }
        }).catch(function () {});

        fetch(api + '/chatroom/get_list?vod_id=' + vodId + '&after_id=0&limit=50', {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && res.code === 1 && res.info && res.info.rows) {
                appendRows(res.info.rows);
                if (!res.info.rows.length) {
                    emptyMsgs('还没有人发言，来抢第一条。');
                }
            } else if (res && res.msg) {
                setErr(res.msg);
            }
        }).catch(function () {
            setErr('消息加载失败，请稍后重试');
        });

        startPoll();
    }

    function renderRooms(list, resolved) {
        if (!roomsEl) {
            return;
        }
        roomsEl.innerHTML = '';
        roomsEl.hidden = false;

        var title = document.createElement('h3');
        title.textContent = list && list.length ? '热门聊天室' : '';
        roomsEl.appendChild(title);

        if (!list || !list.length) {
            var d = document.createElement('div');
            d.className = 'mac-chat-pop__empty';
            d.textContent = '暂无聊天房间，去影片详情页点「聊天室」发起第一条。';
            roomsEl.appendChild(d);
            if (resolved) {
                setErr('暂无房间可进入');
            }
            return;
        }

        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mac-chat-room';
            btn.setAttribute('data-chat-room-id', String(r.vod_id));
            btn.setAttribute('data-chat-room-name', String(r.vod_name || ''));

            var nm = document.createElement('span');
            nm.className = 'mac-chat-room__name';
            nm.textContent = String(r.vod_name || '');

            var cnt = document.createElement('span');
            cnt.className = 'mac-chat-room__cnt';
            cnt.textContent = (parseInt(r.cnt, 10) || 0) + ' 条';

            btn.appendChild(nm);
            btn.appendChild(cnt);
            roomsEl.appendChild(btn);
        }
    }

    function showRooms() {
        state.vodId = 0;
        stopPoll();
        if (msgsEl) {
            msgsEl.innerHTML = '';
        }
        if (roomNameEl) {
            roomNameEl.textContent = '全站大厅';
        }
        showFoot(false, false);
        setErr('选择一个房间后即可发言');
        renderRooms([], false);

        fetch(api + '/chatroom/rooms?limit=12', {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && res.code === 1 && res.info && res.info.rooms) {
                renderRooms(res.info.rooms, true);
            }
        }).catch(function () {
            renderRooms([], true);
        });
    }

    function send() {
        if (!input) {
            return;
        }
        var text = String(input.value || '').trim();
        if (!text) {
            return;
        }
        if (!uid) {
            openLogin();
            return;
        }
        if (sendBtn) {
            sendBtn.disabled = true;
        }
        setErr('');

        fetch(api + '/chatroom/send', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: 'vod_id=' + encodeURIComponent(state.vodId) + '&content=' + encodeURIComponent(text)
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && res.code === 1) {
                input.value = '';
                poll();
                return;
            }
            if (res && (res.code === 1010 || res.code === '1010')) {
                openLogin();
                return;
            }
            setErr((res && res.msg) ? res.msg : '发送失败，请稍后再试');
        }).catch(function () {
            setErr('网络异常，请稍后再试');
        }).finally(function () {
            if (sendBtn) {
                sendBtn.disabled = false;
            }
        });
    }

    function report(chatId, btn) {
        if (!chatId) {
            return;
        }
        if (!uid) {
            openLogin();
            return;
        }
        if (btn && btn.getAttribute('data-done') === '1') {
            return;
        }
        if (!window.confirm('确定举报这条消息？')) {
            return;
        }
        fetch(api + '/chatroom/report', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: 'chat_id=' + encodeURIComponent(chatId)
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && res.code === 1) {
                if (btn) {
                    btn.setAttribute('data-done', '1');
                    btn.textContent = '已举报';
                    btn.disabled = true;
                }
                return;
            }
            if (res && (res.code === 1010 || res.code === '1010')) {
                openLogin();
                return;
            }
            if (btn) {
                btn.textContent = (res && res.msg) ? res.msg : '举报失败';
            }
        }).catch(function () {
            if (btn) {
                btn.textContent = '举报失败';
            }
        });
    }

    function clampWin() {
        if (isMobile()) {
            return;
        }
        var rect = win.getBoundingClientRect();
        var left = Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8));
        var top = Math.max(8, Math.min(rect.top, window.innerHeight - rect.height - 8));
        win.style.left = left + 'px';
        win.style.top = top + 'px';
        win.style.right = 'auto';
        win.style.bottom = 'auto';
    }

    function restorePos() {
        if (isMobile()) {
            return;
        }
        var raw = '';
        try {
            raw = localStorage.getItem(POS_KEY) || '';
        } catch (e) {}
        if (!raw) {
            return;
        }
        try {
            var pos = JSON.parse(raw);
            if (typeof pos.left !== 'number' || typeof pos.top !== 'number') {
                return;
            }
            win.style.left = pos.left + 'px';
            win.style.top = pos.top + 'px';
            win.style.right = 'auto';
            win.style.bottom = 'auto';
            clampWin();
        } catch (e) {}
    }

    function savePos() {
        if (isMobile()) {
            return;
        }
        try {
            var rect = win.getBoundingClientRect();
            localStorage.setItem(POS_KEY, JSON.stringify({left: rect.left, top: rect.top}));
        } catch (e) {}
    }

    function open(vodId, vodName) {
        state.open = true;
        root.classList.add('is-open');
        root.setAttribute('aria-hidden', 'false');
        restorePos();
        if (parseInt(vodId, 10) > 0) {
            enterRoom(parseInt(vodId, 10), vodName || '');
        } else {
            showRooms();
        }
        if (input && uid > 0 && state.vodId > 0) {
            window.setTimeout(function () {
                try { input.focus({preventScroll: true}); } catch (e) { input.focus(); }
            }, 60);
        }
    }

    function close() {
        state.open = false;
        root.classList.remove('is-open');
        root.setAttribute('aria-hidden', 'true');
        stopPoll();
    }

    // 关闭
    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-chat-login]')) {
            e.preventDefault();
            openLogin();
            return;
        }
        if (e.target.closest('[data-chat-close]')) {
            close();
            return;
        }

        var roomBtn = e.target.closest('[data-chat-room-id]');
        if (roomBtn) {
            enterRoom(
                parseInt(roomBtn.getAttribute('data-chat-room-id'), 10) || 0,
                roomBtn.getAttribute('data-chat-room-name') || ''
            );
            if (input && uid > 0) {
                window.setTimeout(function () { try { input.focus(); } catch (e) {} }, 40);
            }
            return;
        }

        var rp = e.target.closest('[data-chat-report]');
        if (rp) {
            report(rp.getAttribute('data-chat-report'), rp);
        }
    });

    if (sendBtn) {
        sendBtn.addEventListener('click', send);
    }
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && state.open) {
            close();
        }
    });

    // 站内弹窗登录成功后同步登录态，不跳会员中心
    document.addEventListener('mac:auth-me-ready', function (e) {
        var info = e.detail || null;
        var logged = info && Number(info.is_login) === 1;
        uid = logged ? (parseInt(info.user_id, 10) || 0) : 0;
        try {
            root.setAttribute('data-uid', String(uid));
        } catch (err) {}
        if (state.open && state.vodId > 0) {
            showFoot(true, uid > 0);
            if (uid > 0) {
                setErr('');
                window.setTimeout(function () {
                    try { input.focus(); } catch (err2) {}
                }, 40);
            }
        }
    });

    // 拖动
    if (head) {
        var drag = null;
        head.addEventListener('pointerdown', function (e) {
            if (isMobile() || e.target.closest('[data-chat-close]')) {
                return;
            }
            var rect = win.getBoundingClientRect();
            win.style.left = rect.left + 'px';
            win.style.top = rect.top + 'px';
            win.style.right = 'auto';
            win.style.bottom = 'auto';
            drag = {x: e.clientX, y: e.clientY, left: rect.left, top: rect.top, id: e.pointerId};
            try { head.setPointerCapture(e.pointerId); } catch (err) {}
            e.preventDefault();
        });
        head.addEventListener('pointermove', function (e) {
            if (!drag) {
                return;
            }
            var left = drag.left + (e.clientX - drag.x);
            var top = drag.top + (e.clientY - drag.y);
            left = Math.max(8, Math.min(left, window.innerWidth - win.offsetWidth - 8));
            top = Math.max(8, Math.min(top, window.innerHeight - win.offsetHeight - 8));
            win.style.left = left + 'px';
            win.style.top = top + 'px';
        });
        var endDrag = function () {
            if (!drag) {
                return;
            }
            drag = null;
            savePos();
        };
        head.addEventListener('pointerup', endDrag);
        head.addEventListener('pointercancel', endDrag);
    }

    window.addEventListener('resize', function () {
        if (state.open) {
            clampWin();
        }
    });

    // 入口点击
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-mac-chatroom-open]') : null;
        if (!el) {
            return;
        }
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        e.preventDefault();
        open(
            parseInt(el.getAttribute('data-vod-id'), 10) || 0,
            el.getAttribute('data-vod-name') || ''
        );
    });

    window.MAC = window.MAC || {};
    window.MAC.Chatroom = {
        open: open,
        close: close,
        pageUrl: pageUrl
    };
})();
