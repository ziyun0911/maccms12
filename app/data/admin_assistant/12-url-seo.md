# URL 地址配置 & SEO 参数配置

## URL 地址配置 (`网站参数 → URL地址配置` / `menu/configurl`)

### 作用

- 定义 **视频、文章、专题、演员、角色、剧情、漫画** 与 **标签 / 单页** 的路径模式（如 `/vod/…`、`/art/…` 或带 ID/拼音）。可写字段就是表单里的 `path[…]`：`vod_type`/`vod_detail`/`vod_play`/`vod_down`/`vod_plot`/`vod_role`、`art_type`/`art_detail`、`topic_index`/`topic_detail`、`actor_index`/`actor_detail`、`role_index`/`role_detail`、`plot_index`/`plot_detail`、`manga_type`/`manga_read`/`manga_down`、`label`、`page_sp`、`suffix`。  
- **网址（website）模块没有 URL 字段** —— 别按旧版说明去这一页找「网址 URL 模式」。  
- 与 **网站参数** 中的 **`pathinfo_depr`**（路径分隔符）、**`path[suffix]`**（伪静态后缀如 html）配合。  
- **伪静态 / Rewrite** 必须在 **Nginx** 或 **Apache** 写入 **官方规则**，否则 404 或参数无法解析；本页保存时还会 **重写 `app/index/route/rewrite.php`**。

### 操作步骤（概念）

1. 后台 **网站参数 → 网站参数** 切到 **「URL地址配置」页签**，为各模块填 **路径模板字符串**（`path[vod_type]`、`path[vod_detail]`、`path[art_detail]` …）与伪静态后缀。  
2. 将程序包或文档中的 **rewrite 规则** 贴入虚拟主机。  
3. **重启 Web 服务** 或 `nginx -t && reload`。  
4. 前台逐项点开 **列表页、详情页、搜索页**。  
5. **不要**在生产频繁改 URL 形态；如需改版，规划 **301 跳转**。

### 常见故障

- **整站 404**：rewrite 缺失或 `install_dir` 与真实子目录不符。  
- **部分模块 404**：该模块路由未保存或 nginx location 优先级覆盖。  
- **双斜杠或重复前缀**：模板中链接标签与 URL 规则重复拼接。

---

## SEO 参数配置 (`网站参数 → SEO参数配置` / `menu/configseo`)

- 它是 **「网站参数」页里的一个页签**（`lay-id="config_seo"`，[config.html:20](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config.html#L20)），菜单项 `system/configseo` 只是个 302 跳板。  
- **页签内还有 6 个子页**：视频首页 / 文章首页 / 演员首页 / 角色首页 / 剧情首页 / 网址首页 SEO（[config_embed_seo.html:3-8](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config_embed_seo.html#L3-L8)）。每个子页固定三项 **标题 `seo[<mod>][name]` / 关键字 `seo[<mod>][key]` / 描述 `seo[<mod>][des]``**，共 18 个输入框。  
- **这是纯文案，不是模板**：填什么前台就原样输出，供 `{$maccms.seo.vod.name}` 之类变量读取（[All.php:287](file:///d:/wwwroot/maccms/maccms12/app/common/controller/All.php#L287)）；只作用于 **各模块首页**（页签内提示写着 `vod/index`、`art/index`）。  
- **详情页的 TDK 不在这里**：由 **AI SEO** 逐条写入 `seo_title / seo_keywords / seo_description`，前台按 `page_detail_*` 输出，缺省时才回退默认值（[All.php:454-456](file:///d:/wwwroot/maccms/maccms12/app/common/controller/All.php#L454-L456)）。  
- 这一页 **不管 Sitemap、不管 RSS、不管推送**：**Sitemap** 走 **模版 → 生成地图**（`make/map`，[Make.php:214](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Make.php#L214)）；**搜索引擎推送** 另有 **网站参数 → Web推送配置**（`system/configpush`）与 **应用 → URL推送**（`urlsend/index`）。  
- 若使用 **静态页**，SEO 字段在 **生成** 时写入 HTML；动态模式则运行时替换。

---

## URL 推送 (`menu/urlsend` → **应用 → URL推送**，`urlsend/index`)

- 向 **百度必应等** 主动提交 URL（需配置令牌/接口，按页面说明）。  
- 不等于 SEO 全部工作，仅 **收录加速辅助**。

---

## English summary

**configurl** is a tab inside the website-parameter page: `path[…]` templates plus the static suffix — and it rewrites `app/index/route/rewrite.php` on save, so the web-server rules must match. **configseo** is also a tab: six sub-tabs holding fixed title/keywords/description **text** (no placeholders) for the vod / art / actor / role / plot / website **index** pages only; detail-page TDK comes from the per-record AI SEO fields, and this page does not touch sitemap, RSS or push (sitemap = 模版 → 生成地图 `make/map`; push = 网站参数 → Web推送配置 and 应用 → URL推送). Changing URLs breaks old links unless you redirect.
