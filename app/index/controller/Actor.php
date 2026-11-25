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
        // 艺人地区展示顺序固定为：内地 -> 港台 -> 日韩 -> 欧美 -> 其他，其余分类按 type_sort 升序兜底
        $areaRank = ['内地' => 0, '港台' => 1, '日韩' => 2, '欧美' => 3, '其他' => 4];
        usort($children, function ($a, $b) use ($areaRank) {
            $rankA = $areaRank[trim((string)($a['type_name'] ?? ''))] ?? PHP_INT_MAX;
            $rankB = $areaRank[trim((string)($b['type_name'] ?? ''))] ?? PHP_INT_MAX;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            $sortA = (int)($a['type_sort'] ?? 0);
            $sortB = (int)($b['type_sort'] ?? 0);
            if ($sortA !== $sortB) {
                return $sortA <=> $sortB;
            }
            return (int)($a['type_id'] ?? 0) <=> (int)($b['type_id'] ?? 0);
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
                $area_words = $this->areaGroupWords($area);
                if ($this->countActors($parent_ids, implode(',', $area_words)) <= 0) {
                    continue;
                }
                $blocks[] = ['type' => $t, 'scope_type' => $type_id, 'scope_area' => implode(',', $area_words)];
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

    private static $areaBlocks = ['内地', '港台', '日韩', '欧美', '其他'];

    /**
     * 地区区块关键字字典：把库里的 actor_area 自动归到对应区块。
     * 命中方式为“包含匹配 + 最长优先”，同长度按 港台 > 日韩 > 欧美 > 内地 的顺序；
     * 四组都不命中的值（如泰国、马来西亚、印度、新加坡、黎巴嫩）统一落「其他」区块。
     */
    private function areaBlockWords()
    {
        return [
            '港台' => [
                '港台', '港澳', '香港', '台湾', '澳门', '中国香港', '中国台湾', '中国澳门',
                '港岛', '九龙', '新界', '将军澳', '屯门', '元朗', '沙田', '荃湾', '大埔', '西贡',
                '深水埗', '观塘', '油尖旺', '湾仔', '铜锣湾', '北角',
                '台北', '新北', '桃园', '台中', '台南', '高雄', '基隆', '新竹', '嘉义', '苗栗',
                '彰化', '南投', '云林', '屏东', '宜兰', '花莲', '台东', '澎湖', '金门', '马祖',
                '中坜', '丰原', '氹仔', '路环',
            ],
            '日韩' => [
                '日韩', '日本', '韩国', '朝鲜', '韩国',
                '东京', '大阪', '京都', '北海道', '札幌', '横滨', '名古屋', '神户', '福冈',
                '广岛', '仙台', '埼玉', '千叶', '爱知', '兵库', '长野', '新潟', '静冈', '栃木',
                '群马', '冈山', '熊本', '冲绳', '奈良', '金泽', '镰仓', '川崎', '神奈川', '福岛',
                '石川', '富山', '岐阜', '滋贺', '和歌山', '鸟取', '岛根', '山口', '德岛', '香川',
                '高知', '佐贺', '长崎', '大分', '宫崎', '鹿儿岛', '秋田', '山形', '岩手', '青森',
                '首尔', '釜山', '大邱', '仁川', '大田', '光州', '蔚山', '世宗', '京畿', '江原',
                '忠清', '全罗', '庆尚', '济州', '城南', '富川', '水原', '龙仁', '安山', '清州',
                '全州', '木浦', '昌原', '高阳', '金海', '浦项', '安东', '天安', '春川', '束草',
                '原州', '龟尾', '瑞山', '晋州',
            ],
            '欧美' => [
                '欧美', '欧洲', '美洲', '北美', '南美', '拉美', '大洋洲', '西欧', '东欧', '北欧',
                '美国', '加拿大', '墨西哥', '巴西', '阿根廷', '智利', '哥伦比亚', '秘鲁',
                '委内瑞拉', '厄瓜多尔', '玻利维亚', '巴拉圭', '乌拉圭', '古巴', '多米尼加',
                '波多黎各', '哥斯达黎加', '巴拿马', '危地马拉', '洪都拉斯', '萨尔瓦多',
                '尼加拉瓜', '牙买加', '海地', '特立尼达',
                '英国', '英格兰', '苏格兰', '威尔士', '北爱尔兰', '爱尔兰', '法国', '德国',
                '意大利', '西班牙', '葡萄牙', '荷兰', '比利时', '卢森堡', '瑞士', '奥地利',
                '列支敦士登', '瑞典', '挪威', '丹麦', '芬兰', '冰岛', '波兰', '捷克', '斯洛伐克',
                '匈牙利', '罗马尼亚', '保加利亚', '希腊', '克罗地亚', '斯洛文尼亚', '塞尔维亚',
                '波黑', '黑山', '北马其顿', '阿尔巴尼亚', '俄罗斯', '乌克兰', '白俄罗斯',
                '摩尔多瓦', '爱沙尼亚', '拉脱维亚', '立陶宛', '格鲁吉亚', '亚美尼亚',
                '阿塞拜疆', '马耳他', '塞浦路斯', '梵蒂冈', '圣马力诺', '安道尔', '摩纳哥',
                '澳大利亚', '新西兰', '斐济', '巴布亚新几内亚', '南非', '埃及', '以色列', '土耳其',
                '纽约', '洛杉矶', '旧金山', '芝加哥', '波士顿', '西雅图', '华盛顿', '底特律',
                '休斯敦', '达拉斯', '迈阿密', '拉斯维加斯', '新泽西', '加利福尼亚', '加州',
                '德克萨斯', '田纳西', '密歇根', '夏威夷', '波特兰', '丹佛', '亚特兰大',
                '费城', '圣地亚哥', '伦敦', '曼彻斯特', '利物浦', '伯明翰', '爱丁堡',
                '格拉斯哥', '巴黎', '马赛', '里昂', '柏林', '慕尼黑', '汉堡', '法兰克福',
                '科隆', '罗马', '米兰', '威尼斯', '佛罗伦萨', '马德里', '巴塞罗那', '里斯本',
                '阿姆斯特丹', '布鲁塞尔', '苏黎世', '日内瓦', '维也纳', '斯德哥尔摩',
                '奥斯陆', '哥本哈根', '赫尔辛基', '雷克雅未克', '华沙', '布拉格', '布达佩斯',
                '雅典', '莫斯科', '圣彼得堡', '基辅', '悉尼', '墨尔本', '布里斯班', '珀斯',
                '奥克兰', '惠灵顿', '多伦多', '温哥华', '蒙特利尔',
            ],
            '内地' => [
                '内地', '大陆', '中国大陆', '中国内地', '中国',
                '北京', '上海', '天津', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江',
                '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南',
                '广东', '广西', '海南', '四川', '贵州', '云南', '西藏', '陕西', '甘肃',
                '青海', '宁夏', '新疆', '内蒙古',
                '广州', '深圳', '成都', '杭州', '南京', '武汉', '西安', '长沙', '沈阳',
                '大连', '青岛', '厦门', '苏州', '郑州', '济南', '哈尔滨', '长春', '昆明',
                '贵阳', '南宁', '福州', '合肥', '南昌', '石家庄', '太原', '兰州', '西宁',
                '银川', '乌鲁木齐', '拉萨', '呼和浩特', '海口', '三亚', '湛江',
                '无锡', '宁波', '温州', '东莞', '佛山',
            ],
        ];
    }

    /**
     * 单个 actor_area 归类：返回 内地 / 港台 / 日韩 / 欧美 / 其他。
     */
    private function classifyArea($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '其他';
        }
        $best = '其他';
        $bestLen = 0;
        foreach ($this->areaBlockWords() as $block => $words) {
            $hitLen = 0;
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                if (mb_stripos($value, $word, 0, 'UTF-8') !== false) {
                    $len = mb_strlen($word, 'UTF-8');
                    if ($len > $hitLen) {
                        $hitLen = $len;
                    }
                }
            }
            if ($hitLen > $bestLen) {
                $bestLen = $hitLen;
                $best = $block;
            }
        }
        return $best;
    }

    /**
     * 库中全部 actor_area 的区块归属表（缓存 10 分钟，地区值新增后自动重算）。
     */
    private function areaBlockMap()
    {
        $cacheKey = 'actor_area_block_map_v2';
        $map = null;
        try {
            $map = \think\facade\Cache::get($cacheKey);
        } catch (\Throwable $e) {
            $map = null;
        }
        if (!is_array($map)) {
            $map = [];
            try {
                $rows = (new \app\common\model\Actor())
                    ->where('actor_status', 1)
                    ->field('actor_area')
                    ->group('actor_area')
                    ->select();
                foreach ($rows as $row) {
                    $value = trim((string)$row['actor_area']);
                    if ($value !== '') {
                        $map[$value] = $this->classifyArea($value);
                    }
                }
            } catch (\Throwable $e) {
                $map = [];
            }
            try {
                \think\facade\Cache::set($cacheKey, $map, 600);
            } catch (\Throwable $e) {
                // 缓存不可用时退化为每次查询
            }
        }
        return $map;
    }

    /**
     * 地区分组多值化：一个区块可对应多个 actor_area 取值。
     * 取值不再写死，而是把库里所有 actor_area 逐个归类：
     * 有明确归属（内地 / 港台 / 日韩 / 欧美）的自动进入对应区块，
     * 判不出归属的（泰国、马来西亚、印度、新加坡、黎巴嫩等）统一进入「其他」区块。
     */
    private function areaGroupWords($area)
    {
        $area = trim((string)$area);
        if (!in_array($area, self::$areaBlocks, true)) {
            return [$area];
        }
        $words = [];
        foreach ($this->areaBlockMap() as $value => $block) {
            if ($block === $area) {
                $words[] = $value;
            }
        }
        if (!in_array($area, $words, true)) {
            $words[] = $area;
        }
        return $words;
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
            // 默认落在「艺人」父类（type_pid=0）上，这样演员库列出的是全部 12xxx 位艺人。
            // 之前取 type_mid=8 的第一条（801 内地），而库里的 actor_type_id 都是父类 8，
            // 用 801 过滤结果是 0 条，页面就显示“共有 0 明星 / 暂无数据”。
            $typeModel = new \app\common\model\Type();
            $default_actor_type = $typeModel->where(['type_mid' => 8, 'type_status' => 1, 'type_pid' => 0])->find();
            if (empty($default_actor_type)) {
                $first_child = $typeModel->where(['type_mid' => 8, 'type_status' => 1])->find();
                $pid = isset($first_child->type_pid) ? (int)$first_child->type_pid : 0;
                if ($pid > 0) {
                    $default_actor_type = $typeModel->where(['type_id' => $pid])->find();
                }
            }
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
        return $this->label_fetch(mac_tpl_fetch_ajax('actor','ajax_show','show'));
    }

    public function search()
    {
        $param = mac_param_url();
        $this->check_search($param);
        SearchService::logFromParam(8, $param);
        $this->label_search($param);
        return $this->label_fetch('actor/search');
    }

    /**
     * 主演名 -> 艺人档案 桥接端点
     * 详情页主演链接统一指向 actor/link.html?wd=名称：
     *  - 库内存在该艺人档案(actor_status=1) => 302 跳转到艺人详情页
     *  - 库内无档案 => 302 退回 vod/search.html?actor=名称，按演员名列出作品，彻底消除死链
     */
    public function link()
    {
        $param = mac_param_url();
        $wd = trim($param['wd'] ?? '');
        if ($wd === '') {
            return $this->redirect(mac_url('actor/index'));
        }

        $actor = (new \app\common\model\Actor())
            ->where('actor_name', $wd)
            ->where('actor_status', 1)
            ->find();

        if ($actor) {
            return $this->redirect(mac_url('actor/detail', [], $actor->toArray()));
        }

        // 库内无对应艺人档案 => 退回按演员名检索影视作品
        return $this->redirect(mac_url_search(['actor' => $wd], 'vod'));
    }

    public function ajax_search()
    {
        $param = mac_param_url();
        $this->check_ajax();
        $this->check_search($param,1);
        SearchService::logFromParam(8, $param);
        $this->label_search($param);
        return $this->label_fetch(mac_tpl_fetch_ajax('actor','ajax_search','search'));
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
        return $this->label_fetch(mac_tpl_fetch_ajax('actor','ajax_detail','detail'));
    }

    public function rss()
    {
        $info = $this->label_actor_detail();
        return response($this->label_fetch('actor/rss'), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
