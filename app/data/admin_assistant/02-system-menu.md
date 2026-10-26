# System menu map / 后台菜单导览

菜单的唯一权威来源是 [app/admin/common/auth.php](file:///d:/wwwroot/maccms/maccms12/app/admin/common/auth.php)：**16 个一级分组、362 个节点**。顶栏按分组排列，点分组后左栏列出该组可见项。节点上的 `show` 只决定左栏是否显示，**不影响**「用户 → 管理员 → 编辑」里的权限勾选树。

## 16 个一级分组（按顶栏顺序）

| 分组 | 说明 | 代表入口（控制器/动作） |
|------|------|------------------------|
| **首页** | 欢迎页与自定义菜单 | `index/welcome`、`index/quickmenu` |
| **系统信息** | 站点健康总览 + 设置中心 | `system/index` |
| **网站参数** | 所有全局配置页 | 见下表 |
| **基础** | 分类、专题、互动、附件 | `type/index`、`topic/data`、`gbook/data`、`comment/data`、`annex/data` |
| **文章** | 文章数据与批量维护 | `art/data`、`art/info`、`art/batch` |
| **漫画** | 漫画数据与批量维护 | `manga/data`、`manga/info`、`manga/batch` |
| **视频** | 点播核心：数据 + 服务器组/播放器/下载器 + 演员/角色库 | `vod/data`、`vod/info`、`vodserver/index`、`vodplayer/index`、`voddowner/index`、`actor/data`、`role/data` |
| **网址** | 网址（`website`）数据 | `website/data`、`website/info` |
| **用户** | 管理员、会员、订单、日志、审计 | `admin/index`、`group/index`、`user/data`、`order/index`、`ulog/index`、`adminaudit/index` |
| **模版** | 模板、广告位、标签向导、主题 | `template/index`、`template/ads`、`template/wizard`、`tpl_config/theme`、`theme_design/design`、`template_market/index` |
| **生成** | 静态首页与地图 | `make/opt`、`make/index`、`make/map` |
| **采集** | 接口、规则、资源站中心、批量替换 | `collect/index`、`cj/index`、`resource_hub/index`、`batch_player/index`、`data_replace/index` |
| **数据库** | 备份、执行 SQL、内容替换 | `database/index`、`database/sql`、`database/rep` |
| **应用** | 本地应用（插件）、URL 推送、安全检测 | `addon/index`、`urlsend/index`、`safety/file`、`safety/checkup`、`safety/data` |
| **直播** | 直播频道与分类 | `live/index`、`live/category` |
| **监控告警** | 服务器性能、实时面板、告警 | `monitor/index`、`monitor/realtime`、`monitor/setting`、`monitor/rule`、`monitor/event`、`monitor/access` |

## 网站参数分组下的配置页

| 左栏名称 | 控制器/动作 | 管什么 |
|----------|-------------|--------|
| **网站参数** | `system/config` | 站点名、域名、安装路径、ICP、统计代码、时区语言；同页内还内嵌 **SEO 设置** 与 **URL 地址配置** 区块 |
| **会员互动** | `system/configuser` | 注册、积分、试看、评论/留言开关等会员侧行为 |
| **访问风控设置** | `system/configuseraccess` | 前台访问频控、黑名单联动 |
| **资源、链接与播放** | `system/configupload` | 上传与附件、图片处理、外链/播放相关参数 |
| **AI 搜索设置** | `system/configaisearch` | 前台 AI 搜索/对话、外部数据源、语义重排、速率限制 |
| **Meilisearch 设置** | `meilisearch/index` | 索引同步、自检、连接配置 |
| **后台助手设置** | `system/configassistant` | 浮动 **`?`** 助手、知识库片段数、助手 LLM 凭据 |
| **主题 AI 设置** | `system/configthemeai` | 主题生成相关 AI 参数 |
| **翻译设置** | `translate/index` | 内容多语言翻译服务 |
| **AI 标注设置** | `system/configaicontent` | 自动标注/打标签 |
| **内容接入** | `system/configcollect` | 页内 Tab 包含 **采集参数**、**站外入库**、**API 设置** 等 |
| **对接与账号** | `system/configconnect` | 页内 Tab 包含 **在线支付**、**微信对接** 等第三方凭据 |
| **邮件与短信** | `system/configemail` | 邮件网关与通知模板 |
| **短信发送配置** | `system/configsms` | 短信驱动（仅阿里云 / 腾讯云）与签名模板 |
| **Web推送配置** | `system/configpush` | Web 消息推送 |
| **计划任务** | `timming/index` | 任务清单与排期，数据存 `app/extra/timming.php` |
| **站群管理** | `domain/index` | 多域名站群 |
| **API 文档** | `api_doc/index` | 站内接口文档页 |
| **说明文档** | `doc/index` | 站内 `app/data/admin_assistant/` 与 `说明文档/` 阅读页 |

## 常见误区

- **没有「管理员角色」页**：v12 权限直接写在 **用户 → 管理员** 编辑页的菜单勾选树里（`admin_auth[]`），`admin_id = 1` 恒为超管且不可被限制。**用户 → 会员组**（`group/index`）管的是前台会员等级，不是后台角色。
- **视频 / 文章 / 漫画分组下没有「分类」**：分类只有 **基础 → 分类列表**（`type/index`）一处，条目按 `type_mid` 标明归属模块（1 视频 / 2 文章 / 8 演员 / 11 网址 / 12 漫画…见 [app/common.php:1472-1489](file:///d:/wwwroot/maccms/maccms12/app/common.php#L1472-L1489)）。
- **没有独立的「网站安全」分组**：安全相关分散在 **应用**（`safety/*`）、**基础 → 评论黑名单 / IP黑名单**（`comment/blacklist`、`comment/blacklist_ip`）、**网站参数 → 访问风控设置**。
- **没有独立的「URL 设置 / SEO 设置 / 附件设置」页**：分别是 `system/config` 页内区块，或 `system/configupload`（资源、链接与播放）、`system/configcollect`（内容接入）的 Tab。
- **「定时任务」现在叫「计划任务」**（`timming/index`）。

## 中文速查

- **看站点健康状况 / 检测项** → **系统信息**（`system/index`）。  
- **找不到某个设置页** → 点顶栏 **系统信息**，在左栏搜索关键字（如 AI、邮件），或按上表找分组。  
- **改站点名、域名、统计代码** → **网站参数 → 网站参数**。  
- **改视频/文章列表网址形态** → **网站参数 → 网站参数** 的 **URL 地址配置** 区块 + Web 服务器 rewrite。  
- **采集入库默认是否审核、是否同步图** → **网站参数 → 内容接入**。  
- **前台 AI 搜索/对话** → **网站参数 → AI 搜索设置**。  
- **后台「?」助手** → **网站参数 → 后台助手设置**（可与 AI 搜索共用 Key）。  
- **新建文章/视频无分类** → 先到 **基础 → 分类列表** 建好类型与分类。
- **给同事开部分权限** → **用户 → 管理员 → 编辑**，在权限树里勾选具体页面。

若用户找不到某项，请其说明 **顶栏分组名称 + 左栏条目**，不要猜测不存在的子菜单路径。
