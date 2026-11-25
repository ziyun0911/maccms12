<?php
namespace app\index\controller;

class Live extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function show()
    {
        return $this->label_fetch('live/show');
    }

    public function play()
    {
        $param = mac_param_url();
        $live_id = 0;
        if (isset($param['id'])) {
            $live_id = (int)$param['id'];
        }
        $this->assign('param', $param);
        $this->assign('live_id', $live_id);
        return $this->label_fetch('live/play');
    }

    /**
     * 直播搜索：按频道名模糊匹配，服务端渲染结果页。
     * 台标统一走 img.php 代理（含台名兜底），与前端直播专栏一致。
     */
    public function search()
    {
        $param = mac_param_url();
        $wd = isset($param['wd']) ? trim((string)$param['wd']) : '';
        $list = [];
        $total = 0;
        if ($wd !== '') {
            $where = [
                'live_status' => 1,
                'live_name'   => ['like', '%' . htmlspecialchars($wd, ENT_QUOTES, 'UTF-8') . '%'],
            ];
            $res = (new \app\common\model\Live())->listData($where, 'live_sort desc, live_id asc', 1, 60, 0);
            $list = isset($res['list']) ? $res['list'] : [];
            $total = isset($res['total']) ? (int)$res['total'] : 0;
            foreach ($list as &$item) {
                $item['live_pic'] = $this->liveSearchProxy($item['live_pic'] ?? '', $item['live_name'] ?? '');
                $item['live_url'] = mac_url('live/play', ['id' => $item['live_id']]);
            }
            unset($item);
        }
        $this->assign('wd', $wd);
        $this->assign('list', $list);
        $this->assign('total', $total);
        return $this->label_fetch('live/search');
    }

    // 台标走站点图片代理 img.php，与 API 行为一致；带台名兜底避免破图
    private function liveSearchProxy($pic, $name)
    {
        $pic = trim((string)$pic);
        if (strpos($pic, '/img.php?url=') !== false) {
            return $pic;
        }
        // 空台标：返回带台名的文字台标地址，保证卡片始终有图
        if ($pic === '') {
            $name = trim((string)$name);
            if ($name !== '') {
                return '/img.php?fb=' . rawurlencode(mb_substr($name, 0, 16, 'UTF-8'));
            }
            return $pic;
        }
        if (substr($pic, 0, 2) === '//') {
            $pic = 'https:' . $pic;
        }
        if (!preg_match('#^https?://#i', $pic)) {
            return $pic;
        }
        $url = '/img.php?url=' . rawurlencode($pic);
        $name = trim((string)$name);
        if ($name !== '') {
            $url .= '&fb=' . rawurlencode(mb_substr($name, 0, 16, 'UTF-8'));
        }
        return $url;
    }
}
