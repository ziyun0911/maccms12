# 插件云市场目录协议（CMS 客户端）

| 字段 | 值 |
|------|-----|
| 状态 | **设计 + CMS 客户端骨架**；默认关闭，需手工开 |
| 更新 | 2026-10-27 |
| 客户端 | [`app/common/util/AddonCloudService.php`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php) |
| 后台页 | 「应用 → 本地应用」顶部页签（[`app/admin/view/addon/index.html`:60](file:///D:/wwwroot/maccms/maccms12/app/admin/view/addon/index.html#L60)、`:213`） |
| 参考 | 模板市场同形态流水线 [`TemplateCloudService.php`](file:///D:/wwwroot/maccms/maccms12/app/common/util/TemplateCloudService.php) |
| 默认目录 URL | `https://api.maccms.ai/addons/catalog.json`（[`AddonCloudService.php:34`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L34)） |
| 旧目录 | `api.maccms.com` **只读并存**（默认开，[`Addon.php:156-158`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L156)） |

云端审核后台、CDN 运维、计费不在本仓库；下架通过目录里的 `status=delisted` 或从 `items` 移除体现。

---

## 1. 四条安装路径的现状

| 路径 | 行为 | 依据 |
|------|------|------|
| `http(s)://api.maccms.com/addon/index` | **只读并存**：专用安全 GET（主机白名单、禁跳转、体积上限、字段净化），仅用来补齐列表的展示字段；`addon_cloud.legacy_catalog=0` 可关 | [`Addon::fetchLegacyOnlineCatalog()`:154-160](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L154)、`downloaded()` `:117-124` |
| FastAdmin 式远程 `Service::install($name)`（uid/token） | **仍关闭**：`Addon::install()` 直接返回 `cloud_use_catalog` 错误，一行安装逻辑都不走；`Service::install()`（[Service.php:36](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L36)）因此没有调用方 | [`Addon.php:149-152`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L149) |
| 本地 zip（`Addon::local`） | 可用，闸门见 [`local-package-signing.md`](local-package-signing.md) | `:485` |
| 签目录一键安装（`AddonCloudService`） | **在线安装的通道**，前提 `addon_cloud.status=1` | [`cloudInstall()`:379](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L379)、[`installById()`:108](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L108) |
| `addon_cloud.mock=1` | 读本地 `catalog.mock.json` 出列表骨架；**禁止安装** | `AddonCloudService` `fetchCatalog()` 分支 + [`app/data/addon_market_cloud/catalog.mock.json`](file:///D:/wwwroot/maccms/maccms12/app/data/addon_market_cloud/catalog.mock.json) |

`isEnabled()` 为假时，目录页返回 `enabled=0 / error=disabled` 的空列表（[`cloudCatalog()`:304-306](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L304)），前端也不会切到云列表（`index.html:213`）。

---

## 2. 目录 JSON（签名的根对象）

```json
{
  "items": [ { "...": "见 §3" } ],
  "sig_alg": "RS256",
  "signature": "<base64 RSA-SHA256>"
}
```

- **签名载荷**：`json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` —— 签发端 [`build_catalog.php:106`](file:///D:/wwwroot/maccms/maccms12/app/data/addon_market_cloud/build_catalog.php#L106) 与客户端 [`AddonCloudService.php:487`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L487) 完全一致；`items` 之外的键都不参与签名。
- `sig_alg` 大小写不敏感，但只接受 `RS256`（`:478-481`）；缺 `signature` 直接判失败（`:474-477`）。
- 校验式：`openssl_verify($payload, base64_decode($sig, true), $pub, OPENSSL_ALGO_SHA256) === 1`（`:496`）。
- 公钥取法（[`getCatalogPublicKeyPem()`:503-513](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L503)）：先读 `app/data/addon_market_cloud/catalog_public.pem`（要求内容含 `-----BEGIN`），否则回落到常量 `AddonCloudService::CATALOG_PUBLIC_KEY_PEM`。**该常量出厂为空串**（`:17`），所以没有 pem 文件就等于验签必失败、目录永远不可用。

---

## 3. `items[]` 字段

### 必填（缺一即整条被客户端丢弃）

| 字段 | 约束 | 依据 |
|------|------|------|
| `id` | `^[a-z0-9][a-z0-9._-]{0,63}$`，目录内唯一，也是 `installById` 的入参 | [`:895`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L895) |
| `name` | 插件标识 `^[a-z][a-z0-9_]{0,31}$`，且须与包内 `info.ini` 的 `name` 一致 | [`AddonSecureInstaller::isValidName()`:42](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L42)、[`Service::check()`:26](file:///D:/wwwroot/maccms/maccms12/app/common/addon/Service.php#L26) |
| `title` | 展示名 | `validateCatalogFormat()` [`:524-526`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L524) |
| `version` | 语义化版本字符串 | 同上 |
| `package_url` | 必须以 `http(s)://` 开头，且过 SSRF 校验 | `:535`、下载侧 `:650-734` |
| `package_hash` | `^sha256:[a-f0-9]{64}$` | [`:548`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L548) |

### 可选

| 字段 | 说明 |
|------|------|
| `status` | 缺省按 `approved`；**只有 `approved` 会进入列表**（[`filterApprovedItems()`:404-414](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L404)），`delisted` 等一律忽略 |
| `cms_compat` | `{ "min": "2026.1000.0", "max": "" }`，对 `config('version.code')` 做 `version_compare`（[`checkCmsCompat()`:383-402](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L383)，版本号取自身 `:388`，回落直接 include `app/extra/version.php` `:390-393`） |
| `intro` / `author` / `image` / `price` | 展示用；`image` 过 `sanitizeLegacyImageUrl` 净化 |

> 列表接口不会把 `package_url` / `package_hash` / `signature` 发给浏览器（[`cloudCatalog()`:327`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L327)），安装时由服务端按 `id` 回查缓存的目录项，**不信任前端传来的地址**。

---

## 4. 客户端流水线

1. **取目录**（[`fetchCatalog()`:48`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L48)）：缓存 → 安全 GET（禁跳转、主机白名单、体积上限）→ RS256 验签 → 逐条格式校验 → 只留 `status=approved`；成功缓存同时留一份备份与原始响应（`CACHE_CATALOG*` 常量 `:10-13`）。
2. **刷新**（[`cloudRefresh()`:343`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L343)）：POST + 一次性令牌 + 按管理员/IP 限流（`:361-366`，**尝试即计数**）。
3. **安装**（[`installById()`:108`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L108)）：限流 `:121-139` → 目录项存在性 `:129` → 兼容矩阵 `:135` → 安装互斥锁 `:141-143` → 下载（`MAX_PACKAGE_BYTES = 10240000`，`:15`，超限即拒 `:198`）→ `hash_equals` 比对 `sha256:`（`:203-204`）→ `AddonSecureInstaller::extractZipSafe()` 进 staging（`:218`）→ `resolveAddonRoot()` 容忍包外层多套一层目录（`:225`；**这点与本地上传不同**，`Addon::local()` 要求 `info.ini` 直接在 staging 根，见 `Addon.php:538`）→ `info.ini` 存在且 `name` 与目录项一致（`:231-241`）→ 主类 `{Ucfirst}.php` 存在（`:243-247`）→ 目标已存在则**先备份成功再 purge**（`:249-258`）→ 落到 `addons/{name}/`（`:260-276`）→ `Service::check()`（`:279`）→ 原本处于启用态则**强制回落 `state=0`**（`:281-284`）→ **只有新装才调插件 `install()`，升级不调**（`:289-291`）→ `importsql()` + `refresh()` + 清缓存（`:293-296`）→ `recordInstall()`（`:164`）。
4. **审计**：每一步的拒绝原因都写本机审计（`appendAudit()` [`:865`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L865)，保留条数 `audit_max`，`recentAudit(5)` 显示在「本地应用」页顶部，[`Addon.php:27`](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L27)）。

---

## 5. CMS 侧配置

`addon_cloud` 由 [`Init` 中间件在内存里补默认值](file:///D:/wwwroot/maccms/maccms12/app/common/middleware/Init.php#L49)（`:49-58`），默认值就是文档里这一份：

```php
'addon_cloud' => [
  'status'         => '0',   // 云市场总开关
  'catalog_url'    => 'https://api.maccms.ai/addons/catalog.json',
  'cache_ttl'      => '10800',
  'rate_limit'     => '10',
  'audit_max'      => '200',
  'legacy_catalog' => '1',   // 旧 api.maccms.com 只读元数据
  'mock'           => '0',   // 1=本地 catalog.mock.json 列表骨架（禁安装）
]
```

三条必须知道的：

1. **后台没有编辑这一组的页面**：全站只有读取点（`Addon.php:156`、`AddonCloudService.php:24-28`），没有任何 `mac_arr2file(... 'addon_cloud')` 写入点，也没有对应的设置页。要改就得手工编辑 `app/extra/maccms.php` 的顶层 `addon_cloud` 分区（该文件被 `.gitignore` 排除，改前先复制到 docroot 之外备份；机制见 [app/extra/README.md §二](file:///D:/wwwroot/maccms/maccms12/app/extra/README.md)）。
2. `Init` **只往内存副本里补默认值，从不回写 `maccms` 配置组**，所以 `config('maccms.addon_cloud')` 在文件里没有这个分区时拿到 `null`；两个服务类因此先读 `$GLOBALS['config']['addon_cloud']`、再回落 `config('maccms.addon_cloud')`（`AddonCloudService.php:24-30`）。把键真的落进文件是唯一稳妥的做法。
3. 本机产物：`app/extra/addon_market_installed.php`（[`recordInstall()`:841-843`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonCloudService.php#L841)）与 `app/extra/addon_market_audit.php`（`:867`）。**出厂不存在属正常**，第一次云安装/审计才会生成；两者都归 `mac_load_extra()` 的非递归扫描，是配置组而非插件代码。

---

## 6. 签发侧工具

见 [`app/data/addon_market_cloud/`](file:///D:/wwwroot/maccms/maccms12/app/data/addon_market_cloud)：`generate_keys.php`（生成 `keys/catalog_private.pem` + `catalog_public.pem`）、`build_package.php`（把 `addons/{name}` 打成 zip 并打印包级 `sha256:`）、`build_catalog.php`（读 `catalog.source.json`，签出 `dist/catalog.json`）。

> 该目录 README 里「`catalog_public.pem` 生成后提交并同步到 `AddonCloudService::CATALOG_PUBLIC_KEY_PEM`」这句**与当前代码不符**：常量是空串占位（`:17`），客户端优先读 pem 文件（`:505`）。走文件即可，不需要把公钥硬编进源码。

**私钥不得入库。**
