<?php

namespace app\api\controller;

use think\facade\Db;
use think\Request;

class Type extends Base
{
    use PublicApi;
    public function __construct()
    {
        parent::__construct();
        $this->check_config();
    }

    public function index()
    {
    }

    public function get_list(Request $request)
    {
        $param = $request->param();
        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => '参数错误: ' . $validate->getError(),
            ]);
        }
        $where = [];
        $where['type_pid'] = 0;

        if (isset($param['type_id'])) {
            $where['type_id'] = (int)$param['type_id'];
        }

        $total = (new \app\common\model\Type())->getCountByCond($where);
        $list = [];
        if ($total > 0) {
            $order = "type_sort DESC";
            $field = '*';
            $list = (new \app\common\model\Type())->getListByCond(0, PHP_INT_MAX, $where, $order, $field, []);
            foreach ($list as $index => $item) {
                $child_total = Db::name('type')->where(['type_pid' => $item['type_id']])->count();
                if ($child_total > 0) {
                    $child = Db::name('type')->where(['type_pid' => $item['type_id']])->select();
                    $list[$index]['child'] = $child;
                }
            }
        }
        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'total'  => $total,
                'rows'   => $list,
            ],
        ]);
    }

    public function get_all_list()
    {
        $list = Db::name('type')->where(['type_pid'=> 0])->column('type_id,type_name,type_en');
        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'total'  => count($list),
                'rows'   => $list,
            ],
        ]);
    }

    public function get_nav_types(Request $request)
    {
        $param = $request->param();
        $ids = isset($param['ids']) ? trim($param['ids']) : '';
        $num = isset($param['num']) ? (int)$param['num'] : 0;
        $mid = isset($param['mid']) ? (int)$param['mid'] : 0;
        $parent = isset($param['parent']) ? (int)$param['parent'] : 0;
        $linkFlag = isset($param['link_flag']) ? trim((string) $param['link_flag']) : 'type';
        if (!in_array($linkFlag, ['type', 'show'], true)) {
            $linkFlag = 'type';
        }

        $where = [];
        if (!empty($ids)) {
            $idArr = array_map('intval', explode(',', $ids));
            $where['type_id'] = ['in', $idArr];
        }
        if ($parent) {
            $where['type_pid'] = 0;
        }
        if ($mid > 0) {
            $where['type_mid'] = $mid;
        }
        $where['type_status'] = 1;

        $visible_types = mac_get_popedom_type_visible(1);

        $list = Db::name('type')->where($where)->order('type_sort asc')->select();

        $rows = [];
        foreach ($list as $v) {
            if (!isset($visible_types[$v['type_id']])) {
                continue;
            }
            $v['type_link'] = mac_url_type($v, [], $linkFlag);
            if (!empty($v['type_extend'])) {
                $v['type_extend'] = is_string($v['type_extend']) ? json_decode($v['type_extend'], true) : $v['type_extend'];
            } else {
                $v['type_extend'] = [];
            }

            $children = Db::name('type')
                ->where(['type_pid' => $v['type_id'], 'type_status' => 1])
                ->order('type_sort asc')
                ->select();

            $child_rows = [];
            foreach ($children as $child) {
                if (!isset($visible_types[$child['type_id']])) {
                    continue;
                }
                $child['type_link'] = mac_url_type($child, [], $linkFlag);
                if (!empty($child['type_extend'])) {
                    $child['type_extend'] = is_string($child['type_extend']) ? json_decode($child['type_extend'], true) : $child['type_extend'];
                } else {
                    $child['type_extend'] = [];
                }
                $child_rows[] = $child;
            }

            $v['children'] = $child_rows;
            $rows[] = $v;
            if ($num > 0 && count($rows) >= $num) {
                break;
            }
        }

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'total' => count($rows),
                'rows'  => $rows,
            ],
        ]);
    }

    public function get_type_with_children(Request $request)
    {
        $param = $request->param();
        if (empty($param['type_id'])) {
            return json(['code' => 1001, 'msg' => '参数错误: type_id 必须']);
        }
        $typeId = (int)$param['type_id'];
        $num = isset($param['num']) ? (int)$param['num'] : 0;

        $parent = Db::name('type')->where(['type_id' => $typeId])->find();
        if (empty($parent)) {
            return json(['code' => 1002, 'msg' => '分类不存在']);
        }
        if (!empty($parent['type_extend'])) {
            $parent['type_extend'] = is_string($parent['type_extend']) ? json_decode($parent['type_extend'], true) : $parent['type_extend'];
        } else {
            $parent['type_extend'] = [];
        }
        $parent['type_link'] = mac_url_type($parent);

        $childQuery = Db::name('type')
            ->where(['type_pid' => $typeId])
            ->order('type_sort asc');
        if ($num > 0) {
            $childQuery = $childQuery->limit($num);
        }
        $children = $childQuery->select();
        foreach ($children as &$child) {
            $child['type_link'] = mac_url_type($child);
            if (!empty($child['type_extend'])) {
                $child['type_extend'] = is_string($child['type_extend']) ? json_decode($child['type_extend'], true) : $child['type_extend'];
            } else {
                $child['type_extend'] = [];
            }
        }
        unset($child);

        $parent['children'] = $children;

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => $parent,
        ]);
    }
}
