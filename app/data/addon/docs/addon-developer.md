# 插件开发文档（Addon Developer Guide）

适用：maccms12（ThinkPHP 8 / PHP ≥ 8.0.2）本地插件。

> **插件代码在仓库根的 `addons/`，不在 `app/data/addon/`。** 本目录（`app/data/addon/`）只放开发者文档与云市场素材。
> 命名空间由 composer 的 PSR-4 映射固定：`"addons\\" => "addons"`（[composer.json:33](file:///D:/wwwroot/maccms/maccms12/composer.json#L33)）。
> 站长视角的操作与排障看 [说明文档/10-插件与扩展手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/10-插件与扩展手册.md)，安全边界看 [09-安全与权限手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/09-安全与权限手册.md)；本文只讲**怎么写插件**。

现成参照：`addons/adminloginbg`（最小钩子）、`addons/aicontent`（配置 / SQL / 控制器 / 模型 / 视图 / 语言包 / 自带静态资源部署）、`addons/socialws`（多钩子 + 控制器）。

---

## 1. 插件系统是本仓库自己实现的

v10 时代依赖的 `karsonzhang/fastadmin-addons` **在本仓库不存在**（`vendor/karsonzhang/` 目录都没有），所有 `think\Addons`、`think\addons\Route`、`think\addons\Controller`、`\think\Hook::listen()` 的写法都不要照抄。实现全部在 [app/common/addon/](file:///D:/wwwroot/maccms/maccms12/app/common/addon)：

| 文件 | 作用 |
|---|---|
| [`Addon.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Addon.php) | 插件主类基类（`abstract class Addon`，`:4`） |
| [`Controller.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Controller.php) | 插件 HTTP 控制器基类（`abstract class Controller`，`:6`） |
| [`Dispatcher.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Dispatcher.php) | `addons/...` URL 的唯一入口 `run()`（`:8`） |
| [`AddonManager.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonManager.php) | 启用插件实例缓存 + 钩子调度（`enabled()` `:14`、`dispatch()` `:44`） |
| [`Service.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php) | 校验 / 卸载 / 启停 / 跑 SQL / 写登记表 |
| [`helpers.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php) | 全局函数：`addon_info` / `addon_list` / `addon_config` / `addon_full_config` / `addon_save_full_config` / `addon_save_info` / `addon_class` / `addon_url` / `addon_hook` |
| [`AddonException.php`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonException.php) | 生命周期异常 |

---

## 2. 目录与命名

插件根目录 `addons/{name}/`，`{name}` 必须匹配 `^[a-z][a-z0-9_]{0,31}$`（[`AddonSecureInstaller::isValidName()`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L37)），且与 `info.ini` 的 `name` 一致（[`Service::check()`:26](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L26) 是唯一强校验点）。

```
addons/demo/
├── Demo.php                 # 主类（必填），文件名 = ucfirst(name)
├── info.ini                 # 元信息（必填）
├── config.php               # 配置项 schema（可选）
├── config.values.php        # 站长填的实际值 —— 由后台生成，不要手写进包
├── install.sql              # 安装 SQL（可选）
├── controller/              # 可选：Index.php / Admin.php / Api.php ...
├── model/                   # 可选，命名空间 addons\demo\model\X
├── view/                    # 可选，控制器 fetch() 的模板根目录
├── assets/                  # 可选：核心不会复制它，要插件自己部署（见 §7.3）
└── lang/                    # 可选：核心不会加载它，要插件自己 Lang::load()（见 §7.4）
```

- 主类文件名允许三种形态，按 [`addon_class()`:180](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L180) 的顺序探测：`Demo.php` → `demo.php` → `CamelCase.php`（下划线名转大驼峰）；**新插件用 `ucfirst(name).php`**。
- **没有** `bootstrap.js` 机制：全仓搜不到 `addons.js` 的合并逻辑，v10 的这条约定在 v12 无效。
- `controller/`、`model/` 之外还可以有任意自有子命名空间目录（`aicontent` 就带 `service/`），只要命名空间前缀是 `addons\{name}\` 就能自动加载。

主类写法：

```php
<?php
namespace addons\demo;

use app\common\addon\Addon;

class Demo extends Addon
{
    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
```

基类提供的东西很少（[Addon.php](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Addon.php)）：

| 成员 | 位置 | 说明 |
|---|---|---|
| `$info` | `:6` | 构造时从 `info.ini` 合并（`:18-21`） |
| `$addon_path` | `:8` | `root_path('addons') . {name} . DS`（`:15`）—— **单数**，v10 文档写的 `addons_path` 在这里不存在 |
| `$addon_dir` | `:10` | 与 `$addon_path` 同值（`:16`） |
| `getName()` | `:24` | 从命名空间第 2 段取小写插件名 |
| `getInfo($name = null)` | `:30` | 取整个 `info` 或单个键 |
| `getConfig($name = null, $default = null)` | `:38` | 转调 `addon_config()`，见 §6 |
| `install()` / `uninstall()` | `:47` / `:52` | 默认 `return true`，可覆写 |

`enable()` / `disable()` / `upgrade()` **不在基类里**，由 [`Service::enable()`:80](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L80)、[`Service::disable()`:99](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L99) 用 `method_exists()` 探测；写了就必须在**返回 false 时自己已经清理好**，否则启停会被判定失败（见 §4）。

---

## 3. `info.ini`

只有 `name` 是被强制的（[Service.php:21-28](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L21)：文件必须存在，且 `name` 小写后等于目录名）。其余键都是**给后台列表看的**，缺了不报错，只是显示空白。

| 字段 | 后台是否用到 | 说明 |
|---|---|---|
| `name` | ✅ 强制 | 必须等于目录名 |
| `title` / `intro` / `author` / `image` / `price` | ✅ | [`Addon::downloaded()`:108-140](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L108) 列表渲染；缺失时从旧市场元数据补，`price` 兜 `0.00` |
| `version` | ✅ | 只做展示与升级比对 |
| `state` | ✅ 行为键 | `0` 禁用 / `1` 启用；启用与否直接决定钩子和 URL 是否可达（[`AddonManager::enabled()`:22](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonManager.php#L22)、[`Dispatcher::run()`:21](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Dispatcher.php#L21)） |
| `website` | ➖ | 核心代码不读 |
| `url` | ✅ | 列表「管理/访问」链接的入口，值形如 `/addons/aicontent/admin/index`（见 `addons/aicontent/info.ini`） |

注意两点：

1. `addon_info()` 会**注入**两个运行时键：`state` 缺省当 `0`、`install` 恒 `1`（[helpers.php:24-26](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L24)）——这两个键不出现在文件里是正常的。
2. 启停会**整文件重写** `info.ini`（[`addon_save_info()`:149-164](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L149)）：只保留标量键、值里的换行被压成空格、含 `=[]";#` 的值加引号。所以 **`info.ini` 不要写注释、不要写分节/数组**。

---

## 4. 生命周期：谁调什么

后台入口全在 [app/admin/controller/Addon.php](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php)（继承 `Base`，构造函数 `:16` 把 `view_path` 锁到 `app/admin/view/`）。所有 POST 动作先过一次性 `__token__` 校验（如 [`config()`:52-56](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L52)）。

| 动作 | 后台方法 | 实际调用顺序 |
|---|---|---|
| 本地 zip 安装 | [`local()`:485](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L485) | `UploadCheck::checkError($file,['zip'],10240000)` → [`extractZipSafe()`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L52) 到 `runtime/addons/stage_*` → `info.ini` / 名称 / [签名](local-package-signing.md) / 主类文件 / **目标目录必须不存在** 四道检查（`:538-572`）→ `rename` 落到 `addons/{name}/`（`:583`）→ `Service::check` → **强制 `state=0`**（`:598-601`）→ `$addon->install()` → `Service::importsql()` → `Service::refresh()`（`:603-609`）；异常则整目录清空回滚（`:616-622`） |
| 云市场安装 / 升级 | [`cloudInstall()`:379](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L379)、[`upgrade()`:642](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L642) | 转 [`AddonCloudService::installById()`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php)，插件侧同样调 `$addon->install()`（`:290`） |
| 旧在线安装 | [`install()`:149](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L149) | **已硬关闭**：直接 `error(lang('admin/addon/cloud_use_catalog'))`，不装任何东西。`Service::install()`（[Service.php:36](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L36)）因此没有后台调用方 |
| 卸载 | [`uninstall()`:404](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L404) | **先备份**，[`backupAddon()`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L431) 返回 `false` 就直接报错中止（`:421-425`）→ `Service::uninstall($name,$force)`：`$addon->uninstall()` 返回 `false` 且未 `force=1` 则抛异常（[Service.php:55](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L55)）→ 递归删 `addons/{name}/` → `AddonManager::reset()` + `refresh()`；失败且目录已消失时用备份还原（`:431-432`） |
| 启用 / 禁用 | [`state()`:445](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L445) | `action` 只认 `enable`/`disable`（`:463`）→ `Service::enable/disable`：跑插件钩子方法、回写 `info.ini` 的 `state`、`AddonManager::reset()`、`refresh()`，最后清 `__menu__` 缓存（`:469-473`） |
| 保存配置 | [`config()`:31](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L31) | 见 [`addon-config-ui.md`](addon-config-ui.md) |

给开发者的三条结论：

1. **表结构清理不会被自动做**。核心只删目录，DROP 语句必须写在 `uninstall()` 里（参考 [`Aicontent::uninstall()`:104-117](file:///D:/wwwroot/maccms/maccms12/addons/aicontent/Aicontent.php#L104)）。
2. **安装后是禁用态**（`local()` 强制 `state=0`），站长必须手动启用；不要假设 `install()` 执行时你的钩子已经在跑。
3. `install()` 之后核心紧接着会调 `Service::importsql()`，所以 `install()` 里**自己再跑一遍 `install.sql` 是重复执行**——只有全靠 `CREATE TABLE IF NOT EXISTS` 才不出错（`aicontent` 就是靠这个，见 [Aicontent.php:18-32](file:///D:/wwwroot/maccms/maccms12/addons/aicontent/Aicontent.php#L18)）。想避免重复就别在 `install()` 里跑 SQL。

### 4.1 `install.sql`

[`Service::importsql()`:110-121](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L110) 的实现很薄：

- 把 `__PREFIX__` 替换成 `config('database.connections.mysql.prefix')`（`:117`，本站为 `mac_`）；
- 按 `;` 切句、`trim`、跳过空串后逐条 `Db::execute()`（`:118-120`）。

**没有**注释剥离、**没有** `INSERT` → `INSERT IGNORE` 改写、**没有** `DELIMITER` 支持。因此：

- 不要写 `-- xxx` / `/* xxx */` 注释行，它们会被当成一条语句发进 MySQL；
- 不要写存储过程 / 触发器；
- 每条语句必须是完整、独立、可重跑的 DDL（配 `IF NOT EXISTS`），或自己承担重复执行的报错。

```sql
CREATE TABLE IF NOT EXISTS `__PREFIX__demo_item` (
  `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL DEFAULT '',
  `item_time` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

卸载示例（照 `aicontent` 的写法，前缀从配置取）：

```php
public function uninstall(): bool
{
    $pre = (string) config('database.connections.mysql.prefix');
    \think\facade\Db::execute('DROP TABLE IF EXISTS `' . $pre . 'demo_item`');
    $dir = ROOT_PATH . 'static' . DS . 'addons' . DS . 'demo';
    if (is_dir($dir)) { /* 自行递归删除 */ }
    return true;
}
```

---

## 5. 钩子

### 5.1 触发方式是 `addon_hook()`，不是 `Hook::listen()`

全仓 `\think\Hook` / `Hook::listen` **零调用**（只在本目录旧文档里出现过）。核心用 [`addon_hook(string $hook, $params = null)`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L202)，它转调 [`AddonManager::dispatch()`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonManager.php#L44)。

钩子名 → 主类方法的映射是 `AddonManager::toMethod()`（`:39-42`）：`_` 和 `-` 转驼峰、首字母小写，即 `admin_login_init` ↔ `adminLoginInit`。反方向由 [`Service::refresh()`:135](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L135) 用 `preg_replace('/(?<!^)[A-Z]/', '_$0', ...)` 生成。

### 5.2 现有插槽一览（这就是你能用的全部）

| 钩子名 | 触发点 | 参数 | 站点里现用的插件方法 |
|---|---|---|---|
| `app_init` | [`AddonInit` 中间件:11](file:///D:/wwwroot/maccms/maccms12/app/common/middleware/AddonInit.php#L11) | `null` | `Socialws::appInit`、`Aicontent::appInit` |
| `admin_login_init` | [`admin/Index::login`:30](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Index.php#L30) | `\think\Request` **对象**，按引用声明 | `Adminloginbg::adminLoginInit(\think\Request &$request)` |
| `action_begin` | [`common/controller/All.php:38`](file:///D:/wwwroot/maccms/maccms12/app/common/controller/All.php#L38) | `$this->request` | `Aicontent::actionBegin(&$params)` |
| `view_filter` | [`All.php:67`](file:///D:/wwwroot/maccms/maccms12/app/common/controller/All.php#L67)、[`addon/Controller::fetch`:39/:44](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Controller.php#L39) | 渲染后的 HTML 字符串 | `Aicontent::viewFilter(&$content)` |
| `social_broadcast` | 6 个模型写库后：[Chatroom.php:148](file:///D:/wwwroot/maccms/maccms12/app/common/model/Chatroom.php#L148)、[Danmaku.php:145](file:///D:/wwwroot/maccms/maccms12/app/common/model/Danmaku.php#L145)、[Dynamics.php:100](file:///D:/wwwroot/maccms/maccms12/app/common/model/Dynamics.php#L100)、[Follow.php:316](file:///D:/wwwroot/maccms/maccms12/app/common/model/Follow.php#L316)、[ShareLog.php:137](file:///D:/wwwroot/maccms/maccms12/app/common/model/ShareLog.php#L137)、[UserPm.php:139](file:///D:/wwwroot/maccms/maccms12/app/common/model/UserPm.php#L139) | 数组 | `Socialws::socialBroadcast($params)` |

业务代码想加新插槽，必须把 `addon_hook('新钩子名', $参数)` 合进主库，并在本表登记；**插件不能假设未合入的钩子存在**。

### 5.3 参数与返回值的真实语义

`dispatch($hook, $params)` 拿到的是**值拷贝**（[AddonManager.php:44-54](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonManager.php#L44)）：

- 处理器签名写 `&$param` 只能改到 dispatch 内部的那份拷贝，改不到调用方的变量；
- 所以 `dispatch()` **返回这份拷贝**，只有把返回值用起来的位置才看得到修改——`view_filter` 正是 `return addon_hook('view_filter', $html);`（[All.php:67](file:///D:/wwwroot/maccms/maccms12/app/common/controller/All.php#L67)）；
- 传对象（`admin_login_init` 传 `Request`）不受这条限制，对象属性改动直接可见。

异常与静态性：处理器抛任何 `\Throwable` 都会被吃掉并写日志 `[addon] hook ... failed: ...`（[AddonManager.php:48-52](file:///D:/wwwroot/maccms/maccms12/app/common/addon/AddonManager.php#L48)），不会拖垮宿主请求——但**别指望异常有提示**，要排错请自己看 `runtime/log`。`static` 方法和基类自身声明的方法不会被当成钩子（`:74-76`）。

### 5.4 `app/extra/addons.php` 只是登记表，不参与调度

[`Service::refresh()`:123-155](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L123) 每次启停 / 装 / 卸 / 存配置都会重写它，恒写 `autoload = true`、`route = []`，`hooks` 是「钩子名 → 插件名」映射（`:141-145`）。**全仓没有任何代码读 `config('addons')`**，所以：

- 钩子是否生效取决于「插件 `state=1` + 主类有同名方法」，`AddonManager` 每次请求现场反射（`:65-78`），静态属性只在同请求内缓存；
- 改主类的钩子方法后，下一个请求就会认，**不需要** refresh 或重新启用；
- 仓库里那份出厂 `addons.php` 写着 `autoload = false`、`hooks` 为空，属从未跑过 refresh 的历史残留，别拿它当事实来源。

---

## 6. 配置读写

配置页的表单渲染与校验规则单独写在 [`addon-config-ui.md`](addon-config-ui.md)，这里只给开发者视角的三件事：

1. **`config.php` 是 schema，不是值。** 它返回索引数组，每项描述一个表单项（`name` / `title` / `type` / `content` / `value` / `rule` / `tip` / `msg` / `ok` / `extend`）。`value` 只是**出厂默认值**。
2. **站长填的值落在 `addons/{name}/config.values.php`**（[`addon_save_full_config()`:131-132](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L131)），读取时按 `name` 覆盖 schema 里的 `value`（[`addon_full_config()`:86-98](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L86)）。**打包发布时不要带 `config.values.php`**，那是站长的数据。
3. **v12 没有 `get_addon_config()` / `get_addon_fullconfig()` / `set_addon_fullconfig()`**（全仓仅出现在本文档的旧版本里）。可用的是：

```php
$cfg  = $this->getConfig();               // 主类内，name => value（Addon.php:38）
$val = $this->getConfig('api_key', '');   // 单键 + 默认值
$cfg  = addon_config('demo');             // 全局函数，helpers.php:105
$rows = addon_full_config('demo');        // 完整 schema 数组，helpers.php:58
```

最小 schema 见 `addons/adminloginbg/config.php`，复杂表单见 `addons/aicontent/config.php`。

---

## 7. 控制器、视图与静态资源

### 7.1 URL 与调度

路由在 [route/addons.php](file:///D:/wwwroot/maccms/maccms12/route/addons.php)，三条规则全部指向 `\app\common\addon\Dispatcher@run`：

| URL | 定义 | 默认值 |
|---|---|---|
| `/addons/{addon}/{controller}/{action}` | `:6` | — |
| `/addons/{addon}/{controller}` | `:8` | `action=index` |
| `/addons/{addon}` | `:11` | `controller=index`、`action=index` |

段内模式由 `:4` 限定：`addon` 必须 `[a-z][a-z0-9_]*`，`controller` / `action` 必须 `[A-Za-z][A-Za-z0-9_]*`。

[`Dispatcher::run()`:8-38](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Dispatcher.php#L8) 的判定顺序（任何一步不过都是 404）：

1. 三段名字各自过正则（`:14-18`）；
2. `addon_info()` 取到的 `state` 为空 → 404（`:20-23`）——**禁用中的插件连前台页面也进不去**；
3. 类名固定拼 `addons\{addon}\controller\{Ucfirst}`（`:25`），不存在 → 404；
4. 实例化后 `method_exists` 校验 action（`:31-33`），先调 `_initialize()` 再调 action（`:35-36`）；
5. 返回值不是 `\think\Response` 就套 `response()`（`:38`）。

URL 由 `route/addons.php` 直接命中，**不依赖 ThinkPHP 的控制器/方法名解析**，也没有 v10 那套 `think\addons\Route`。所以旧文档里「路由检测被关掉，要在 `app_init` 里 `App::route(true)`」的补丁**不需要**——`Socialws::appInit()` 现在就是空实现（[addons/socialws/Socialws.php:19-21](file:///D:/wwwroot/maccms/maccms12/addons/socialws/Socialws.php#L19)）。

生成链接用 [`addon_url($name, $params = [])`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/helpers.php#L189)：拼的是「当前入口文件 + `/addons/` + 路径」，即 `addon_url('demo/admin/index')` → `/index.php/addons/demo/admin/index`（入口常量 `IN_FILE`，`:193`）。

### 7.2 控制器基类与鉴权

```php
<?php
namespace addons\demo\controller;

use app\common\addon\Controller;

class Index extends Controller
{
    public function index()
    {
        return $this->fetch();      // 模板根 = addons/demo/view/
    }
}
```

[`app\common\addon\Controller`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Controller.php) 提供：

| 成员 | 位置 | 说明 |
|---|---|---|
| `$this->request` | `:17` | `request()` 实例 |
| `$this->addon` | `:19` | 从命名空间第 2 段取的小写插件名 |
| `_initialize()` | `:22` | 空实现，覆写时记得 `parent::_initialize()` |
| `fetch($template, $vars)` | `:26` | 把 `view_path` 指到 `addons/{name}/view/`，`$vars['title']` 缺省填 `ucfirst(插件名)`，输出统一过 `view_filter` 钩子 |
| `assign()` / `success()` / `error()` | `:73` / `:79` / `:84` | `success`/`error` 返回 `json(['code'=>1|0,'msg','data','url'])` |
| `$noNeedLogin` / `$noNeedRight` | `:12-13` | **核心完全不读**，只是插件自己的习惯命名 |

> **调度器不做任何管理员校验。** `Dispatcher::run()` 只查 `state`，不查登录、不查权限、不查 CSRF。后台型插件必须自己在 `_initialize()` 里校验 CMS 会话，参考 [`aicontent/controller/Admin.php`:18-29](file:///D:/wwwroot/maccms/maccms12/addons/aicontent/controller/Admin.php#L18)（`session('admin_auth') !== '1' || empty(session('admin_info'))` 判登录，POST 用 `hash_equals` 比对会话内的 `_csrf_token`）。子管理员权限同理不会自动继承，插件页要自己收敛。

视图里可以引用后台模块的模板：`{extend name="admin@..." /}` / `{include file="admin@..." /}` 会被 [`resolveCrossModule()`:47-58](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Controller.php#L47) 换算成相对路径——仅当模板正文里检测到这种 `模块@路径` 写法时才走这条分支（`:37`）。

### 7.3 静态资源：核心不搬，自己搬

`Init.php:96` 定义了常量 `MAC_ADDON_PATH_STATIC = ROOT_PATH.'static/addons/'`，但**全仓再无引用**；[`Service`](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php) 里也没有任何复制逻辑。所以插件的 `assets/` 目录放那儿不会自动生效，需要自己在 `install()` / `enable()` 里复制，参考 [`Aicontent::deployAssets()` + `copyDir()`](file:///D:/wwwroot/maccms/maccms12/addons/aicontent/Aicontent.php#L50)（按 `filemtime` 增量覆盖，`:77`），并在 `uninstall()` 里删掉 `static/addons/{name}`（`:109-114`）。

> 注意 `static/addons/` 在插件本地安装的**屏蔽前缀清单**里（见下节 §8）：zip 包里不能带 `static/...` 条目，静态资源只能由插件代码在运行期复制出去。

### 7.4 语言包：核心不加载，自己 load

[`app/common/controller/All.php:52-55`](file:///D:/wwwroot/maccms/maccms12/app/common/controller/All.php#L52) 只加载 `app/lang/{语言}.php`，不会碰 `addons/{name}/lang/`。要插件文案跟着语言走，就在 `appInit()` 里自己 `Lang::load()`，参考 [`Aicontent::appInit()`:157-168](file:///D:/wwwroot/maccms/maccms12/addons/aicontent/Aicontent.php#L157)（从 `think_lang` cookie 取 locale、找不到回落 `zh-cn`/`en`）。界面语言集合见 `config/lang.php:4`（`zh-cn` / `zh-tw` / `en`）。

---

## 8. 本地包的安全闸门

后台上传 zip 走 [`AddonSecureInstaller`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php)：

- 屏蔽的顶层前缀 [`$blockedPrefixes`:6-19](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L6)：`application/`、`app/`、`config/`、`route/`、`static/`、`thinkphp/`、`extend/`、`vendor/`、`template/`、`public/`、`runtime/`、`addons/`（同时带 v10 旧名 `application/` 与新名 `app/`）。命中的是**条目相对路径的前缀**，`assets/app/icon.png`、根级 `config.php` 这类不受影响。
- 屏蔽扩展名 [`$blockedExt`:21-24](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L21)：`phtml phar php3 php4 php5 php7 phps shtml asp aspx jsp`。
- 屏蔽基名 [`$blockedBasenames`:26-31](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L26)：`.htaccess`、`.htpasswd`、`.user.ini`、`web.config`。
- 体积上限（`:33-35`）：条目数 5000、解压后总字节 50 MB、单条目 10 MB；上传本身另有 [`local()`:503](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L503) 的 10 240 000 字节上限。
- 解包只发生在 `runtime/addons/stage_*` 临时目录，全部校验通过才 `rename` 进 `addons/{name}/`（[Addon.php:524-593](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L524)）。v10 的 `Service::getCheckDirs()` / `noconflict` 机制在本仓库**不存在**，别依赖。
- 可选 RS256 包签名：见 [`local-package-signing.md`](local-package-signing.md)。
- 需要 `ZipArchive` 扩展；本机 PHP 未编译 zip 时 `extractZipSafe()` 直接返回 `zip_unavailable`（[AddonSecureInstaller.php:54-56](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L54)），后台会提示无法解压。

**不要**在插件里做这些事：关闭 SSL 校验、写死密钥、往 `addons/` 之外的目录写文件、用 `eval`/动态拼函数名绕过屏蔽清单。装 zip 等于在这个站点上执行任意 PHP，闸门拦的是误操作和旧包，不是攻击者。

---

## 9. 菜单与权限

- 后台「应用」菜单在核心权限表里（`app/admin/common/auth.php` 的 `addon` 控制器节点），**与具体业务插件无关**。
- 插件**不要**改核心 `auth.php`：升级会覆盖，也无法随包分发；启停后 [`state()`:473](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L473) 只清 `__menu__` 缓存，不会替你新增节点。
- 推荐入口两种：`info.ini` 的 `url` 指向插件后台页，从「应用 → 本地应用」列表进；或者只做钩子增强（如登录背景），完全不碰菜单。
- 确实要出现在左侧菜单，属于产品需求：正式合入核心 `auth.php` + 语言包，走 PR。

---

## 10. 开发清单

1. `addons/{name}/`，`name` 小写、`^[a-z][a-z0-9_]{0,31}$`；写 `{Ucfirst}.php` + `info.ini`（`name` 对齐目录）。
2. 有配置写 `config.php`（只用 §6 与 [`addon-config-ui.md`](addon-config-ui.md) 列出的 `type`）；**包里不带 `config.values.php`**。
3. 有表写 `install.sql`：`__PREFIX__`、无注释、无 `DELIMITER`、DDL 全部 `IF NOT EXISTS`；`uninstall()` 里自己 DROP。
4. 钩子只实现 §5.2 表里存在的插槽名；需要新插槽先把它合进主库并登记。
5. 静态资源 / 语言包各自在 `appInit()`（或 `install()` / `enable()`）里自己部署 / 加载；`uninstall()` 里自己清。
6. 后台型控制器在 `_initialize()` 校验 `session('admin_auth')`，POST 校验 CSRF——调度器不会替你把关。
7. 本地跑一遍：装（zip 或手工放目录）→ 启用 → 触发钩子页面 → 停用 → 卸载 → 查表是否清干净；再换**自定义表前缀**跑一遍 SQL。
8. 语法按 PHP 8：`admin.php:6` 要求站点跑 PHP ≥ 8.0.2，插件不需要迁就 v10 的「7.0 语法」约定（那只对官方 v10 市场包有意义）。
9. 不要往 `template/`、`static/`、`app/` 里塞插件文件；后台 UI 改动只碰 `app/admin/view/`，插件自己的 view 在 `addons/{name}/view/`。

---

## 11. 路径速查

| 用途 | 路径 |
|---|---|
| 插件代码 | `addons/`（仓库根，PSR-4 `addons\`） |
| 插件系统实现 | [`app/common/addon/`](file:///D:/wwwroot/maccms/maccms12/app/common/addon) |
| 后台控制器 | [`app/admin/controller/Addon.php`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php) |
| 后台模板 | `app/admin/view/addon/`（`index.html` / `config.html` / `add.html`） |
| 本地包安全闸门 | [`app/common/util/AddonSecureInstaller.php`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php) |
| 云市场客户端 | [`app/common/util/AddonCloudService.php`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php) |
| 钩子登记表（只写不读） | `app/extra/addons.php` |
| 运行时生成 | `addons/{name}/config.values.php`、`app/extra/addon_market_installed.php`、`app/extra/addon_market_audit.php` |
| URL 规则 | [`route/addons.php`](file:///D:/wwwroot/maccms/maccms12/route/addons.php) |
| 目录签发工具 | [`app/data/addon_market_cloud/`](file:///D:/wwwroot/maccms/maccms12/app/data/addon_market_cloud) |
| 市场 backlog | [`../backlog/addon-marketplace.md`](file:///D:/wwwroot/maccms/maccms12/app/data/addon/backlog/addon-marketplace.md) |
| 站长向手册 | [说明文档/10-插件与扩展手册.md](file:///D:/wwwroot/maccms/maccms12/说明文档/10-插件与扩展手册.md) |
