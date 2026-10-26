# Site parameters → configuration pages / 网站参数配置页（全书式参考）

顶栏 **网站参数** 分组下有 **19 个可见入口**（清单见 `02-system-menu.md`）；点顶栏 **系统信息**（`system/index`）后，左栏会按 **8 个设置分组**（base / content / media / ai / user / connect / notify / system，由 [app/common/util/SystemSettingsMenu.php](file:///d:/wwwroot/maccms/maccms12/app/common/util/SystemSettingsMenu.php) 定义）列出同一批入口，可用搜索框过滤。

**两个关键事实**：

1. **很多「配置页」其实是页签**。一批 `system/configXxx` 方法在 GET 时只做一次 302，把请求甩给宿主页的对应 Tab（[System.php:716,891,927,955,983,1167,1204,1372,1399,1500,1575](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L716)）。
2. **左栏 `show=0` 的节点仍然可授权、可直达**，只是不显示在菜单里；系统信息页的卡片会替你把入口找回来。

界面文案以当前语言包的 `menu/...` 为准，下文括注的 `menu/*` 是语言键，`controller/action` 是权限串。

---

## 1. 网站参数 (`menu/config` → `system/config`)

- **站点信息**：站点名、域名、WAP 域名、关键词、描述、ICP、QQ、邮箱、统计代码、Logo。  
- **安装目录 / `site.install_dir`**：必须与 **实际 URL 路径** 一致（含子目录），否则 CSS/链接错误。  
- **模板目录 `template_dir`、`html_dir`、`ads_dir`**：决定 `template/{模板名}/{html|ads}/`。  
- **手机站 `mob_status` / `mob_template_dir`**：开关与 WAP 模板。  
- **站点状态 `site_status`**：关闭站点时显示维护提示。  
- **App 区块**（`maccms.app`）：缓存类型与参数、页面缓存、压缩、**搜索** 开关与间隔、热门搜索词、**扩展分类/地区/语言/年代** 可选列表、**过滤词 `filter_words`**、后台分页条数、编辑器类型、后台语言 `lang`、登录验证码等。  
- 本页共 **11 个页签**：基本设置 / 地址发布页 / 性能优化 / 预留参数 / 后台设置 / SEO参数配置 / AI SEO 设置 / AI 视频封面设置 / 附件参数配置 / URL地址配置 / 播放器参数配置（`lay-id` 依次为 `config_1`、`config_publish`、`config_2`、`config_3`、`config_4`、`config_seo`、`config_aiseo`、`config_aicover`、`config_upload`、`config_url`、`config_play`）。后五个各自带独立 `<form>` 提交。  
- 保存写入 **`app/extra/maccms.php`**（整文件重写）；失败先查 `app/extra` 写权限。

---

## 2. SEO 参数配置 (`menu/configseo`，是页签)

- 首页/栏目/内容页 **TDK** 规则、**sitemap**、链接形态相关 SEO 选项；入口是 **网站参数 → 「SEO参数配置」页签**，`system/configseo` 直接 302 过去。  
- 改动后若生成静态或开了缓存，需 **重新生成 / 清理缓存**。

---

## 3. AI SEO 与 AI 视频封面 (`menu/configaiseo`、`menu/configaicover`，都是页签)

- 位于 **网站参数** 的「AI SEO 设置」「AI 视频封面设置」页签，各自有独立保存按钮与提交地址（`system/configaiseo`、`system/configaicover` 均 302 到页签）。  
- 列表/详情批量或单行 **AI 生成 SEO**（标题/描述等），使用独立 **API Base / Key / 模型**，与会话型 AI 搜索互不共用。  
- 保存前建议小批量试跑，避免消耗过大。

---

## 4. AI 搜索设置 (`menu/configaisearch` → `system/configaisearch`)

- 独立页面。前台 **AI 搜索/对话**、扩展词、语义向量重排、外部数据源（TMDB、豆瓣等）、速率限制。  
- 搜索引擎本体接入在 **Meilisearch 设置**（`menu/meilisearch` → `meilisearch/index`），本页只管 LLM 侧。

---

## 5. Meilisearch 设置 (`menu/meilisearch` → `meilisearch/index`)

- 独立页面：连接地址 / API Key / 索引名 / 超时 / 保存时是否同步（`sync_on_save`）等，并提供 **同步、自检、重建** 动作。  
- 批量改库或改结构后在这里做一次全量同步，否则前台搜索结果与筛选会滞后。

---

## 6. 后台助手设置 (`menu/configassistant` → `system/configassistant`)

- 浮动 **`?`** 管理端助手；知识库就是 **`app/data/admin_assistant/*.md`**，可选附带非敏感环境快照。  
- 可与 **AI 搜索设置** 的 LLM 凭据 **复用**（本页开关），或单独填写模型 / API Base / Key。  
- 配置块 `maccms.admin_assistant` **出厂不存在**，只有在本页点过保存才会写入 `maccms.php`。

---

## 7. 会员互动 (`menu/configuser` → `system/configuser`)

- **两个页签**：会员本体 + **评论留言配置**（`user_main` / `user_comment`）。  
- 会员侧：注册开关与审核、手机/邮箱校验与白黑名单域名、登录验证码、**积分 / 邀请 / 奖励 / 提现** 比例与门槛、**试看 trysee**、**视频/文章积分消费** 策略、**昵称过滤词 `user.filter_words`**。  
- 与 **用户 → 会员组 / 会员 / 订单 / 充值卡** 联动。

---

## 8. 评论留言配置 (`menu/configcomment`，是「会员互动」的页签)

- **评论** `maccms.comment` 与 **留言本** `maccms.gbook`：开关、是否需审核、是否需登录、验证码、分页、时间间隔防刷。  
- 独立地址 `system/configcomment` 会 302 到 **`system/configuser?tab=comment`**（[System.php:927](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L927)）。

---

## 9. 访问风控设置 (`menu/configuseraccess` → `system/configuseraccess`)

- 独立页面：前台访问频率、封禁与风控日志相关阈值；配合 **用户 → 访问风控日志**（`user_access_log/index`）与 **基础 → IP黑名单**（`comment/blacklist_ip`）使用。

---

## 10. 资源、链接与播放 (`menu/configupload`，是「网站参数」的页签)

- 「附件参数配置」页签：缩略图、水印、本地上传、**远程 FTP / 七牛 / 又拍 / 微博图床** 等 `mode` 与 `api` 子数组、远程 URL 前缀、防盗链。  
- 需要 `extend/aws/src/Aws/aws.phar` 才支持 S3 系驱动（[System.php:852](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L852)）。  
- 改 `mode` 后已存在附件不会自动迁移，需自行规划。  
- 同组的 **「URL地址配置」** 与 **「播放器参数配置」** 页签分别见第 11、12 节；`system/configupload` / `configurl` / `configplay` 三个旧地址都是 302 到对应页签。

---

## 11. URL 地址配置 (`menu/configurl`，是「网站参数」的页签)

- 可配的字段是 `path[…]` 与 `rewrite[…]`：视频（`vod_type`/`vod_detail`/`vod_play`/`vod_down`/`vod_plot`/`vod_role`）、文章（`art_type`/`art_detail`）、专题、演员、角色、剧情、漫画（`manga_type`/`manga_read`/`manga_down`）、标签 `label`、单页 `page_sp`，以及统一后缀 `path[suffix]`、`rewrite[route]`、`rewrite[encode_key/len]`。  
- **网址（website）模块在这个页签里没有 URL 字段**，别按旧版教程找「网址 URL」。  
- 保存时除了写 `maccms.php`，还会 **重写 `app/index/route/rewrite.php`**（[System.php:690-712](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L690-L712)）；改 URL 规则会导致 **旧外链失效**，需做 301 或公告。

---

## 12. 播放器参数配置 (`menu/configplay`，是「网站参数」的页签)

- 全局播放器默认与预置（与 **视频 → 播放器** `vodplayer/index` 里各条具体代码配合）。  
- 本页有「恢复默认值」按钮，误点后需重新保存。

---

## 13. 内容接入 (`menu/configcollect` → `system/configcollect`)

- **三个外层页签**：内容采集 / 站外入库 / API 设置（`acc_collect` / `acc_if` / `acc_api`），内层再按 **视频、文章、演员、角色、网址、漫画** 等分子页签。  
- 采集侧控制入库 **审核状态、随机点击/顶踩/评分、同步图片、自动 TAG、分类过滤、同义词/随机词** 等 —— **大批量采集前必先保存本页**。  
- **站外入库**（`menu/configinterface`，302 到 `configcollect?tab=interface`）：开关、**通信密钥 `interface.pass`**（开启时长度 **至少 16 字符**，[System.php:1183](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L1183)）、分类映射字符串（`动作片=动作` 形式）。密钥需保密，配合防火墙限制来源 IP。  
- **API 设置**（`menu/configapi`，302 到 `configcollect?tab=api`）：见下一节。

---

## 14. 开放 API 配置 (`menu/configapi`，是「内容接入」的页签)

- 按模块存 `maccms.api.{vod|art|actor|role|manga|website|publicapi}`，字段只有 **`status` / `charge` / `pagesize` / `imgurl` / `typefilter` / `datafilter` / `cachetime` / `from` / `auth`**（[app/extra/maccms.php:445-458](file:///d:/wwwroot/maccms/maccms12/app/extra/maccms.php#L445-L458)）。  
- **`auth` 是域名/IP 白名单**，在接口里由 `checkDomainAuth()` 校验（[app/api/controller/Provide.php:34-35](file:///d:/wwwroot/maccms/maccms12/app/api/controller/Provide.php#L34-L35)）。  
- **这一层没有 `pass`、也没有 token / 限速字段**：站外入库的密钥在 `interface.pass`，别把两者混为一谈。

---

## 15. 对接与账号 (`menu/configconnect` → `system/configconnect`)

- **三个页签**：对接与账号（第三方登录）/ 微信对接 / 在线支付（`conn_oauth` / `conn_weixin` / `conn_pay`）。  
- QQ/微信等 **第三方登录** 需平台 **AppID/Secret/回调 URL**（HTTPS）。  
- **在线支付**（`menu/configpay`，302 到 `configconnect?tab=pay`）：商户号、密钥、回调；沙箱与生产分离，回调地址须公网可达且与站点 HTTPS 一致。  
- **微信对接**（`menu/configweixin`，302 到 `configconnect?tab=weixin`）：字段只有 **`weixin.token`**、`weixin.status` 开关，以及关注 / 搜索 / 播放 / 对接 / 无资源这几类自动回复文案。**没有 AppID、AppSecret、AESKey 字段**，别照旧版或开放平台文档去填。

---

## 16. 邮件与短信 / 短信发送 / Web推送

| 页面 | 控制器/动作 | 说明 |
|------|-------------|------|
| **邮件与短信** | `system/configemail` | SMTP（Phpmailer）或服务商参数；注册验证、通知等出站邮件 |
| **短信发送配置** | `system/configsms` | 驱动只有 **阿里云** 与 **腾讯云** 两种（`app/common/extend/sms/Aliyun.php`、`Qcloud.php`）；填 AccessKey、签名、模板 ID，注意计费 |
| **Web推送配置** | `system/configpush` | Web 消息推送密钥与开关 |

---

## 17. 计划任务 (`menu/timming` → `timming/index`)

- 独立页面：维护 **任务类型、状态、执行周期（周/小时/间隔秒）**，数据写入 **`app/extra/timming.php`**（不在数据库里）。  
- 实际执行仍依赖外部 crontab 定时访问 **`api.php/timming/index`**；在本页点按钮不等于全站定时已经配好。

---

## 18. 站群管理 (`menu/domain` → `domain/index`)

- 多域名绑定不同 **站点参数覆盖**（模板、WAP、路径等）。  
- 配置错误会导致 **错模板或死链**；新增域名后确认 DNS 与 Web 服务器已解析到同一站点。

---

## 19. 翻译 / AI 标注 / 主题 AI / API 文档 / 说明文档

| 左栏名称 | 控制器/动作 | 管什么 |
|----------|-------------|--------|
| **翻译设置** | `translate/index` | 内容多语言翻译服务凭据与开关（配置在 `app/extra/translate.php`） |
| **AI 标注设置** | `system/configaicontent` | 自动打标/标注模型与队列参数，配合 **用户 → AI 标注队列**（`ai_annotation/index`） |
| **主题 AI 设置** | `system/configthemeai` | 主题生成相关 AI 参数，配合 **模版 → 主题设计**（`theme_design/design`） |
| **API 文档** | `api_doc/index` | 站内接口文档页 |
| **说明文档** | `doc/index` | 站内阅读 `app/data/admin_assistant/` 与 `说明文档/` |

---

## English summary

The **网站参数** group mixes standalone pages with pages that are really **tabs inside another page** — `configseo`, `configupload`, `configurl`, `configplay`, `configaiseo`, `configaicover` redirect into **网站参数**; `configcomment` redirects into **会员互动**; `configinterface` and `configapi` redirect into **内容接入**; `configpay` and `configweixin` redirect into **对接与账号**. Always **save** after edits (the whole `app/extra/maccms.php` is rewritten); fix **file permissions** if the save fails. The open-API section only carries `status/charge/pagesize/imgurl/typefilter/datafilter/cachetime/from/auth` — `auth` is a domain allowlist and there is **no per-API secret**; the import secret lives in `interface.pass`. **URL** and **collect** pages affect the whole site — test in staging.
