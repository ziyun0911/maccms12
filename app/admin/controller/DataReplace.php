<?php
namespace app\admin\controller;
use think\facade\Db;

class DataReplace extends Base
{
    private static $allowed_tables = ['vod', 'art', 'manga'];

    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    public function index()
    {
        $this->assign('title', lang('admin/datareplace/title'));
        return $this->fetch('datareplace/index');
    }

    public function doReplace()
    {
        $param = input('post.');
        $table = trim($param['table'] ?? '');
        $field = trim($param['field'] ?? '');
        $search = $param['search'] ?? '';
        $replace = $param['replace'] ?? '';

        if (empty($table) || empty($field) || $search === '') {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        if (!in_array($table, self::$allowed_tables, true)) {
            return json(['code' => 0, 'msg' => lang('admin/datareplace/table_not_allowed')]);
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $prefix = config('database.connections.mysql.prefix');
        try {
            $columns = Db::query("SHOW COLUMNS FROM `{$prefix}{$table}`");
            $validFields = array_column($columns, 'Field');
            if (!in_array($field, $validFields)) {
                return json(['code' => 0, 'msg' => lang('param_err')]);
            }
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => lang('admin/datareplace/replace_err')]);
        }

        try {
            $count = Db::name($table)
                ->where($field, 'like', '%' . $search . '%')
                ->count();

            if ($count == 0) {
                return json(['code' => 0, 'msg' => lang('admin/datareplace/no_match')]);
            }

            Db::execute(
                "UPDATE `{$prefix}{$table}` SET `{$field}` = REPLACE(`{$field}`, ?, ?) WHERE `{$field}` LIKE ?",
                [$search, $replace, '%' . $search . '%']
            );

            return json(['code' => 1, 'msg' => sprintf(lang('admin/datareplace/replace_ok'), $count)]);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => lang('admin/datareplace/replace_err') . ': ' . $e->getMessage()]);
        }
    }

    public function getFields()
    {
        $table = input('table');
        if (empty($table)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        if (!in_array($table, self::$allowed_tables, true)) {
            return json(['code' => 0, 'msg' => lang('admin/datareplace/table_not_allowed')]);
        }

        $prefix = config('database.connections.mysql.prefix');
        try {
            $fields = Db::query("SHOW COLUMNS FROM `{$prefix}{$table}`");
            $result = [];
            foreach ($fields as $f) {
                $result[] = [
                    'field' => $f['Field'],
                    'type' => $f['Type'],
                ];
            }
            return json(['code' => 1, 'data' => $result]);
        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => $e->getMessage()]);
        }
    }
}
