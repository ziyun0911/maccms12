# 开放 API 配置 & 站外入库配置

## API 设置（**网站参数 → 内容接入** 的「API 设置」页签；`menu/configapi`，旧地址 `system/configapi` 302 到 `configcollect?tab=api`）

- 提供给 **APP、小程序、采集端、合作方** 拉取 **视频/文章等 JSON** 的控制台，按模块存 `maccms.api.{vod|art|actor|role|manga|website|publicapi}`。  
- 每个模块的字段只有：**`status`（开关）、`charge`（消耗点卡/积分）、`detail_inc_hits`、`pagesize`、`imgurl`（图片域名替换前缀）、`typefilter`（允许的分类 ID）、`datafilter`（附加 where 片段）、`cachetime`、`from`、`auth`**（[app/extra/maccms.php:445-458](file:///d:/wwwroot/maccms/maccms12/app/extra/maccms.php#L445-L458)）。  
- **`auth` 是域名/IP 白名单**，由 `checkDomainAuth()` 校验（[app/api/controller/Provide.php:28-36](file:///d:/wwwroot/maccms/maccms12/app/api/controller/Provide.php#L28-L36)）。**这一层没有 `pass`、没有 token、也没有限速字段** —— 别把站外入库的 `interface.pass` 当成 API 密钥。  
- **安全**：靠 `auth` 白名单 + **IP 防火墙** + 仅用 **HTTPS**；`datafilter` 是拼进 where 的片段，只给可信管理员改。  
- **性能**：为高 QPS 配 **缓存 / CDN** 或拆分只读库（架构级，超纲可建议运维）。

---

## 站外入库配置（**网站参数 → 内容接入** 的「站外入库」页签；`menu/configinterface`，旧地址 302 到 `configcollect?tab=interface`）

- **允许远程 HTTP 入库**（视频/文章/演员…）时使用，对应 `maccms.interface`。  
- **status** 开关、**pass** 通信密码（开启时 **至少 16 字符**，[System.php:1183](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L1183)）、各类型 **vodtype / arttype 映射**。  
- 接收地址一般为 **`/api.php/receive/vod`** 等（以官方文档与您服务器 **伪静态** 为准）。  
- **务必**：强密码、`pass` **仅服务器间传递**；WAF **限流**；日志监控异常大批量写入。

---

## 与前台「搜索/播放」的区别

- **开放 API**：机器读 JSON。  
- **播放页**：_visitor HTML_，走的是 **index + 模板**；鉴权与用户组不同。

---

## English summary

**configapi** (the third tab of **内容接入**) exposes read APIs gated only by a **domain/IP allowlist** (`api.<mod>.auth`) plus per-module switches — there is **no shared secret** at this layer. **configinterface** (second tab) is the one with a passphrase (`interface.pass`, ≥16 chars) for remote ingestion — **very sensitive**; lock down by IP + strong pass + HTTPS.
