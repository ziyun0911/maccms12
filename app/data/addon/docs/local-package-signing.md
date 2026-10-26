# 本地插件包签名（可选 RS256）

面向「后台 → 应用 → 本地应用 → 本地上传 zip」这条路径（[`Addon::local()`:485](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L485)，验签调用在 `:553`）。
与云市场目录签名是**两套东西**：目录签的是 `catalog.json`（见 [`addon-marketplace-protocol.md`](addon-marketplace-protocol.md)），这里签的是 zip 包内的文件清单。

## 1. 开关与公钥

两项都读 `$GLOBALS['config']['addon']`（[`requireSignature()`:45-50](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L45)、[`loadPublicKeyPem()`:409-429](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L409)）：

```php
// app/extra/maccms.php 的顶层 'addon' 分区
'addon' => [
    'require_local_signature' => '0', // 非空即强制签名
    'local_public_key'        => '',  // PEM 正文，或 .pem 文件的绝对路径
],
```

> **出厂 `app/extra/maccms.php` 里没有 `addon` 这个顶层分区**（实测 29 个顶层键不含它，`Init` 也不注入），
> 要用签名就得手工补一段。补之前先备份该文件：它被后台整文件 `var_export` 回写，注释留不住，机制见 [app/extra/README.md §二](file:///D:/wwwroot/maccms/maccms12/app/extra/README.md)。

公钥解析顺序（`loadPublicKeyPem()`）：`local_public_key` 含 `-----BEGIN` 就当成 PEM 正文（`:415`）→ 否则当文件路径读（`:418`）→ 都没有则回落默认文件 `app/data/addon/local_public.pem`（`:423`）。

## 2. 包内文件

zip 根目录（与 `info.ini` 同级）：

### `package.manifest.json`

```json
{
  "name": "demo",
  "version": "1.0.0",
  "files": {
    "info.ini": "<sha256 hex>",
    "Demo.php": "<sha256 hex>"
  }
}
```

- `files` 的键是相对 zip 根的路径；值是小写 sha256 hex（比对前两侧都 `strtolower` + `hash_equals`，`:311`）。
- 签名载荷 = `ksort($files)` 之后的 `json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`（`:296`、`:330`）。
  **注意**：`ksort` 用的是 manifest 里的**原键**，路径规范化（`normalizeManifestRelPath()` `:358`）只影响文件比对，不影响载荷。
- 不要把 `package.sig` / `package.manifest.json` 写进 `files`；即便写了，验签也会跳过这两项（`:303`、`:322`）。
- 校验是双向的：`files` 里列的每个文件必须存在且哈希一致（`:307-313`），磁盘上**多出来的文件一律拒收**（`:317-328`）。清单没写全就等于安装失败。

### `package.sig`

上述载荷的 RSA-SHA256 签名，**base64** 文本（`base64_decode(..., true)` 严格模式，`:331`），最终 `openssl_verify(..., OPENSSL_ALGO_SHA256) === 1`（`:348`）。

手工生成（发布机执行，私钥不要进仓库）：

```bash
# 1) 列清单（相对包根，排除两个签名材料）
find . -type f ! -name package.sig ! -name package.manifest.json \
  -exec sh -c 'printf "%s %s\n" "$1" "$(sha256sum "$1" | cut -d" " -f1)"' _ {} \;

# 2) 把结果整理成 {"name":...,"version":...,"files":{...}} 后存成 package.manifest.json

# 3) 签名载荷必须是 ksort 后的 files 对象本身（不含 name/version），键序与转义要和上面一致
php -r '$m=json_decode(file_get_contents("package.manifest.json"),true);$f=$m["files"];ksort($f);
        echo json_encode($f,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);' > payload.json

openssl dgst -sha256 -sign keys/package_private.pem payload.json | base64 > package.sig
```

> 仓库里的 [`app/data/addon_market_cloud/build_package.php`](file:///D:/wwwroot/maccms/maccms12/app/data/addon_market_cloud/build_package.php) **只打 zip 并打印包级 `sha256:`**（`:87`），
> 不生成 `package.manifest.json` / `package.sig`；云市场的 `generate_keys.php` + `build_catalog.php` 签的是目录 JSON，签的是 `catalog_private.pem` 那把钥匙。包签名目前只能按上面的方式自己出。

## 3. 判定矩阵

| 包内情况 | `require_local_signature` | 公钥可用 | 结果 |
|---|---|---|---|
| 两个签名文件都没有 | 未设置 / `0` | — | **放行**，返回 `ok / 'skip'`（`:279-284`） |
| 两个签名文件都没有 | 非空 | — | 拒收 `sig_required`（`:280-282`） |
| 只有其中一个 | 任意 | 任意 | 拒收 `sig_incomplete`（`:285-287`） |
| 两个都有 | 任意 | 否 | **拒收** `sig_pubkey_missing`（`:336-342`，两个分支同一结果） |
| 两个都有 | 任意 | 是 | 走完清单 + 载荷验签，任一不符即拒收 |

也就是说：**只要包里带了签名材料，验签就是强制的，跟开关无关**；开关只决定「未签名的包能不能装」。

## 4. 与其余闸门的关系

签名之外，`extractZipSafe()`（[`AddonSecureInstaller.php:52`](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L52)）在解包阶段就已经拦：

- Zip Slip / 绝对路径 / 盘符 / `..`（条目名规范化 `:202`，校验 `:230`）；
- 屏蔽的顶层前缀 [`$blockedPrefixes`:6-19](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L6)：`application/`、`app/`、`config/`、`route/`、`static/`、`thinkphp/`、`extend/`、`vendor/`、`template/`、`public/`、`runtime/`、`addons/`。清单同时带 v10 旧名 `application/`、`thinkphp/`、`public/` 与 TP8 新名 `app/`、`config/`、`route/`，老包新包都覆盖。
- 命中依据是**规范化后的条目相对路径**（大小写不敏感，`:244-250`）：整段等于前缀或以 `前缀/` 开头才拒，所以 `assets/app/icon.png`、根级 `config.php` 都不受影响；而基名与扩展名检查作用于**任意深度**的文件名（`:254-266`），`assets/.htaccess`、`x.asp.rar` 一样被拒。`*.php.*` 这类双扩展也在拦截范围内（`:258-262`）。
- 屏蔽扩展名 [`$blockedExt`:21-24](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L21)（`phtml phar php3 php4 php5 php7 phps shtml asp aspx jsp`）与基名 [`$blockedBasenames`:26-31](file:///D:/wwwroot/maccms/maccms12/app/common/util/AddonSecureInstaller.php#L26)（`.htaccess`、`.htpasswd`、`.user.ini`、`web.config`）；
- 体量上限（`:33-35`）：条目 ≤5000、解压后总字节 ≤50 MB、单条目 ≤10 MB。

解包只发生在 `runtime/addons/stage_*`，全部检查通过才落到 `addons/{name}/`（[Addon.php:524-593](file:///D:/wwwroot/maccms/maccms12/app/admin/controller/Addon.php#L524)）；v10 时代的「覆盖站点根目录」这一步在本仓库不存在，这份前缀清单防的是它被重新引入。

## 5. 现实预期

- **默认关闭**：未签名 zip 过完上面的路径/扩展名检查就能装。装 zip 等于在站点上执行任意 PHP —— 签名解决的是「包在传输和归档里有没有被改过」，不解决「作者是否可信」。生产站建议 `require_local_signature=1` + 部署 `app/data/addon/local_public.pem`，并要求上传的包都带签名材料。
- 需要 `ZipArchive` 扩展；没有时 `extractZipSafe()` 与 `backupAddon()` 都会直接失败（`:54-56`、`:433`）。
- 完整的签发 / 分发 / 吊销体系仍是 backlog，见 [`../backlog/addon-marketplace.md`](file:///D:/wwwroot/maccms/maccms12/app/data/addon/backlog/addon-marketplace.md)。
