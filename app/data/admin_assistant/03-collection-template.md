# Collection, templates, cache / 采集、模板与缓存

## A. 采集（对接资源站）

### 1) 采集前准备（必做）

1. 打开 **网站参数 → 内容接入**（`system/configcollect`），停在第一个 Tab **内容采集**。  
2. 检查各 Tab（视频/文章/…）中的 **数据状态**（如：未审核 / 已审核）、**同步图片**、**随机点击/评分** 等是否符合运营要求。  
3. 保存。大批量采集后再改规则，往往需 **重新处理已入库数据**，成本高。

### 2) 添加采集节点（常见流程）

1. 进入顶栏 **采集** 分组：**自定义接口**（`collect/index`，API 型资源站）或 **自定义规则**（`cj/index`，HTML 规则抓取）。详见「采集」专卡。  
2. **新增** 节点：填写资源站提供的 **接口地址**、编码、请求参数等。  
3. **绑定分类映射**：将资源站的类型ID映射到 **本地类型/分类**；映射错误会导致内容进错栏目或无法入库。  
4. 先 **测试** 单页或少量数据，确认封面、播放地址、备注字段正常。  
5. 再执行 **当天/全量/断点续采**。

### 3) 常见问题

- **无播放地址 / 解析失败**：检查播放器配置（**视频 → 播放器**）与资源站格式是否匹配。  
- **图片不显示**：检查 **附件/远程图片** 设置与 **内容采集** 页签里是否开启同步图。  
- **重复数据**：用各模块的 **重名数据** 页处理 —— **视频 → 重名视频数据**（`vod/data?repeat=1`）、**文章 → 重名文章数据**、**漫画 → 重名漫画数据**、**网址 → 重名网址数据**。注意权限串只按 `控制器/动作` 记录（[admin/info.html:43](file:///d:/wwwroot/maccms/maccms12/app/admin/view/admin/info.html#L43)），这些带 `param` 的筛选子集 **共用同一条 `vod/data`**，勾一次全都放开，也无法只给去重页。

### 4) 萌芽采集 / Teek 等插件

若使用第三方采集插件，安装与卸载以 **插件官方文档** 为准；核心 CMS 的 **采集参数配置** 仍可能影响入库默认值。

---

## B. 模板（前台外观）

1. **模版 → 模板管理**（`template/index`）里浏览模板文件；打开文件走隐藏节点 `template/info`，**在线保存有内容黑名单，绝大多数带标签的模板存不回去** —— 日常改模板请用 FTP/本地编辑器（细节见 `20-template-ads-wizard.md`）。  
2. 模板文件位于站点根目录 **`template/{模板名}/`** 下（含 html、css、js）。  
3. 修改后：  
   - 模板编译缓存在 `runtime/temp/`，一般会自动重编译；行为异常时点后台 **顶栏右侧「⋯」→ 清理缓存**（`index/clear`）。**没有独立的「缓存」菜单**，清理缓存只是这一个入口 + 一种计划任务类型。  
   - 若开了静态生成，去 **生成 → 生成首页 / 生成地图** 重出（**生成** 分组只有 `make/opt`、`make/index`、`make/map` 三类页）。  
   - 浏览器 **强制刷新 (Ctrl+F5)** 避免旧 JS/CSS；改过 `static/` 下打包资源时要跑 `php think front:bundle` 重建 `admin.bundle.css` 等合包。  
4. 标签语法见站内 **网站参数 → 说明文档**（`doc/index`）与 **模版 → 标签向导**（`template/wizard`）。

---

## C. 静态与缓存

- **全站缓存 / 页面缓存**：提高性能，但会导致 **改模板或配置后短期内仍看旧页** → 需清理。  
- **浏览器 CDN**：若有 Cloudflare 等，改模板后可能还需刷新 CDN。  

---

## English summary

Before bulk **collect/import**, open **网站参数 → 内容接入**（Site parameters → Content access, first tab）and set the defaults. Map remote categories correctly. Templates live under `template/`; after editing them use the top-bar **⋯ → 清理缓存**, and re-run **生成** if you build static pages. Test one node before full sync.
