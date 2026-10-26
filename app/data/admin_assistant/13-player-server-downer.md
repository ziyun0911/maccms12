# 播放器、服务器组、下载器

三张页面是同构的：**视频 → 播放器**（`vodplayer/index`）、**视频 → 服务器组**（`vodserver/index`）、**视频 → 下载器**（`voddowner/index`）。三者的记录分别写进 **`app/extra/vodplayer.php` / `vodserver.php` / `voddowner.php`**（键名即控制器 `$this->_pre`），**不是数据库表**。每张页面还有 `info / del / field` 三个 `show=0` 节点，要在权限树里单独勾选。

## 播放器 (`视频 → 播放器` / `vodplayer/index`)

### 字段

| 表单项 | 键 | 说明 |
|--------|----|------|
| 状态 | `status` | 关闭的播放器在视频编辑下拉与前台播放源里 **都不出现**（`Vod.php:918-921` 按 `status=1` 过滤）。 |
| 编码 | `from` | 唯一标识；**纯数字会自动补 `_`，禁止出现 `.` `/` `\`**；**编辑时只读，建错只能删了重建**（[Vodplayer.php:36-42](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Vodplayer.php#L36-L42)、[info.html:24](file:///d:/wwwroot/maccms/maccms12/app/admin/view/vodplayer/info.html#L24)）。 |
| 名称 | `show` | 前台显示的中文播放器名。 |
| 备注 | `des` | 后台备注。 |
| 排序 | `sort` | **数值越大越靠前**（页签提示原文如此，列表按 `SORT_DESC` 排，[Vodplayer.php:46-51](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Vodplayer.php#L46-L51)）。⚠️ 前台是否按它重排还取决于 **`app.player_sort`**：`=1`（出厂值）或非后台入口/静态生成时才 `SORT_DESC` 重排，`=0` 则保持视频里线路的原始顺序（[common.php:1907-1915](file:///d:/wwwroot/maccms/maccms12/app/common.php#L1907-L1915)）。 |
| 窗口 | `target` | `_self` / `_blank`。 |
| 解析状态 | `ps` | 是否走独立解析。 |
| 接口地址 | `parse` | **独立解析接口，权重高于全局播放器设置的接口**。 |
| 说明 | `tip` | 表单提示文案。 |
| 播放器代码 | `code` | 单独一个 **「播放器代码」页签**，保存时写进 **`./static/player/<from>.js`**（[Vodplayer.php:58](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Vodplayer.php#L58)）。 |

若开启内容多语言，表单会带 `content_lang_enabled` 与各语言标签页，留空即沿用默认语言。

### 配置步骤

1. **网站参数 → 资源、链接与播放**（`system/configplay`）：全局播放器策略与接口，和单条播放器的 `parse` 配合。  
2. **视频 → 播放器**：**添加**，填编码/名称/排序，需要自定义切页时再在「播放器代码」页签粘贴 JS。  
3. 在 **添加视频** 时，**播放来源** 选对应播放器编码；多线路用 **`$$$`** 分隔，线路内多集用 **`#`** 分隔，格式为 `剧集名称$地址[$播放器]`（详见说明文档 03）。  
4. 前台打开详情页，**F12 控制台** 查跨域、混合内容 (HTTPS/HTTP)、广告拦截。

### 常见问题

- **改了播放器配置但前台没变**：`./static/js/playerconfig.js` 是 **派生产物**，由顶栏「⋯ → 清理缓存」（`Base::_cache_clear`，[Base.php:130-171](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Base.php#L130-L171)）或 **网站参数 → 资源、链接与播放** 保存时重建；清完还要 **强刷浏览器 / 清 CDN**。  
- **黑屏**：播放器代码与地址格式不匹配；或 **HTTPS 页加载 HTTP 资源** 被拦。  
- **只能播一条**：多集拼接符错误或多组字段未填满。  
- **前台播放源顺序不对**：核对 `sort`（大的在前）与 `status`。  
- **版权提示**：`maccms.app.copyright_notice` 等开关与业务策略。

---

## 服务器组 (`视频 → 服务器组` / `vodserver/index`)

- 只有 `status / from / show / des / sort` 五项，作用是给播放线路起 **代号与显示名**（如「线路一」「蓝光」）。  
- 视频里 **播放服务器** 字段填的字符串要 **等于这里的编码**，前台才会带上 `server_info`（[common.php:1855-1893](file:///d:/wwwroot/maccms/maccms12/app/common.php#L1855-L1893)）；对不上只是没有显示名，**不会导致播不了**。  
- 同样按 `sort` 降序、`status=1` 过滤，并只把 `show / des` 导出进 `playerconfig.js`。

---

## 下载器 (`视频 → 下载器` / `voddowner/index`)

- 字段与播放器 **完全一致**（含 `target / ps / parse / sort`，**没有** 代码页签），供 **下载线路**（`vod_down_*`）使用。  
- 前台取下载源时读的是 `config('voddowner')` 而非 `config('vodplayer')`（[common.php:1875-1881](file:///d:/wwwroot/maccms/maccms12/app/common.php#L1875-L1881)）—— 所以 **下载器里没启用的编码，下载列表就不显示**。

---

## English summary

Players, server groups and downloaders are three sibling pages (`vodplayer` / `vodserver` / `voddowner`) that persist to **`app/extra/*.php`, not the DB**. `sort` is **descending** (bigger = first) and only `status=1` rows reach the video form or the frontend. A player's `from` code is **immutable after creation**, must not contain `.` `/` `\`, and gets `_` appended if purely numeric. Each player can carry its own JS, saved to `./static/player/<code>.js`, and `parse` overrides the global parser URL. After any change, wipe the cache so **`static/js/playerconfig.js`** is regenerated, then hard-refresh the browser. Server groups only decorate play lines whose `server` string matches their code; downloaders mirror players but feed the download source list. Fix playback issues first in the **browser console**, then URL scheme (HTTPS), then parser code.
