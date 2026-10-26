# Administrators, permissions, timing / 管理员、权限与计划任务

## 1) 创建后台管理员

1. 打开 **用户 → 管理员**（`admin/index`）。  
2. 点击 **添加**（节点 `admin/info`）。  
3. 填写 **用户名、密码**（强密码），并在 **权限树** 里勾选允许访问的页面。  
4. 保存。把登录地址与账号交给同事时，勿通过公开渠道发送密码。

## 2) 权限模型

v12 **没有「角色」表**：权限直接存在每个管理员账号上。

1. **用户 → 管理员 → 编辑** 页里的勾选项就是 `admin_auth[]`（[app/admin/view/admin/info.html:43](file:///d:/wwwroot/maccms/maccms12/app/admin/view/admin/info.html#L43)），存的是 **`控制器/动作`** 字符串，例如 `vod/data`、`template/index`。  
2. 勾选树列出 **全部** 节点，包括左栏隐藏的 `show=0` 项 —— 所以「左栏看不到」不等于「不能授权」。一个列表页和它的详情/批量页是两个节点时（如 `vod/data` 与 `vod/info`），**两边都要勾**，否则点进编辑页会提示无权限。  
3. 反过来也一样：左栏里那些只靠 `param` 区分的子集页（重名/无地址/未审核/草稿/定时发布…）在权限树里 **共用同一条 `vod/data`**，勾一次就全放开，做不到「只给去重页」。  
4. `admin_id = 1` 恒为超级管理员，**不受权限树限制**（[app/admin/controller/Base.php:118-120](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Base.php#L118-L120) 直接返回通过）；该账号务必强密码、勿共享。  
5. `index/index`、`index/welcome`、`index/logout`、`index/unlocked` 对任何已登录后台账号都开放，不用勾选（[Base.php:117](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Base.php#L117) 硬编码在通过串里）。  
6. **用户 → 会员组**（`group/index`）是前台会员等级，**不是** 后台角色；**视频 → 角色库**（`role/data`）是剧集角色资料，两者都与后台权限无关。  
7. 权限不足时，由超管在同一棵勾选树里补勾对应 `控制器/动作`。

### 不走勾选项的特例

`check_auth()` 里有几处硬编码的放行规则（[Base.php:54-116](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Base.php#L54-L116)），授权时要知道：

| 动作 | 规则 |
|------|------|
| `upload/ueditorai`、`upload/ueditor_ai` | **无条件放行**（编辑器取图，登录后就能用）。 |
| `assistant/chat`、`assistant/ping` | 不看权限树，只看 **`maccms.admin_assistant.access_scope`**（`all` / `super`）。 |
| `safety/fix` | 勾了 `safety/checkup` 也算通过。 |
| `database/uploadstorage` | 勾了 `database/index` 或 `database/export` 也算通过。 |
| `addon/cloudcatalog` | 勾了 `addon/index` 或 `addon/downloaded` 也算通过。 |
| `*/contentLangTranslate` | 勾了对应模块的 `*/info`（vod/art/manga/topic/actor/website/live/link/task/type）就算通过。 |
| `system/index` | 超管直通；其他人只要权限串里出现 **任何 `system/`** 前缀就通过。 |

## 3) 计划任务

1. 在 **网站参数 → 计划任务**（`timming/index`）里维护任务清单：类型、状态、执行周期（周 / 小时 / 间隔秒）。数据存放在 **`app/extra/timming.php`**（不在数据库里）。  
2. 触发方式仍是外部定时 HTTP 访问 **`api.php/timming/index`**，由宝塔/crontab 按分钟或小时调用。  
3. 是否真的执行由排期判断；带 `enforce=1` 可立即执行，但要求已通过管理员登录校验（[app/api/controller/Timming.php:29](file:///d:/wwwroot/maccms/maccms12/app/api/controller/Timming.php#L29)）。  
4. **安全**：该入口没有独立密钥，**不要**把触发地址公开到公网可猜测的位置；需要收紧时在 Web 层限制来源 IP。  
5. 任务未执行：检查服务器 crontab 是否报错、HTTPS 证书、防火墙，以及 CMS 内任务状态与周期设置。

## 4) 日志与审计

- **用户 → 操作审计**（`adminaudit/index`）：后台账号改了什么、何时改的。  
- **用户 → 访问日志**（`ulog/index`）/ **访问记录**（`visit/index`）/ **访问风控日志**（`user_access_log/index`）：前台访问与风控命中记录。  
- 定期归档或清理，以免表过大拖慢统计页。

## English summary

Admins live under **用户 → 管理员**；permissions are a per-account `controller/action` checkbox tree (**no roles** in v12, and `admin_id = 1` always passes). Give a sub-admin both the list node and the detail/batch node of a page — but note that the filter sub-pages (duplicates / no-play-url / unaudited / draft / scheduled …) share one `vod/data` string, so they cannot be granted individually. Seven endpoints bypass the tree with hardcoded rules (editor uploads, assistant chat/ping, safety fix, backup upload, cloud catalogue, per-record translation, `system/index`); see the table above. Scheduled jobs are configured in **网站参数 → 计划任务** (stored in `app/extra/timming.php`) and fired by crontab hitting **`api.php/timming/index`** — that URL has no secret, so restrict it at the web-server level. Review **操作审计** and the access logs periodically.
