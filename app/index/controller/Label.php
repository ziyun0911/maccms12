<?php
namespace app\index\controller;


class Label extends Base
{
    /**
     * 排行榜独立页：分类列表与首页 module/index_theme_vars 中 $index_rank_types 规则一致，
     * 便于调用相同的 vod/get_rank 接口并得到同一批栏目。
     * 优先级：theme.rank.hid（与首页一致）> theme.rank.id（旧独立页配置）> 等价于 hid 为空时的 parent。
     */
    protected function labelRankTypesForPage(): array
    {
        $mc = isset($GLOBALS['mctheme']) && is_array($GLOBALS['mctheme'])
            ? $GLOBALS['mctheme']
            : (config('mctheme') ?: ['theme' => []]);
        $rankCfg = (isset($mc['theme']['rank']) && is_array($mc['theme']['rank'])) ? $mc['theme']['rank'] : [];
        $rankHid = isset($rankCfg['hid']) ? trim((string) $rankCfg['hid']) : '';
        $rankIdLegacy = isset($rankCfg['id']) ? trim((string) $rankCfg['id']) : '';
        if ($rankHid !== '') {
            $rankTypeIdsRaw = $rankHid;
        } elseif ($rankIdLegacy !== '') {
            $rankTypeIdsRaw = $rankIdLegacy;
        } else {
            $rankTypeIdsRaw = 'parent';
        }

        $typeModel = (new \app\common\model\Type());
        $typeListCache = $typeModel->getCache('type_list');
        $rankTypeList = [];
        if (!is_array($typeListCache)) {
            return [];
        }
        if ($rankTypeIdsRaw === '' || $rankTypeIdsRaw === 'parent') {
            foreach ($typeListCache as $typeInfo) {
                if (!is_array($typeInfo)) {
                    continue;
                }
                if ((int) ($typeInfo['type_pid'] ?? 0) !== 0) {
                    continue;
                }
                if ((int) ($typeInfo['type_mid'] ?? 0) !== 1) {
                    continue;
                }
                if (isset($typeInfo['type_status']) && (int) $typeInfo['type_status'] !== 1) {
                    continue;
                }
                $rankTypeList[] = $typeInfo;
            }
            usort($rankTypeList, function ($a, $b) {
                return ((int) ($a['type_sort'] ?? 0)) <=> ((int) ($b['type_sort'] ?? 0));
            });
            $rankTypeList = array_slice($rankTypeList, 0, 6);
        } else {
            $ids = array_filter(array_map('intval', explode(',', $rankTypeIdsRaw)));
            foreach ($ids as $rid) {
                $info = $typeModel->getCacheInfo($rid);
                if (!empty($info) && (int) ($info['type_mid'] ?? 0) === 1) {
                    $rankTypeList[] = $info;
                }
            }
            $rankTypeList = array_slice($rankTypeList, 0, 6);
        }

        $out = [];
        foreach ($rankTypeList as $typeInfo) {
            $out[] = [
                'type_id'   => (int) ($typeInfo['type_id'] ?? 0),
                'type_name' => (string) ($typeInfo['type_name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 自定义页入口：URL 里的动作名即模板名（label/<action>.html），各主题自带哪些文件不同，
     * 所以不逐个定义方法。TP8 的 think\route\Dispatch 用 is_callable 判定动作，未定义的动作
     * 会落到 __call；若仍让未定义动作直接抛给框架，前台就会显示「方法不存在」调试页。
     */
    public function __call($name, $arguments)
    {
        return $this->label_page($name);
    }

    public function index()
    {
        return $this->label_page('index');
    }

    protected function label_page($file)
    {
        $param = mac_param_url();
        if (!empty($param['file'])) {
            $file = $param['file'];
        }
        $file = str_replace('\\', '/', (string) $file);
        if (strpos($file, '/') !== false
            || !file_exists($GLOBALS['MAC_ROOT_TEMPLATE'] . 'label/' . $file . '.html')) {
            // 当前主题没有这个自定义页：给站点自己的 404，而不是框架报错页
            $this->page_error(lang('illegal_request'));
        }

        if ($file === 'rank') {
            // 空列表交给前端按当前界面语言渲染“全站”
            $types = $this->labelRankTypesForPage();
            $mc = isset($GLOBALS['mctheme']) && is_array($GLOBALS['mctheme'])
                ? $GLOBALS['mctheme']
                : (config('mctheme') ?: ['theme' => []]);
            $rankCfg = (isset($mc['theme']['rank']) && is_array($mc['theme']['rank'])) ? $mc['theme']['rank'] : [];
            $vodNum = max(1, min(50, (int) ($rankCfg['num'] ?? 6)));
            // 未配置标题时留空，由前端取 string_rank，避免写死中文兜底
            $rankTitleSuffix = isset($rankCfg['title']) ? trim((string) $rankCfg['title']) : '';
            $this->assign('label_rank_types', $types);
            $this->assign('label_rank_config', [
                'vod_num'           => $vodNum,
                'rank_title_suffix' => $rankTitleSuffix,
            ]);
        }

        return $this->label_fetch('label/' . $file);
    }
}
