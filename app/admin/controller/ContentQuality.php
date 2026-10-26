<?php
namespace app\admin\controller;

use think\facade\Db;

class ContentQuality extends Base
{
    const SIDE_LIST_LIMIT = 10;

    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    public function index()
    {
        $param = input('param.');
        $mid = isset($param['mid']) && in_array(intval($param['mid']), [1, 2], true) ? intval($param['mid']) : 0;
        $order = isset($param['order']) && $param['order'] === 'asc' ? 'asc' : 'desc';
        $minScore = isset($param['min_score']) && $param['min_score'] !== '' ? floatval($param['min_score']) : null;
        $maxScore = isset($param['max_score']) && $param['max_score'] !== '' ? floatval($param['max_score']) : null;
        $page = max(1, intval(isset($param['page']) ? $param['page'] : 1));
        $limit = 20;

        $where = [];
        if ($mid > 0) {
            $where['mid'] = $mid;
        }
        if ($minScore !== null && $maxScore !== null) {
            $where['score_total'] = [['egt', $minScore], ['elt', $maxScore]];
        } elseif ($minScore !== null) {
            $where['score_total'] = ['egt', $minScore];
        } elseif ($maxScore !== null) {
            $where['score_total'] = ['elt', $maxScore];
        }

        $total = intval(Db::name('ContentQuality')->where($where)->count());
        $list = Db::name('ContentQuality')
            ->where($where)
            ->order('score_total ' . $order)
            ->page($page, $limit)
            ->select();

        $sideWhere = $mid > 0 ? ['mid' => $mid] : [];
        $sideWhere['is_cold_start'] = 0;
        $cleanList = Db::name('ContentQuality')
            ->where($sideWhere)
            ->order('score_total asc')
            ->limit(self::SIDE_LIST_LIMIT)
            ->select();
        $recommendList = Db::name('ContentQuality')
            ->where($sideWhere)
            ->order('score_total desc')
            ->limit(self::SIDE_LIST_LIMIT)
            ->select();

        $listRows = $list instanceof \think\Collection ? $list->all() : (array)$list;
        $cleanRows = $cleanList instanceof \think\Collection ? $cleanList->all() : (array)$cleanList;
        $recommendRows = $recommendList instanceof \think\Collection ? $recommendList->all() : (array)$recommendList;
        $nameMap = $this->buildNameMap(array_merge($listRows, $cleanRows, $recommendRows));

        $this->assign('list', $list);
        $this->assign('clean_list', $cleanList);
        $this->assign('recommend_list', $recommendList);
        $this->assign('name_map', $nameMap);
        $this->assign('mid', $mid);
        $this->assign('order', $order);
        $this->assign('min_score', $minScore === null ? '' : $minScore);
        $this->assign('max_score', $maxScore === null ? '' : $maxScore);
        $this->assign('page', $page);
        $this->assign('total', $total);
        $this->assign('limit', $limit);
        $this->assign('title', lang('admin/content_quality/title'));
        return $this->fetch('content_quality/index');
    }

    private function buildNameMap($rows)
    {
        $vodIds = [];
        $artIds = [];
        foreach ($rows as $row) {
            if (intval($row['mid']) === 1) {
                $vodIds[intval($row['content_id'])] = true;
            } else {
                $artIds[intval($row['content_id'])] = true;
            }
        }

        $map = [];
        if (!empty($vodIds)) {
            $vodRows = Db::name('Vod')->where('vod_id', 'in', array_keys($vodIds))->field('vod_id,vod_name')->select();
            foreach ($vodRows as $r) {
                $map['1_' . intval($r['vod_id'])] = $r['vod_name'];
            }
        }
        if (!empty($artIds)) {
            $artRows = Db::name('Art')->where('art_id', 'in', array_keys($artIds))->field('art_id,art_name')->select();
            foreach ($artRows as $r) {
                $map['2_' . intval($r['art_id'])] = $r['art_name'];
            }
        }
        return $map;
    }

    public function markRecommend()
    {
        $param = input('post.');
        if (!$this->checkAjaxToken($param)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }
        $mid = intval(isset($param['mid']) ? $param['mid'] : 0);
        $contentId = intval(isset($param['content_id']) ? $param['content_id'] : 0);

        if (($mid !== 1 && $mid !== 2) || $contentId < 1) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $row = Db::name('ContentQuality')->where(['mid' => $mid, 'content_id' => $contentId])->find();
        if (empty($row)) {
            return json(['code' => 0, 'msg' => lang('admin/content_quality/not_found')]);
        }

        return json(['code' => 1, 'msg' => lang('admin/content_quality/mark_recommend_ok')]);
    }

    private function checkAjaxToken($param)
    {
        $token = isset($param['__token__']) ? (string)$param['__token__'] : '';
        $sess = (string)\think\facade\Session::get('__token__');
        return $token !== '' && $sess !== '' && hash_equals($sess, $token);
    }
}
