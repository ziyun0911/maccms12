<?php
namespace app\api\controller;
use app\common\util\ExternalSyncRunner;

use think\facade\Db;
use app\common\util\AnalyticsAggregator;
use app\admin\controller\AiAnnotation as AiAnnotationCtl;
use app\common\util\AiProvider;
use app\common\util\ContentAnnotator;
use app\common\util\ContentQualityScorer;

class Timming extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input('get.','','trim,urldecode');
        $name = $param['name'];
        if(empty($name)){
        }

        $list = config('timming');
        $enforce = false;
        if (isset($param['enforce']) && $param['enforce'] == '1') {
            $adminChk = ((new \app\common\model\Admin()))->checkLogin();
            $enforce = is_array($adminChk) && isset($adminChk['code']) && intval($adminChk['code']) <= 1;
        }
        foreach($list as $k=>$v){
            if(!empty($name) && $v['name'] !=$name){
                continue;
            }

            if(!empty($v['runtime'])) { $oldweek= date('w',$v['runtime']); $oldhours= date('H',$v['runtime']); }
            $curweek= date('w',time()) ;	$curhours= date("H",time());
            if(strlen($oldhours)==1 && intval($oldhours) <10){ $oldhours= '0'.$oldhours; }
            if(strlen($curhours)==1 && intval($curhours) <10){ $curhours= substr($curhours,1,1); }
            $last = (!empty($v['runtime']) ? date('Y-m-d H:i:s',$v['runtime']) : lang('api/never'));
            $status = $v['status'] == '1' ?  lang('open'): lang('close');

            $interval = isset($v['interval']) ? intval($v['interval']) : 0;
            $weekOk = strpos($v['weeks'],$curweek)!==false;
            $shouldRun = false;
            if($v['status']=='1'){
                if($enforce){
                    $shouldRun = true;
                }elseif(empty($v['runtime'])){
                    $shouldRun = true;
                }elseif($interval > 0){
                    $shouldRun = ($weekOk && (time() - intval($v['runtime'])) >= $interval);
                }else{
                    $shouldRun = (($oldweek."-".$oldhours) != ($curweek."-".$curhours) && $weekOk && strpos($v['hours'],$curhours)!==false);
                }
            }

            if( $shouldRun ) {
                mac_echo( lang('api/task_tip_exec',[$v['name'] ,$status,$last]));
                $list[$k]['runtime'] = time();

                $res = mac_arr2file( APP_PATH .'extra/timming.php', $list);
                if($res===false){
                    return $this->error(lang('write_err_config'));
                }
                $this->reset();

                $file  = isset($v['file']) && $v['file'] !== '' ? $v['file'] : (isset($v['type']) ? $v['type'] : '');
                $param = isset($v['param']) ? $v['param'] : '';
                if ($param === '' && !empty($v['url'])) {
                    $query = parse_url($v['url'], PHP_URL_QUERY);
                    $param = $query !== null ? $query : '';
                }

                if (!is_string($file) || $file === '' || !method_exists($this, $file)) {
                    mac_echo(lang('api/task_tip_jump', [$v['name'], $status, $last]));
                    die;
                }

                $this->$file($param);
                die;
            }
            else{
                mac_echo(lang('api/task_tip_jump',[$v['name'] ,$status,$last]));
            }
        }
    }

    private function reset()
    {
        foreach($_REQUEST as $k=>$v){
            $_REQUEST[$k]='';
        }
    }

    protected function collect($param)
    {
        @parse_str($param,$output);
        $request = new \app\admin\controller\Collect();
        $request->api($output);
    }

    protected function make($param)
    {
        @parse_str($param,$output);
        $request = new \app\admin\controller\Make();
        $request->make($output);
    }

    protected function cj($param)
    {
        @parse_str($param,$output);
        $request = new \app\admin\controller\Cj();
        $request->col_all($output);
    }

    protected function cache($param)
    {
        @parse_str($param,$output);
        $request = new \app\admin\controller\Index();
        $request->clear();
    }

    protected function urlsend($param)
    {
        @parse_str($param,$output);
        $request = new \app\admin\controller\Urlsend();
        $request->push($output);
    }

    protected function analytics($param)
    {
        @parse_str($param, $output);
        $mode = empty($output['mode']) ? 'hour' : trim($output['mode']);
        $date = empty($output['date']) ? '' : trim($output['date']);
        $res = $mode === 'day'
            ? AnalyticsAggregator::runDay($date)
            : AnalyticsAggregator::runHour($date);
        if (isset($res['msg'])) {
            mac_echo('[analytics] ' . $res['msg']);
        }
    }

    protected function extsync($param)
    {
        @parse_str($param, $output);
        $provider = isset($output['provider']) ? trim((string)$output['provider']) : '';
        $cfg = config('maccms');
        $extCfg = isset($cfg['ai_search']['external_sources']) && is_array($cfg['ai_search']['external_sources'])
            ? $cfg['ai_search']['external_sources']
            : [];
        $runner = new ExternalSyncRunner();
        $runner->runDueJobs($extCfg, $provider);
    }

    protected function annotate($param)
    {
        @parse_str($param, $output);
        $mid = intval(isset($output['mid']) ? $output['mid'] : 1);
        if ($mid !== 1 && $mid !== 2) {
            $mid = 1;
        }
        $cfg = AiProvider::resolveConfig();
        if (empty($cfg['enabled'])) {
            mac_echo('[annotate] ai_content disabled');
            return;
        }
        $limit = intval(isset($output['limit']) ? $output['limit'] : $cfg['batch_size']);
        $limit = max(1, min($cfg['batch_size'], $limit));

        $ids = AiAnnotationCtl::pickPending($mid, $limit);
        $ok = 0;
        $fail = 0;
        foreach ($ids as $id) {
            try {
                $res = ContentAnnotator::annotate($mid, $id, false);
                if (intval($res['code']) === 1) {
                    $ok++;
                } else {
                    $fail++;
                }
            } catch (\Throwable $e) {
                $fail++;
            }
        }
        mac_echo('[annotate] mid=' . $mid . ' ok=' . $ok . ' fail=' . $fail);
    }

    protected function content_quality($param)
    {
        @parse_str($param, $output);
        $mid = intval(isset($output['mid']) ? $output['mid'] : 1);
        if ($mid !== 1 && $mid !== 2) {
            $mid = 1;
        }
        $limit = intval(isset($output['limit']) ? $output['limit'] : 200);
        if ($limit < 1) {
            $limit = 200;
        }
        $days = intval(isset($output['days']) ? $output['days'] : 30);
        if ($days < 1) {
            $days = 30;
        }

        $cfg = config('maccms');
        $weights = (isset($cfg['analytics']['quality_weights']) && is_array($cfg['analytics']['quality_weights']))
            ? $cfg['analytics']['quality_weights']
            : ContentQualityScorer::defaultWeights();
        $halflifeDays = isset($cfg['analytics']['quality_fresh_halflife_days']) ? floatval($cfg['analytics']['quality_fresh_halflife_days']) : 30;
        if ($halflifeDays <= 0) {
            $halflifeDays = 30;
        }

        $now = time();
        $from = date('Y-m-d', $now - $days * 86400);
        $to = date('Y-m-d', $now);

        $prefix = ($mid === 2) ? 'art' : 'vod';
        $table = ($mid === 2) ? 'Art' : 'Vod';
        $idField = $prefix . '_id';
        $statusField = $prefix . '_status';

        $fields = array($idField, 'type_id', $prefix . '_blurb', $prefix . '_tag', $prefix . '_pic', $prefix . '_content', $prefix . '_score', $prefix . '_up', $prefix . '_down', $prefix . '_hits', $prefix . '_time_add');
        if ($prefix === 'vod') {
            $fields[] = $prefix . '_actor';
        }

        $pre = Db::getConfig('connections.mysql.prefix');
        $fieldsSql = 'c.' . implode(',c.', $fields) . ',q.time_update as q_time_update';

        $rows = Db::name($table)
            ->alias('c')
            ->join($pre . 'content_quality q', 'q.mid = ' . $mid . ' and q.content_id = c.' . $idField, 'left')
            ->field($fieldsSql)
            ->where('c.' . $statusField, 1)
            ->order('q_time_update asc, c.' . $idField . ' asc')
            ->limit($limit)
            ->select();

        $ids = array();
        foreach ($rows as $row) {
            $ids[] = intval($row[$idField]);
        }

        $aggList = array();
        if (!empty($ids)) {
            $aggRows = Db::name('AnalyticsContentDay')
                ->field('mid,content_id,sum(view_pv) view_pv,sum(view_uv) view_uv,avg(avg_stay_ms) avg_stay_ms,sum(bounce_cnt) bounce_cnt,sum(collect_add) collect_add,sum(want_add) want_add,sum(order_cnt) order_cnt,sum(order_amount) order_amount')
                ->where('stat_date', 'between', array($from, $to))
                ->where('mid', $mid)
                ->where('content_id', 'in', $ids)
                ->group('mid,content_id')
                ->select();
            foreach ($aggRows as $agg) {
                $aggList[intval($agg['content_id'])] = $agg;
            }
        }

        $calcDate = date('Y-m-d', $now);
        $model = (new \app\common\model\ContentQuality());
        $ok = 0;
        $fail = 0;
        foreach ($rows as $row) {
            try {
                $contentId = intval($row[$idField]);
                $agg = isset($aggList[$contentId]) ? $aggList[$contentId] : null;
                $score = ContentQualityScorer::scoreRow($mid, $row, $agg, $weights, $halflifeDays, $now);
                $data = array(
                    'type_id' => intval($row['type_id']),
                    'score_total' => $score['score_total'],
                    'score_behavior' => $score['score_behavior'],
                    'score_interact' => $score['score_interact'],
                    'score_complete' => $score['score_complete'],
                    'score_fresh' => $score['score_fresh'],
                    'is_cold_start' => $score['is_cold_start'],
                    'calc_date' => $calcDate,
                );
                $res = $model->saveByObject($mid, $contentId, $data);
                if ($res !== false) {
                    $ok++;
                } else {
                    $fail++;
                }
            } catch (\Throwable $e) {
                $fail++;
            }
        }
        mac_echo('[content_quality] mid=' . $mid . ' ok=' . $ok . ' fail=' . $fail);
    }

    protected function user_profile($param)
    {
        @parse_str($param, $output);
        $limit = intval(isset($output['limit']) ? $output['limit'] : 200);
        if ($limit < 1) {
            $limit = 200;
        }
        $days = intval(isset($output['days']) ? $output['days'] : 30);
        if ($days < 1) {
            $days = 30;
        }

        $cfg = config('maccms');
        $windowDays = isset($cfg['analytics']['profile_window_days']) ? intval($cfg['analytics']['profile_window_days']) : $days;
        if ($windowDays < 1) {
            $windowDays = $days;
        }

        $now = time();
        $pre = Db::getConfig('connections.mysql.prefix');

        $rows = Db::name('Ulog')
            ->alias('u')
            ->join($pre . 'user_profile p', 'p.user_id = u.user_id', 'left')
            ->field('u.user_id,p.time_update as p_time_update')
            ->where('u.user_id', '>', 0)
            ->group('u.user_id')
            ->order('p_time_update asc, u.user_id asc')
            ->limit($limit)
            ->select();

        $calcDate = date('Y-m-d', $now);
        $builder = new \app\common\util\UserProfileBuilder();
        $model = (new \app\common\model\UserProfile());
        $ok = 0;
        $fail = 0;
        foreach ($rows as $row) {
            try {
                $uid = intval($row['user_id']);
                $data = $builder->buildForUser($uid, $windowDays, $now);
                $data['calc_date'] = $calcDate;
                $res = $model->saveByUser($uid, $data);
                if ($res !== false) {
                    $ok++;
                } else {
                    $fail++;
                }
            } catch (\Throwable $e) {
                $fail++;
            }
        }
        mac_echo('[user_profile] ok=' . $ok . ' fail=' . $fail);
    }

    protected function notify($param)
    {
        @parse_str($param, $output);
        $days = isset($output['days']) ? intval($output['days']) : 3;
        if ($days < 1) {
            $days = 3;
        }
        $res = (new \app\common\model\Notify())->sendVipExpirationReminders($days);
        if (isset($res['info']['sent'])) {
            mac_echo('[notify] vip expiration reminders sent: ' . intval($res['info']['sent']));
        }
    }

    protected function vodpublish($param)
    {
        @parse_str($param, $output);
        $limit = isset($output['limit']) ? intval($output['limit']) : 200;
        $res = \app\common\util\VodPublishService::publishDue($limit);
        mac_echo('[vodpublish] ' . $res['msg']);
        if (!empty($res['ids'])) {
            mac_echo('[vodpublish] ids: ' . implode(',', $res['ids']));
        }
    }

    protected function pushbroadcast($param)
    {
        @parse_str($param, $output);
        $batch = isset($output['batch']) ? intval($output['batch']) : 100;
        $max   = isset($output['max']) ? intval($output['max']) : 500;
        $res = \app\common\util\PushDispatcher::runQueue($batch, $max);
        mac_echo('[pushbroadcast] ' . (isset($res['msg']) ? $res['msg'] : '') . ' processed=' . (isset($res['processed']) ? intval($res['processed']) : 0));
    }

    protected function user_access_purge($param)
    {
        @parse_str($param, $output);
        $cfg = config('maccms');
        $mon = isset($cfg['monitor']) && is_array($cfg['monitor']) ? $cfg['monitor'] : [];
        $retain = intval(isset($mon['retain_user_access_days']) ? $mon['retain_user_access_days'] : 90);
        $retain = max(1, min(365, $retain));
        $anon = intval(isset($mon['user_access_anonymize_days']) ? $mon['user_access_anonymize_days'] : 30);
        $anon = max(0, min(365, $anon));
        if ($anon > 0 && $anon >= $retain) {
            $anon = $retain - 1;
        }
        $max = intval(isset($output['max']) ? $output['max'] : 5000);
        $res = \app\common\model\UserAccessLog::purge($retain, $anon, $max);
        mac_echo('[user_access_purge] deleted=' . intval($res['deleted']) . ' anonymized=' . intval($res['anonymized']));
    }
}

