<?php
namespace app\common\util;

use think\facade\Db;

class Upgrades
{
    public const NAME_RX = '/^\d{8}_\d{2}_[a-z0-9_]+$/';
    public const FILE_GLOB = '*.sql';

    public static function dir(): string
    {
        return rtrim(str_replace('\\', '/', root_path()), '/') . '/app/data/upgrade/';
    }

    public static function files(): array
    {
        $out = [];
        foreach ((array) glob(self::dir() . self::FILE_GLOB) as $f) {
            $out[] = basename($f, '.sql');
        }
        sort($out);

        return $out;
    }

    public static function ensure(): void
    {
        $table = self::table();
        if (self::exists()) {
            return;
        }
        Db::execute('CREATE TABLE IF NOT EXISTS `' . $table . '` (
            `version_id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT \'自增ID\',
            `version_name` varchar(191) NOT NULL DEFAULT \'\' COMMENT \'增量脚本文件名，不含扩展名\',
            `version_hash` char(32) NOT NULL DEFAULT \'\' COMMENT \'脚本内容md5，用于追溯改动\',
            `version_stmt` smallint(5) unsigned NOT NULL DEFAULT \'0\' COMMENT \'执行的语句条数\',
            `version_cost` int(10) unsigned NOT NULL DEFAULT \'0\' COMMENT \'执行耗时(毫秒)\',
            `version_time` int(10) unsigned NOT NULL DEFAULT \'0\' COMMENT \'执行完成时间戳\',
            PRIMARY KEY (`version_id`),
            UNIQUE KEY `version_name` (`version_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'数据库增量脚本执行记录\'');
    }

    public static function table(): string
    {
        return config('database.connections.mysql.prefix') . 'db_version';
    }

    public static function exists(): bool
    {
        try {
            Db::query('SELECT COUNT(*) AS c FROM `' . self::table() . '`');
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public static function recorded(): array
    {
        if (!self::exists()) {
            return [];
        }
        $out = [];
        foreach (Db::name('db_version')->order('version_name')->select() as $r) {
            $out[(string) $r['version_name']] = [
                'hash' => (string) $r['version_hash'],
                'stmt' => (int) $r['version_stmt'],
                'time' => (int) $r['version_time'],
            ];
        }

        return $out;
    }

    public static function plan(): array
    {
        $recorded = self::recorded();
        $rows = [];
        foreach (self::files() as $name) {
            $file = self::dir() . $name . '.sql';
            $hash = (string) md5_file($file);
            $row = [
                'name' => $name,
                'file' => $file,
                'hash' => $hash,
                'status' => 'pending',
                'applied' => 0,
                'stmt' => 0,
            ];

            if (!preg_match(self::NAME_RX, $name)) {
                $row['status'] = 'bad-name';
            } elseif (isset($recorded[$name])) {
                $row['status'] = $recorded[$name]['hash'] === $hash ? 'applied' : 'modified';
                $row['applied'] = $recorded[$name]['time'];
                $row['stmt'] = $recorded[$name]['stmt'];
            }

            if ($row['status'] === 'pending') {
                $row['stmt'] = count(self::statements($file));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public static function statements(string $file): array
    {
        $pre = config('database.connections.mysql.prefix');
        $list = mac_parse_sql((string) file_get_contents($file), 0, ['mac_' => $pre]);
        $out = [];
        foreach ((array) $list as $s) {
            $s = trim((string) $s);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    public static function run(bool $dry = false): array
    {
        $result = ['applied' => 0, 'failed' => 0, 'skipped' => 0, 'log' => []];

        if (!$dry) {
            self::ensure();
        }

        foreach (self::plan() as $row) {
            if ($row['status'] !== 'pending') {
                $result['skipped']++;
                $result['log'][] = [$row['status'], $row['name'], ''];
                continue;
            }
            if ($dry) {
                $result['log'][] = ['would-run', $row['name'], $row['stmt'] . ' 条语句'];
                continue;
            }

            $start = microtime(true);
            $ok = true;
            $err = '';
            $stmts = self::statements($row['file']);
            foreach ($stmts as $s) {
                try {
                    Db::execute($s);
                } catch (\Throwable $e) {
                    $ok = false;
                    $err = preg_replace('/\s+/', ' ', $e->getMessage());
                    break;
                }
            }

            if (!$ok) {
                $result['failed']++;
                $result['log'][] = ['fail', $row['name'], $err];
                break;
            }

            Db::name('db_version')->insert([
                'version_name' => $row['name'],
                'version_hash' => $row['hash'],
                'version_stmt' => count($stmts),
                'version_cost' => (int) round((microtime(true) - $start) * 1000),
                'version_time' => time(),
            ]);
            $result['applied']++;
            $result['log'][] = ['applied', $row['name'], count($stmts) . ' 条语句'];
        }

        return $result;
    }
}
