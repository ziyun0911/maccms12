# 附件参数配置 & 附件管理

## 附件参数配置（左栏 **资源、链接与播放** → 「网站参数」的 **附件参数配置** 页签；`system/configupload` 会 302 过去）

### `maccms.upload` 主要块

| 区域 | 说明 |
|------|------|
| **缩略图 thumb** | 是否生成、尺寸 `thumb_size`、裁剪方式 `thumb_type`。 |
| **水印 watermark** | 开关、位置、文字、字号、颜色。 |
| **存储模式 mode** | `local` = 本地磁盘，`remote` = 远程存储；`protocol` 只有 `http` / `https` 两种前缀。 |
| **远程驱动** | 驱动实现在 `app/common/extend/upload/`：**Ftp、Qiniu（七牛）、Upyun（又拍）、Weibo（微博图床）、Uomg、Alibaba（阿里云 OSS）、S3**。选 `remote` 后要填对应 **`api` 子数组**（AccessKey / bucket / 域名等）；S3 系还依赖 `extend/aws/src/Aws/aws.phar`。 |
| **remoteurl** | 远程附件访问域名前缀（用于拼接 URL）。 |
| **img_key / img_api** | 远程抓图/转存相关（依环境使用）。 |

### 操作注意

1. 改 **存储模式** 前，确认新存储 **可写且 URL 可公网访问**。  
2. **密钥**只填后台，勿提交到 Git 或贴到聊天。  
3. 旧图片不会自动搬迁；换云存储需 **迁移脚本或重新抓图**。  
4. **图片不存在/403**：检查云存储 bucket 权限、防盗链、HTTPS 证书。

---

## 附件管理 (`基础 → 附件管理`，`annex/data`；语言键仍叫 `menu/images`)

- 浏览已上传文件、删除无用附件、释放空间。  
- 删除前确认 **无内容引用**，否则前台破图。

---

## English summary

**configupload** controls thumbs, watermark, and storage **mode** (`local` vs cloud APIs). Changing mode does not migrate files automatically. **images** admin lists uploaded media.
