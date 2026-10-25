<?php
namespace app\common\db;

/**
 * 兼容 maccms 历史写法：limit('start,length') / page('2') 等字符串参数。
 * think-orm 3.x 对 limit/page 使用严格 int 类型，这里统一收口转换。
 */
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
