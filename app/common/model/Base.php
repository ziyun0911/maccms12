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
    /** @var array<string,bool> 建表兜底按模型类名每次请求只做一次 */
    private static $tableEnsured = [];

    public function __construct(array | object $data = [])
    {
        parent::__construct($data);
        // TP8 把 think\Model::initialize() 声明成 private，子类同名方法不会被构造函数调用，
        // 自定义初始化必须在父类构造完成后显式触发，否则 $tablePrefix 永远是 null、建表兜底也永不执行。
        $this->customInitialize();
    }

    //自定义初始化
    protected function customInitialize()
    {
        $this->tablePrefix = $this->tablePrefix ?: ThinkConfig::get('database.connections.mysql.prefix');
        $this->readFromMaster = $this->readFromMaster ?: false;
        // 表创建或修改
        if (method_exists($this, 'createTableIfNotExists') && empty(self::$tableEnsured[static::class])) {
            self::$tableEnsured[static::class] = true;
            $this->createTableIfNotExists();
        }
    }

    /**
     * 主键列名：模型显式声明优先，否则按表结构取真实主键。
     * 按 `$name . '_id'` 猜出来的列名在 20 张表上并不存在（如 mac_seo_ai_result 的主键是 seo_id、
     * mac_user_follow 的是 follow_id），拿去排序会直接 1054；框架自己的 schema 主键才是准的。
     */
    protected function primaryIdField()
    {
        if (empty($this->primaryId)) {
            $pk = Db::connect()->getPk($this->getTable());
            $this->primaryId = is_array($pk) ? (string) reset($pk) : (string) $pk;
        }
        return (string) $this->primaryId;
    }

    /**
     * 写库失败的统一出口。
     * TP5 时代这些方法写「if(false === $res) return msg + $this->getError()」；TP8 的 insert/update/delete
     * 要么返回受影响行数、要么直接抛 PDOException，不存在 false，而 think\Model 上也已经没有 getError()
     * （走到那行会变成 DbException: method not exist:Query->getError）。所以真实失败一律先落到这里：
     * 记录异常原文，返回控制器约定的 JSON 结构，避免把 SQL/表名泄露给浏览器。
     */
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

    /**
     * 原子 upsert：按唯一键 INSERT ... ON DUPLICATE KEY UPDATE，一条 SQL 完成插入或更新，
     * 取代「先 find 再 insert/update」的两次查询，并规避并发首插撞唯一键。
     * 列名取自入参数组，占位符参数化，无 SQL 注入面。
     * @param array $row        插入并在冲突时更新的列
     * @param array $insertOnly 仅插入时写入、冲突时保留的列（如 time_add）
     * @return int 影响行数（新插入=1，更新=2，无变化=0）
     */
    protected function upsertByUnique(array $row, array $insertOnly = [])
    {
        $all = array_merge($row, $insertOnly);
        $cols = array_keys($all);
        // 表名取自框架的 getTable()（已含配置前缀），不要自己拼 tablePrefix：
        // TP5 时代的 initialize() 钩子在 TP8 不会被调用，拼出来会是 nullmac_xxx。
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