# `app/extra/` 目录说明

> 本目录是 **运行期可覆盖配置**：每个 `.php` 文件返回一个数组，被注册成同名配置组，代码里用 `config('<文件名>.<键>')` 读。
> 目录里有 16 个 `.php` + 本说明。**配置项逐键的作用与后台入口在 [说明文档/02-站点配置参考.md](file:///D:/wwwroot/maccms/maccms12/说明文档/02-站点配置参考.md)**，
> 本文只讲「文件级」的事：谁在写、能不能手改、哪些键改了会被覆盖、新增文件要守什么规矩。

---

## 一、加载机制

- 加载函数 [`mac_load_extra()`](file:///D:/wwwroot/maccms/maccms12/app/common.php#L14)（`app/common.php:14-28`）：
  对 `app/extra/*.php` 做**非递归 `glob()`**，逐个 `include`，是数组就 `Config::set($data, <文件名>)`；文件名叫 `README` 的跳过。
- 调用点在四个入口：[index.php:60](file:///D:/wwwroot/maccms/maccms12/index.php#L60)、
  [admin.php:60](file:///D:/wwwroot/maccms/maccms12/admin.php#L60)、[api.php:60](file:///D:/wwwroot/maccms/maccms12/api.php#L60)、
  [install.php:65](file:///D:/wwwroot/maccms/maccms12/install.php#L65)。
- 因此**不能建子目录**：加载器只扫一层，挪进子目录等于不加载；而所有 `config('xxx.yyy')` 调用点和
  `mac_arr2file(APP_PATH.'extra/xxx.php', ...)` 写入点都要跟着改路径，改漏一处就是线上事故。
- 文件内**不要写注释来解释配置**（除第四节标 ❌ 的那 4 个文件）：后台保存走整文件 `var_export` 回写，注释必被抹掉（见第二节）。

## 二、两条读取路径，和一条写入路径

| 机制 | 位置 | 后果 |
|---|---|---|
| 写回 | [`mac_arr2file()`](file:///D:/wwwroot/maccms/maccms12/app/common.php#L280)（`app/common.php:280-293`）：`var_export($arr,true)` → `"<?php\nreturn …;"` 整文件重写 + `opcache_invalidate()` | 手写注释丢失；已有键**保持原位置**（后台都是 `array_merge($old,$new)` 后整体回写）；文件里原本没有的键**追加到末尾** |
| 写回（别名） | [`mac_save_config_data()`](file:///D:/wwwroot/maccms/maccms12/app/common.php#L295)（`:295-301`）只是 `is_array()` 包一层再转调 | 同上 |
| 读法 A | `config('maccms.xxx')` / `config('maccms')` | **只认文件里真实存在的键**，缺键拿到 `null`（`Init` 第 14 行把 warning/deprecated 全压掉了，不会报错，见下） |
| 读法 B | `$GLOBALS['config']['xxx']`（老代码里 745 处、106 个文件用这种） | 拿到的是 [`Init` 中间件](file:///D:/wwwroot/maccms/maccms12/app/common/middleware/Init.php#L18) 内存兜底后的那份（注入在 `:18-64`，赋值在 `:199`） |

> **两种读法可能不一致**，因为 `Init` 只往局部变量里补默认值、**从不回写 `maccms` 配置组**（全文件没有 `Config::set($config,'maccms')`）。
> 目前 `Init` 内存注入的键有 `app.security_headers_base`、`app.content_lang_list`、`app.content_lang_default`（`:22-29`）、
> `meilisearch`（`:31`）、`template_cloud`（`:42`）、`addon_cloud`（`:49`）。
> 其中 `template_cloud` / `addon_cloud` 恰好也不在出厂 `maccms.php` 里——所以对应的两个服务类自己又写了一遍默认值：
> [`TemplateCloudService.php:29-38`](file:///D:/wwwroot/maccms/maccms12/app/common/util/TemplateCloudService.php#L29)、
> [`AddonCloudService.php:24-40`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L24)。
> 前者用 `config()`、后者优先 `$GLOBALS['config']`。**新代码请统一走 `config()`，并把键真的落进文件**，别依赖 `Init` 兜底。

## 三、三个分级口径

| 级别 | 含义 | 谁该改 |
|---|---|---|
| 🖥️ 服务器/部署 | 跟着服务器、网络、存储、凭据走 | 装机或迁移时由懂技术的人改一次，填错直接连不上库/存储/发信 |
| ⚙️ 运营 | 后台设置页的主体 | 站长随便改，改错影响小且可回退 |
| 🔒 系统级 | 框架底层参数或程序自动维护的派生数据 | 🚫 不要手改：只有二次开发和官方升级流程会动，改错会导致前台 404、路由错乱或插件系统失效 |

## 四、逐文件：谁在写、能不能手改

`APP_PATH` = `app/`。下表行号为 2026-10-27 对着当前树量过的结果。

| 文件 | 级别 | 运行期用户数据 | 回写入口（`文件:行`） | 后台入口 | 能不能手改 |
|---|---|---|---|---|---|
| `maccms.php` | 🖥️+⚙️+🔒 混合 | ✅ | [`System.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L553) 25 处（553/709/751/828/883/920/948/976/1004/1042/1075/1123/1160/1194/1298/1352/1392/1493/1568/1767/2007/2108/2168/2243/2306）、[`Collect.php:33`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Collect.php#L33)、[`Meilisearch.php:115`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Meilisearch.php#L115)、[`Urlsend.php:24`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Urlsend.php#L24)、[`SecurityHealthCheck.php:242`](file:///D:/wwwroot/maccms/maccms12/app/common/util/SecurityHealthCheck.php#L242)、[`SinaUpload.php:30`](file:///D:/wwwroot/maccms/maccms12/app/common/util/SinaUpload.php#L30)、[`install/Index.php:341`](file:///D:/wwwroot/maccms/maccms12/app/install/controller/Index.php#L341) | 网站参数组各子页（02 §3） | ✅ 手改立即生效；但要防注释被抹、防下次表单保存覆盖同名键 |
| `timming.php` | ⚙️ | ✅ | [`Timming.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Timming.php#L37) 37/56/79、[`ResourceHub.php:809`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/ResourceHub.php#L809)、[`api/Timming.php:63`](file:///D:/wwwroot/maccms/maccms12/app/api/controller/Timming.php#L63)（每个 tick 回写 `runtime` 时间戳） | 网站参数 → 计划任务 | ✅ 任务定义手改有效 |
| `vodplayer.php` | ⚙️ | ✅ | [`Vodplayer.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Vodplayer.php#L53) 53/109/132/193、[`BatchPlayer.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/BatchPlayer.php#L46) 46/72/101/130、[`ResourceHub.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/ResourceHub.php#L699) 699/985 | 视频 → 播放器；采集 → 批量播放器管理 / 资源站中心 | ✅ 但它同时驱动 `static/js/playerconfig.js` 与 `static/player/*.js` 的生成，改完要在后台重新保存对应页（10 §11） |
| `vodserver.php` | ⚙️ | ✅ | [`Vodserver.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Vodserver.php#L49) 49/96/120（写入路径用 `$this->_pre` 拼文件名） | 视频 → 服务器组 | ✅ |
| `voddowner.php` | ⚙️ | ✅ | [`Voddowner.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Voddowner.php#L49) 49/96/119 | 视频 → 下载器 | ✅ |
| `bind.php` | ⚙️ | ✅ | [`Collect.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Collect.php#L236) 236/264、[`ResourceHub.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/ResourceHub.php#L501) 501/923 | 采集 → 分类绑定 | ⚠️ 建议只通过后台操作生成，手写映射容易对不上 `type_id` |
| `blacks.php` | ⚙️ | ✅ | [`Comment.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Comment.php#L144) 144/167、[`IpBanRepository.php:109-125`](file:///D:/wwwroot/maccms/maccms12/app/common/util/IpBanRepository.php#L109) | 基础 → 评论黑名单 / IP黑名单 | ✅ 读的一方是 [`IpBlock` 中间件:21](file:///D:/wwwroot/maccms/maccms12/app/common/middleware/IpBlock.php#L21)、[`api/Chatroom.php:70`](file:///D:/wwwroot/maccms/maccms12/app/api/controller/Chatroom.php#L70)、[`api/Danmaku.php:199`](file:///D:/wwwroot/maccms/maccms12/app/api/controller/Danmaku.php#L199) |
| `domain.php` | 🖥️ | ✅ | [`Domain.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Domain.php#L33) 33/70/130 | 网站参数 → 站群管理 | ✅ 但该页 POST 不校验令牌（`90` §1.2） |
| `quickmenu.php` | ⚙️ | ✅ | [`admin/Index.php:187`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Index.php#L187) | 首页 → 自定义菜单配置 | ✅ 存的是 `标识,地址` 字符串数组 |
| `mctheme.php` | ⚙️ | ✅ | [`TplConfig.php:171`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/TplConfig.php#L171)（走 `mac_save_config_data()`）、[`TemplateCloudService.php:635-645`](file:///D:/wwwroot/maccms/maccms12/app/common/util/TemplateCloudService.php#L635)（主题包下发的 `theme` 递归合并后回写，写入调用在 `:645`） | 模版 → 主题配置 / 主题设计 | ✅ 每请求由 [`Init.php:16`](file:///D:/wwwroot/maccms/maccms12/app/common/middleware/Init.php#L16) 塞进 `$GLOBALS['mctheme']` |
| `translate.php` | 🖥️ | ✅ | [`Translate.php:43`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Translate.php#L43) | 网站参数 → 翻译设置 | ⚠️ 里面有第三方翻译服务的 appid / secret **明文**；已在 `.gitignore` 内。任何文档、截图、提交信息都只写键名，不写值 |
| `addons.php` | 🔒 | ✅ 程序自动维护 | [`common/addon/Service.php:141-151`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L141)（启停/装卸时整文件重写，`autoload` 恒 `true`、`route` 恒空） | 应用 → 本地应用（间接） | 🚫 手改必被覆盖，见 10 §5 |
| `type_synonyms.php` | ⚙️ | ❌ 只读 | **无写入点**；唯一读取 [`ResourceHub.php:527`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/ResourceHub.php#L527)（直接 `include`，不走配置组） | 无（后台没有编辑页） | ✅ 只能手改文件，扩充采集分类同义词；正因为没有回写点，它的头部注释可以安全保留 |
| `version.php` | 🔒 | ❌ | 无程序写入点，随升级包整体替换 | 无 | 🚫 手改 `code` 会让在线升级比对错位；改 `app/admin/controller/Update.php` 不重算 `update_hash` 会让后台直接白页（哈希闸门见 09 §6） |
| `captcha.php` | 🔒 | ❌ | 无 | 无 | 🚫 框架默认参数，改错验证码失效；有头部注释（安全，因为无回写点） |
| `queue.php` | 🖥️ | ❌ | 无 | 无 | ⚠️ 默认同步执行；只有接 Redis 等队列中间件才改；有头部注释 |
| `resource_sites_custom.php` | ⚙️ | ✅ 按需生成 | [`ResourceHub.php:149,159`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/ResourceHub.php#L149)（保存自定义资源站时创建） | 采集 → 自定义接口 | ✅ 出厂不存在，属正常 |

## 五、`maccms.php` 的 29 个顶层分区

当前物理顺序就是「三段 + 末尾追加」，从上到下（`php -r` 读 `array_keys` 实测，2026-10-27）：

| 段 | 顶层键（顺序即文件顺序） | 级别 |
|---|---|---|
| 前段 | `db`(12) / `meilisearch`(8) / `email`(6) | 🖥️ |
| 中段 | `site`(30) / `app`(72) / `user`(25) / `gbook`(6) / `comment`(6) / `upload`(15) / `interface`(6) / `pay`(7) / `collect`(7) / `api`(7) / `connect`(2) / `weixin`(25) / `play`(15) / `sms`(7) / `seo`(6) / `urlsend`(2) / `extra`(0) | ⚙️ |
| 后段 | `view`(23) / `path`(19) / `rewrite`(13) | 🔒 路由与页面底层定义，普通运营不要碰 |
| 末尾追加 | `ai_search`(33) / `trusted_proxies`(字符串，非数组) / `analytics`(6) / `ai_content`(10) / `monitor`(34) / `push`(4) | 分别属 ⚙️ / 🖥️ / ⚙️ / ⚙️ / ⚙️ / ⚙️ |

- 括号里是子键个数（`count()`）。`db` 与 `config/database.php` 是**两份**：真正生效的连接配置是后者，`maccms.db` 主要给备份功能展示用，改库要两处一起看。
- 「末尾追加」这一段不是排错了：后台保存都是 `array_merge($old, $new)` 后整体回写，`array_merge` 保留 `$old` 里已有键的位置，
  **只有升级/安装脚本新注入的键才追加到末尾**。这批键由 [`install/Index.php:205-341`](file:///D:/wwwroot/maccms/maccms12/app/install/controller/Index.php#L205) 的「只补缺失键」逻辑写入。
  要归位只能再跑一次一次性重排（历史做法见 [90-变更记录.md §3.3](file:///D:/wwwroot/maccms/maccms12/说明文档/90-变更记录.md)），不是 bug。
- `template_cloud` / `addon_cloud` **不在这个文件里**，是 `Init` 每次请求内存注入的（第二节），所以在这里找不到它们不代表功能没开。

## 六、三条必须守住的不变量

1. **幂等补写**：给 `maccms.php` 加键一律「读旧值 → 只补缺失键 → 整体回写」（`isset()`/`array_key_exists()` + `$changed` 标志），
   不能无条件覆盖——那是站长的数据。现成写法见 [`install/Index.php:205-341`](file:///D:/wwwroot/maccms/maccms12/app/install/controller/Index.php#L205) 与
   [`Init.php:18-64`](file:///D:/wwwroot/maccms/maccms12/app/common/middleware/Init.php#L18)。
2. **升级包不打包运行期用户数据**：在线升级靠「包里压根没有这些文件」来保护站长配置，不是靠代码黑名单。
   [`Update::step1()`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Update.php#L19) 用 `PCLZIP_OPT_REPLACE_NEWER` 解压到 docroot，这个选项**不会跳过任何文件**；
   `version.php` 是唯一例外，它**必须**进包，否则 `config('version.code')` 永远不前进。
   所以任何目录/文件层面的重构都要同步改打包流程，否则这条约定会被无声破坏。
3. **本仓库有三个文件不入库**：`.gitignore` 把 `app/extra/maccms.php`、`app/extra/translate.php`（明文凭据）和 `app/extra/timming.php`（每 tick 回写时间戳）排除了。
   代价是**从 Git 克隆出来的树里没有 `maccms.php`**，而 [`install/Index.php:196`](file:///D:/wwwroot/maccms/maccms12/app/install/controller/Index.php#L196) 是
   `$config_new = config('maccms')` 起步的——文件不存在时它拿到 `null`，PHP 会静默把后续赋值自动转成数组（实测无 warning），于是安装只写出**十个分区**
   （`app / site / api / interface / trusted_proxies / analytics / ai_search / ai_content / monitor / push`，见 `:196-341` 里被赋值的顶层键），
   `db`、`email`、`user`、`upload`、`pay`、`collect`、`weixin`、`play`、`sms`、`seo`、`urlsend`、`view`、`path`、`rewrite` 等 19 个分区全部缺失。
   换新环境要么带上一份出厂 `maccms.php`，要么补一个默认值注入脚本；`.gitignore` 里「install.php 会重新生成 maccms.php」那句注释并不成立。
   改动这三个文件前，先复制到 docroot 之外备份。

## 七、新增配置的操作规范

1. 新增配置项走第六节第 1 条的幂等补写模式；不要无条件覆盖。
2. 新增顶层键时先判断它属于 🖥️ / ⚙️ / 🔒 哪一级，并更新本文第五节的分段表；逐项作用写进 02，别在这里重复。
3. 不要往「运行期用户数据」文件加头部注释——只有第四节标 ❌ 的 4 个文件（`type_synonyms`/`version`/`captcha`/`queue`）没有回写点，注释才留得住；本 README 不被加载器读取（第一节），同样安全。
4. 新增文件必须直接放在 `app/extra/` 一层（不能建子目录，见第一节），并在本文第四节补一行；文件名即配置组名，改名等于改所有 `config('旧名.*')` 调用点。
5. 任何文档、提交信息、截图都不写密钥值——只写键名和填它的那个后台页面。
