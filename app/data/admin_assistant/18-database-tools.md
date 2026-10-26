# 数据库备份、执行 SQL、内容替换、挂马检测

数据库分组（顶栏 **数据库**，语言键 `menu/db`）只有 **3 个可见入口**：数据库备份 `database/index`、执行SQL语句 `database/sql`、数据库内容替换 `database/rep`。**数据批量替换在「采集」分组，挂马检测在「应用」分组**，旧版「数据库 → 挂马检测」的路径在本版本不存在（`Database` 控制器里没有 `inspect` 动作）。

## 数据库备份 (`数据库 → 数据库备份` / `database/index`)

- **备份**：导出为 `.sql` 或分片包，目录取 `maccms.db.backup_path`（默认 `./app/data/backup/database/`，[app/extra/maccms.php:13](file:///d:/wwwroot/maccms/maccms12/app/extra/maccms.php#L13)），单片大小受 `db.part_size`（默认 20971520 = 20 MB）、压缩由 `db.compress` / `db.compress_level` 控制（[app/extra/maccms.php:14-16](file:///d:/wwwroot/maccms/maccms12/app/extra/maccms.php#L14-L16)）。  
- **恢复/导入**：`database/import` —— 覆盖当前数据，**只对测试库或停机维护窗口**执行。  
- **优化 / 修复表**：`database/optimize`、`database/repair`，用于 InnoDB 碎片或异常关机后（仍先备份）。  
- 备份文件可直传远程存储（方法名 `uploadStorage`，**权限串与 auth 节点里都是小写 `database/uploadstorage`**；勾 `database/index` 或 `database/export` 也放行，[Base.php:81-88](file:///d:/wwwroot/maccms/maccms12/app/admin/controller/Base.php#L81-L88)）。

## 执行 SQL 语句 (`database/sql`)

- **最高危**：一句 `DROP`/`DELETE`/错误 `UPDATE` 可毁掉全站。  
- 守则：**先备份 → 仅 SELECT 试运行 → 低峰窗口 → 由 DBA 执行**。  
- 禁止在生产让 **不受信管理员** 有此权限（`database/sql` 是独立权限串）。

## 数据库内容替换 (`database/rep`)

- 批量替换字段内字符串（如域名迁移、违禁词）；必须 **限定表与 WHERE**（若有），**先抽样 SELECT**。  
- **UTF-8 / 二进制** 混淆可能乱码；替换前确认目标列字符集。

## 数据批量替换 (**采集 → 数据批量替换** / `data_replace/index`)

- 面向 **按表/按条件** 的批量改写（如按分类刷字段），与上面的字符串替换是两个页面，别混用。  
- 高危动作同样要求 **先备份 + 小批量试跑**。

## 挂马检测 (**应用 → 数据挂马检测** / `safety/data`)

- 扫描 **`mac_*` 表**中可疑 iframe/script/Base64（**启发式**，非杀毒软件）。  
- 发现后：**人工核验 → 溯源入侵（弱口令/FTP/WebShell）→ 修漏洞 → 全量杀毒**。  
- 同组还有 **文件安全检测**（`safety/file`）与 **安全体检**（`safety/checkup`），详见 `21-safety-urlsend-addon.md`。

## English summary

The **数据库** group is only backup/restore/optimize-repair (`database/index`), ad-hoc SQL (`database/sql`) and string replace (`database/rep`). Batch field rewriting lives under **采集 → 数据批量替换**, and the data-taint scan under **应用 → 数据挂马检测** — there is no `database/inspect`. Ad-hoc SQL and replace are destructive by nature: mandatory backups and staging first. The scanner finds obvious malware patterns, not guarantees.
