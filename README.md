<div align="center">
<h1><img src="static/images/logo@2x.png" alt="MacCMS20 Logo" width="80"/>苹果 CMS v12（maccms12）— ThinkPHP 8 版</h1>
<h2>AI 驱动的全能影视内容管理系统，建站效率翻倍</h2>
<p>集成 AI SEO 优化、多平台 AI 内容生成、数据安全管理与多云存储，一站式搞定影视网站搭建、运营与全球化部署</p>
<p>
<a href="https://github.com/ziyun0911/maccms12/stargazers"><img src="https://img.shields.io/badge/STARS-⭐-black?logo=github&style=for-the-badge"/></a>
<a href="https://github.com/ziyun0911/maccms12/forks"><img src="https://img.shields.io/badge/FORKS-🍴-blue?logo=git&style=for-the-badge"/></a>
<a href="https://github.com/ziyun0911/maccms12/blob/main/LICENSE"><img src="https://img.shields.io/badge/LICENSE-GPL--3.0-green?style=for-the-badge"/></a>
<a href="https://github.com/sponsors/ziyun0911"><img src="https://img.shields.io/badge/SPONSOR-❤️-pink?style=for-the-badge"/></a>
</p>
<p>
<a href="#"><img src="https://img.shields.io/badge/TELEGRAM-CHANNEL-blue?logo=telegram&style=for-the-badge"/></a>
<a href="#"><img src="https://img.shields.io/badge/TELEGRAM-GROUP-blue?logo=telegram&style=for-the-badge"/></a>
</p>
</div>
苹果 CMS v12（maccms12）— ThinkPHP 8 版 由 maccms10（ThinkPHP 5.1）整树迁移升级而来：内核 **ThinkPHP 8.1.4**，运行在 PHP 8.0.2+（本仓库验证环境 PHP 8.3.8），功能与数据结构保持兼容。

这份 README 只回答三件事：**跑起来要什么、目录长什么样、文档去哪儿读**。

- 具体操作（安装、配置、后台、模板、接口、插件……）在 [`说明文档/`](file:///D:/wwwroot/maccms/maccms12/说明文档) 的 12 份使用者手册里，入口见本文件第七节。
- 当年 v10→v12 的框架改造流水账已从本文件移出，收在 [`说明文档/90-变更记录.md` §3](file:///D:/wwwroot/maccms/maccms12/说明文档/90-变更记录.md)；**当前代码里核实存在的缺陷与遗留**在同一文件 §1，改代码前必读。

---

## 一、运行环境要求

| 项目 | 要求 / 本机验证值 | 依据 |
| --- | --- | --- |
| PHP | **≥ 8.0.2**（本机 8.3.8） | [composer.json:15](file:///D:/wwwroot/maccms/maccms12/composer.json#L15) |
| 框架 | ThinkPHP 8.1.4 + think-orm 3.0.34 + think-view 1.0.14 + think-multi-app 1.1.1 | [composer.json:16-19](file:///D:/wwwroot/maccms/maccms12/composer.json#L16) |
| 数据库 | MySQL 5.7 / 8.0+（本机 5.7.38），表前缀 `mac_`，编码 `utf8mb4` | [config/database.php](file:///D:/wwwroot/maccms/maccms12/config/database.php) |
| Web 服务器 | Nginx / Apache / IIS / PHP 内置服务器均可 | 伪静态规则四套样例见 [说明文档/伪静态规则/](file:///D:/wwwroot/maccms/maccms12/说明文档/伪静态规则) |
| 常用扩展 | PDO、pdo_mysql、GD、curl、openssl | — |
| 插件相关 | 本地上传 / 云安装 / 卸载备份需要 **zip 扩展（`ZipArchive`）**；常驻 WebSocket 插件（socialws）需要 `workerman/workerman`，靠 `composer require` 另装 | [composer.json:25-26](file:///D:/wwwroot/maccms/maccms12/composer.json#L25)、[10-插件与扩展手册.md §5.3/§8](file:///D:/wwwroot/maccms/maccms12/说明文档/10-插件与扩展手册.md) |

---

## 二、入口文件

| 入口 | 访问地址 | 说明 |
| --- | --- | --- |
| 前台 | `http://<域名>/index.php` | 站点前台，应用绑定在 [index.php:48](file:///D:/wwwroot/maccms/maccms12/index.php#L48) |
| 后台 | `http://<域名>/admin.php` | 管理后台，建议改名或加访问限制；入口检查与后台基类闸门见 [03-后台操作手册.md §1](file:///D:/wwwroot/maccms/maccms12/说明文档/03-后台操作手册.md) |
| 接口 | `http://<域名>/api.php` | 对外开放接口（内容读取、用户、站外入库、供数据、埋点等），鉴权分五类，见 [06-接口与采集手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/06-接口与采集手册.md) |
| 计划任务 | `http://<域名>/api.php/timming/index.html` | 定时调度入口，**无鉴权**，靠服务端限制来源 |
| 安装程序 | `http://<域名>/install.php` | 全新安装；存在 `app/data/install/install.lock` 后访问提示已锁 |

Nginx 需配 PATHINFO 伪静态（`try_files $uri $uri/ /index.php?s=$uri&$args;`）；`?s=/vod/detail/id/1` 兼容模式同样可用。

---

## 三、快速开始

```bash
# 1) 建一个空库（utf8mb4），把站点根目录指向本目录
# 2) 浏览器访问 install.php，按向导填数据库与站点信息（会写 config/database.php 与 app/extra/maccms.php）
# 3) 导入种子数据（安装向导里点「导入数据」即可，等价于手工执行）
#    app/install/sql/install.sql   —— 建 80 张表
#    app/install/sql/initdata.sql  —— 出厂数据
# 4) 装完删掉/保留 install.lock（保留即锁死安装），登录后台先改密码
# 5) 改过 static/ 下的 CSS/JS 源文件后重建资源包
php think front:bundle            # 生成五张 bundle（含后台 admin.bundle.css）
php think front:bundle --check    # 只校验是否与源文件一致，不写盘
```

命令行工具只有两个（[config/console.php](file:///D:/wwwroot/maccms/maccms12/config/console.php)）：`front:bundle` 与 `seo:generate`（AI SEO 文案批量生成，`--mid/--limit/--id`）。计划任务不是命令行工具，走 `api.php/timming`，详见 [07-定时任务与运维手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/07-定时任务与运维手册.md)。

用 PHP 内置服务器调试：`php -S 127.0.0.1:8080 router.php`（根 `router.php` 承担静态路由与内部文件拦截）。

---

## 四、数据库与配置放在哪

- **数据库连接**：[config/database.php](file:///D:/wwwroot/maccms/maccms12/config/database.php)，TP8 原生结构，参数统一在 `connections.mysql` 下；代码里读 `config('database.connections.mysql.*')`，没有 TP5 的顶层镜像键。该文件已在 `.gitignore` 内，**密码不入库、不出现在任何文档里**。
- **表**：建表脚本 [app/install/sql/install.sql](file:///D:/wwwroot/maccms/maccms12/app/install/sql/install.sql) 80 张。本机库里是 84 张——多出的 `mac_tmpart` / `mac_tmpmanga` / `mac_tmpwebsite` / `mac_vod_repeat` 由后台「重名数据」功能按需 `CREATE TABLE`（如 [app/admin/controller/Art.php:57-58](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Art.php#L57)），不在建表脚本里。
- **站点配置**：`app/extra/*.php` 共 16 个文件，由 [`mac_load_extra()`](file:///D:/wwwroot/maccms/maccms12/app/common.php#L14) 在各入口注册成独立配置组；其中大部分是**运行期用户数据**，后台保存时整文件 `var_export` 回写。逐文件说明见 [app/extra/README.md](file:///D:/wwwroot/maccms/maccms12/app/extra/README.md)，逐项作用见 [02-站点配置参考.md](file:///D:/wwwroot/maccms/maccms12/说明文档/02-站点配置参考.md)。
- **运行缓存与日志**：`runtime/`（不发布、不公开）。

---

## 五、默认后台账号

- 账号：安装向导里自己填的那个；本地测试站为 `admin`
- 密码：不写入文档；正式环境务必改掉。`mac_admin` 里存 bcrypt（老数据兼容 md5，首次登录成功自动升级为 bcrypt）
- 图形验证码开关：`app/extra/maccms.php` → `app.admin_login_verify`（`1` 开 / `0` 关）
- 子管理员按「控制器/动作」逐项授权；`admin_id = 1` 在权限判断里直接全放行，细节见 [09-安全与权限手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/09-安全与权限手册.md) 与 [03-后台操作手册.md §2](file:///D:/wwwroot/maccms/maccms12/说明文档/03-后台操作手册.md)

---

## 六、目录/文件说明

| 路径 | 说明 |
| --- | --- |
| `app/` | TP8 应用目录（common / admin / api / index / install） |
| `app/extra/` | 运行期生成的可覆盖配置（`maccms.php`、`vodplayer.php`、`version.php` 等 16 个） |
| `app/common/middleware/` | 12 个中间件（原 TP5 behavior 全部迁来） |
| `app/common/addon/` | 插件体系内核（Service / AddonManager / Dispatcher / helpers） |
| `app/common/extend/` | 七类扩展驱动（upload / sms / connect / …），机制见 10 §10 |
| `app/admin/view/` | 后台视图 |
| `config/` | TP8 全局配置（app / database / cache / route / view …） |
| `route/addons.php` | 插件公共路由（各应用入口在绑定后加载） |
| `addons/` | 插件目录（PSR-4 命名空间 `addons\`），插件的**代码**在这里，不在 `app/data/addon/` |
| `template/` | 前台模板（当前仅 `default`，PC 与移动端同用） |
| `static/` | 站点静态资源（`static_new/` 已并入） |
| `extend/` | 第三方 SDK / 类库 |
| `app/data/` | 程序自用的数据目录：安装锁、备份、升级包落地、后台助手知识卡片、插件开发文档 |
| `说明文档/` | 12 份使用者手册 + `伪静态规则/` + `火车头模块/`，从 [00-文档导航.md](file:///D:/wwwroot/maccms/maccms12/说明文档/00-文档导航.md) 进 |
| `vendor/` | Composer 依赖（**保持官方原样，本仓不打任何兼容补丁**） |
| `runtime/` | 运行缓存与日志 |

---

## 七、文档从哪里读

详细导航（按场景跳转、阅读顺序、旧 `.txt` 处置对照）在 [说明文档/00-文档导航.md](file:///D:/wwwroot/maccms/maccms12/说明文档/00-文档导航.md)。

| 编号 | 文件 | 讲什么 |
|---|---|---|
| 01 | [安装与升级手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/01-安装与升级手册.md) | 环境、安装器逐步、种子数据、升级与数据脚本、装完自检 |
| 02 | [站点配置参考.md](file:///D:/wwwroot/maccms/maccms12/说明文档/02-站点配置参考.md) | 配置三层结构，每个站点配置项「键 → 作用 → 后台入口 → 生效方式」 |
| 03 | [后台操作手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/03-后台操作手册.md) | 进后台的门、16 组菜单地图、内容编辑共性、播放地址格式、破坏性动作清单 |
| 04 | [前台模板与静态化手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/04-前台模板与静态化手册.md) | 三条出路径、模板选择链、`mac_url()`、路由与伪静态、静态生成 |
| 05 | [模板标签手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/05-模板标签手册.md) | 标签引擎、20 个标签、`$maccms` 全局变量、属性怎么查才算准 |
| 06 | [接口与采集手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/06-接口与采集手册.md) | `api.php` 五种鉴权、接口清单、对外供数据、站外入库 |
| 07 | [定时任务与运维手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/07-定时任务与运维手册.md) | 计划任务端点与调度判定、任务清单、crontab、缓存与日志、排障 |
| 08 | [多语言手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/08-多语言手册.md) | 界面语言 / 内容多语言 / 模板短语 / 前台短语四层机制 |
| 09 | [安全与权限手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/09-安全与权限手册.md) | 请求面、后台登录、权限模型、两套 CSRF 令牌、哈希闸门、五层下载拦截 |
| 10 | [插件与扩展手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/10-插件与扩展手册.md) | 四种扩展形态、插件包与 `info.ini`、6 个钩子插槽、云市场、七类驱动 |
| 90 | [变更记录.md](file:///D:/wwwroot/maccms/maccms12/说明文档/90-变更记录.md) | §1 已核实缺陷与遗留台账；§2-§3 迁移与核对记录归档 |

> 文档里的每一句都带 `文件:行`，可以直接对着代码核。行号会漂，所以同时写了函数名/键名。
> 后台「网站参数 → 说明文档」也能打开这一批文件，但那一页是 `<pre>` 原样输出，**Markdown 会显示成源码**；要阅读体验请在 IDE 或 Git 平台里看。

---

## 八、部署提示

- 正式部署请将站点根目录指向本目录，并保留 `install.lock`（`app/data/install/install.lock`）以锁定安装。
- 后台入口建议改名或加访问限制（`admin.php`）。
- `addons`、`extend` 目录结构与 maccms10 保持一致；插件的额外依赖（如 workerman）另行安装。
- 站点根目录即源码树，`说明文档/`、`runtime/`、`config/`、`app/`、`vendor/`、`extend/` 及
  `.md .sql .log .lock .wpm .sh .ps1 .py .bak .old .orig` 等内部文件一律禁止经 HTTP 直接下载：
  Apache 用根 `.htaccess`（nginx / IIS 的规则见 `说明文档/伪静态规则/` 内同名文件），
  PHP 内置服务器用根 `router.php`。五套规则的拦截名单需同步维护，新增根级文件时按此清单补全，
  现状与逐条依据见 [09-安全与权限手册.md §7](file:///D:/wwwroot/maccms/maccms12/说明文档/09-安全与权限手册.md)。
- 拦截的是公网直连，不是「文档看不到」：后台「网站参数 → 说明文档」（`admin.php/doc/index.html`，
  控制器 `app/admin/controller/Doc.php`）列出 `说明文档/` 与本 `README.md` 并渲染正文，
  仅登录管理员可见，子管理员需单独勾选 `doc/index`、`doc/info` 权限。只读白名单为
  `.txt .md .conf .ini .config`，超出站点文档目录的路径一律拒绝，单文件超过 1MB 只截断显示开头。

---

*本文档为 maccms12（ThinkPHP 8）仓库门面说明。原 maccms10 站点与数据库未做任何改动。*

---

## 九、关于苹果CMS（多语言项目简介）

苹果CMS程序是一套采用PHP+MYSQL环境下运行的完善而强大的快速建站系统。经过近多年的开发经验和技术积累，苹果CMS程序已逐步走向成熟，在易用性和功能上已经成为同行中的佼佼者。程序体积小->优化程序代码，运行速度快->高效的缓存处理，只要普通的虚拟主机就可以完美搭建起来，建站成本非常低。仿MVC模板分离，内置标签，自定义函数标签接口，强大的自定义采集功能，只要你会HTML就可以轻松做出个性化的网站。 程序易用性和功能上一直以来都积极采纳广大站长提出的各种好的建议，迅速响应各种紧急问题，我们的服务理念贯穿其中，保证每一位站长每一个环节都可以从容应对。v12 采用 ThinkPHP 8 内核进行开发，扩展了模板处理引擎，将后台程序与html模板简单的分离出来，让设计人员与程序人员最大限度的发挥自己的优势而互不干扰，大大加快了项目有序、快速的完成。即使您是第一次接触，也会在最短的时间内熟练掌握它的使用方法。后台管理模块，一目了然，操作简单，绝对不会让您眼花缭乱。

蘋果CMS程式是一套採用PHP+MYSQL環境下執行的完善而強大的快速建站系統。經過多年的開發經驗和技術累積，蘋果CMS程式已逐步走向成熟，在易用性和功能上已經成為同行中的佼佼者。程式體積小->最佳化程式碼，執行速度快->高效的快取處理，只要普通的虛擬主機就可以完美搭建起來，建站成本非常低。仿MVC模板分離，內建標籤，自訂函式標籤介面，強大的自訂採集功能，只要你會HTML就可以輕鬆做出個性化的網站。程式易用性和功能上一直以來都積極採納廣大站長提出的各種好的建議，迅速回應各種緊急問題，我們的服務理念貫穿其中，保證每一位站長每一個環節都可以從容應對。v12 採用 ThinkPHP 8 核心進行開發，擴充了模板處理引擎，將後台程式與html模板簡單地分離出來，讓設計人員與程式人員最大限度地發揮自己的優勢而互不干擾，大大加快了專案有序、快速的完成。即使您是第一次接觸，也會在最短的時間內熟練掌握它的使用方法。後台管理模組，一目了然，操作簡單，絕對不會讓您眼花撩亂。

Apple CMS program is a set of PHP and MYSQL environment operating in a perfect and powerful fast station system. After nearly years of development experience and technology accumulation, Apple CMS program has gradually matured, in ease of use and functionality has become the leader in peers. The program size is small - > optimizer code, running fast - > efficient caching processing, as long as the ordinary virtual host can be perfectly built, the cost of building a station is very low. Imitation MVC template separation, built-in tags, custom function label interface, powerful custom acquisition function, as long as you will HTML can easily make personalized website. Program ease of use and function has been actively adopted by the vast number of station director put forward a variety of good suggestions, quickly respond to a variety of urgent issues, our service concept throughout it, to ensure that every station director every link can be calmly coped with. v12 uses the ThinkPHP 8 kernel for development, extended the template processing engine, the background program and html template simple separation, so that designers and programmers to maximize their advantages without interference, greatly speed up the project orderly and fast completion. Even if you are in first contact, you will master how to use it in the shortest possible time. Back-office management module, at a glance, easy to operate, will never dazzle you.

アップルCMS（Apple CMS）は、PHP＋MySQL環境で動作する、完成度が高く強力な高速サイト構築システムです。長年にわたる開発経験と技術の蓄積により、アップルCMSは徐々に成熟し、使いやすさと機能の両面で同業他社の中でトップクラスの存在となりました。プログラム本体は軽量（コードを最適化）で動作は高速（効率的なキャッシュ処理）、一般的な仮想ホスティングさえあれば完璧に構築でき、サイト構築コストを大幅に抑えられます。MVC風のテンプレート分離、組み込みタグ、カスタム関数タグインターフェース、強力なカスタム収集機能を備え、HTMLさえ分かれば誰でも個性的なウェブサイトを簡単に作成できます。使いやすさや機能面については、これまで多くのサイト運営者の優れた提案を積極的に取り入れ、さまざまな緊急課題にも迅速に対応してきました。私たちのサービス精神はそのすべてを貫き、あらゆる場面で運営者が余裕をもって対応できることを保証します。v12 は ThinkPHP 8 カーネルを採用して開発され、テンプレート処理エンジンを拡張し、バックエンドプログラムとHTMLテンプレートをシンプルに分離することで、デザイナーとプログラマーがそれぞれの強みを最大限に発揮しつつ互いに干渉することなく、プロジェクトを整然かつ迅速に進められるようにしています。初めて触れる方でも、最短時間で使いこなせるようになります。バックエンド管理モジュールは一目瞭然で操作も簡単、決して迷わせません。

애플CMS(Apple CMS)는 PHP+MySQL 환경에서 동작하는 완성도 높고 강력한 빠른 사이트 구축 시스템입니다. 다년간의 개발 경험과 기술 축적을 바탕으로 애플CMS는 점차 성숙해졌으며, 사용 편의성과 기능 면에서 동종 업계 선두에 올랐습니다. 프로그램 크기가 작고(코드 최적화) 실행 속도가 빠르며(고효율 캐시 처리), 일반적인 가상 호스팅만으로도 완벽하게 구축할 수 있어 사이트 구축 비용이 매우 저렴합니다. MVC 방식의 템플릿 분리, 내장 태그, 커스텀 함수 태그 인터페이스, 강력한 커스텀 수집 기능을 제공하며, HTML만 알고 있다면 누구나 개성 있는 웹사이트를 손쉽게 만들 수 있습니다. 사용 편의성과 기능 면에서 그동안 수많은 운영자의 훌륭한 제안을 적극 수용해 왔고, 각종 긴급 이슈에도 신속히 대응해 왔습니다. 저희의 서비스 철학이 전 과정에 녹아 있어, 모든 운영자가 모든 단계에서 여유롭게 대응할 수 있도록 보장합니다. v12은 ThinkPHP 8 커널 기반으로 개발되어 템플릿 처리 엔진을 확장하였고, 백엔드 프로그램과 HTML 템플릿을 간단히 분리함으로써 디자이너와 개발자가 각자의 강점을 최대한 발휘하면서도 서로 간섭하지 않도록 하여 프로젝트를 질서 있고 신속하게 완료할 수 있게 합니다. 처음 사용하시는 분도 최단 시간 안에 능숙하게 사용할 수 있으며, 백엔드 관리 모듈은 한눈에 들어오고 조작이 간편하여 결코 혼란을 드리지 않습니다.

Apple CMS ist ein umfassendes und leistungsstarkes System zum schnellen Aufbau von Websites, das in einer PHP+MySQL-Umgebung läuft. Nach jahrelanger Entwicklungserfahrung und technischer Weiterentwicklung ist Apple CMS schrittweise gereift und gehört heute in puncto Bedienkomfort und Funktionsumfang zu den Spitzenreitern der Branche. Das Programm ist schlank (optimierter Code), schnell im Betrieb (effiziente Cache-Verarbeitung) und lässt sich bereits auf einem einfachen Webhosting perfekt einrichten – die Kosten für den Website-Aufbau sind dadurch sehr gering. Mit MVC-artiger Trennung von Template und Logik, integrierten Tags, einer Schnittstelle für benutzerdefinierte Funktions-Tags und einer leistungsstarken, anpassbaren Sammelfunktion genügen HTML-Kenntnisse, um problemlos eine individuelle Website zu erstellen. In Bezug auf Benutzerfreundlichkeit und Funktionen haben wir stets zahlreiche hervorragende Vorschläge aus der Community aufgegriffen und auf dringende Probleme schnell reagiert; unser Servicegedanke zieht sich durch das gesamte Produkt und stellt sicher, dass Betreiber in jeder Situation souverän reagieren können. v12 basiert auf dem ThinkPHP-8-Kernel, erweitert die Template-Engine und trennt Backend-Programm und HTML-Template klar voneinander, sodass Designer und Entwickler ihre Stärken ohne gegenseitige Beeinträchtigung voll ausspielen können – das Projekt schreitet dadurch geordnet und zügig voran. Selbst Einsteiger finden sich in kürzester Zeit zurecht. Das Backend-Verwaltungsmodul ist übersichtlich und einfach zu bedienen und wird Sie niemals überfordern.

Apple CMS est un système complet et puissant de création rapide de sites web, fonctionnant dans un environnement PHP+MySQL. Fort de plusieurs années d'expérience de développement et d'accumulation technique, Apple CMS a progressivement atteint sa maturité et s'impose aujourd'hui parmi les meilleurs de sa catégorie en matière d'ergonomie et de fonctionnalités. Le programme est léger (code optimisé), rapide à l'exécution (traitement de cache efficace) et peut être parfaitement installé sur un simple hébergement mutualisé, ce qui rend les coûts de mise en place très faibles. Grâce à une séparation de type MVC des modèles, des balises intégrées, une interface de balises de fonctions personnalisées et à une puissante fonction de collecte personnalisable, la seule connaissance du HTML suffit à créer facilement un site personnalisé. En matière d'ergonomie et de fonctionnalités, nous avons toujours activement pris en compte les excellentes suggestions des nombreux webmasters et réagi rapidement aux divers problèmes urgents ; notre esprit de service imprègne l'ensemble du produit et garantit à chaque exploitant de pouvoir faire face sereinement à chaque étape. La v12 est développée sur le noyau ThinkPHP 8, enrichit le moteur de traitement des templates et sépare simplement le backend des templates HTML, permettant aux designers et aux développeurs de tirer le meilleur parti de leurs compétences respectives sans interférence mutuelle, ce qui accélère considérablement l'avancement ordonné et rapide du projet. Même pour un premier contact, vous en maîtriserez l'utilisation en un minimum de temps. Le module d'administration du backend, clair et simple d'usage, ne vous perdra jamais dans une avalanche de détails.

Apple CMS es un sistema completo y potente de creación rápida de sitios web que funciona en un entorno PHP+MySQL. Gracias a varios años de experiencia en desarrollo y acumulación técnica, Apple CMS ha madurado de forma progresiva y se ha convertido en uno de los líderes de su sector tanto en facilidad de uso como en funcionalidad. El programa es ligero (código optimizado) y rápido (procesamiento de caché eficiente); basta con un alojamiento virtual común para montarlo a la perfección, por lo que el coste de crear un sitio es muy bajo. Con una separación de plantillas al estilo MVC, etiquetas integradas, una interfaz de etiquetas de función personalizadas y una potente función de recopilación personalizable, basta con saber HTML para crear fácilmente un sitio web a medida. En cuanto a usabilidad y funcionalidad, siempre hemos incorporado de forma activa las valiosas sugerencias de los numerosos administradores de sitios y hemos respondido con rapidez a todo tipo de incidencias urgentes; nuestra filosofía de servicio impregna todo el producto y garantiza que cada administrador pueda afrontar cada paso con tranquilidad. La v12 se desarrolla sobre el núcleo ThinkPHP 8, amplía el motor de plantillas y separa de forma sencilla el programa del backend y las plantillas HTML, permitiendo que diseñadores y programadores aprovechen al máximo sus puntos fuertes sin interferencias mutuas, acelerando considerablemente la finalización ordenada y rápida del proyecto. Aunque sea su primer contacto, dominará su uso en el menor tiempo posible. El módulo de administración del backend es claro y sencillo de manejar, y nunca le resultará abrumador.

Apple CMS é um sistema completo e poderoso para a criação rápida de sites, que funciona num ambiente PHP+MySQL. Após vários anos de experiência de desenvolvimento e acumulação técnica, o Apple CMS amadureceu progressivamente e destaca-se hoje entre os melhores do setor em facilidade de utilização e funcionalidade. O programa é leve (código otimizado) e rápido (processamento de cache eficiente); basta um alojamento virtual comum para o instalar na perfeição, o que torna o custo de construção de um site muito reduzido. Com separação de modelos ao estilo MVC, etiquetas integradas, uma interface de etiquetas de funções personalizadas e uma poderosa função de recolha personalizável, basta saber HTML para criar facilmente um site personalizado. Em termos de usabilidade e funcionalidades, sempre acolhemos ativamente as excelentes sugestões de muitos administradores de sites e respondemos rapidamente a diversos problemas urgentes; a nossa filosofia de serviço está presente em todo o produto e garante que cada administrador possa lidar com cada etapa com tranquilidade. A v12 é desenvolvida sobre o núcleo ThinkPHP 8, expande o motor de processamento de modelos e separa de forma simples o programa de backend dos modelos HTML, permitindo que designers e programadores aproveitem ao máximo as suas capacidades sem interferências mútuas, acelerando assim a conclusão ordenada e rápida do projeto. Mesmo no primeiro contacto, conseguirá dominar a sua utilização no mais curto espaço de tempo. O módulo de gestão do backend é claro e simples de utilizar e nunca o irá sobrecarregar.

## 十、免责声明

本程序仅供内部学习和交流使用，没有内置任何数据，请在遵守当地法律的前提下使用本站程序，对用户在使用过程中的自行维护的信息内容本站不负任何责任！

本程式僅供內部學習和交流使用，未內建任何資料，請在遵守當地法律的前提下使用本站程式，對使用者在使用過程中自行維護的資訊內容，本站不負任何責任！

This program is for internal learning and communication use only, there is no built-in data, please comply with local laws under the premise of using the site program, the user in the process of self-maintenance of the information content of this site is not responsible!

本プログラムは内部での学習および交流を目的としたものであり、データは一切同梱されていません。現地の法律を遵守したうえでご利用ください。ユーザーが利用時にご自身で追加・管理される情報内容について、当サイトは一切責任を負いません！

본 프로그램은 내부 학습 및 교류 용도로만 제공되며, 내장된 데이터는 전혀 없습니다. 현지 법률을 준수하는 전제 하에 본 프로그램을 사용해 주시기 바라며, 사용자가 이용 과정에서 직접 관리하는 정보 내용에 대해 본 사이트는 어떠한 책임도 지지 않습니다！

Dieses Programm dient ausschließlich dem internen Lernen und Austausch und enthält keinerlei vorinstallierte Daten. Bitte verwenden Sie es nur unter Einhaltung der örtlichen Gesetze. Für Inhalte, die der Nutzer im Rahmen der Nutzung selbst pflegt, übernimmt diese Seite keinerlei Verantwortung!

Ce programme est destiné uniquement à l'apprentissage et aux échanges internes et ne contient aucune donnée préinstallée. Veuillez l'utiliser dans le respect des lois locales. Ce site décline toute responsabilité quant aux contenus que l'utilisateur gère lui-même lors de son utilisation !

Este programa está destinado únicamente al aprendizaje y al intercambio interno y no incluye datos predefinidos. Utilícelo siempre respetando las leyes locales. Este sitio no asume ninguna responsabilidad por los contenidos que el usuario gestione por sí mismo durante su uso.

Este programa destina-se apenas à aprendizagem e à troca interna e não inclui quaisquer dados pré-instalados. Utilize-o sempre em conformidade com a legislação local. Este site não se responsabiliza por quaisquer conteúdos que o utilizador mantenha por conta própria durante a utilização!
