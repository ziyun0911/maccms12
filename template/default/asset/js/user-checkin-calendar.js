/*
 * 签到日历（新增）
 * 位置：签到按钮与签到规则之间。
 * 数据来源：复用签到页已有的 get_sign_info 接口（data-url-get-sign-info）。
 *   - info.serial_days / info.is_signed_today  连续天数、今日是否已签
 *   - info.month_dates  后端返回的本月真实签到日期数组（"YYYY-MM-DD"，来自 SignLog::getSignInfo）
 *   - info.sign_dates / info.sign_data  兼容旧字段名
 * 优先按真实日期数组精确打勾；仅当接口未返回任何日期数组时，才退化用 serial_days 推导。
 */
(function () {
  "use strict";

  var root = document.getElementById("mac-user-checkin-root");
  if (!root) return;

  var apiUrl = root.getAttribute("data-url-get-sign-info");
  var TODAY = new Date();
  var viewYear = TODAY.getFullYear();
  var viewMonth = TODAY.getMonth(); // 0-11

  var SERIAL = 0;        // 连续签到天数（仅日历推导兜底用，里程碑判定已改用累计次数）
  var SIGNED_TODAY = false;
  var SIGN_DATES = [];   // 后端真实签到日期数组（"YYYY-MM-DD"），优先于 serial 推导
  var HAS_REAL_DATES = false; // 接口是否返回了真实日期数组
  var MONTH_SIGNS = null;     // 后端返回的本月签到总数（month_signs），用于汇总显示

  function pad(n) { return n < 10 ? "0" + n : "" + n; }
  function ymd(y, m, d) { return y + "-" + pad(m + 1) + "-" + pad(d); }

  // 由 serial_days + is_signed_today 推导「连续签到」日期集合（仅作无真实日期数组时的兜底，里程碑判定已改用累计次数）
  function deriveSignedSet() {
    var set = {};
    var base = new Date(TODAY.getFullYear(), TODAY.getMonth(), TODAY.getDate());
    var n = SERIAL;
    if (SIGNED_TODAY) {
      // 今日已签：今天 + 往前 (n-1) 天
      for (var i = 0; i < n; i++) {
        var dt = new Date(base);
        dt.setDate(base.getDate() - i);
        set[ymd(dt.getFullYear(), dt.getMonth(), dt.getDate())] = true;
      }
    } else if (n > 0) {
      // 今日未签但仍有连续天数：截止到昨天往前 n 天
      for (var j = 0; j < n; j++) {
        var d2 = new Date(base);
        d2.setDate(base.getDate() - (j + 1));
        set[ymd(d2.getFullYear(), d2.getMonth(), d2.getDate())] = true;
      }
    }
    return set;
  }

  function render() {
    var grid = document.getElementById("mac-checkin-cal-grid");
    var monthLabel = document.getElementById("mac-checkin-cal-month");
    var summary = document.getElementById("mac-checkin-cal-summary");
    if (!grid) return;

    var first = new Date(viewYear, viewMonth, 1);
    var startWeekday = first.getDay(); // 0=周日
    var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

    monthLabel.textContent = viewYear + "年" + (viewMonth + 1) + "月";

    var derived = deriveSignedSet();
    var realSet = {};
    SIGN_DATES.forEach(function (s) { realSet[s] = true; });

    grid.innerHTML = "";

    // 前置空白格，对齐星期
    for (var b = 0; b < startWeekday; b++) {
      var blank = document.createElement("div");
      blank.className = "uc-cal-cell uc-cal-cell--blank";
      grid.appendChild(blank);
    }

    var signedCount = 0;
    var isCurrentMonth = (viewYear === TODAY.getFullYear() && viewMonth === TODAY.getMonth());

    for (var d = 1; d <= daysInMonth; d++) {
      var cell = document.createElement("div");
      cell.className = "uc-cal-cell";
      var dateStr = ymd(viewYear, viewMonth, d);
      var cellDate = new Date(viewYear, viewMonth, d);
      var isFuture = cellDate > TODAY;

      // 是否已签：优先后端真实日期数组（含跨月），否则用 serial 推导（仅当前月）
      var signed = HAS_REAL_DATES ? !!realSet[dateStr] : (isCurrentMonth && !!derived[dateStr]);

      if (isFuture) cell.classList.add("uc-cal-cell--future");
      if (isCurrentMonth && d === TODAY.getDate()) cell.classList.add("uc-cal-cell--today");

      if (signed) {
        cell.classList.add("uc-cal-cell--signed");
        if (isCurrentMonth) signedCount++;
        cell.innerHTML = '<span>' + d + '</span><span class="uc-cal-ico">&#10003;</span>';
      } else {
        cell.textContent = d;
      }
      grid.appendChild(cell);
    }

    if (isCurrentMonth) {
      // 汇总优先用后端权威的 month_signs；无该字段时回退按格子统计
      var total = (MONTH_SIGNS != null) ? MONTH_SIGNS : signedCount;
      summary.innerHTML = "本月已签到 <b>" + total + "</b> 天" +
        (SIGNED_TODAY ? "，今日已签 &#10003;" : "");
    } else {
      summary.textContent = "仅当前月展示签到记录";
    }
  }

  function bindNav() {
    var prev = document.getElementById("mac-checkin-cal-prev");
    var next = document.getElementById("mac-checkin-cal-next");
    if (prev) prev.addEventListener("click", function () {
      viewMonth--;
      if (viewMonth < 0) { viewMonth = 11; viewYear--; }
      render();
    });
    if (next) next.addEventListener("click", function () {
      viewMonth++;
      if (viewMonth > 11) { viewMonth = 0; viewYear++; }
      render();
    });
  }

  // 重新拉取签到信息并就地刷新日历（保留当前查看的月份，不重置视图）
  function refresh() {
    if (!apiUrl) { render(); return; }
    fetch(apiUrl, {
      method: "GET",
      cache: "no-store",
      credentials: "include",
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(function (r) { return r.json(); })
      .then(function (u) {
        if (u && u.code === 1 && u.info) {
          var g = u.info;
          SERIAL = Number(g.serial_days) || 0;
          SIGNED_TODAY = !!g.is_signed_today;
          // 后端真实字段优先：month_dates（兼容 sign_dates / sign_data）
          var real = g.month_dates || g.sign_dates || g.sign_data;
          HAS_REAL_DATES = Array.isArray(real);
          SIGN_DATES = HAS_REAL_DATES ? real : [];
          MONTH_SIGNS = (g.month_signs != null) ? Number(g.month_signs) : null;
        }
        render();
      })
      .catch(function () { render(); });
  }

  function load() {
    bindNav();   // 翻页事件只绑定一次，避免签到后重复刷新时累加监听
    refresh();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", load);
  } else {
    load();
  }

  // 签到成功后由 user-checkin.js 派发此事件，触发日历无刷新更新
  document.addEventListener("mac:checkin:changed", function () {
    refresh();
  });
})();

/* ===== 签到规则第 4 条：把 {site_name} 替换为模板输出的真实站点名 =====
   原因：strings_zh.properties 由 jQuery.i18n 同步加载，是静态文件，
   {$maccms.site_name} 写进去不会被模板引擎解析，只会显示成字面文本。
   因此模板把站点名放在 .uc-rules[data-site-name] 上，这里在规则列表
   渲染后替换占位符；用 MutationObserver 兼容 i18n 回调与 AJAX 渲染时序。 */
(function () {
  var PH = "{site_name}";

  function getSiteName() {
    var el = document.querySelector(".uc-rules[data-site-name]");
    return el ? String(el.getAttribute("data-site-name") || "").trim() : "";
  }

  function patch(scope) {
    var root = scope || document;
    if (!root.querySelectorAll) return;
    var items = root.querySelectorAll("#mac-checkin-rules-list li, .uc-rules__list li");
    for (var i = 0; i < items.length; i++) {
      var li = items[i];
      // 已替换过则跳过，避免 MutationObserver 自我触发导致死循环
      if (li.textContent.indexOf(PH) === -1) continue;
      // 站点名为空时替换成空串，降级回「本活动解释权归平台所有」
      li.textContent = li.textContent.split(PH).join(getSiteName());
    }
  }

  function start() {
    patch(document);
    var list = document.getElementById("mac-checkin-rules-list");
    if (!list || !window.MutationObserver) return;
    new MutationObserver(function () { patch(list); })
      .observe(list, { childList: true, subtree: true, characterData: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
