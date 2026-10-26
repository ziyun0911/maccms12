# Search, Meilisearch, AI / 搜索、索引与 AI

## 1) 前台搜索总开关与行为

1. 进入 **网站参数 → 网站参数**（`system/config`），切到 **「性能优化」页签**。  
2. 搜索相关的字段都在 `app` 区块：**搜索开关 `app[search]`**、**搜索验证码 `app[search_verify]`**、**搜索参数长度 `app[search_len]`**、**搜索间隔 `app[search_timespan]`**（[config.html:499-530](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config.html#L499-L530)），以及参与模糊匹配的字段 **`app[search_vod_rule][]` / `app[search_art_rule][]`**（[config.html:720-777](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config.html#L720-L777)）。  
3. **搜索热词 `app[search_hot]` 不在本页签**，在下一个 **「预留参数」页签**（[config.html:890-895](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config.html#L890-L895)）。  
4. 保存后在前台搜索框验证；若仍异常，检查 **网站参数 → URL地址配置** 页签里的搜索路由与 **伪静态**。

### 搜索增强（视频 LIKE 缓存）

- 开关是 **`app.vod_search_optimise[]`**（多选，含 `frontend` 等值）与 **`app.vod_search_optimise_cache_minutes`**，就在 **性能优化页签**（[config.html:785-789](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/config.html#L785-L789)）。  
- 命中结果缓存进 **`mac_vod_search`** 表（模型 `app\common\model\VodSearch`）；**清理缓存**（`index/clear`）会顺带清掉过期结果（[Index.php:222](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Index.php#L222)）。

### 搜索词日志与联想

- **`mac_search_query_log` 表**：记录用户搜索词（去重窗口默认 120 秒），用于 **热门词** 与登录用户的搜索历史（[app/common/util/SearchService.php:11-45](file:///d:/wwwroot/maccms/maccms12/app/common/util/SearchService.php#L11-L45)）。  
- 开关是 **`app.search_query_log`** 与去重窗口 **`app.search_query_log_dedupe_sec`**（默认 120 秒、下限 30 秒）。**后台表单里没有这两项，出厂 `maccms.php` 里也不存在** —— 读不到就按 **默认开启** 处理（`intval(cfg('search_query_log', 1)) === 1`，[SearchService.php:45-48](file:///d:/wwwroot/maccms/maccms12/app/common/util/SearchService.php#L45-L48)）；要关掉只能在 `app` 段手写 `'search_query_log' => '0'`。  
- **联想词 (suggest)**：按模块走 `api.php/{vod|art|topic|actor|role|manga}/suggest`，对主表名称字段做前缀/包含匹配（`jsonSuggestByKind`，[app/api/controller/Base.php:22](file:///d:/wwwroot/maccms/maccms12/app/api/controller/Base.php#L22)），**不读日志表**；`app.search != 1` 时直接返回关闭。  
- 若启用了 Meilisearch，联想改走 `ApiMeilisearchSuggest::suggestListDataRes()`。

---

## 2) Meilisearch（若本站已启用）

入口是 **网站参数 → Meilisearch 设置**（`meilisearch/index`，[auth.php](file:///d:/wwwroot/maccms/maccms12/app/admin/common/auth.php) 里 `menu/meilisearch`，show=1）。

- **仅超级管理员（`admin_id=1`）可用**：控制器构造函数直接拦掉其他账号，给别的管理员勾 `meilisearch/*` 权限也没用（[Meilisearch.php:12-19](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Meilisearch.php#L12-L19)）。  
- **配置字段**（写在 `maccms.php` 的 `meilisearch` 顶级键）：`enabled`、`host`、`index_uid`（默认 `maccms_contents`）、`api_key`、`timeout`、`ssl_verify`、`sync_on_save`、`search_only_wd`（[index.html:9-45](file:///d:/wwwroot/maccms/maccms12/app/admin/view/meilisearch/index.html#L9-L45)）。  
- **页面按钮**：保存配置、**一键初始化**（`meilisearch/setup`）、**全量同步**（`meilisearch/sync`，独立页 `meilisearch/sync.html`）、**一键自检**（`meilisearch/selfcheck`）；`save/status/selfcheck/sync/setup` 五个节点 `show=0`，只在权限树里出现、不进左菜单，**需要单独勾选**。  
- **首次启用或大规模改库后必须执行一次「全量同步」**，否则服务连通但索引为空，前台搜索返回空结果。  
- **走 Meili 的条件**：仅在「只带关键词 `wd`、筛选条件简单」时命中，带分类/地区等筛选时仍回退 MySQL LIKE（`search_only_wd`）。服务不可达时逐次请求会拖慢响应，最多等 `timeout` 秒后降级。  
- Meili 服务需 **独立进程/容器** 可访问；检查 host、密钥、防火墙。索引文档为 **视频 / 文章 / 漫画**。

---

## 3) AI 搜索 / 前台 AI 对话（网站参数 → AI 搜索设置）

独立页面 **`system/configaisearch`**（菜单名 **「AI 搜索设置」**，show=1；注意它和 `AI SEO 设置`、`AI 视频封面设置` 不同，后两个只是「网站参数」里的页签）。所有字段落在 `maccms.php` 的 **`ai_search`** 顶级键。

1. **启用**：`ai_search[enabled]`。  
2. **模型与接口**：`provider`（服务商）、`model`、`response_language`（响应语言：自动/中/英/日/韩/俄）、`api_base`、`api_key`（**留空即不替换已保存的 Key**）、`timeout`、`max_terms`（扩展词数量）、`min_query_len`（最小查询长度）。  
3. **站内召回模块**：`ai_search[module][…]`，可勾 **vod / art / manga / topic / actor / role / plot / website** 八个（[configaisearch.html:96-108](file:///d:/wwwroot/maccms/maccms12/app/admin/view/system/configaisearch.html#L96-L108)）。命中站内数据优先返回，缺失时回退普通对话。  
4. **外部资源链接**：`external_enabled`、`external_max_links`、`external_domains`，以及 `external_sources` 整块 —— 内含总开关 `enabled` / `use_live` / `use_cache` / `cache_ttl` / `merge_limit` / `sync_interval`，和 **tmdb、douban、imdb** 三个源各自的 `enabled`、`api_key`、`base_url`、`language`、`region` 等。  
5. **语义重排**：`semantic_enabled` + `embedding_model` + `semantic_weight` + `semantic_candidates`；需要一个可用的 **Embeddings** 接口。  
6. **频率与预算**：`rate_limit_enabled`、`rate_limit_window`、`rate_limit_max`、`max_question_chars`、`require_login`、`anon_captcha_after`、`daily_budget`、`llm_call_cap`、`circuit_fail_threshold`、`circuit_hold_seconds`（熔断）、`debug_log`。建议至少开启限流，防止接口被刷。

---

## 4) 后台助手（浮动「?」按钮）

1. 进入 **网站参数 → 后台助手设置**（`system/configassistant`，独立页面，与 **「AI 搜索设置」** 是两条菜单；菜单名取 `menu/configassistant`，页内标题写「后台助手配置」）。字段落在 `maccms.php` 的 **`admin_assistant`** 键 —— 该键 **出厂不存在**，第一次保存本页才会创建（[System.php:2004-2006](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/System.php#L2004-L2006)）。  
2. **`enabled`** 启用；**`use_ai_search_credentials`** 打开后复用「AI 搜索设置」里的 `api_key` / `api_base`，关掉才需要在本页单独填 `provider`（通常 openai）、`model`、`api_base`、`api_key`、`max_tokens`、`timeout`。  
3. **`access_scope`**：`all` = 所有已登录管理员（默认），`super` = 仅 `admin_id=1`；`assistant/chat|ping` 的鉴权就按这一项判定。  
4. **`retrieve_chunks`**（每次带的知识条数，默认 8，上限 12）、**`rate_per_minute`**（速率限制）。  
5. **知识库文件路径**：**`app/data/admin_assistant/*.md`**，可按站点业务增删改；改完无需清缓存，助手直接读文件。  
6. **`include_env_snapshot`**（可选）：仅包含搜索开关、语言、缓存类型、站点名、模板目录等非敏感项，便于解释当前环境。  

助手 **不会** 自动修改数据库或配置；仅作说明与步骤引导。

---

## English summary

Frontend search: **System config → Website parameters → “Performance” tab** (`app[search]`, `search_verify`, `search_len`, `search_timespan`, `search_vod_rule[]`, `search_art_rule[]`); the hot-word list `app[search_hot]` sits on the next **“Reserved parameters”** tab. Optional video LIKE cache (`app[vod_search_optimise][]`) stores results in `mac_vod_search`, cleared by the top-bar cache wipe. `mac_search_query_log` has **no UI switch** — edit `app/extra/maccms.php` by hand. Suggest queries go to `api.php/{module}/suggest`. **Meilisearch** is configured at **Website parameters → Meilisearch settings** (`meilisearch/index`), **super-admin only**, and needs one full re-index after enabling; filtered searches still fall back to MySQL LIKE. **AI search** is its own page (**Website parameters → AI search settings**, `system/configaisearch`) covering provider/model, per-module recall, TMDB/Douban/IMDB external sources, embedding rerank and rate/budget guards. **Admin assistant** lives at **Website parameters → Admin assistant settings** (`system/configassistant`) and answers from markdown in `app/data/admin_assistant/`.
