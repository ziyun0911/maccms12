# 重要目录与运行时

| 路径 | 说明 |
|------|------|
| **站点根目录 = Web 根** | 本仓库 **没有 `public/`**：`index.php`、`admin.php`、`api.php`、`img.php`、`install.php` 直接放在仓库根，虚拟主机的文档根要指到这里。 |
| **app/** | 业务代码：`admin` `index` `api` `common` `install` `extra`(配置) `data`(知识库/备份等) `lang`(6 个语言包) … |
| **app/extra/maccms.php** | 主配置（见 `22-maccms-php-config.md`） |
| **app/data/admin_assistant/*.md** | 后台助手知识库 |
| **app/data/backup/database/** | 默认数据库备份目录（以 `maccms.db.backup_path` 为准） |
| **config/** | ThinkPHP 框架配置（`database.php`、`cache.php` 等）；`config/database.php` 是 **实际生效** 的数据库连接 |
| **runtime/** | 缓存、日志、临时文件；应 **可写**，但 **绝不能让公网直读**（内部文件下载拦截见 `说明文档/07`） |
| **template/{模板目录}/** | 前台模版（配合 `site.template_dir`） |
| **static/** | 前后台静态资源（JS/CSS 合包由 `php think front:bundle` 生成） |
| **upload/** | 上传目录 |
| **extend/** | 第三方扩展（如 `aws/src/Aws/aws.phar`） |
| **vendor/** | Composer 依赖；框架在 `vendor/topthink/framework`——**没有顶层 `thinkphp/` 目录**，也不要改 vendor 里的文件 |
| **addons/** | 已安装插件代码（PSR-4 `addons\` → 本目录） |

## 缓存

- **`app.cache_type`**：`file` / `redis` / `memcache` / `memcached`（非法值回落 `file`，[app/common/middleware/Init.php:136](file:///d:/wwwroot/maccms/maccms12/app/common/middleware/Init.php#L136)）；非 file 时需 **可达的缓存主机**。  
- **`app.cache_page`**：页面缓存；改模板后要 **清除**（后台顶栏 **「⋯」→ 清理缓存**）。  
- **模板编译缓存**在 `runtime/temp/`；**浏览器 / CDN** 与服务器缓存分层，排查时 **每层单独清理**。

## 日志

- **应用日志**按入口分目录：**`runtime/admin/log/`**、**`runtime/index/log/`**、**`runtime/api/log/`**，文件名形如 `202610/26.log`（年月目录 + 日文件）。  
- **`runtime/log/`** 是 CLI / 未指定应用时的落点（`php think ...` 的报错在这里），另有 `runtime/log/bot/`（爬虫日志，按需生成）。  
- 宿主 PHP `error_log` 也要看：PHP 致命错误常在框架日志之前。  
- **后台行为类日志**在菜单里：**用户 → 操作审计**（`adminaudit/index`）、**访问日志**（`ulog/index`）、**积分日志**（`plog/index`）。

---

## English summary

The repo root **is** the web root (there is no `public/`, and no top-level `thinkphp/` — the framework sits in `vendor/topthink/framework`). Know **app/** (code + `app/extra` config), **config/** (the real DB connection lives in `config/database.php`), **runtime/** (writable cache/logs; per-entrance logs under `runtime/admin|index|api/log/`), **template/** (themes), **addons/** (plugin code). Debug = **runtime logs** + browser network.
