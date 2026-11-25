# `app/extra/maccms.php` 结构说明（无敏感值）

主配置文件为 **`app/extra/maccms.php`**（`return array(...)`，**当前出厂共 29 个顶级键**）。后台 **网站参数** 各页保存时按区块 **整体回写** 该文件（`mac_arr2file()`，[app/common.php:280-293](.../app/common.php#L280-L293)）—— **文件里的手写注释会被清掉**。

## 顶级键（实测全部 29 个）

| 键 | 主要内容 |
|----|----------|
| **db** | 数据库类型、主机、库名、用户、**表前缀 `tablepre`**、`backup_path`、`part_size`、压缩。**注意与 `config/database.php` 重复**：TP8 连接实际读后者，本块主要供安装与备份逻辑使用。 |
| **meilisearch** | 搜索引擎 host / api_key / index_uid / 超时 / 是否随保存同步 |
| **email** | 驱动（Phpmailer 等）、发信账号、模板 |
| **site** | `site_name`、`site_url`、`site_wapurl`、`install_dir`、`template_dir`/`html_dir`/`ads_dir`/`mob_template_dir`、`site_status`、Logo、统计代码、ICP 等 |
| **app** | 缓存类型 `cache_type` 与参数、`cache_flag`、`pagesize`/`makesize`、搜索开关与间隔、扩展分类/地区/年代、`filter_words`、编辑器、`lang`、**`pathinfo_depr`**、版权与安全开关 |
| **user** | 会员注册登录、积分提现、试看、点播/文章点数策略、`filter_words` |
| **gbook** / **comment** | 留言与评论开关、审核、分页、间隔 |
| **upload** | 缩略图、水印、`mode`（`local`/`remote`）、`protocol`、各云驱动 `api.*` 密钥块 |
| **interface** | 站外入库 `status`、`pass`（≥16 字符）、类型映射 |
| **pay** | `min`/`scale`/`card.url` + 各网关块（出厂含 `alipay`/`codepay`/`weixin`/`zhapay`；`jeepay`/`epay` 的表单片段存在，块在保存后出现） |
| **collect** | 按内容类型的采集默认值 |
| **api** | 按模块的开放接口开关与 `auth` 域名白名单 |
| **connect** | 第三方登录凭据 |
| **weixin** | 微信 `token` 与自动回复文案 |
| **play** | 播放器全局默认 |
| **sms** | 短信驱动（仅 `aliyun` / `qcloud`）与模板 |
| **seo** | **`seo.vod` / `seo.art` / `seo.actor` … 的 name/key/des**（模块级 SEO 在这个块下，**没有独立的顶级 `vod`/`art` 键**） |
| **urlsend** | 搜索引擎 URL 推送配置 |
| **extra** / **view** | 预留参数 / 前台显示开关 |
| **path** | 各模块 URL 路径模板 + **`suffix`**（伪静态后缀，[maccms.php:700](.../app/extra/maccms.php#L700)） |
| **rewrite** | `status`、`route_status`、**`suffix_hide`**（[maccms.php:704](.../app/extra/maccms.php#L704)）、`encode_key/len`、各模块 rewrite 参数 |
| **ai_search** | AI 搜索 / 外部源 / 向量重排（连接字段同步自 `theme_ai`） |
| **trusted_proxies** | 反向代理可信 IP 串（顶级 **标量**，非数组） |
| **analytics** | 统计口径开关 |
| **ai_content** | AI 标注业务参数（连接字段同步自 `theme_ai`） |
| **ai_seo** | AI SEO 与后台 UEditor AI 工具栏业务参数（连接字段同步自 `theme_ai`） |
| **ai_cover** | AI 视频封面业务参数（连接字段同步自 `theme_ai` 的 image_* 配置） |
| **monitor** | 监控与告警、`cron_token` |
| **push** | Web 推送 |

**出厂不存在、按需生成的键**：`admin_assistant`（后台助手）只有在 **网站参数 → 后台助手设置** 点过保存后才会出现；其中只保存助手行为参数，**不含** `provider` / `model` / `api_base` / `api_key` —— 这些连接信息统一读取 `theme_ai`，避免同一份密钥存放两处；`addon`、`addon_cloud`、`template_cloud` 同理。  
**AI 连接信息归口**：`ai_search` / `ai_seo` / `ai_content` / `ai_cover` 的 `provider` / `model` / `api_base` / `api_key` 都由 `theme_ai` 同步写入（`AiServiceConfig::syncAll()`），运行时再由 `AiServiceConfig` 实时覆盖，避免同一份密钥存多处或换服务商后不跟随。这四个段只保存各自业务参数；`ai_seo` / `ai_cover` 段在本站已由一次性同步创建。旧的 `ai_content.use_ai_search_credentials` 复用开关已移除。  
**不在本文件里的配置**：计划任务条目在 **`app/extra/timming.php`**，数据库连接在 **`config/database.php`**，翻译在 **`app/extra/translate.php`** —— 详见 `app/extra/README.md`。

## 操作守则

1. **不要**把含真实密码的 `maccms.php` 提交到 Git 或发聊天。  
2. 手工编辑前 **复制备份**；PHP **语法错误**会导致全站崩溃。  
3. 多环境（测试/生产）用 **不同副本**，勿直接覆盖。

---

## English summary

**maccms.php** holds **all major CMS settings** as PHP array keys: `site`, `app`, `user`, `upload`, `interface`, `pay`, AI blocks, etc. Treat it like **production secrets** + **backup before edit**.
