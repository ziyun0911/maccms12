<?php
/**
 * dplayer.php —— dplayer.html 的 PHP 版播放器外壳
 * 1) 保留原 html 的全部前端逻辑（DPlayer + hls.js + 弹幕 + 进度/失败回调）；
 * 2) 新增服务端 ?url= 支持：无父页 MacPlayer 时也能独立播放（可传 base64url 或明文地址）；
 * 3) 父页取不到（独立打开）时不再抛异常。
 */
$__dplayer_url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($__dplayer_url !== '' && !preg_match('#^https?://#i', $__dplayer_url)) {
    $__decoded = base64_decode(strtr($__dplayer_url, '-_', '+/'), true);
    if ($__decoded !== false && preg_match('#^https?://#i', trim($__decoded))) {
        $__dplayer_url = trim($__decoded);
    }
}
// 外链播放页通用解析：当 url 不是媒体直链（m3u8/mp4/flv…）时，服务端抓该页面，从中找出真正可播的地址
// 找到 → 交给本地 DPlayer 播放；找不到 → iframe 回退到原外链页（保持“能看”，不白屏）
$__dplayer_fallback = '';
$__dplayer_orig = $__dplayer_url;
$__mediaRe = '~\.(m3u8|mp4|flv|mkv|mov|ts|webm)(\?|#|$)~i';
if ($__dplayer_url !== '' && !preg_match($__mediaRe, $__dplayer_url)) {
    $__p = parse_url($__dplayer_url);
    $__scheme = isset($__p['scheme']) ? strtolower($__p['scheme']) : '';
    $__host = isset($__p['host']) ? strtolower($__p['host']) : '';
    $__blocked = ($__scheme !== 'http' && $__scheme !== 'https');
    if ($__host === '' || preg_match('#^(localhost|127\.|0\.0\.0\.0|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|169\.254\.|::1|\[::1\])#i', $__host)) {
        $__blocked = true;
    }
    if (!$__blocked) {
        $__mapFile = dirname(__DIR__, 2) . '/runtime/cache/ext_page_map.json';
        $__ckey = md5($__dplayer_url);
        $__map = array();
        if (is_file($__mapFile)) {
            $__tmp = json_decode((string) @file_get_contents($__mapFile), true);
            if (is_array($__tmp)) {
                $__map = $__tmp;
            }
        }
        $__resolved = '';
        if (isset($__map[$__ckey]['u'], $__map[$__ckey]['t'])) {
            $__ttl = 43200; // 12 小时；带签名/临时的地址不会长期复用
            if ((time() - (int) $__map[$__ckey]['t']) < $__ttl && strpos((string) $__map[$__ckey]['u'], '.') !== false) {
                $__resolved = (string) $__map[$__ckey]['u'];
            }
        }
        if ($__resolved === '') {
            for ($__try = 0; $__try < 3; $__try++) {
                $__ch = curl_init($__dplayer_url);
                curl_setopt_array($__ch, array(
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => 1,
                    CURLOPT_SSL_VERIFYPEER => 0,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_ENCODING => '',
                    CURLOPT_HTTPHEADER => array(
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/132 Safari/537.36',
                        'Accept: text/html,*/*',
                        'Referer: ' . $__dplayer_url,
                    ),
                ));
                $__body = curl_exec($__ch);
                $__code = curl_getinfo($__ch, CURLINFO_HTTP_CODE);
                curl_close($__ch);
                if ($__code >= 200 && $__code < 400 && is_string($__body) && $__body !== '') {
                    $__cand = '';
                    // 1) 绝对地址（含 // 协议相对）
                    if (preg_match('#(?:https?:)?//[^\s"\x27<>\\\\]+?\.(?:m3u8|mp4|flv)[^\s"\x27<>\\\\]*#i', $__body, $__mm)) {
                        $__cand = $__mm[0];
                        if (strpos($__cand, '//') === 0) {
                            $__cand = $__scheme . ':' . $__cand;
                        }
                    } elseif (preg_match('#(/[^\s"\x27<>\\\\]+?\.(?:m3u8|mp4|flv))[^\s"\x27<>\\\\]*#i', $__body, $__mm)) {
                        // 2) 站内相对路径
                        $__cand = $__scheme . '://' . $__host . '/' . ltrim($__mm[1], '/');
                    }
                    if ($__cand !== '') {
                        // 已知 lzcdn 系可去签名，其余保留原查询串以防票据校验
                        if (preg_match('#(^|\.)lzcdn[0-9]*\.[a-z]+$#i', $__host)) {
                            $__cand = preg_replace('~\?[^#]*~', '', $__cand);
                        }
                        // 轻量确认可播（避免把 404/错误页塞给播放器）
                        $__ch2 = curl_init($__cand);
                        curl_setopt_array($__ch2, array(
                            CURLOPT_NOBODY => 1,
                            CURLOPT_TIMEOUT => 6,
                            CURLOPT_CONNECTTIMEOUT => 4,
                            CURLOPT_FOLLOWLOCATION => 1,
                            CURLOPT_SSL_VERIFYPEER => 0,
                            CURLOPT_SSL_VERIFYHOST => 0,
                            CURLOPT_HTTPHEADER => array('User-Agent: Mozilla/5.0 Chrome/132 Safari/537.36', 'Referer: ' . $__dplayer_url),
                        ));
                        curl_exec($__ch2);
                        $__code2 = curl_getinfo($__ch2, CURLINFO_HTTP_CODE);
                        curl_close($__ch2);
                        if ($__code2 >= 200 && $__code2 < 400) {
                            $__resolved = $__cand;
                            break;
                        }
                    }
                }
                usleep(150000);
            }
            if ($__resolved !== '') {
                $__map[$__ckey] = array('u' => $__resolved, 't' => time(), 'p' => $__dplayer_url);
                if (count($__map) > 500) {
                    $__map = array_slice($__map, -500, null, true);
                }
                $__dir = dirname($__mapFile);
                if (!is_dir($__dir)) {
                    @mkdir($__dir, 0777, true);
                }
                @file_put_contents($__mapFile, json_encode($__map), LOCK_EX);
            }
        }
        if ($__resolved !== '') {
            $__dplayer_url = $__resolved;
        } else {
            $__dplayer_url = '';
            $__dplayer_fallback = $__dplayer_orig;
        }
    }
}
$__dplayer_from = isset($_GET['from']) ? preg_replace('/[^0-9A-Za-z_]/', '', (string) $_GET['from']) : '';
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}
?>
<!DOCTYPE html>
<html>
<head>
	<title>dplayer播放器</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<meta http-equiv="X-UA-Compatible" content="IE=11" />
	<meta content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no" id="viewport" name="viewport">
	<style type="text/css">
	html,body{width:100%;height:100%; padding:0; margin:0; background:#000;}
	#playerCnt{width:100%;height:100%;}
	.dplayer-loading{display: none !important;}
	/* 放慢 DPlayer 内置弹幕动画（默认横移约 5s / 全屏约 8s） */
	.dplayer-danmaku .dplayer-danmaku-right.dplayer-danmaku-move {
		-webkit-animation-duration: 9s !important;
		animation-duration: 9s !important;
	}
	.dplayer:-webkit-full-screen .dplayer-danmaku .dplayer-danmaku-right.dplayer-danmaku-move,
	.dplayer:fullscreen .dplayer-danmaku .dplayer-danmaku-right.dplayer-danmaku-move {
		-webkit-animation-duration: 14s !important;
		animation-duration: 14s !important;
	}
	.dplayer-danmaku .dplayer-danmaku-top.dplayer-danmaku-move,
	.dplayer-danmaku .dplayer-danmaku-bottom.dplayer-danmaku-move,
	.dplayer:-webkit-full-screen .dplayer-danmaku .dplayer-danmaku-top.dplayer-danmaku-move,
	.dplayer:-webkit-full-screen .dplayer-danmaku .dplayer-danmaku-bottom.dplayer-danmaku-move,
	.dplayer:fullscreen .dplayer-danmaku .dplayer-danmaku-top.dplayer-danmaku-move,
	.dplayer:fullscreen .dplayer-danmaku .dplayer-danmaku-bottom.dplayer-danmaku-move {
		-webkit-animation-duration: 9s !important;
		animation-duration: 9s !important;
	}
	/* 与父页 play 弹幕/输入统一走 postMessage，隐藏内置发弹幕 UI，避免重复 */
	.dplayer .dplayer-comment-box,
	.dplayer .dplayer-comment,
	.dplayer .dplayer-icon.dplayer-comment-icon,
	.dplayer-setting-item.dplayer-setting-showdan,
	.dplayer-setting-item.dplayer-setting-danunlimit {
		display: none !important;
	}
	/* 嵌入 MacCMS 父页 iframe：PC 端常驻显示「网页全屏」，无需悬停 */
	html.mac-embed-player .dplayer:not(.dplayer-mobile) .dplayer-controller .dplayer-icons .dplayer-full .dplayer-full-in-icon {
		display: inline-block !important;
		position: static !important;
		top: auto !important;
	}
	/* 弹幕字黑色描边，亮底/亮字仍可读 */
	.dplayer-danmaku .dplayer-danmaku-item {
		text-shadow:
			0 1px 0 #000,
			1px 0 0 #000,
			-1px 0 0 #000,
			0 -1px 0 #000,
			1px 1px 0 #000,
			-1px -1px 0 #000,
			1px -1px 0 #000,
			-1px 1px 0 #000;
	}
	</style>
	<!-- DPlayer 资源走 CDN 加速（国内 npmmirror），CDN 未命中时同步回退本地，顺序不受影响 -->
	<link rel="preconnect" href="https://registry.npmmirror.com" crossorigin>
	<link rel="dns-prefetch" href="//registry.npmmirror.com">
	<link rel="stylesheet" href="https://registry.npmmirror.com/dplayer/1.25.0/files/dist/DPlayer.min.css" onerror="this.onerror=null;this.href='/static/player/dplayer/DPlayer.min.css?v=1.25.0'">
	<script type="text/javascript" src="https://registry.npmmirror.com/dplayer/1.27.1/files/dist/DPlayer.min.js"></script>
	<script type="text/javascript" src="/static/player/dplayer/flv.min.js"></script>
	<script type="text/javascript" src="/static/player/dplayer/hls.min.js"></script>
	<script type="text/javascript" src="/static/player/dplayer/dash.all.min.js"></script>
	<script type="text/javascript" src="/static/player/dplayer/webtorrent.min.js"></script>
	<script type="text/javascript">
	// CDN 未加载成功时同步写回本地脚本，保证下方播放器初始化时 DPlayer 一定已就绪
	if (typeof window.DPlayer === 'undefined') {
		document.write('<scr' + 'ipt type="text/javascript" src="/static/player/dplayer/DPlayer.min.js?v=1.27.1"><\/scr' + 'ipt>');
	}
	</script>
	<script type="text/javascript">
	// 本地也失败才上报播放失败，避免 CDN 回退被误判为线路故障
	if (typeof window.DPlayer === 'undefined') {
		try { parent.postMessage({ source: 'mac-player', type: 'play_fail', reason: 'script_load' }, location.origin); } catch (e) {}
	}
	</script>
</head>
<body marginwidth="0" marginheight="0">
<div id="playerCnt"></div>
<script type="text/javascript">
(function () {
	'use strict';

	var PLAYBACK_SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
	var LS_SPEED_KEY = 'mac_dplayer_playback_speed';
	var LS_QUALITY_KEY = 'mac_dplayer_quality_name';
	var playOkSent = false;
	var playFailSent = false;
	var canplayTimer = null;

	function clearCanplayTimeout() {
		if (canplayTimer) {
			clearTimeout(canplayTimer);
			canplayTimer = null;
		}
	}

	function notifyPlayOk() {
		if (playOkSent) return;
		playOkSent = true;
		playFailSent = false;
		clearCanplayTimeout();
		try {
			parent.postMessage({ source: 'mac-player', type: 'play_ok' }, location.origin);
		} catch (eOk) {}
	}

	function notifyPlayFail(reason) {
		// 播放失败（error / timeout / script_load）时收起缓冲遮罩，避免遮罩长期压住工具栏
		hideCustomBuffering();
		if (playFailSent || playOkSent) return;
		playFailSent = true;
		clearCanplayTimeout();
		try {
			parent.postMessage(
				{ source: 'mac-player', type: 'play_fail', reason: reason || 'error' },
				location.origin
			);
		} catch (eFail) {}
	}

	if (typeof DPlayer === 'undefined') {
		notifyPlayFail('script_load');
		return;
	}

	function detectVideoType(url) {
		url = String(url || '');
		if (url.indexOf('.m3u8') > -1) {
			return { type: 'hls', live: true };
		}
		if (url.indexOf('magnet:') > -1) {
			return { type: 'webtorrent', live: false };
		}
		if (url.indexOf('.flv') > -1) {
			return { type: 'flv', live: false };
		}
		if (url.indexOf('.mpd') > -1) {
			return { type: 'dash', live: false };
		}
		return { type: 'normal', live: false };
	}

	function parseQualityFromUrl(raw) {
		raw = String(raw || '').trim();
		if (!raw || raw.indexOf('$') === -1) return null;
		var sep = raw.indexOf('$$') > -1 ? '$$' : (raw.indexOf('#') > -1 ? '#' : '');
		if (!sep) return null;
		var segments = raw.split(sep);
		var out = [];
		for (var i = 0; i < segments.length; i++) {
			var seg = String(segments[i] || '').trim();
			if (!seg) continue;
			var pos = seg.indexOf('$');
			if (pos <= 0) return null;
			var name = seg.slice(0, pos).trim();
			var url = seg.slice(pos + 1).trim();
			if (!name || !url) return null;
			if (!/^https?:\/\//i.test(url) && url.indexOf('.') === -1 && url.indexOf('magnet:') !== 0) {
				return null;
			}
			out.push({ name: name, url: url });
		}
		return out.length >= 2 ? out : null;
	}

	function readSavedQualityName() {
		try {
			return String(window.localStorage.getItem(LS_QUALITY_KEY) || '').trim();
		} catch (e) {
			return '';
		}
	}

	function saveQualityName(name) {
		try {
			if (name) window.localStorage.setItem(LS_QUALITY_KEY, String(name));
		} catch (e2) {}
	}

	function resolveQualityIndex(items) {
		var pref = readSavedQualityName();
		if (pref) {
			for (var i = 0; i < items.length; i++) {
				if (items[i].name === pref) return i;
			}
		}
		return 0;
	}

	function buildVideoConfig(playUrl, pa) {
		var qualities = pa && pa.qualities && pa.qualities.length >= 2 ? pa.qualities : null;
		if (!qualities) {
			qualities = parseQualityFromUrl(playUrl);
		}
		if (qualities && qualities.length >= 2) {
			var qArr = [];
			for (var i = 0; i < qualities.length; i++) {
				var q = qualities[i];
				var dt = detectVideoType(q.url);
				qArr.push({ name: q.name, url: q.url, type: dt.type });
			}
			var defaultIdx = resolveQualityIndex(qArr);
			var picked = qArr[defaultIdx];
			return {
				url: picked.url,
				type: picked.type,
				live: detectVideoType(picked.url).live,
				quality: qArr,
				defaultQuality: defaultIdx,
			};
		}
		var dt = detectVideoType(playUrl);
		return { url: playUrl, type: dt.type, live: dt.live };
	}

	function readSavedSpeed() {
		try {
			var v = parseFloat(window.localStorage.getItem(LS_SPEED_KEY));
			if (!isNaN(v) && PLAYBACK_SPEEDS.indexOf(v) !== -1) return v;
		} catch (e) {}
		return 1;
	}

	function saveSpeed(rate) {
		try {
			window.localStorage.setItem(LS_SPEED_KEY, String(rate));
		} catch (e) {}
	}

	function applySavedSpeed(dp) {
		var rate = readSavedSpeed();
		if (!dp || !dp.video || rate === 1) return;
		try {
			dp.speed(rate);
		} catch (e) {
			try {
				dp.video.playbackRate = rate;
			} catch (e2) {}
		}
	}

	function bindSpeedMemory(dp) {
		if (!dp || !dp.video) return;
		dp.on('ratechange', function () {
			var r = dp.video ? dp.video.playbackRate : 1;
			if (PLAYBACK_SPEEDS.indexOf(r) !== -1) saveSpeed(r);
		});
	}

	function bindQualityMemory(dp) {
		if (!dp || !dp.video || !dp.options || !dp.options.video || !dp.options.video.quality) return;
		dp.on('quality_start', function () {
			try {
				var idx = typeof dp.qualityIndex === 'number' ? dp.qualityIndex : dp.options.video.defaultQuality;
				var q = dp.options.video.quality[idx];
				if (q && q.name) saveQualityName(q.name);
			} catch (e) {}
		});
	}

	var pa = {};
	try {
		pa = (window.parent && window.parent.player_aaaa) || {};
	} catch (ePa) {}
	var parentMac = {};
	try {
		parentMac = (window.parent && window.parent.MacPlayer) || {};
	} catch (eMac) {}
	// 服务端 ?url= 兜底：父页没有 MacPlayer（独立打开 dplayer.php）时也能播放
	var serverUrl = <?php echo json_encode($__dplayer_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
	var serverFrom = <?php echo json_encode($__dplayer_from, JSON_UNESCAPED_SLASHES); ?>;
	// 分享页解析失败时：直接落到原分享页，避免出现空白播放器
	var serverFallback = <?php echo json_encode($__dplayer_fallback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
	if (serverFallback) {
		try {
			location.replace(serverFallback);
		} catch (eFb) {}
		return;
	}
	// 服务端已解析出可播地址（?url= 传入的直链/分享页解析结果）时优先使用，避免父页原始分享页地址覆盖
	var playUrl = serverUrl || parentMac.PlayUrl || '';
	var videoCfg = buildVideoConfig(playUrl, pa);
	var type = videoCfg.type;
	var live = !!videoCfg.live;
	var vid = pa.id != null ? pa.id : 0;
	var sid = pa.sid != null ? pa.sid : 0;
	var nid = pa.nid != null ? pa.nid : 0;
	var dmId = String(vid) + '-' + String(sid) + '-' + String(nid);
	// jsvar 在站点装在根目录时会把 maccms.path 规范成 ''，此处不能把空路径当成「未配置」
	var root = '';
	try {
		if (parent.maccms) {
			var p = parent.maccms.path;
			if (p == null || String(p) === '') {
				p = parent.maccms.path_assets;
			}
			root = String(p != null ? p : '').replace(/\/+$/, '');
			if (root === '/' || root === '.') {
				root = '';
			}
		}
	} catch (e0) {}
	var dmEnabled = !!(dmId && dmId !== '0-0-0');

	var apiBackendMac = null;
	if (dmEnabled) {
		apiBackendMac = {
			read: function (opt) {
				if (!opt || !opt.url) {
					if (opt && opt.success) opt.success([]);
					return;
				}
				if (opt.url.indexOf('/v3/?') !== -1 || opt.url.indexOf('/v3?') !== -1) {
					if (opt.success) opt.success([]);
					return;
				}
				fetch(opt.url, { credentials: 'same-origin' })
					.then(function (r) {
						return r.json();
					})
					.then(function (a) {
						var okCode = a && (a.code === 0 || a.code === '0' || Number(a.code) === 0);
						if (okCode && a.data && Array.isArray(a.data)) {
							opt.success(
								a.data.map(function (row) {
									return {
										time: row[0],
										type: row[1],
										color: row[2],
										author: row[3],
										text: row[4],
									};
								})
							);
						} else if (opt.error) {
							opt.error(a && a.msg);
						}
					})
					.catch(function () {
						if (opt.error) opt.error();
					});
			},
			send: function (opt) {
				var d = opt.data || {};
				var parts = [
					'id=' + encodeURIComponent(d.id != null ? String(d.id) : ''),
					'time=' + encodeURIComponent(String(d.time != null ? d.time : 0)),
					'text=' + encodeURIComponent(d.text != null ? String(d.text) : ''),
					'type=' + encodeURIComponent(String(d.type != null ? d.type : 0)),
					'color=' + encodeURIComponent(d.color != null ? String(d.color) : '#FFFFFF'),
				];
				if (d.token) {
					parts.push('token=' + encodeURIComponent(String(d.token)));
				}
				fetch(root + '/api.php/danmaku/dplayer', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: parts.join('&'),
				})
					.then(function (r) {
						return r.json();
					})
					.then(function (a) {
						var ok = a && (a.code === 0 || a.code === '0');
						try {
							parent.postMessage(
								{ source: 'mac-player', type: 'danmaku_sent', ok: !!ok, msg: (a && a.msg) || '' },
								location.origin
							);
						} catch (e1) {}
						if (ok) {
							if (opt.success) opt.success(a);
						} else if (opt.error) {
							opt.error(a && a.msg);
						}
					})
					.catch(function () {
						try {
							parent.postMessage(
								{ source: 'mac-player', type: 'danmaku_sent', ok: false, msg: 'network' },
								location.origin
							);
						} catch (e2) {}
						if (opt.error) opt.error();
					});
			},
		};
	}

	var dpOpts = {
		container: document.getElementById('playerCnt'),
		autoplay: true,
		screenshot: false,
		playbackSpeed: PLAYBACK_SPEEDS,
		video: {
			url: videoCfg.url,
			live: live,
			type: type,
		},
		contextmenu: [],
	};
	if (videoCfg.quality && videoCfg.quality.length >= 2) {
		dpOpts.video.quality = videoCfg.quality;
		dpOpts.video.defaultQuality = videoCfg.defaultQuality || 0;
	}
	if (dmEnabled) {
		dpOpts.apiBackend = apiBackendMac;
		dpOpts.danmaku = {
			id: dmId,
			api: root + '/',
			addition: [root + '/api.php/danmaku/dplayer?id=' + encodeURIComponent(dmId)],
			unlimited: true,
		};
	}

	var dp;
	try {
		dp = new DPlayer(dpOpts);
	} catch (eInit) {
		notifyPlayFail('error');
		return;
	}
	applySavedSpeed(dp);
	bindSpeedMemory(dp);
	bindQualityMemory(dp);

	function markEmbedPlayer() {
		var embedded = false;
		try {
			embedded = !!(window.parent && window.parent !== window);
		} catch (eEmb) {
			embedded = true;
		}
		if (!embedded) {
			return;
		}
		// 仅标记嵌入态：PC 常驻「网页全屏」入口；不改动主全屏按钮（仍走浏览器原生全屏）
		document.documentElement.classList.add('mac-embed-player');
	}
	markEmbedPlayer();

	// 根据父页面主题配置控制画中画（Picture-in-Picture）开关
	// 默认开启；仅当 parent.MAC_PLAYER_PIP === false 时禁用浏览器原生画中画入口
	function applyPipPolicy() {
		try {
			var pipEnabled = true;
			try {
				if (window.parent && typeof window.parent.MAC_PLAYER_PIP === 'boolean') {
					pipEnabled = !!window.parent.MAC_PLAYER_PIP;
				}
			} catch (eParent) {
				// 跨域访问 parent 失败时保持默认开启
			}
			var v = dp && dp.video;
			if (v && typeof v.disablePictureInPicture !== 'undefined') {
				v.disablePictureInPicture = !pipEnabled;
			}
			// 兜底：若 video 元素尚未就绪，loadedmetadata 后再设置一次
			if (v && !pipEnabled) {
				v.addEventListener('loadedmetadata', function () {
					try { v.disablePictureInPicture = true; } catch (eM) {}
				});
			}
		} catch (ePip) {}
	}
	applyPipPolicy();

	dp.on('ended', function () {
		if (parentMac.PlayLinkNext) {
			top.location.href = parent.MacPlayer.PlayLinkNext;
		}
	});

	dp.on('waiting', function () {
		showCustomBuffering();
	});
	dp.on('seeking', function () {
		showCustomBuffering();
	});
	dp.on('canplay', function () {
		applySavedSpeed(dp);
		hideCustomBuffering();
	});
	dp.on('seeked', function () {
		hideCustomBuffering();
	});
	var bufferShowTimer = null;
	var bufferHideTimer = null;
	function showCustomBuffering() {
		// 延迟 250ms 再显示：短暂卡顿（几百毫秒的 waiting）不闪烁
		clearTimeout(bufferHideTimer);
		var existing = document.getElementById('customBuffer');
		if (existing) {
			existing.style.opacity = '1';
			armBufferTimeout();
			return;
		}
		if (bufferShowTimer) return;
		bufferShowTimer = setTimeout(function () {
			bufferShowTimer = null;
			if (document.getElementById('customBuffer')) return;
			var iframe = document.createElement('iframe');
			iframe.src = parentMac.Buffer || '/static/player/loading.html';
			// 只覆盖视频画面区：底部让出 41px 给播放器工具栏；pointer-events:none 保证控制栏仍可点击
			// 注意 iframe/img 属于替换元素，right+bottom+auto 不会拉伸，只有 300x150 默认尺寸，必须显式给宽高
			// opacity 过渡：出现/消失都淡入淡出，避免整屏一亮一灭
			iframe.style.cssText =
				'position:absolute;top:0;left:0;width:100%;height:calc(100% - 41px);z-index:999;border:none;pointer-events:none;opacity:0;transition:opacity .25s ease;';
			iframe.id = 'customBuffer';
			document.body.appendChild(iframe);
			requestAnimationFrame(function () {
				var f = document.getElementById('customBuffer');
				if (f) f.style.opacity = '1';
			});
			armBufferTimeout();
		}, 250);
	}
	var bufferTimer = null;
	var BUFFER_MAX_MS = 8000;
	function armBufferTimeout() {
		clearBufferTimeout();
		bufferTimer = setTimeout(hideCustomBuffering, BUFFER_MAX_MS);
	}
	function clearBufferTimeout() {
		if (bufferTimer) {
			clearTimeout(bufferTimer);
			bufferTimer = null;
		}
	}
	function hideCustomBuffering() {
		clearBufferTimeout();
		// 尚未真正显示就被取消：直接取消待显示任务，不产生任何闪烁
		if (bufferShowTimer) {
			clearTimeout(bufferShowTimer);
			bufferShowTimer = null;
		}
		var bufferFrame = document.getElementById('customBuffer');
		if (!bufferFrame) return;
		bufferFrame.style.opacity = '0';
		clearTimeout(bufferHideTimer);
		bufferHideTimer = setTimeout(function () {
			var f = document.getElementById('customBuffer');
			if (f) f.remove();
		}, 260);
	}

	var MSG_PARENT = 'mac-play-parent';
	var lastProgressPost = 0;
	var pendingSeekPoint = null;
	var hasUserPlayed = false;
	var canplayTimeoutMs = 15000;

	function armCanplayTimeout() {
		clearCanplayTimeout();
		playFailSent = false;
		canplayTimer = setTimeout(function () {
			notifyPlayFail('timeout');
		}, canplayTimeoutMs);
	}

	function postProgressMessage(checkpoint) {
		var video = dp.video;
		if (!video) return;
		var ct = Number(video.currentTime) || 0;
		var dur = Number(video.duration);
		if (!isFinite(dur)) dur = 0;
		if (!checkpoint) {
			var now = Date.now();
			if (now - lastProgressPost < 1500) return;
			lastProgressPost = now;
		}
		try {
			parent.postMessage(
				{
					source: 'mac-player',
					type: 'progress',
					point: ct,
					duration: dur,
					checkpoint: checkpoint === true,
				},
				location.origin
			);
		} catch (e4) {}
	}

	function canSeekNow() {
		var video = dp.video;
		return !!(video && video.readyState >= 1);
	}

	function seekToPoint(sec) {
		sec = Math.max(0, Number(sec) || 0);
		if (!canSeekNow()) {
			pendingSeekPoint = sec;
			return;
		}
		pendingSeekPoint = null;
		if (typeof dp.seek === 'function') {
			dp.seek(sec);
			return;
		}
		if (dp.video) dp.video.currentTime = sec;
	}

	function flushPendingSeek() {
		if (pendingSeekPoint == null) return;
		seekToPoint(pendingSeekPoint);
	}

	var lastPauseCheckpointAt = 0;

	function checkpointPause() {
		var video = dp.video;
		if (!video) return;
		var ct = Number(video.currentTime) || 0;
		if (!hasUserPlayed && ct < 1) return;
		var now = Date.now();
		if (now - lastPauseCheckpointAt < 400) return;
		lastPauseCheckpointAt = now;
		postProgressMessage(true);
	}

	function bindProgressReporting() {
		var video = dp.video;
		if (!video || video.__macProgressBound) return;
		video.__macProgressBound = true;
		video.addEventListener('playing', function () {
			hasUserPlayed = true;
			try {
				parent.postMessage({ source: 'mac-player', type: 'playing' }, location.origin);
			} catch (ePlay) {}
		});
		video.addEventListener('timeupdate', function () {
			postProgressMessage(false);
		});
	}

	function bindFailoverHooks() {
		dp.on('error', function () {
			notifyPlayFail('error');
		});
		dp.on('canplay', notifyPlayOk);
		dp.on('playing', notifyPlayOk);
		dp.on('loadeddata', function () {
			if (dp.video && dp.video.readyState >= 2) notifyPlayOk();
		});
		if (dp.video) {
			dp.video.addEventListener('error', function () {
				notifyPlayFail('error');
			});
		}
		armCanplayTimeout();
	}

	dp.on('pause', checkpointPause);

	dp.on('loadedmetadata', flushPendingSeek);
	dp.on('canplay', flushPendingSeek);
	dp.on('loadedmetadata', bindProgressReporting);
	bindProgressReporting();
	bindFailoverHooks();

	window.addEventListener('message', function (ev) {
		try {
			if (ev.origin !== location.origin) return;
		} catch (e) {
			return;
		}
		var d = ev.data;
		if (!d || d.source !== MSG_PARENT) return;
		if (d.type === 'init') {
			return;
		}
		if (d.type === 'failover_init') {
			canplayTimeoutMs = Math.max(5000, parseInt(d.canplayTimeoutMs, 10) || 15000);
			armCanplayTimeout();
			return;
		}
		if (d.type === 'danmaku_toggle' && dp.danmaku) {
			if (d.on !== false) {
				dp.danmaku.show();
			} else {
				dp.danmaku.hide();
			}
			return;
		}
		if (d.type === 'danmaku_submit' && d.text && dp.danmaku && typeof dp.danmaku.send === 'function') {
			dp.danmaku.send(
				{
					text: String(d.text),
					color: d.color || '#FFFFFF',
					type: d.dmType != null ? d.dmType : 0,
				},
				function () {}
			);
			return;
		}
		if (d.type === 'danmaku_push' && dp.danmaku && typeof dp.danmaku.draw === 'function') {
			dp.danmaku.draw({
				time: Number(d.time) || 0,
				type: d.dmType != null ? d.dmType : 0,
				color: d.color || '#FFFFFF',
				author: d.author || '',
				text: String(d.text || ''),
			});
			return;
		}
		if (d.type === 'progress_seek') {
			seekToPoint(d.point);
		}
	});

	function syncParentWebFullscreen(on) {
		try {
			parent.postMessage({ source: 'mac-player', type: 'web_fullscreen', on: !!on }, location.origin);
		} catch (eWf) {}
	}

	// iframe 内网页全屏只改 #playerCnt 的 class；dp.on('webfullscreen') 在部分环境不触发，改监听 class。
	var playerRoot = document.getElementById('playerCnt');
	if (playerRoot) {
		var lastWebFs = playerRoot.classList.contains('dplayer-fulled');
		var webFsObs = new MutationObserver(function () {
			var on = playerRoot.classList.contains('dplayer-fulled');
			if (on === lastWebFs) return;
			lastWebFs = on;
			syncParentWebFullscreen(on);
		});
		webFsObs.observe(playerRoot, { attributes: true, attributeFilter: ['class'] });
	}

	try {
		parent.postMessage({ source: 'mac-player', type: 'ready', player: 'dplayer' }, location.origin);
	} catch (e3) {}
})();
</script>
</body>
</html>
