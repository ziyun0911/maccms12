<?php

namespace app\common\model;

use think\facade\Config as ThinkConfig;
use think\facade\Log;
use think\Model;
use think\facade\Db;
use think\facade\Cache;

class Base extends Model
{
    protected $tablePrefix;
    protected $primaryId;
    protected $readFromMaster;
    private static $tableEnsured = [];

    public function __construct(array | object $data = [])
    {
        parent::__construct($data);
        $this->customInitialize();
    }

    protected function customInitialize()
    {
        $this->tablePrefix = $this->tablePrefix ?: ThinkConfig::get('database.connections.mysql.prefix');
        $this->readFromMaster = $this->readFromMaster ?: false;
        if (method_exists($this, 'createTableIfNotExists') && empty(self::$tableEnsured[static::class])) {
            self::$tableEnsured[static::class] = true;
            $this->createTableIfNotExists();
        }
    }

    protected function primaryIdField()
    {
        if (empty($this->primaryId)) {
            $pk = Db::connect()->getPk($this->getTable());
            $this->primaryId = is_array($pk) ? (string) reset($pk) : (string) $pk;
        }
        return (string) $this->primaryId;
    }

    protected function writeFailed(string $scene, \Throwable $e, string $langKey, int $code): array
    {
        Log::error($scene . ' failed: ' . get_class($e) . ' ' . $e->getMessage());
        return ['code' => $code, 'msg' => lang($langKey)];
    }

    public function getCountByCond($cond)
    {
        $query_object = $this;
        if ($this->readFromMaster === true) {
            $query_object = $query_object->master();
        }
        return (int)$query_object->where($cond)->count();
    }

    public function getListByCond($offset, $limit, $cond, $orderby = '', $fields = "*", $transform = false)
    {
        $offset = max(0, (int)$offset);
        $limit = max(1, (int)$limit);

        $pk = $this->primaryIdField();
        if ($pk !== '') {
            if (empty($orderby)) {
                $orderby = $pk . " DESC";
            } elseif (strpos($orderby, $pk) === false) {
                $orderby .= ", " . $pk . " DESC";
            }
        }

        $query_object = $this;
        if ($this->readFromMaster === true) {
            $query_object = $query_object->master();
        }
        $list = $query_object->where($cond)->field($fields)->order($orderby)->limit($offset, $limit)->select();
        if (!$list) {
            return [];
        }
        $final = [];
        foreach ($list as $row) {
            $row_array = $row->getData();
            if ($transform !== false) {
                $row_array = $this->transformRow($row_array, $transform);
            }
            $final[] = $row_array;
        }
        return $final;
    }

    public function transformRow($row, $extends = []) {
        return $row;
    }

    protected function upsertByUnique(array $row, array $insertOnly = [])
    {
        $all = array_merge($row, $insertOnly);
        $cols = array_keys($all);
        $table = $this->getTable();
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colSql = '`' . implode('`,`', $cols) . '`';
        $updates = [];
        foreach (array_keys($row) as $c) {
            $updates[] = "`{$c}`=VALUES(`{$c}`)";
        }
        $sql = "INSERT INTO `{$table}` ({$colSql}) VALUES ({$placeholders}) "
             . "ON DUPLICATE KEY UPDATE " . implode(',', $updates);
        return Db::execute($sql, array_values($all));
    }
}