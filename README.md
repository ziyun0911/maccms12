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
4. **公共层**：TP5 兼容助手里 `app/common.php` 仅保留 `mac_load_extra()`（将 `app/extra/*.php` 注册为独立配置组，等价 TP8 的 extra 配置扩展）；已移除 `mac_config_set()`、`model()`、`THINK_VERSION`、`class_alias` 等兼容助手。其余 200+ 个 `mac_*` 函数是程序自身的助手族（配置读写 `mac_arr2file()` / `mac_save_config_data()`、模板与 URL `mac_tpl_file()` / `mac_url()`、输出 `mac_echo()` / `mac_jump()` 等），不属于兼容层。
5. **控制器基类**：`Jump` 的 `redirect()` 改为先用 `url()` 拼装参数再跳转（TP8 `redirect(string,int)` 不再收 params）。
6. **表前缀占位符**：`__ANALYTICS_SESSION__` / `__COUPON__` / `__USER__` 等 `__TABLENAME__` 占位符（think-orm 不解析）替换为实际表名（`mac_analytics_session` 等）；读取前缀统一走 `connections.mysql.prefix`。
7. **标签库**：`app/common/taglib/Maccms.php` 9 处 `paging=='yes'` 增加空值防御。
8. **后台视图路径**：`app/admin/view/` 下 `../../../application/` → `../../../app/`（迁移时一次改了 352 处；现存 `../../../app/` 引用 360 处，`application/` 残留 0 处，其余 `application/json` 等为 MIME 类型）；中间件按入口设置 `view.view_dir_name`。
9. **路由完整匹配**：`config/route.php` 开启 `route_complete_match => true`，避免含可选变量的规则（如 `actor-【page】`）前缀匹配劫持 detail/type 等其他路由。
10. **查询构造器**：`app/common/db/Query` 继承 `think\db\Query`，兼容旧代码中 `limit('offset,length')` / `page('x,y')` 的字符串写法，不改动 vendor 源码。
11. **插件体系**：`app/common/addon`（Service / AddonManager / helpers）为 TP8 原生实现，插件命名空间 `addons\` 映射 `/addons`，支持动态挂载、启停、配置与跨模块视图布局；插件 SQL 使用 `__PREFIX__` 占位符。
12. **入口路由挂载**：各入口通过 `$app->http->name(...)` 绑定应用；插件路由由 `route/addons.php` 及各应用 `route/addons.php` 加载。

### 清理项（相对 maccms10 遗留）

- 删除 TP5 惰性死代码：`app/common/behavior/*`（12 个，已迁为中间件）、`app/tags.php`
- 删除无用文件/目录：`theme-request-form/`、`tests/`、`storage/`（空）
- 删除 `tp5_buding/` 兼容补丁包：三处 vendor 补丁已回滚，`vendor/` 保持 Composer 原样（`composer update` 后无需再打补丁）
- `static_new/` 已并入 `static/`（同名以 static_new 版本覆盖，保留目录名 `static`），代码中 17 处 `static_new` 引用统一改为 `static`（现全库 `static_new` 引用 0 处）
- 6 份界面语言包（`app/lang/*.php`）里 5 处提示文案的旧目录名 `application/data/admin_assistant/`、`application/data/addon/local_public.pem`、`application/extra/addons.php` 改为 `app/...`；快捷菜单示例地址 `/application/xxxx.html` 改为插件路由 `/index.php/addons/plugin/controller/action`（`app/` 已被 HTTP 拦截，旧示例点了只会 403/404）
- `middleware/Init.php` 中 memcache/memcached 动态配置改用 TP8 `Config::set([...], 'cache')` 整组写入
- 后台「定时任务 → 执行文件」下拉补齐为 14 项（新增 `annotate` / `content_quality` / `user_profile`，与安装脚本预置的 `content_ai_annotate` / `content_quality(_art)` / `user_profile` 对应）；此前这三条预置任务在下拉里无匹配项，编辑后保存会静默落到第一项 `collect`。现在不在候选项里的自定义执行文件也会作为保底 option 原样保留（`app/admin/view/timming/info.html`）。
- 插件 zip 顶层目录黑名单 `AddonSecureInstaller::$blockedPrefixes` 补上 TP8 的 `app/`、`config/`、`route/`（原来只有 maccms10 的 `application/` 等），两种命名的插件包都拦得住。

---

## 六、目录/文件说明

| 路径 | 说明 |
| --- | --- |
| `app/` | TP8 应用目录（common / admin / api / index / install） |
| `app/extra/` | 运行期生成的可覆盖配置（maccms.php、version.php 等） |
| `app/common/middleware/` | 迁入的中间件 |
| `config/` | TP8 全局配置（app / database / cache / view …） |
| `template/` | 前台模板（当前仅 `default`，PC 与移动端均使用它） |
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

- 接口对接 / 二次开发 → `API接口说明.txt`（含 maccms12 独有补充接口第十章；文末附录A 为老格式 Provide 聚合接口速查与 JSON / XML 样例）
- 火车头等第三方入库 → `站外入库接口说明.txt` + `火车头模块/`（6 个 .wpm，兼容）
- 伪静态部署 → `伪静态规则/`（Apache / IIS6 / IIS7+ / Nginx + rewrite 说明 + 默认路由规则）
- 模板开发 → `标签说明.txt`（模板结构 / 全局与模块标签 / 处理函数 / JS 类处理）
- 采集调度 → `定时任务说明.txt`（api.php/timming + 后台执行文件）
- 多语言 → `多语言支持说明.txt`（界面语言包）；`内容级多语言说明.txt`（内容级多语言，maccms12 已按官方 master 蓝本完整实现）
- 模板开发 / 排查 → `标签说明.txt`（模板标签）；`模板修复与试用说明.txt`（conch / datll_Layui_v1 的历史修复记录，两套模板未随包提供）
- 导航与差异摘要 → `00-说明文档总览.txt`

> 文档内容已与 maccms12 代码库/数据库逐项核对；官方文档中 maccms12 未实现的功能（Novel 小说、Sycms）均已醒目标注；内容级多语言为 maccms12 已实现功能。

## 八、部署提示

- 正式部署请将站点根目录指向本目录，并将 `install.lock`（`app/data/install/install.lock`）保留以锁定安装。
- 后台入口建议改名或加访问限制（`admin.php`）。
- `addons`、`extend` 插件目录结构与 maccms10 保持一致，插件升级依赖另行安装（如 workerman）。
- 站点根目录即源码树，`说明文档/`、`runtime/`、`config/`、`app/`、`vendor/`、`extend/` 及
  `.md .sql .log .lock .wpm .sh .ps1 .py .bak .old .orig` 等内部文件一律禁止经 HTTP 直接下载：
  Apache 用根 `.htaccess`（nginx / IIS 的规则见 `说明文档/伪静态规则/` 内同名文件），
  PHP 内置服务器用根 `router.php`。五套规则的拦截名单需同步维护，新增根级文件时按此清单补全。

---

*本文档为 maccms12（TP8）交付说明。原 maccms10 站点与数据库未做任何改动。*

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
