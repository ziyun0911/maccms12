<?php

namespace app\api\controller;

use think\facade\Db;
use think\Request;

class Actor extends Base
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
                'msg' => '参数错误: ' . $validate->getError(),
            ]);
        }
        $offset = isset($param['offset']) ? (int)$param['offset'] : 0;
        $limit = isset($param['limit']) ? (int)$param['limit'] : 20;
        $where = [];
        $where['actor_status'] = ['in', [0, 1]];

        if (isset($param['type_id']) && (int)$param['type_id'] > 0) {
            $where['type_id'] = (int)$param['type_id'];
        }

        if (isset($param['sex'])) {
            $where['actor_sex'] = $param['sex'];
        }

        if (isset($param['area']) && strlen($param['area']) > 0) {
            // 地区筛选支持多值：如“港台,香港,台湾”按合并地区查询
            $areas = [];
            foreach (explode(',', (string)$param['area']) as $one) {
                $one = $this->format_sql_string($one);
                if ($one !== '' && !in_array($one, $areas, true)) {
                    $areas[] = $one;
                }
            }
            if (count($areas) > 1) {
                $where['actor_area'] = ['in', $areas];
            } elseif (count($areas) === 1) {
                $where['actor_area'] = ['like', '%' . $areas[0] . '%'];
            }
        }

        if (isset($param['letter']) && strlen($param['letter']) > 0) {
            $where['actor_letter'] = ['like', '%' . $this->format_sql_string($param['letter']) . '%'];
        }

        if (isset($param['level']) && strlen($param['level']) > 0) {
            $where['actor_level'] = ['like', '%' . $this->format_sql_string($param['level']) . '%'];
        }

        if (isset($param['name']) && strlen($param['name']) > 0) {
            $where['actor_name'] = ['like', '%' . $this->format_sql_string($param['name']) . '%'];
        }

        if (isset($param['blood']) && strlen($param['blood']) > 0) {
            $where['actor_blood'] = ['like', '%' . $this->format_sql_string($param['blood']) . '%'];
        }

        if (isset($param['starsign']) && strlen($param['starsign']) > 0) {
            $where['actor_starsign'] = ['like', '%' . $this->format_sql_string($param['starsign']) . '%'];
        }

        if (isset($param['time_end']) && isset($param['time_start'])) {
            $where['actor_time'] = ['between', [(int)$param['time_start'], (int)$param['time_end']]];
        } elseif (isset($param['time_end'])) {
            $where['actor_time'] = ['<', (int)$param['time_end']];
        } elseif (isset($param['time_start'])) {
            $where['actor_time'] = ['>', (int)$param['time_start']];
        }

        $total = (new \app\common\model\Actor())->getCountByCond($where);
        $list = [];
        if ($total > 0) {
            $order = "actor_time DESC";
            if (!empty($param['orderby'])) {
                $order = 'actor_' . $param['orderby'] . " DESC";
            }
            $field = 'actor_id,actor_name,actor_en,actor_alias,actor_sex,actor_pic,actor_remarks,actor_hits,actor_hits_month,actor_hits_week,actor_hits_day,actor_time,type_id';
            $list = (new \app\common\model\Actor())->getListByCond($offset, $limit, $where, $order, $field, false);
            foreach ($list as &$row) {
                $row['actor_pic'] = $this->withImgFallback(mac_url_img($row['actor_pic'] ?? ''), $row['actor_name']);
                $row['actor_link'] = mac_url_actor_detail($row);
            }
            unset($row);
        }
        return json([
            'code' => 1,
            'msg' => '获取成功',
            'info' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => $total,
                'rows' => $list,
            ],
        ]);
    }

    public function get_detail(Request $request)
    {
        $param = $request->param();
        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json([
                'code' => 1001,
                'msg' => '参数错误: ' . $validate->getError(),
            ]);
        }

        $res = Db::name('actor')
            ->where('actor_id', (int) $param['actor_id'])
            ->where('actor_status', 'in', [0, 1])
            ->find();
        if (empty($res)) {
            return json(['code' => 1001, 'msg' => '数据不存在']);
        }

        $res['actor_pic'] = $this->withImgFallback(mac_url_img($res['actor_pic']), $res['actor_name']);
        $res['actor_pic_thumb'] = $this->withImgFallback(mac_url_img($res['actor_pic_thumb'] ?? ''), $res['actor_name']);
        $res['actor_link'] = mac_url_actor_detail($res);
        // 代表作品：拆成独立片名并关联 VOD 库单片，供前台渲染逗号分隔的可点击列表
        $res['actor_works_items'] = $this->buildActorWorksItems(
            (string)($res['actor_works'] ?? ''),
            (string)($res['actor_name'] ?? '')
        );

        return json([
            'code' => 1,
            'msg' => '获取成功',
            'info' => $res
        ]);
    }

    /**
     * 走代理的头像追加 fb 兜底名：上游取图失败时 img.php 返回本地 SVG 文字台标，
     * 避免列表/详情卡片破图；非代理地址或无名时原样返回。
     */
    private function withImgFallback($pic, $name)
    {
        $pic = (string)$pic;
        if ($pic === '' || strpos($pic, '/img.php?url=') === false) {
            return $pic;
        }
        $name = trim((string)$name);
        if ($name === '') {
            return $pic;
        }
        return $pic . '&fb=' . rawurlencode(mb_substr($name, 0, 16, 'UTF-8'));
    }

    /**
     * 解析 actor_works（例：《青春作证》《中国音乐电视》）为逐条作品，并关联站内 VOD 单片。
     * - 命中单片：link 指向 /vod/detail.html?id=xxx，matched=1
     * - 未命中：link 指向该片名的站内 VOD 搜索，matched=0（片库无此片时不伪造单片链接）
     * @param string $works     原始代表作品字符串
     * @param string $actorName 明星名，用于在同名影片中优先挑选本明星参演的那条
     * @return array
     */
    private function buildActorWorksItems($works, $actorName)
    {
        $works = $this->safeUtf8(trim((string)$works));
        $actorName = $this->safeUtf8(trim((string)$actorName));
        if ($works === '') {
            return [];
        }

        // 《》只是装饰括号；连同常见分隔符一起切开，得到纯片名
        $works = strip_tags(html_entity_decode($works, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = preg_split('/[《》【】\[\]<>，、,;；|\r\n\t]+/u', $works, -1, PREG_SPLIT_NO_EMPTY);
        $titles = [];
        foreach ($parts as $part) {
            // trim() 按字节处理，字符掩码只能放 ASCII；全角括号/全角空格用 Unicode 正则单独去掉
            $title = trim((string)$part);
            $title = preg_replace('/^[()（）\s\x{3000}]+|[()（）\s\x{3000}]+$/u', '', $title);
            $title = $this->safeUtf8((string)$title);
            if ($title === '' || isset($titles[$title])) {
                continue;
            }
            $titles[$title] = true;
            if (count($titles) >= 30) {
                break; // 防止异常长文本拖慢详情页
            }
        }
        if (empty($titles)) {
            return [];
        }
        $names = array_keys($titles);

        // 一次批量查精确同名，避免逐条查询；vod_time 是 mac_url_vod_detail() 必需字段
        $rows = Db::name('vod')
            ->field('vod_id,vod_en,vod_name,vod_actor,vod_time,type_id,type_id_1')
            ->where('vod_status', 1)
            ->whereIn('vod_name', $names)
            ->order('vod_time', 'desc')
            ->limit(200)
            ->select();
        $exact = [];
        foreach ($rows as $row) {
            $exact[(string)$row['vod_name']][] = $row;
        }

        $items = [];
        foreach ($names as $name) {
            $picked = null;
            if (!empty($exact[$name])) {
                // 同名多条时，优先选演员表中包含本明星的那条
                foreach ($exact[$name] as $row) {
                    if ($actorName !== '' && strpos((string)$row['vod_actor'], $actorName) !== false) {
                        $picked = $row;
                        break;
                    }
                }
                if ($picked === null) {
                    $picked = $exact[$name][0];
                }
            }
            // 只做精确同名关联：模糊 LIKE 会误链到无关影片，且在片库量大时单次要 0.6s 以上
            if (!empty($picked)) {
                // vod_en 由采集写入，个别记录含非法 UTF-8 字节，先净化再拼 URL，避免 json_encode 失败返回空响应
                $picked['vod_en'] = $this->safeUtf8($picked['vod_en'] ?? '');
                $picked['vod_name'] = $this->safeUtf8($picked['vod_name'] ?? '');
                $link = $this->safeUtf8(mac_url_vod_detail($picked));
                if ($link === '') {
                    $link = $this->buildWorkSearchLink($name);
                    $matched = 0;
                    $vodId = 0;
                } else {
                    $matched = 1;
                    $vodId = (int)$picked['vod_id'];
                }
            } else {
                $link = $this->buildWorkSearchLink($name);
                $matched = 0;
                $vodId = 0;
            }
            $items[] = [
                'name'    => $name,
                'link'    => $link,
                'vod_id'  => $vodId,
                'matched' => $matched,
            ];
        }
        return $items;
    }

    /** 站内 VOD 搜索链接（mac_url() 会带上空的 page= 参数，需清理） */
    private function buildWorkSearchLink($name)
    {
        $link = str_replace('page=&', '', mac_url('vod/search', ['wd' => $name]));
        $link = preg_replace('/[?&]page=$/', '', $link);
        return $this->safeUtf8($link);
    }

    /** 保证输出字符串是合法 UTF-8，否则前端 JSON 会报 Malformed UTF-8 */
    private function safeUtf8($str)
    {
        $str = (string)$str;
        if ($str === '' || mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
        return $fixed === false ? '' : $fixed;
    }

    public function get_recommend(Request $request)
    {
        $param = $request->param();
        $ids = isset($param['ids']) ? trim($param['ids']) : '';
        $num = isset($param['num']) ? (int)$param['num'] : 8;
        $start = isset($param['start']) ? max(0, (int)$param['start']) : 0;
        $by = isset($param['by']) ? trim($param['by']) : 'time';

        $allowBy = ['hits', 'hits_day', 'hits_week', 'hits_month', 'time'];
        if (!in_array($by, $allowBy)) {
            $by = 'time';
        }

        $where = [];
        $where['actor_status'] = ['eq', 1];
        if (!empty($ids)) {
            $idArr = array_map('intval', explode(',', $ids));
            $where['actor_id'] = ['in', $idArr];
        }

        $list = Db::name('actor')
            ->field('actor_id,actor_name,actor_pic,actor_sex,actor_area,actor_hits,actor_hits_month,actor_time')
            ->where($where)
            ->order('actor_' . $by . ' desc')
            ->limit($start, $num)
            ->select();

        foreach ($list as &$v) {
            $v['actor_pic'] = $this->withImgFallback(mac_url_img($v['actor_pic']), $v['actor_name']);
            $v['actor_link'] = mac_url_actor_detail($v);
        }
        unset($v);

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'total' => count($list),
                'rows'  => $list,
            ],
        ]);
    }

    public function suggest(Request $request)
    {
        return $this->jsonSuggestByKind($request, 'actor');
    }
}
