<?php
namespace app\common\util;

use think\facade\Db;

class MonitorState
{
    private static function table()
    {
        return Db::getConfig('connections.mysql.prefix') . 'monitor_state';
    }

    private static function ensureRow($key)
    {
        $table = self::table();
        Db::execute(
            "INSERT IGNORE INTO `{$table}` (`state_key`,`state_num`,`state_val`,`updated_at`) VALUES (?,0,'',0)",
            [(string)$key]
        );
    }

    public static function getNum($key, $default = 0)
    {
        $table = self::table();
        $row = Db::query("SELECT `state_num` FROM `{$table}` WHERE `state_key`=? LIMIT 1", [(string)$key]);
        if (empty($row)) {
            return intval($default);
        }
        return intval($row[0]['state_num']);
    }

    public static function getVal($key, $default = '')
    {
        $table = self::table();
        $row = Db::query("SELECT `state_val` FROM `{$table}` WHERE `state_key`=? LIMIT 1", [(string)$key]);
        if (empty($row)) {
            return (string)$default;
        }
        return (string)$row[0]['state_val'];
    }

    public static function set($key, $num, $val = '')
    {
        $table = self::table();
        $now = time();
        Db::execute(
            "INSERT INTO `{$table}` (`state_key`,`state_num`,`state_val`,`updated_at`) VALUES (?,?,?,?)"
            . " ON DUPLICATE KEY UPDATE `state_num`=VALUES(`state_num`),`state_val`=VALUES(`state_val`),`updated_at`=VALUES(`updated_at`)",
            [(string)$key, intval($num), (string)$val, $now]
        );
    }

    public static function due($key, $intervalSec, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $intervalSec = max(1, intval($intervalSec));
        self::ensureRow($key);

        $table = self::table();
        $affected = Db::execute(
            "UPDATE `{$table}` SET `state_num`=?,`updated_at`=? WHERE `state_key`=? AND `state_num` <= ?",
            [$now + $intervalSec, $now, (string)$key, $now]
        );
        return intval($affected) === 1;
    }

    public static function consumeBudget($key, $cap, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $cap = intval($cap);
        if ($cap <= 0) {
            return false;
        }
        self::ensureRow($key);

        $table = self::table();
        $affected = Db::execute(
            "UPDATE `{$table}` SET `state_num`=`state_num`+1,`updated_at`=? WHERE `state_key`=? AND `state_num` < ?",
            [$now, (string)$key, $cap]
        );
        return intval($affected) === 1;
    }

    public static function budgetExceeded($key, $cap)
    {
        $cap = intval($cap);
        if ($cap <= 0) {
            return true;
        }
        $table = self::table();
        $row = Db::query(
            "SELECT `state_num` FROM `{$table}` WHERE `state_key`=? LIMIT 1",
            [(string)$key]
        );
        $used = (!empty($row) && isset($row[0]['state_num'])) ? intval($row[0]['state_num']) : 0;
        return $used >= $cap;
    }

    public static function purgeByPrefix($prefix)
    {
        $prefix = (string)$prefix;
        if ($prefix === '') {
            return 0;
        }
        $table = self::table();
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
        return intval(Db::execute("DELETE FROM `{$table}` WHERE `state_key` LIKE ?", [$like]));
    }

    public static function purgeBudgetBefore($now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $table = self::table();
        $cur = 'notify_budget_' . date('YmdH', $now);
        return intval(Db::execute(
            "DELETE FROM `{$table}` WHERE `state_key` LIKE 'notify\\_budget\\_%' AND `state_key` < ?",
            [$cur]
        ));
    }
}
