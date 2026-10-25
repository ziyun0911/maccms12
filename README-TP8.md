# 苹果 CMS v12（maccms12）— ThinkPHP 8 升级版

> 由 maccms10（ThinkPHP 5.1）整树迁移、重写升级而来。核心框架已实装 **ThinkPHP 8.1.4**，功能与数据结构保持兼容。

---

## 一、运行环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | **≥ 8.0.2**（本环境验证使用 PHP 8.3.8） |
| 数据库 | MySQL 5.7 / 8.0+ |
| Web 服务器 | Nginx / Apache / PHP 内置服务器均可 |
| 扩展 | PDO、pdo_mysql、GD、curl、openssl 等常用扩展 |

---

## 二、入口文件

| 入口 | 访问地址 | 说明 |
| --- | --- | --- |
| 前台 | `http://<域名>/index.php` | 站点前台首页 |
| 后台 | `http://<域名>/admin.php` | 管理后台登录 |
| 定时任务 | `http://<域名>/api.php/timming/index.html` | API 计划任务 |
| 安装程序 | `http://<域名>/install.php` | 全新安装（已安装后访问提示已锁） |

> 使用 Nginx 时建议配置 PATHINFO 伪静态（`try_files $uri $uri/ /index.php?s=$uri&$args;`），使 URL 更友好；当前内置服务器可直接访问。

---

## 三、数据库配置

数据库配置文件位于 `config/database.php`，当前环境为：

- 主机：`127.0.0.1`
- 端口：`3306`
- 库名：`maccms12`（84 张表，含内容语言表 mac_content_lang）
- 用户名：`root`
- 密码：不入库、不写入文档，取本机 `config/database.php`（该文件已在 `.gitignore` 内）
- 表前缀：`mac_`
- 编码：`utf8mb4`

> 配置为 TP8 原生结构，连接参数统一位于 `connections.mysql`；代码中通过 `config('database.connections.mysql.*')` 或 `Db::getConfig('connections.mysql.*')` 读取，不存在 TP5 顶层镜像键。

---

## 四、默认后台账号

- 账号：`admin`
- 密码：不写入文档；本地测试站的取值见部署笔记，正式环境务必改掉
- 验证码配置：`app/extra/maccms.php` → `admin_login_verify`（`1` 开启 / `0` 关闭）

---

## 五、TP8 兼容性改造清单（相对 maccms10）

1. **目录结构**：`application/` → `app/`；模块 `admin` / `api` / `index` / `install` 按 TP8 多应用布局迁移。
2. **入口引导**：`index.php` / `admin.php` / `api.php` / `install.php` 重写为 TP8 引导并修复 PATHINFO 解析。
3. **行为迁移**：12 个 `behavior/*`（TP5 行为）迁为 `middleware/*`（TP8 中间件）。
4. **公共层**：`app/common.php` 仅保留 `mac_load_extra()`（将 `app/extra/*.php` 注册为独立配置组，等价 TP8 的 extra 配置扩展）；已移除 `mac_config_set()`、`model()`、`THINK_VERSION`、`class_alias` 等 TP5 兼容助手。
5. **控制器基类**：`Jump` 的 `redirect()` 改为先用 `url()` 拼装参数再跳转（TP8 `redirect(string,int)` 不再收 params）。
6. **表前缀占位符**：`__ANALYTICS_SESSION__` / `__COUPON__` / `__USER__` 等 `__TABLENAME__` 占位符（think-orm 不解析）替换为实际表名（`mac_analytics_session` 等）；读取前缀统一走 `connections.mysql.prefix`。
7. **标签库**：`app/common/taglib/Maccms.php` 9 处 `paging=='yes'` 增加空值防御。
8. **后台视图路径**：`view` 下 352 处 `../../../application/` → `../../../app/`；中间件按入口设置 `view.view_dir_name`。
9. **路由完整匹配**：`config/route.php` 开启 `route_complete_match => true`，避免含可选变量的规则（如 `actor-【page】`）前缀匹配劫持 detail/type 等其他路由。
10. **查询构造器**：`app/common/db/Query` 继承 `think\db\Query`，兼容旧代码中 `limit('offset,length')` / `page('x,y')` 的字符串写法，不改动 vendor 源码。
11. **插件体系**：`app/common/addon`（Service / AddonManager / helpers）为 TP8 原生实现，插件命名空间 `addons\` 映射 `/addons`，支持动态挂载、启停、配置与跨模块视图布局；插件 SQL 使用 `__PREFIX__` 占位符。
12. **入口路由挂载**：各入口通过 `$app->http->name(...)` 绑定应用；插件路由由 `route/addons.php` 及各应用 `route/addons.php` 加载。

### 清理项（相对 maccms10 遗留）

- 删除 TP5 惰性死代码：`app/common/behavior/*`（12 个，已迁为中间件）、`app/tags.php`
- 删除无用文件/目录：`theme-request-form/`、`tests/`、`storage/`（空）
- 删除 `tp5_buding/` 兼容补丁包：三处 vendor 补丁已回滚，`vendor/` 保持 Composer 原样（`composer update` 后无需再打补丁）
- `static_new/` 已并入 `static/`（同名以 static_new 版本覆盖，保留目录名 `static`），代码中 17 处 `static_new` 引用统一改为 `static`
- `middleware/Init.php` 中 memcache/memcached 动态配置改用 TP8 `Config::set([...], 'cache')` 整组写入

---

## 六、目录/文件说明

| 路径 | 说明 |
| --- | --- |
| `app/` | TP8 应用目录（common / admin / api / index / install） |
| `app/extra/` | 运行期生成的可覆盖配置（maccms.php、version.php 等） |
| `app/common/middleware/` | 迁入的中间件 |
| `config/` | TP8 全局配置（app / database / cache / view …） |
| `template/` | 前台模板（default / conch / datll_Layui_v1，当前默认 conch） |
| `app/admin/view/` | 后台视图 |
| `static/` | 站点静态资源（static 与 static_new 已合并） |
| `说明文档/` | 按官方 maccms10「说明文档」结构整理的 maccms12 详细说明文档（API/伪静态/多语言/定时任务/标签/火车头/站外入库，见《00-说明文档总览.txt》） |
| `route/addons.php` | 插件公共路由（各应用入口在绑定后加载） |
| `addons/` | 插件目录（PSR-4 命名空间 `addons\`） |
| `vendor/` | Composer 依赖（保持官方原样，无兼容补丁） |
| `runtime/` | 运行缓存与日志 |

---

## 七、说明文档

按官方 maccms10 GitHub「说明文档」目录结构建立了 maccms12 详细说明文档，位于项目根 `说明文档/`：

- 接口对接 / 二次开发 → `API接口说明V2.txt`（含 maccms12 独有补充接口第十章）、`API接口说明.txt`（老格式）
- 火车头等第三方入库 → `站外入库接口说明.txt` + `火车头模块/`（6 个 .wpm，兼容）
- 伪静态部署 → `伪静态规则/`（Apache / IIS6 / IIS7+ / Nginx + rewrite 说明 + 默认路由规则）
- 模板开发 → `标签说明.txt`（模板结构 / 全局与模块标签 / 处理函数 / JS 类处理）
- 采集调度 → `定时任务说明.txt`（api.php/timming + 后台执行文件）
- 多语言 → `多语言支持说明.txt`（界面语言包）；`内容级多语言说明.txt`（内容级多语言，maccms12 已按官方 master 蓝本完整实现）
- 模板排查 / 换模板 → `模板修复与试用说明.txt`（conch / datll_Layui_v1 修复记录与验收）
- 导航与差异摘要 → `00-说明文档总览.txt`

> 文档内容已与 maccms12 代码库/数据库逐项核对；官方文档中 maccms12 未实现的功能（Novel 小说、Sycms）均已醒目标注；内容级多语言为 maccms12 已实现功能。

## 八、部署提示

- 正式部署请将站点根目录指向本目录，并将 `install.lock`（`app/data/install/install.lock`）保留以锁定安装。
- 后台入口建议改名或加访问限制（`admin.php`）。
- `addons`、`extend` 插件目录结构与 maccms10 保持一致，插件升级依赖另行安装（如 workerman）。

---

*本文档为 maccms12（TP8）交付说明。原 maccms10 站点与数据库未做任何改动。*
