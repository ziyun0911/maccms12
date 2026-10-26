# 模板管理、广告位、标签向导

顶栏 **模版** 分组（语言键 `menu/templates`）可见入口 6 个：模板管理 `template/index`、广告位管理 `template/ads`、标签向导 `template/wizard`、主题配置 `tpl_config/theme`、主题设计 `theme_design/design`、模板市场 `template_market/index`。

## 模板管理 (`模版 → 模板管理` / `template/index`)

### 磁盘路径

- 物理路径：`站点根/template/{模板目录}/`，PC 模板名取 **`maccms.site.template_dir`**、HTML 子目录 **`site.html_dir`**（默认 `html`）、广告片段 **`site.ads_dir`**（默认 `ads`）；WAP 另有 **`site.mob_template_dir` / `mob_html_dir` / `mob_ads_dir`**（[app/extra/maccms.php:70-84](file:///d:/wwwroot/maccms/maccms12/app/extra/maccms.php#L70-L84)）。  
- 列表页三个快捷过滤：`?current=1` 只看当前模板、`?label=1` 只看 `html/label/`、`?ads=1` 只看 `html/ads/`；路径用 `.@template@…` 形式传递，前缀不合法直接 `illegal_request`（[Template.php:12-50](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Template.php#L12-L50)）。

### 两个必须先知道的坑

1. **在线编辑页是隐藏节点**：点文件进的是 `template/info`（`show=0`，[auth.php](file:///d:/wwwroot/maccms/maccms12/app/admin/common/auth.php) 里 `admin/perm/template_info`），删除走 `template/del`。**只勾「模板管理」不给这两项，非超管打开文件就是「权限不足」**。  
2. **在线保存有内容黑名单，绝大多数模板存不回去**：保存前先用 `mac_reg_replace()` 把一大串关键字替换成 `*`，只要原文被改动就报 `admin/template/php_safe_tip` 并拒绝写入（[Template.php:172-190](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Template.php#L172-L190)）。黑名单里含 `php file config input get post request session cookie {$ {: {/ {+ {-` 等 **任意子串**，而 ThinkPHP 标签恰好是 `{$var}`、`{:func()}`、`{include file=…}`。实测当前自带模板 **12 个里 11 个通不过**（只有纯静态的 `blurb.html` 能存），所以：  
   - **改模板请走 FTP / 本地编辑器**，在线编辑只适合写不含标签的纯 HTML 片段；  
   - 这不是 bug 提示，改不了就别硬改，也别去关这个过滤。  
3. 白名单扩展名只有 **`html / htm / js / xml`**，**`.css` 不在里面**；路径必须以 `./template` 开头且只允许出现两次 `./`（[Template.php:161-168](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Template.php#L161-L168)）。样式文件在 `template/{模板}/asset/` 里自行维护。

### 操作步骤

1. **网站参数 → 网站参数（基本设置页签）**：确认 **PC/WAP 模板目录名**。  
2. **模板管理**：定位文件后 **FTP 下载 → 本地修改 → 覆盖上传**（或极少数纯 HTML 文件用在线编辑）。  
3. 用 **标签向导**（见下）复制 **标准标签**，避免手写错字段名。  
4. 改后 **顶栏「⋯」→ 清理缓存**（`index/clear`，模板编译缓存就在 `runtime/temp`）；若使用静态或页面缓存还要 **重新生成** 并 **Ctrl+F5**。

### 常见坑

- 把 **业务逻辑** 写进模板导致难维护——建议保持 **展示层**。  
- **相对路径** 与 `install_dir` 不一致导致 **CSS 404**。  
- **JS 引用 HTTP** 在 HTTPS 站被拦。  
- 前台静态资源是打包产物：改 `static/**` 源文件后要 `php think front:bundle` 重建，见「模板修复与试用」章节及说明文档 06。

---

## 广告位管理 (`模版 → 广告位管理` / `template/ads`)

- 维护 `template/{模板目录}/{html目录}/ads/` 下的 **广告片段文件**，模板里通过标签或 `{include}` 调用；页面提供 `?ads=1` 快捷过滤。  
- 统计、轮播、代码广告（联盟 JS）注意 **XSS**：仅信任来源。

---

## 标签向导 (`模版 → 标签向导` / `template/wizard`)

- 可视化生成 **列表/详情/导航/留言/评论** 等标签代码（`Template::wizard`，[Template.php:231](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Template.php#L231)）。  
- **以向导输出为准**：手写容易把字段名与参数拼错；复制后再进编辑器。  
- 完整的标签参数表在仓库 **`说明文档/`**（后台 **网站参数 → 说明文档**，`doc/index` 也能读）。

---

## English summary

The **Templates** bar has six visible entries: template manager, ad slots, tag wizard, theme config, theme designer and template marketplace. Two traps matter most. First, opening a file goes to the **hidden `template/info` node** (delete is `template/del`), so granting only "template manager" leaves non-super admins with *permission denied*; grant all three. Second, saving through the web editor runs the content through a blacklist (`php`, `file`, `config`, `input`, `{$`, `{:`, `{/`, …) and rejects the file if anything would be stripped — which is **11 of the 12 bundled templates**, since ThinkPHP tags are exactly `{$var}` / `{:func()}` / `{include file=…}`. So edit via **FTP/local editor**, and reserve the online editor for plain HTML fragments. Only `html/htm/js/xml` are editable (not `.css`), paths must stay under `./template`, and after changes wipe the cache (`index/clear`, compiled templates live in `runtime/temp`) plus rebuild static bundles with `php think front:bundle`.
