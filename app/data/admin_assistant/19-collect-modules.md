# 采集：自定义接口、自定义规则、推荐资源、定时挂机

## 自定义接口 (`采集 → 自定义接口` / `menu/collect`)

- **API 对接型**：填写资源站 **接口根地址、绑定分类、点播或文章 mid**、更新时间等。  
- **分类映射**：远程 `type_id` → 本地 `type_id`；错映射会 **进店错误类目**。  
- **测试**：单次拉取一页再全量。**断点续采**避免重复重压。

## 自定义规则 (`采集 → 自定义规则` / `menu/cj`)

- **HTML/正则/规则模板**抓取页面（兼容性依具体规则写法）。  
- 适合 **非标准 API** 源；维护成本较高，需在 **抓取失败**时更新规则。

## 推荐资源 (`menu/union` → `collect/union`，**`show=0` 隐藏节点**)

- 预设或官方合作资源入口（以实现为准）；启用前阅读 **资费与版权条款**。  
- 左栏 **不显示**：超级管理员可在 **用户 → 管理员** 权限树里勾 `collect/union` 后直接访问该地址。

## 定时挂机 (`menu/collect_timming` → `collect/timing`，**同样是 `show=0` 隐藏节点**)

- 配置 **挂机任务**列表或开关；**真正执行依赖 crontab 访问 `api.php`**。  
- 与 **网站参数 → 计划任务**（`timming/index`）互补：一处配 **任务与周期**，一处配 **服务器 crontab**。  
- 勾权限时写 `collect/timing`。

## 采集分组里的其它入口

| 左栏名称 | 控制器/动作 | 用途 |
|----------|-------------|------|
| **资源站中心** | `resource_hub/index` | 资源站列表与对接入口 |
| **批量播放器管理** | `batch_player/index` | 跨线路批量写播放器标识 |
| **数据批量替换** | `data_replace/index` | 按表/条件批量改写（高危，见 `18-database-tools.md`） |

## 全局前提

- **网站参数 → 内容接入** 的第一个页签「内容采集」已保存。  
- 服务器 **`allow_url_fopen`/`curl`** 出站正常、**DNS** 正常。  
- **播放器/下载器** 与源站字段匹配。

---

## English summary

**Custom API** collects via HTTP APIs; **Rules** scrape HTML—brittle when site layout changes.**Union** presets partners.**Cron** executes collection—configure both CMS URLs **and** server crontab.
