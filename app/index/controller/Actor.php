<?php
namespace app\index\controller;

use app\common\util\SearchService;

class Actor extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $info = $this->label_actor();
        return $this->label_fetch('actor/index');
    }

    public function type()
    {
        $info = $this->label_type();
        $this->assign('types', $this->typeBlocks($info));
        return $this->label_fetch( mac_tpl_fetch('actor',$info['type_tpl'],'type') );
    }

    private function typeBlocks($info)
    {
        $type_id = isset($info['type_id']) ? (int)$info['type_id'] : 0;
        if ($type_id <= 0) {
            return [];
        }
        $type_list = (new \app\common\model\Type())->getCache('type_list');
        if (empty($type_list[$type_id])) {
            return [];
        }

        $children = [];
        foreach ($type_list as $t) {
            if ((int)$t['type_pid'] === $type_id && (int)$t['type_status'] === 1) {
                $children[] = $t;
            }
        }
        usort($children, function ($a, $b) {
            return (int)$b['type_sort'] <=> (int)$a['type_sort'];
        });

        $blocks = [];
        if (!empty($children)) {
            foreach ($children as $t) {
                $scope_ids = $this->typeSelfIds($type_list, (int)$t['type_id']);
                if ($this->countActors($scope_ids, '') > 0) {
                    $blocks[] = ['type' => $t, 'scope_type' => (int)$t['type_id'], 'scope_area' => ''];
                    continue;
                }
                $area = $this->typeAreaWords($t);
                if ($area === '') {
                    continue;
                }
                $parent_ids = $this->typeSelfIds($type_list, $type_id);
                if ($this->countActors($parent_ids, $area) <= 0) {
                    continue;
                }
                $blocks[] = ['type' => $t, 'scope_type' => $type_id, 'scope_area' => $area];
            }
            return $blocks;
        }

        $parent_id = (int)$type_list[$type_id]['type_pid'];
        if ($parent_id > 0 && !empty($type_list[$parent_id])) {
            $blocks[] = ['type' => $type_list[$parent_id], 'scope_type' => $parent_id, 'scope_area' => ''];
        }
        return $blocks;
    }

    private function typeSelfIds($type_list, $type_id)
    {
        $ids = [$type_id];
        foreach ($type_list as $t) {
            if ((int)$t['type_pid'] === $type_id && (int)$t['type_status'] === 1) {
                $ids[] = (int)$t['type_id'];
            }
        }
        return $ids;
    }

    private function typeAreaWords($type)
    {
        $extend = json_decode((string)($type['type_extend'] ?? ''), true);
        $area = trim((string)(is_array($extend) && isset($extend['area']) ? $extend['area'] : ''));
        if ($area === '') {
            $area = trim((string)($type['type_name'] ?? ''));
        }
        return $area;
    }

    private function countActors($type_ids, $area)
    {
        if (empty($type_ids)) {
            return 0;
        }
        $query = (new \app\common\model\Actor())
            ->where('actor_status', 1)
            ->whereIn('type_id', $type_ids);
        if ($area !== '') {
            $query->whereIn('actor_area', explode(',', $area));
        }
        return (int)$query->count();
    }

    public function show()
    {
        $this->check_show();
        $param = mac_param_url();
        $type_id_specified = 0;
        if (empty($param['id'])) {
            $default_actor_type = (new \app\common\model\Type())->where(['type_mid' => 8, 'type_status' => 1])->find();
            $type_id_specified = isset($default_actor_type->type_id) ? $default_actor_type->type_id : 0;
        }
        $info = $this->label_type(0, $type_id_specified);
        return $this->label_fetch( mac_tpl_fetch('actor',$info['type_tpl_list'],'show') );
    }

    public function ajax_show()
    {
        $this->check_ajax();
        $this->check_show(1);
        $info = $this->label_type();
        return $this->label_fetch('actor/ajax_show');
    }

    public function search()
    {
        $param = mac_param_url();
        $this->check_search($param);
        SearchService::logFromParam(8, $param);
        $this->label_search($param);
        return $this->label_fetch('actor/search');
    }

    public function ajax_search()
    {
        $param = mac_param_url();
        $this->check_ajax();
        $this->check_search($param,1);
        SearchService::logFromParam(8, $param);
        $this->label_search($param);
        return $this->label_fetch('actor/ajax_search');
    }

    public function detail()
    {
        $info = $this->label_actor_detail();
        return $this->label_fetch( mac_tpl_fetch('actor',$info['actor_tpl'],'detail') );
    }

    public function ajax_detail()
    {
        $this->check_ajax();
        $info = $this->label_actor_detail();
        return $this->label_fetch('actor/ajax_detail');
    }

    public function rss()
    {
        $info = $this->label_actor_detail();
        return response($this->label_fetch('actor/rss'), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
