# CMS overview / 系统概览

**完整后台知识目录**：见同目录 **`00-index-toc.md`**（列出全部 `.md` 主题索引）。单靠本文件不足以覆盖全部菜单与配置细节。

MacCMS（苹果 CMS）**v12** 分支，跑在 **PHP ≥ 8.0.2 + MySQL** 上，内核是 **ThinkPHP 8**。版本号写在 [admin.php:10](file:///d:/wwwroot/maccms/maccms12/admin.php#L10) 的 `MAC_VERSION`，PHP 版本闸门在 [admin.php:6](file:///d:/wwwroot/maccms/maccms12/admin.php#L6)。

站点根目录下的入口文件共 5 个业务入口（另有 `router.php` 只服务于 `php -S` 本地开发）：

1. **`index.php`**：前台访客站点（点播、文章、专题、漫画、留言、搜索等）。
2. **`admin.php`**：管理员后台（内容、栏目、采集、用户、模板、系统参数等）。
3. **`api.php`**：对外数据接口 + 计划任务回调。
4. **`install.php`**：安装向导，绑定到 `install` 应用（装完后应删除或改名）。
5. **`img.php`**：不走框架的独立取图脚本，用于代理抓取外链图片。

Main runtime configuration is stored in **`app/extra/maccms.php`** (site URL, app switches, upload, cache, AI keys, etc.). Saving options in the admin UI usually **rewrites this file**; keep a backup before experiments.

## Content types (mid 参考)

- **视频 (VOD)**：主力资源，含播放地址、多集、演员等。
- **文章 (ART)**：图文、资讯、公告。
- **专题 (TOPIC)**：打包展示多条视频/文章等。
- **演员 / 角色 / 网址 / 漫画**：扩展模块，部分站点会关闭或不用。

## Typical workflow for new sites

1. **网站参数 → 网站参数**（`system/config`）：填写站点名、域名、安装目录、关闭维护模式等。  
2. **基础 → 分类列表**（`type/index`）：类型与分类只有这一处入口，**视频、文章、网址、漫画的分组都在同一页里按 `type_mid` 区分**，不要在内容菜单下找「分类」。前台 URL 与筛选依赖于此。  
3. **内容入库**：手工 **添加** 或 **采集** 对接资源站。  
4. **模板**：在 **模板** 中选择/编辑 PC 与 WAP 模板；改模板后用首页的 **清理缓存** 按钮或 **生成** 菜单重生成静态。  
5. **网站参数 → 网站参数** 页内的 **URL 地址配置** 区块：按服务器环境配置伪静态后缀与路由模式。  

## Safety

- 修改数据库、升级、批量替换前：**备份数据库 + `app/extra/maccms.php`**。  
- 计划任务的执行入口是 **`api.php/timming/index`**（任务清单在 **网站参数 → 计划任务**，`timming/index`，数据存 `app/extra/timming.php`）。该入口没有独立密钥，只按 **周 / 小时排期** 判断是否执行；带 `enforce=1` 立即执行时要求已登录的管理员（[app/api/controller/Timming.php:29](file:///d:/wwwroot/maccms/maccms12/app/api/controller/Timming.php#L29)）。**不要把会写库的任务地址公开**。

---

MacCMS（苹果 CMS）基于 **PHP + MySQL**。

- **前台**：`index` 应用，模板在站点根目录 **`template/模板名/`** 下。  
- **后台**：`admin` 应用。  
- **主配置**：`app/extra/maccms.php`。后台保存设置时是 **整文件 `var_export` 重写**（`mac_arr2file()`，[app/common.php:280-293](file:///d:/wwwroot/maccms/maccms12/app/common.php#L280-L293)），手写的注释会被抹掉，请先备份。顶层键含义摘要见 **`22-maccms-php-config.md`**。

**常见上线顺序**：网站参数 → 建好分类 → 添加或采集内容 → 检查模板与 URL/伪静态 → 缓存与性能相关开关。

**安全**：生产环境务必限制后台入口、强密码、定期备份；API/定时任务 URL 勿随意公开。
