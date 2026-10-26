<?php
namespace app\common\db;

class Query extends \think\db\Query
{
    public function limit($offset, $length = null)
    {
        if (is_string($offset) && strpos($offset, ',') !== false) {
            [$offset, $length] = explode(',', $offset, 2);
        }
        return parent::limit((int) $offset, $length === null ? null : (int) $length);
    }

    public function page($page, $listRows = null)
    {
        return parent::page((int) $page, $listRows === null ? null : (int) $listRows);
    }
}
