# Sidebar modules: 基础 / 内容 / 用户 / 模版 / 生成 / 采集 / 数据库 / 应用 / 直播 / 监控告警

以下名称取自 **app/admin/common/auth.php** 的可见节点（`show=1`），括注为权限串 `controller/action`；语言键是 `menu/*`，界面文案随语言包变化。

---

## 基础 (`menu/base`)

| 左栏名称 | 控制器/动作 | 用途 |
|----------|-------------|------|
| **分类列表** | `type/index` | 全站 **类型与分类** 树（视频/文章/网址/漫画…按 `type_mid` 区分），内容发布前 **必须先有分类** |
| **专题管理** | `topic/data` | 专题列表；添加/编辑在隐藏节点 `topic/info` |
| **友链管理** | `link/index` | 友情链接 |
| **留言管理** | `gbook/data` | 留言本审核与删除 |
| **评论管理** | `comment/data` | 全站评论审核 |
| **评论黑名单** | `comment/blacklist` | 按用户/关键词拦截评论 |
| **IP黑名单** | `comment/blacklist_ip` | 按来源 IP 拦截 |
| **聊天室管理** | `chatroom/data` | 直播间聊天室消息 |
| **弹幕管理** | `danmaku/data` | 弹幕审核 |
| **任务管理 / 任务记录** | `task/data` / `task/log` | 会员任务与完成记录 |
| **签到里程碑** | `sign_milestone/data` | 签到奖励节点 |
| **会员动态 / 关注管理 / 私信管理 / 分享记录** | `dynamics/data`、`follow/data`、`userpm/data`、`sharelog/data` | 社交类内容治理 |
| **附件管理** | `annex/data` | 已上传图片/附件浏览与维护 |

---

## 视频 (`menu/vod`)

| 左栏名称 | 控制器/动作 | 用途 |
|----------|-------------|------|
| **服务器组** | `vodserver/index` | 多线路/多源时的 **server 标识** 与解析入口配合 |
| **播放器** | `vodplayer/index` | **解析代码**、排序、编码；与 **网站参数 → 播放器参数配置** 配合 |
| **下载器** | `voddowner/index` | 下载方式配置 |
| **视频数据** | `vod/data` | 主列表：筛选、编辑、删除、推荐、锁定 |
| **添加视频** | `vod/info` | 单条发布 |
| **无地址 / 已锁定 / 未审核 / 已驳回 / 草稿 / 定时发布 / 需积分 / 有分集剧情 / 重名** | 全是 `vod/data` + `param`：`url=1`、`lock=1`、`status=0`(未审核)、`status=2`(已驳回)、`status=3`(草稿)、`status=4`(定时发布)、`points=1`、`plot=1`、`repeat=1` | 条件子集列表，便于运营处理 |
| **审核自动规则** | `vodauditrule/index` | 自动审核条件规则 |
| **批量操作视频** | `vod/batch` | 批量改分类、状态等 |
| **演员库 / 角色库** | `actor/data` / `role/data` | 人物、角色资料维护 —— **它们挂在「视频」分组下**，不是独立顶栏分组 |
| **播放失败统计** | `vodplayfail/index` | 哪些线路/条目播放报错 |

> 权限串只按 **`控制器/动作`** 记录（勾选框 value 见 [admin/info.html:43](file:///d:/wwwroot/maccms/maccms12/app/admin/view/admin/info.html#L43)），带 `param` 的筛选子集 **共用同一条 `vod/data`**：勾一次全都放开，也无法只给某个子集页。文章/漫画/网址同理。

---

## 文章 / 漫画 / 网址

- **文章**：文章数据 `art/data`、添加文章 `art/info`、批量 `art/batch`，以及锁定/未审核/重名等筛选子集。  
- **漫画**：`manga/data`、`manga/info`、`manga/batch` + **无地址漫画**、**需积分漫画** 筛选子集。章节内容存 `manga_content`，话数用 `$$$` 分隔（见 `07-types-topic-manga.md`）。  
- **网址**：`website/data`、`website/info`、`website/batch` + 锁定/未审核/重名筛选，用于导航站、资源站收录类内容。

---

## 用户 (`menu/users`)

| 左栏名称 | 控制器/动作 | 用途 |
|----------|-------------|------|
| **管理员** | `admin/index` | 后台账号与 **权限勾选树**（v12 无角色表） |
| **会员组** | `group/index` | **前台** 会员等级：价格、积分、权限等级 —— 与后台权限无关 |
| **会员** | `user/data` | 前台用户列表、封禁、调积分 |
| **邀请统计 / 分销奖励** | `user/invite` / `user/reward` | 推广与分销数据 |
| **充值卡** | `card/index` | 卡密生成与核销 |
| **积分商城** | `mall_goods/index` | 积分兑换商品 |
| **会员订单** | `order/index` | 支付订单查询 |
| **访问日志 / 访问风控日志 / 访问记录** | `ulog/index`、`user_access_log/index`、`visit/index` | 行为与风控记录 |
| **积分日志** | `plog/index` | 积分变动 |
| **统计总览** | `analytics/index` | 站点运营统计 |
| **消息中心** | `notify/index` | 站内通知与广播 |
| **操作审计** | `adminaudit/index` | 谁在后台改了什么 |
| **AI 标注队列 / 内容质量** | `ai_annotation/index`、`content_quality/index` | AI 处理队列与质量分 |
| **提现记录 / 优惠券 / 秒杀** | `cash/index`、`coupon/index`、`seckill/index` | 交易类运营页 |

---

## 模版 (`menu/templates`)

| 左栏名称 | 控制器/动作 | 用途 |
|----------|-------------|------|
| **模板管理** | `template/index` | 选择/编辑 PC、WAP 模板文件 |
| **广告位管理** | `template/ads` | 广告位与模板内调用标识 |
| **标签向导** | `template/wizard` | 生成常用 **标签代码** 片段 |
| **主题配置** | `tpl_config/theme` | 当前模板的主题变量 |
| **主题设计** | `theme_design/design` | AI 辅助主题设计 |
| **模板市场** | `template_market/index` | 云端主题包浏览与安装 |

---

## 生成 (`menu/make`)

- **生成选项** `make/opt`：路径、大小等。  
- **生成首页** `make/index` / **生成WAP首页**（同一动作、不同参数）/ **生成地图** `make/map`。  
- 使用静态化后，改模板或大数据变更后需 **重新生成**；模板编译异常时点顶栏 **「⋯」→ 清理缓存**。

---

## 采集 (`menu/cjs`)

| 左栏名称 | 控制器/动作 | 用途 |
|----------|-------------|------|
| **自定义接口** | `collect/index` | API 型采集节点 |
| **自定义规则** | `cj/index` | 规则型采集 |
| **资源站中心** | `resource_hub/index` | 资源站列表与对接入口 |
| **批量播放器管理** | `batch_player/index` | 跨线路批量写播放器 |
| **数据批量替换** | `data_replace/index` | 批量改字段内容，易误伤，需 where 条件与备份 |
| **推荐资源 / 定时挂机** | `collect/union` / `collect/timing` | **`show=0` 隐藏节点**：左栏不显示，要在权限树里单独勾 |

---

## 数据库 (`menu/db`)

- **数据库备份** `database/index`：备份/恢复/优化入口（左栏文案就是「数据库备份」）。  
- **执行SQL语句** `database/sql`：高危，仅维护人员且 **先备份**。  
- **数据库内容替换** `database/rep`：字符串替换。  
- **注意**：**数据批量替换** 在 **采集** 分组，**挂马检测** 在 **应用** 分组（`safety/data`），都不在数据库分组里。

---

## 应用 (`menu/apps`)

- **本地应用** `addon/index`：本机插件与云市场的安装/启用/禁用/设置。**装完强制停用**，且 **插件不会往菜单树里加节点**（见 `09-plugins-comments-user.md`）。  
- **URL推送** `urlsend/index`：向搜索引擎主动提交 URL。  
- **文件安全检测** `safety/file`、**安全体检** `safety/checkup`、**数据挂马检测** `safety/data`。

---

## 直播 (`menu/live`) 与 监控告警 (`menu/monitor`)

- **直播**：频道管理 `live/index`、分类管理 `live/category`。  
- **监控告警**：服务器性能 `monitor/index`、实时面板 `monitor/realtime`、监控设置 `monitor/setting`、告警规则 `monitor/rule`、告警记录 `monitor/event`、异常访问 `monitor/access`。

---

## English summary

**基础** = taxonomy (`type/index`) + topic + link + gbook + comment (+ both blacklists) + chat/danmaku/social + annex (`annex/data`). **视频** carries server-group / player / downloader, many filtered `vod/data` subsets, audit rules, play-failure stats, and the actor & role libraries. **用户** mixes backend accounts (`admin/index`, permission tree — no roles) with front-end membership (`group/index`), orders, cards, logs, analytics, notify, audit and AI queues. **采集** hosts `data_replace`; **数据库** only has backup / SQL / replace; **应用** hosts addons, URL push and the three safety pages. Live channels and the monitor/alert pages are their own two top-bar groups.
