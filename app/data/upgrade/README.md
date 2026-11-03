# app/data/upgrade —— 数据库增量脚本

放本仓库自带的、按顺序执行的建表/改表脚本。与在线升级包（`app/admin/controller/Update.php`）里
随包而来、跑完即删的 `app/data/update/database.php` 是两条路：那条路只服务「官方整包升级」这一次性动作，
本目录服务「本仓库自己的结构变更」，执行历史记在 `mac_db_version` 里，跑过的不会重跑。

    php think upgrade:run --dry-run   # 看有哪些没执行
    php think upgrade:run             # 按文件名顺序执行
    php think doctor                  # 「增量脚本」一节汇总待执行项

## 文件规则

- 一个文件一个逻辑变更，文件名 `YYYYMMDD_NN_小写下划线说明.sql`，例如 `20261103_01_vod_search_index.sql`。
  正则见 `app/common/util/Upgrades::NAME_RX`，不合规的文件会被标成 `bad-name` 并且不执行。
- 执行顺序只按文件名升序，所以日期段决定先后；同一天多个变更就递增 `NN`（01、02…）。
- 已经登记过的文件**不要改内容**：改了 hash 对不上，doctor 会报 `modified`，但引擎不会重跑。
  要再来一次就新增一个文件。
- 删文件不回收：`mac_db_version` 里的记录留着，历史可追溯。

## 语句写法

解析走 `mac_parse_sql()`，与安装、在线升级完全同一套规则：

- 以 `#`、`--` 开头的行和 `/* ... */` 注释块会被丢掉，别指望注释进库。
- 语句以「分号紧跟换行」为边界，所以一条语句写完后必须 `;` 然后换行；不要把两条语句挤在同一行。
- 表名前缀写 `` `mac_` ``，执行时按 `config('database.connections.mysql.prefix')` 替换。
- 内容表多是 MyISAM，DDL 之外的事务保护并不存在；失败即中断，当前文件不登记，修好后重跑该文件。
- 因此每个脚本都要写成可重复执行的形式：`CREATE TABLE IF NOT EXISTS`、
  加列前判断存在性（或把 `ALTER ... ADD COLUMN` 单独成文件，失败时人工处理）。

## 与缓存的关系

`config/database.php` 没开 `fields_cache`（think-orm 默认也是 false），字段元数据每次向 MySQL 现读，
所以改表之后不需要专门清 schema 缓存。若哪天把它打开，改表就要连带清一次运行缓存
（`php think clear` 或后台「清空缓存」），否则新列在缓存过期前对模型不可见。
