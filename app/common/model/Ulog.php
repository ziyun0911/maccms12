<?php
namespace app\common\model;
use think\facade\Db;

class Ulog extends Base {
    protected $name = 'ulog';

    protected $createTime = '';
    protected $updateTime = '';

    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function listData($where,$order,$page=1,$limit=20,$start=0)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        $total = $this->where($where)->count();
        $list = Db::name('Ulog')->where($where)->order($order)->limit($limit_str)->select();

        $user_ids=[];
        foreach($list as $k=>&$v){
            if($v['user_id'] >0){
                $user_ids[$v['user_id']] = $v['user_id'];
            }

            if($v['ulog_mid']==12){
                $manga_info = (new \app\common\model\Manga())->infoData(['manga_id'=>['eq',$v['ulog_rid']]],'*',1);
                if (!empty($manga_info['info'])) {
                    if (intval($v['ulog_type']) === 4 && intval($v['ulog_sid']) > 0 && intval($v['ulog_nid']) > 0) {
                        $manga_info['info']['link'] = mac_url_manga_play($manga_info['info'], [
                            'sid' => intval($v['ulog_sid']),
                            'nid' => intval($v['ulog_nid']),
                        ]);
                    } else {
                        $manga_info['info']['link'] = mac_url_manga_detail($manga_info['info']);
                    }
                    $v['data'] = [
                        'id'   => $manga_info['info']['manga_id'],
                        'name' => $manga_info['info']['manga_name'],
                        'pic'  => mac_url_img($manga_info['info']['manga_pic']),
                        'link' => $manga_info['info']['link'],
                        'type' => [
                            'type_id'   => $manga_info['info']['type']['type_id'],
                            'type_name' => $manga_info['info']['type']['type_name'],
                            'link'      => mac_url_type($manga_info['info']['type']),
                        ],
                    ];
                }
            }

            if($v['ulog_mid']==1){
                $vod_info = (new \app\common\model\Vod())->infoData(['vod_id'=>['eq',$v['ulog_rid']]],'*',1);

                if($v['ulog_sid']>0 && $v['ulog_nid']>0){
                    if($v['ulog_type']==5){
                        $vod_info['info']['link'] = mac_url_vod_down($vod_info['info'],['sid'=>$v['ulog_sid'],'nid'=>$v['ulog_nid']]);
                    }
                    else{
                        $vod_info['info']['link'] = mac_url_vod_play($vod_info['info'],['sid'=>$v['ulog_sid'],'nid'=>$v['ulog_nid']]);
                    }
                }
                else{
                    $vod_info['info']['link'] = mac_url_vod_detail($vod_info['info']);
                }
                $v['data'] = [
                    'id'=>$vod_info['info']['vod_id'],
                    'name'=>$vod_info['info']['vod_name'],
                    'pic'=>mac_url_img($vod_info['info']['vod_pic']),
                    'link'=>$vod_info['info']['link'],
                    'type'=>[
                        'type_id'=>$vod_info['info']['type']['type_id'],
                        'type_name'=>$vod_info['info']['type']['type_name'],
                        'link'=>mac_url_type($vod_info['info']['type']),
                    ],

                ];
            }
            elseif($v['ulog_mid']==2){
                $art_info = (new \app\common\model\Art())->infoData(['art_id'=>['eq',$v['ulog_rid']]],'*',1);
                if (intval($v['ulog_type']) === 4 && intval($v['ulog_nid']) > 0) {
                    $art_info['info']['link'] = mac_url('art/read', [
                        'id' => intval($v['ulog_rid']),
                        'page' => intval($v['ulog_nid']),
                    ]);
                } else {
                    $art_info['info']['link'] = mac_url_art_detail($art_info['info']);
                }
                $v['data'] = [
                    'id'=>$art_info['info']['art_id'],
                    'name'=>$art_info['info']['art_name'],
                    'pic'=>mac_url_img($art_info['info']['art_pic']),
                    'link'=>$art_info['info']['link'],
                    'type'=>[
                        'type_id'=>$art_info['info']['type']['type_id'],
                        'type_name'=>$art_info['info']['type']['type_name'],
                        'link'=>mac_url_type($art_info['info']['type']),
                    ],

                ];
            }
            elseif($v['ulog_mid']==3){
                $topic_info = (new \app\common\model\Topic())->infoData(['topic_id'=>['eq',$v['ulog_rid']]],'*',1);
                $topic_info['info']['link'] = mac_url_topic_detail($topic_info['info']);
                $v['data'] = [
                    'id'=>$topic_info['info']['topic_id'],
                    'name'=>$topic_info['info']['topic_name'],
                    'pic'=>mac_url_img($topic_info['info']['topic_pic']),
                    'link'=>$topic_info['info']['link'],
                    'type'=>[],
                ];
            }
            elseif($v['ulog_mid']==8){
                $actor_info = (new \app\common\model\Actor())->infoData(['actor_id'=>['eq',$v['ulog_rid']]],'*',1);
                $actor_info['info']['link'] = mac_url_actor_detail($actor_info['info']);
                $v['data'] = [
                    'id'=>$actor_info['info']['actor_id'],
                    'name'=>$actor_info['info']['actor_name'],
                    'pic'=>mac_url_img($actor_info['info']['actor_pic']),
                    'link'=>$actor_info['info']['link'],
                    'type'=>[],
                ];
            }
        }

        if(!empty($user_ids)){
            $where2=[];
            $where['user_id'] = ['in', $user_ids];
            $order='user_id desc';
            $user_list = (new \app\common\model\User())->listData($where2,$order,1,999);
            $user_list = mac_array_rekey($user_list['list'],'user_id');

            foreach($list as $k=>&$v){
                $list[$k]['user_name'] = $user_list[$v['user_id']]['user_name'];
            }
        }

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function continueWatchData($user_id, $page = 1, $limit = 12, $options = [])
    {
        $user_id = intval($user_id);
        $page = $page > 0 ? intval($page) : 1;
        $limit = $limit > 0 ? intval($limit) : 12;
        $hide_finished = !empty($options['hide_finished']);

        if ($user_id < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        $where = [
            'user_id'   => ['eq', $user_id],
            'ulog_mid'  => ['eq', 1],
            'ulog_type' => ['eq', 4],
        ];
        $finished_sql = '(ulog_duration <= 0 OR ulog_point * 100 < ulog_duration * 95)';

        $total_query = Db::name('Ulog')->where($where);
        $rows_query = Db::name('Ulog')
            ->field('ulog_id,ulog_rid,ulog_sid,ulog_nid,ulog_point,ulog_duration,ulog_time')
            ->where($where);
        if ($hide_finished) {
            $total_query->where($finished_sql);
            $rows_query->where($finished_sql);
        }

        $total = $total_query->count();
        $rows = $rows_query
            ->order('ulog_time desc')
            ->limit(($limit * ($page - 1)) . ',' . $limit)
            ->select();

        $list = [];
        if (!empty($rows)) {
            $vod_ids = [];
            foreach ($rows as $v) {
                $vod_ids[intval($v['ulog_rid'])] = intval($v['ulog_rid']);
            }
            $vod_list = Db::name('Vod')
                ->field('vod_id,type_id,type_id_1,vod_name,vod_en,vod_letter,vod_pic,vod_remarks,vod_play_from,vod_play_url,vod_time')
                ->where('vod_id', 'in', array_values($vod_ids))
                ->select();
            $vod_list = mac_array_rekey($vod_list, 'vod_id');

            $type_list = (new \app\common\model\Type())->getCache('type_list');

            foreach ($rows as $v) {
                $vod_id = intval($v['ulog_rid']);
                if (!isset($vod_list[$vod_id])) {
                    continue;
                }
                $vod = $vod_list[$vod_id];

                $sid = intval($v['ulog_sid']);
                $nid = intval($v['ulog_nid']);
                $point = intval($v['ulog_point']);
                $duration = intval($v['ulog_duration']);

                $percent = 0;
                if ($duration > 0) {
                    $percent = intval(floor($point * 100 / $duration));
                    if ($percent > 100) {
                        $percent = 100;
                    }
                }

                $episode_name = '';
                if ($sid > 0 && $nid > 0 && !empty($vod['vod_play_url'])) {
                    $play_url_arr = explode('$$$', $vod['vod_play_url']);
                    if (isset($play_url_arr[$sid - 1])) {
                        $episode_arr = explode('#', $play_url_arr[$sid - 1]);
                        if (isset($episode_arr[$nid - 1])) {
                            $tmp = explode('$', $episode_arr[$nid - 1]);
                            $episode_name = trim($tmp[0]);
                        }
                    }
                }

                if ($sid > 0 && $nid > 0) {
                    $link_play = mac_url_vod_play($vod, ['sid' => $sid, 'nid' => $nid]);
                } else {
                    $link_play = mac_url_vod_detail($vod);
                }

                $type_info = [];
                if (isset($type_list[$vod['type_id']])) {
                    $type = $type_list[$vod['type_id']];
                    $type_info = [
                        'type_id'   => intval($type['type_id']),
                        'type_name' => $type['type_name'],
                        'link'      => mac_url_type($type),
                    ];
                }

                $list[] = [
                    'ulog_id'      => intval($v['ulog_id']),
                    'vod_id'       => $vod_id,
                    'vod_name'     => $vod['vod_name'],
                    'vod_pic'      => mac_url_img($vod['vod_pic']),
                    'vod_remarks'  => $vod['vod_remarks'],
                    'type'         => $type_info,
                    'sid'          => $sid,
                    'nid'          => $nid,
                    'point'        => $point,
                    'duration'     => $duration,
                    'percent'      => $percent,
                    'finished'     => ($duration > 0 && $percent >= 95) ? 1 : 0,
                    'episode_name' => $episode_name,
                    'link_play'    => $link_play,
                    'link_detail'  => mac_url_vod_detail($vod),
                    'time'         => intval($v['ulog_time']),
                ];
            }
        }

        return [
            'code'      => 1,
            'msg'       => lang('data_list'),
            'page'      => $page,
            'pagecount' => ceil($total / $limit),
            'limit'     => $limit,
            'total'     => $total,
            'list'      => $list,
        ];
    }

    public function infoData($where,$field='*')
    {
        if(empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();

        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }
        $info = $info->toArray();

        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function recordReadChapter($userId, $mid, $rid, $sid, $nid)
    {
        $validated = $this->validateReadChapter($userId, $mid, $rid, $sid, $nid);
        if ($validated['code'] !== 1) {
            return $validated;
        }

        $data = $validated['data'];
        $data['ulog_time'] = time();
        $data['ulog_touch'] = (int)round(microtime(true) * 1000000);
        $historyWhere = $data;
        unset($historyWhere['ulog_time'], $historyWhere['ulog_touch']);
        $lockName = 'ulog_read_' . sha1(implode(':', [
            $data['user_id'], $data['ulog_mid'], $data['ulog_rid'], $data['ulog_sid'], $data['ulog_nid'],
        ]));
        try {
            $lock = Db::query('SELECT GET_LOCK(?, 3) AS acquired', [$lockName]);
            if (empty($lock) || intval($lock[0]['acquired']) !== 1) {
                return ['code' => 1004, 'msg' => lang('save_err')];
            }
            $existing = $this->field('ulog_id')->where($historyWhere)->find();
            if (!empty($existing)) {
                $res = $this->where('ulog_id', intval($existing['ulog_id']))->update([
                    'ulog_time' => $data['ulog_time'],
                    'ulog_touch' => $data['ulog_touch'],
                ]);
                if ($res === false) {
                    return ['code' => 1004, 'msg' => lang('save_err')];
                }
                return ['code' => 1, 'msg' => lang('save_ok')];
            }
            return $this->saveData($data);
        } catch (\Exception $e) {
            return ['code' => 1004, 'msg' => lang('save_err')];
        } finally {
            try {
                Db::query('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Exception $e) {
            }
        }
    }

    public function readChapterData($userId, $mid, $rid, $sid = 0)
    {
        $userId = intval($userId);
        $mid = intval($mid);
        $rid = intval($rid);
        $sid = intval($sid);
        if ($userId < 1 || $rid < 1 || !in_array($mid, [2, 12], true)) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        if ($mid === 2) {
            $sid = 1;
        } elseif ($sid < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        $where = [
            'user_id' => $userId,
            'ulog_mid' => $mid,
            'ulog_type' => 4,
            'ulog_rid' => $rid,
            'ulog_sid' => $sid,
        ];
        $rows = Db::name('Ulog')
            ->field('ulog_nid')
            ->where($where)
            ->where('ulog_nid', 'gt', 0)
            ->group('ulog_nid')
            ->order('ulog_nid asc')
            ->select();

        $readChapters = [];
        foreach ($rows as $row) {
            $readChapters[] = intval($row['ulog_nid']);
        }
        $lastRow = Db::name('Ulog')
            ->field('ulog_nid,ulog_time')
            ->where($where)
            ->where('ulog_nid', 'gt', 0)
            ->order('ulog_touch desc,ulog_time desc,ulog_id desc')
            ->find();
        $lastChapter = empty($lastRow) ? 0 : intval($lastRow['ulog_nid']);
        $lastReadTime = empty($lastRow) ? 0 : intval($lastRow['ulog_time']);

        return [
            'code' => 1,
            'msg' => lang('obtain_ok'),
            'info' => [
                'mid' => $mid,
                'rid' => $rid,
                'sid' => $sid,
                'read_chapters' => $readChapters,
                'read_count' => count($readChapters),
                'last_chapter' => $lastChapter,
                'last_read_time' => $lastReadTime,
            ],
        ];
    }

    private function validateReadChapter($userId, $mid, $rid, $sid, $nid)
    {
        $userId = intval($userId);
        $mid = intval($mid);
        $rid = intval($rid);
        $sid = intval($sid);
        $nid = intval($nid);
        if ($userId < 1 || $rid < 1 || $nid < 1 || !in_array($mid, [2, 12], true)) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        if ($mid === 2) {
            if ($sid !== 1) {
                return ['code' => 1001, 'msg' => lang('param_err')];
            }
            $content = Db::name('Art')->where('art_id', $rid)->value('art_content');
            if ($content === null || $content === '') {
                return ['code' => 1002, 'msg' => lang('obtain_err')];
            }
            $chapterCount = count(explode('$$$', $content));
            if ($nid > $chapterCount) {
                return ['code' => 1001, 'msg' => lang('param_err')];
            }
        } else {
            if ($sid < 1) {
                return ['code' => 1001, 'msg' => lang('param_err')];
            }
            $manga = Db::name('Manga')->field('manga_chapter_from,manga_chapter_url')->where('manga_id', $rid)->find();
            if (empty($manga) || empty($manga['manga_chapter_url'])) {
                return ['code' => 1002, 'msg' => lang('obtain_err')];
            }
            $sourceList = explode('$$$', (string)$manga['manga_chapter_url']);
            if (!isset($sourceList[$sid - 1]) || $sourceList[$sid - 1] === '') {
                return ['code' => 1001, 'msg' => lang('param_err')];
            }
            $chapterList = explode('#', $sourceList[$sid - 1]);
            if (!isset($chapterList[$nid - 1]) || trim($chapterList[$nid - 1]) === '') {
                return ['code' => 1001, 'msg' => lang('param_err')];
            }
        }

        return [
            'code' => 1,
            'data' => [
                'user_id' => $userId,
                'ulog_mid' => $mid,
                'ulog_type' => 4,
                'ulog_rid' => $rid,
                'ulog_sid' => $sid,
                'ulog_nid' => $nid,
                'ulog_points' => 0,
            ],
        ];
    }

    public function hasBought($where)
    {
        if (empty($where) || !is_array($where)) {
            return false;
        }
        $isVideoDownload = (intval(isset($where['ulog_mid']) ? $where['ulog_mid'] : 0) == 1
            && intval(isset($where['ulog_type']) ? $where['ulog_type'] : 0) == 5);
        unset($where['ulog_time']);
        if ($isVideoDownload) {
            unset($where['ulog_points']);
        }
        return $this->where($where)->count() > 0;
    }

    public function saveData($data)
    {
        if (empty($data['user_id'])) {
            $data['user_id'] = intval(cookie('user_id'));
        } else {
            $data['user_id'] = intval($data['user_id']);
        }
        $data['ulog_time'] = time();

        $validate = validate('app\\common\\validate\\Ulog', [], false, false);
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
        }

        if($data['user_id']==0 || !in_array($data['ulog_mid'],['1','2','3','8','12']) || !in_array($data['ulog_type'],['1','2','3','4','5']) ) {
            return ['code'=>1002,'msg'=>lang('param_err')];
        }

        if(!empty($data['ulog_id'])){
            $where=[];
            $where['ulog_id'] = ['eq',$data['ulog_id']];
            $res = $this->allowField([])->where($where)->update($data);
        }
        else{
            $res = $this->allowField([])->insert($data);
        }
        if(false === $res){
            return ['code'=>1004,'msg'=>lang('save_err').'：'.$this->getError() ];
        }

        if (empty($data['ulog_id']) && intval($data['ulog_type']) === 2) {
            try {
                $dyn_text = '';
                $content_name = $this->_getContentName(intval($data['ulog_mid']), intval($data['ulog_rid']));
                if ($content_name) {
                    $dyn_text = lang('dynamics/type_fav') . ': ' . $content_name;
                }
                (new \app\common\model\Dynamics())->saveData([
                    'user_id'       => intval($data['user_id']),
                    'dynamics_type' => 'fav',
                    'dyn_mid'       => intval($data['ulog_mid']),
                    'dyn_rid'       => intval($data['ulog_rid']),
                    'dyn_text'      => $dyn_text,
                ]);
            } catch (\Exception $e) {
            }
        }

        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    private function _getContentName($mid, $rid)
    {
        $table_map = [1 => 'vod', 2 => 'art', 3 => 'topic', 8 => 'actor', 12 => 'manga'];
        if (!isset($table_map[$mid])) {
            return '';
        }
        $table = $table_map[$mid];
        $pk = $table . '_id';
        $name_col = $table . '_name';
        $row = \think\facade\Db::name($table)->where($pk, $rid)->field($name_col)->find();
        return $row ? $row[$name_col] : '';
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('del_ok')];
    }

    public function fieldData($where,$col,$val)
    {
        if(!isset($col) || !isset($val)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $data = [];
        $data[$col] = $val;
        $res = $this->allowField([])->where($where)->update($data);
        if($res===false){
            return ['code'=>1001,'msg'=>lang('set_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('set_ok')];
    }
}