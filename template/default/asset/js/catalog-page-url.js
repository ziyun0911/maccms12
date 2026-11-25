/* 分类页 AJAX 分页的地址同步：
 * 列表通过 API 翻页时，把浏览器地址同步成对应页码的规范地址，
 * 使“第 2 页”不再停留在第 1 页的 URL 上。
 * 规则：/vod/type/1.html（第 1 页）、/vod/type/1-2.html（第 2 页）。
 * 由 type-show.js 在翻页回调里调用 window.__macCatPageUrl(el, page)。
 */
(function (w) {
  function baseOf(el) {
    var attr = (el && el.getAttribute && el.getAttribute('data-page-base')) || '';
    attr = String(attr).trim();
    if (attr) { return attr; }
    // 没有显式基准时，退化为当前地址去掉页码段
    return String(w.location.pathname || '/').replace(/-\d+\.html?$/i, '.html');
  }

  w.__macCatPageUrl = function (el, page) {
    try {
      page = parseInt(page, 10) || 1;
      var base = baseOf(el);
      if (!base) { return; }

      var url;
      if (/\.html?$/i.test(base)) {
        // 带后缀的规范地址：/vod/type/1.html -> /vod/type/1-2.html
        var stem = base.replace(/-\d+\.html?$/i, '').replace(/\.html?$/i, '');
        url = page > 1 ? (stem + '-' + page + '.html') : (stem + '.html');
      } else {
        // 目录式地址（如 /actor/show/）：保留当前非 page 筛选参数，仅叠加页码
        var sp = new URLSearchParams(w.location.search || '');
        sp.delete('page');
        if (page > 1) { sp.set('page', String(page)); }
        var qs = sp.toString();
        url = base.split('?')[0] + (qs ? '?' + qs : '');
      }

      if ((w.location.pathname + w.location.search) !== url) {
        w.history.pushState({ macCatPage: page }, '', url);
      }
    } catch (e) { /* 忽略：不阻断翻页 */ }
  };
})(window);
