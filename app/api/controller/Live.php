<?php

namespace app\api\controller;

use think\facade\Db;
use think\Request;

class Live extends Base
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

    /**
     * 直播台标优先走站点图片代理 img.php。
     * 数据库里的 live_pic 多为外部 http(s) 链接，浏览器直连常被防盗链 /
     * 跨域 / 网络策略阻断而破图；这里统一转换成代理地址，前端三个消费方
     * （直播专栏 live/show、播放页 live/play、首页直播区块）无需各自改逻辑。
     * 仅转换外部 http(s) 图片；本地路径、协议相对地址补全后转换，
     * 已是代理地址的不再重复包装。img.php 自身的 SSRF 防护保持不变。
     */
    private function proxyLivePic($pic, $name = '', $boxW = 0, $boxH = 0)
    {
        $pic = trim((string)$pic);
        // 已是代理地址，不再重复包装
        if (strpos($pic, '/img.php?url=') !== false) {
            return $pic;
        }
        // 空台标：返回带台名的文字台标地址，保证卡片始终有图（不破图/不空白）
        if ($pic === '') {
            $name = trim((string)$name);
            if ($name !== '') {
                return '/img.php?fb=' . rawurlencode(mb_substr($name, 0, 16, 'UTF-8'));
            }
            return $pic;
        }
        // 协议相对地址补全为 https，img.php 只接受 http(s) 绝对地址
        if (substr($pic, 0, 2) === '//') {
            $pic = 'https:' . $pic;
        }
        if (!preg_match('#^https?://#i', $pic)) {
            return $pic;
        }
        // fb 传台名：上游图源失效时由 img.php 生成本地文字台标，保证卡片始终有图
        $url = '/img.php?url=' . rawurlencode($pic);
        $name = trim((string)$name);
        if ($name !== '') {
            $url .= '&fb=' . rawurlencode(mb_substr($name, 0, 16, 'UTF-8'));
        }
        // w/h 传实际图片框体尺寸，img.php 按框体自动调节台名字号；
        // 不传时 img.php 用默认 200×290 竖版台标（首页等场景）。
        $boxW = (int)$boxW;
        $boxH = (int)$boxH;
        if ($boxW >= 32 && $boxW <= 800) {
            $url .= '&w=' . $boxW;
        }
        if ($boxH >= 32 && $boxH <= 800) {
            $url .= '&h=' . $boxH;
        }
        return $url;
    }

    public function get_category(Request $request)
    {
        $cate_list = (new \app\common\model\Live())->categoryList(['cate_status' => 1]);
        $rows = [];
        foreach ($cate_list as $cate) {
            if (is_object($cate) && method_exists($cate, 'toArray')) {
                $cate = $cate->toArray();
            }
            $cate = (array) $cate;
            $cate['cate_name'] = mac_tpl_text($cate['cate_name'] ?? '');
            $rows[] = $cate;
        }
        // 分类表为空时给前端一个“全部”兜底，否则 live/show 会一直停在加载中
        if (empty($rows)) {
            $rows[] = [
                'cate_id'     => 0,
                'cate_name'   => lang('all'),
                'cate_status' => 1,
            ];
        }

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'list' => $rows,
        ]);
    }

    public function get_list(Request $request)
    {
        $param = $request->param();
        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('api/param_validate', [$validate->getError()]),
            ]);
        }

        $where = [];
        $where['live_status'] = ['eq', 1];

        // 调用方可声明图片框体尺寸（专栏竖/横版、播放页侧栏各不同）
        $boxW = (int)($param['pic_w'] ?? 0);
        $boxH = (int)($param['pic_h'] ?? 0);

        $offset = isset($param['offset']) ? (int)$param['offset'] : 0;
        $limit  = isset($param['limit']) ? (int)$param['limit'] : 20;

        if (isset($param['cate_id']) && (int)$param['cate_id'] > 0) {
            $where['cate_id'] = ['eq', (int)$param['cate_id']];
        }

        if (isset($param['name']) && strlen($param['name']) > 0) {
            $where['live_name'] = ['like', '%' . htmlspecialchars($param['name']) . '%'];
        }

        if (isset($param['level']) && (int)$param['level'] > 0) {
            $where['live_level'] = ['egt', (int)$param['level']];
        }

        $orderby = isset($param['orderby']) ? $param['orderby'] : 'sort';
        $orderMap = [
            'sort'      => 'live_sort desc, live_id asc',
            'hits'      => 'live_hits desc',
            'hits_day'  => 'live_hits_day desc',
            'hits_week' => 'live_hits_week desc',
            'time'      => 'live_time desc',
            'id'        => 'live_id desc',
        ];
        $order = isset($orderMap[$orderby]) ? $orderMap[$orderby] : 'live_sort desc, live_id asc';

        $res = (new \app\common\model\Live())->listData($where, $order, 1, $limit, $offset);

        $cateMap = [];
        $cateRows = (new \app\common\model\Live())->categoryList(['cate_status' => 1]);
        foreach ($cateRows as $c) {
            $cateMap[$c['cate_id']] = $c;
        }

        $liveModel = (new \app\common\model\Live());
        $rows = [];
        foreach ($res['list'] as $item) {
            $item = (array)$item;
            $item['cate_name'] = isset($cateMap[$item['cate_id']]) ? mac_tpl_text($cateMap[$item['cate_id']]['cate_name']) : '';
            $item['live_url_list'] = $liveModel->parseUrlList($item['live_url'] ?? '');
            unset($item['live_url']);
            // 外链台标改走 img.php 代理，避免浏览器直连破图；带台名与框体兜底
            $item['live_pic'] = $this->proxyLivePic($item['live_pic'] ?? '', $item['live_name'] ?? '', $boxW, $boxH);
            $rows[] = $item;
        }

        return json([
            'code'      => 1,
            'msg'       => lang('obtain_ok'),
            'page'      => $res['page'],
            'pagecount' => $res['pagecount'],
            'limit'     => $res['limit'],
            'total'     => $res['total'],
            'list'      => $rows,
        ]);
    }

    public function get_detail(Request $request)
    {
        $param = $request->param();
        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('api/param_validate', [$validate->getError()]),
            ]);
        }

        $live_id = (int)$param['live_id'];
        $boxW = (int)($param['pic_w'] ?? 0);
        $boxH = (int)($param['pic_h'] ?? 0);
        $where = ['live_id' => ['eq', $live_id], 'live_status' => ['eq', 1]];
        $res = (new \app\common\model\Live())->infoData($where);

        if ($res['code'] > 1) {
            return json($res);
        }

        $info = $res['info'];
        $info['live_url_list'] = (new \app\common\model\Live())->parseUrlList($info['live_url'] ?? '');
        unset($info['live_url']);
        // 播放页台标同样走代理（含台名兜底）；get_detail 默认不带框体参数，用竖版大图做播放器封面
        $info['live_pic'] = $this->proxyLivePic($info['live_pic'] ?? '', $info['live_name'] ?? '', $boxW, $boxH);

        if (!empty($info['cate_id'])) {
            $cate = Db::name('live_category')->where('cate_id', $info['cate_id'])->find();
            $info['cate_name'] = $cate ? mac_tpl_text($cate['cate_name']) : '';
        } else {
            $info['cate_name'] = '';
        }

        Db::name('live')->where('live_id', $live_id)->setInc('live_hits');
        Db::name('live')->where('live_id', $live_id)->setInc('live_hits_day');
        Db::name('live')->where('live_id', $live_id)->setInc('live_hits_week');
        Db::name('live')->where('live_id', $live_id)->setInc('live_hits_month');
        Db::name('live')->where('live_id', $live_id)->update(['live_time_hits' => time()]);

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => $info,
        ]);
    }
}
