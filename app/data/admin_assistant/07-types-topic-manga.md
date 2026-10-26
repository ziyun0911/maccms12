# Types, topics, manga / 分类、专题与漫画

## 1) 分类与类型

分类只有 **一个入口**：**基础 → 分类列表**（`type/index`）。视频、文章、网址、漫画的分类都在这同一页维护，每条用 `type_mid` 标明归属模块（1 视频 / 2 文章 / 3 专题 / 8 演员 / 9 角色 / 10 剧情 / 11 网址 / 12 漫画，映射见 [app/common.php:1472-1489](file:///d:/wwwroot/maccms/maccms12/app/common.php#L1472-L1489)）。

1. 进入 **基础 → 分类列表**，先建 **一级类型**（`type_pid = 0`），再在其下 **新建子分类**。  
2. 每个分类可设置：**名称、排序、编码、模板、SEO 标题/关键词/描述、图标、隐藏与筛选属性** 等。  
3. **保存后**，再到添加内容页的分类下拉框中选择该分类。  
4. 页面本身（`type/info`、`type/batch`、`type/del`…）是 `show=0` 的隐藏节点：左栏看不到，但**授权树里要勾**，否则子管理员只能看列表、不能增删。  
5. 若改了分类结构，前台 **静态页需重生成**（**生成 → 生成首页 / 生成地图**），异常时点顶栏 **「⋯」→ 清理缓存**。

错误排查：**添加内容时下拉无分类** → 该模块下没建分类，或 `type_mid` 选错了模块，或管理员没勾 `type/index`。

---

## 2) 专题（TOPIC）

1. 入口是 **基础 → 专题管理**（`topic/data`）。  
2. **添加/编辑专题没有左栏菜单**：它是 `show=0` 的隐藏节点 `topic/info`（[app/admin/common/auth.php:448-451](file:///d:/wwwroot/maccms/maccms12/app/admin/common/auth.php#L448-L451)）。子管理员必须同时勾选 **专题管理 `topic/data`** 与 **`topic/info`**，才能既看到列表又点进编辑页。  
3. 填写 **专题名称、封面、简介**，并 **关联内容**（按界面勾选视频/文章）。  
4. 保存后，前台通过模板中的专题列表/详情标签展示；链接形态在 **网站参数 → 网站参数 → URL 地址配置** 的 `path[topic_index]` / `path[topic_detail]`。

---

## 3) 漫画

1. 分类同样在 **基础 → 分类列表**（`type_mid = 12`）；内容在 **漫画 → 漫画数据**（`manga/data`）与 **漫画 → 添加漫画**（`manga/info`）。  
2. **章节 / 阅读内容** 与视频的播放地址 **不是同一套格式**：漫画正文存在 `manga_content`（富文本 / `<img>` 地址），话数之间用 `$$$` 分隔，配套的 `manga_title`、`manga_note` 也按同样的 `$$$` 段数对齐（[app/common/model/Manga.php:509-519](file:///d:/wwwroot/maccms/maccms12/app/common/model/Manga.php#L509-L519)）。  
3. 漫画侧还有若干带筛选的列表节点：**无地址漫画 / 需积分漫画**（都指向 `manga/data`，参数不同）。  
4. 若对接 **Meilisearch**，漫画参与索引；改库后到 **网站参数 → Meilisearch 设置**（`meilisearch/index`）执行同步。

---

## English summary

All categories live in **基础 → 分类列表** (`type/index`), discriminated by `type_mid` — there is no per-module "category" menu. Topics are listed at **基础 → 专题管理** (`topic/data`), while the add/edit page `topic/info` is a hidden (`show=0`) node that must be granted explicitly in the admin permission tree. Manga pages are stored as rich text in `manga_content` with chapters split by `$$$` (aligned with `manga_title` / `manga_note`) — they do **not** use the video `#` episode format. Re-sync Meilisearch from **网站参数 → Meilisearch 设置** after bulk edits.
