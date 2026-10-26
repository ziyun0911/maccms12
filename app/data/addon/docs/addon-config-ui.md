# 插件配置界面（Config UI）

| 字段 | 值 |
|------|-----|
| 状态 | **已落地** |
| 更新 | 2026-10-27 |
| 控制器 | [`app/admin/controller/Addon.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php) → `config()`（`:31`） |
| 模板 | [`app/admin/view/addon/config.html`](file:///D:/wwwroot/maccms/maccms12/app/admin/view/addon/config.html)（构造函数 `:19` 把 `view_path` 锁到 `app/admin/view/`，插件无法替换这一页） |
| schema | `addons/{name}/config.php` |
| 实际值 | `addons/{name}/config.values.php`（**后台生成，别打进发布包**） |

> v12 的读写实现全部在 [`app/common/addon/helpers.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php)，
> 与 v10 的 `get_addon_config()` / `set_addon_fullconfig()`（写回 `config.php` 的 `value`）**不是同一套**：
> schema 与站长值分成两个文件，`config.php` 保存时永远不会被改写。

---

## 1. 一次保存发生了什么

1. 「应用 → 本地应用」列表点「配置」→ GET `admin.php/addon/config?name={name}`（`:33-45` 取名字、校验目录存在、读 `addon_info()` + `addon_full_config()`）。
2. 表单 POST 回同一页（`config.html:19-20` 带 `__token__` 与隐藏 `name`），字段名 `row[配置项]`；`checkbox` / `selects` 是 `row[配置项][]`（`config.html:65`、`:79`）。
3. 服务端顺序：一次性令牌校验（`:52-56`，失败回传新 `__token__`）→ 取 `post('row/a')`（`:58`）→ [`validateAddonConfigRows()`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L680) → [`addon_save_full_config()`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L118) 写 `config.values.php` → [`Service::refresh()`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L123) → `Cache::delete('hooks')` / `Cache::delete('addons')`（`:72-74`）。
4. schema 为空时整页只显示「无配置项」提示（`config.html:13-16`）。

读取运行时配置（**函数名以 `helpers.php` 为准**）：

```php
$cfg  = addon_config('demo');        // name => value（helpers.php:105）
$rows = addon_full_config('demo');   // 完整 schema + 合并后的 value（helpers.php:58）
$cfg  = $this->getConfig();          // 主类内（app/common/addon/Addon.php:38）
```

合并规则：先读 `config.php` 的每一行（缺 `name` 时用数组键补，`helpers.php:75-82`），再用 `config.values.php` 里同名键覆盖 `value`（`:86-98`）。**`config.values.php` 里没有的键就沿 `config.php` 的出厂 `value`**，所以升级换默认值要改 `config.php` 并留意站长的旧值会一直覆盖它。

---

## 2. 支持的 `type`

白名单在 [`Addon::validateAddonConfigRows()`:682-686](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L682)，共 14 个；不在表内的 `type` 直接保存失败。渲染分支在 `config.html:25-110` 的 `{switch}`。

| type | 控件 | 提交形态 | 落库值 | 校验（代码行号） |
|---|---|---|---|---|
| `string` | 单行文本 `:28` | 标量 | 字符串 | `required` 非空 `:754`；≤65535 字节 `:770` |
| `text` | 多行 `:33` | 标量 | 字符串 | 同上 |
| `number` | `<input type=number>` `:59` | 标量 | 字符串 | 非空时 `is_numeric` `:758` |
| `datetime` | 文本 + `datetimepicker` `:54` | 标量 | 字符串 | 同 `string`（不做日期格式校验） |
| `array` | 每个**已有键**一行输入 `:44` | 数组或 JSON 串 | **PHP 数组** | JSON 串会被 `json_decode`，非数组即报错 `:717-726` |
| `checkbox` | 多选框 `:65` | `row[k][]` | 逗号串 `a,b,c` | 每个值必须 ∈ `content` 键 `:741-747`；未提交该键 → 置 `''` `:710-712` |
| `radio` | 单选 `:72` | 标量 | 字符串 | 值必须 ∈ `content` 键 `:764-768` |
| `select` | 下拉 `:79` | 标量 | 字符串 | 同 `radio` |
| `selects` | 多选下拉 `:79` | `row[k][]` | 逗号串 | 同 `checkbox` |
| `image` / `images` | 只读输入 + 上传按钮 `:89` | 标量 | 路径字符串 | 同 `string`（不校验扩展名，见下） |
| `file` / `files` | 同上 `:98` | 标量 | 路径字符串 | 同上 |
| `bool` | 是/否 单选 `:106-107` | `'1'` / `'0'` | `'1'` / `'0'` | 归一化：仅 `1`/`true`/`on` 记 `1`，其余一律 `0` `:761-763` |

补充三条：

- **`required` 只认 `rule` 里有没有这个子串**（`:703`），前端同时挂 `lay-verify="required"`。数组与多选看的是「有没有选中项」（`:738`）。
- **未提交的字段不会被清空**：非 `checkbox`/`selects` 的键如果压根没出现在 `row` 里且不必填，就跳过不动（`:706-713`），保存时仍写回它当前值。
- `image` / `file` 只是把上传返回的路径填进文本框（上传走 `{:url('upload/upload')}?flag=addon`，`config.html:131`），**服务端不做任何 MIME / 扩展名复查**，站长手敲一个路径也能存进去。插件读到的值要自己判断。

---

## 3. 键与 schema 的约束

| 约束 | 依据 |
|---|---|
| 配置项 `name` 必须匹配 `^[a-zA-Z_][a-zA-Z0-9_]*$` | [`Addon.php:693`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L693) |
| schema 每行必须是数组且带 `name` | `:689-691` |
| `title` 留空时错误提示回落成 `name` | `:700` |
| `content` 必须是「提交值 => 展示文案」的映射 | 选项校验按 `array_key_exists` 走，`:742`/`:765` |
| `array` 类型的新增键只能改代码 | 模板按已有键渲染输入框（`config.html:39-46`），UI 上没有「加一行」按钮 |

---

## 4. 钩子与生效时机

| 场景 | 是否即时 | 说明 |
|---|---|---|
| 改配置值 | **是**（下一请求） | `addon_config()` 每次请求现读文件，进程内缓存只在 `$GLOBALS`（`helpers.php:65`/`:136`） |
| 启用 / 禁用 | **是** | `Service::enable/disable` 回写 `info.ini` 的 `state` 并 [`AddonManager::reset()`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonManager.php#L84) |
| 主类新增 / 删除钩子方法 | **是，不需要 refresh** | 调度是每次请求现场反射（`AddonManager::handlers()` `:65-78`）；`app/extra/addons.php` 的 `hooks` 表写了但没有任何读取方 |
| 改 `install.sql` / 静态资源 | **否** | 属安装 / 启用副作用，不是配置页职责 |

配置页始终会调 `refresh()`（`:72`），但那只是维护登记表与清缓存；**插件处于禁用态时钩子不会被调度**，先启用再说。

---

## 5. 安全与运维

- CSRF：`app\common\validate\Token` 一次性令牌，成功/失败都回传新 `__token__`（`:52-56`、`:65`、`:76`）。
- 启停 / 卸载必须是 POST，且都要带令牌（`state()` `:450-455`、`uninstall()` `:410-414`）。
- 保存值写的是插件目录内的 `config.values.php`，因此**后台账号即等于对该插件有任意写权限**；不要指望这页帮你脱敏密钥。
- 站点文档页面（后台「网站参数 → 说明文档」）渲染本文用的是 `<pre>`，Markdown 会以源码显示；要读排版请在 IDE / Git 平台里看这些 `.md`。

开发者视角的 schema 全貌与生命周期仍在 [`addon-developer.md`](addon-developer.md)。
