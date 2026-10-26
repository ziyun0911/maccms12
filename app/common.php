<?php
/*
'软件名称：苹果CMS  源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/

use think\facade\View;

error_reporting(E_ERROR | E_PARSE );

function mac_load_extra(): void
{
    $dir = APP_PATH . 'extra' . DIRECTORY_SEPARATOR;
    foreach (glob($dir . '*.php') as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        if ($name === 'README') {
            continue;
        }
        $data = include $file;
        if (is_array($data)) {
            \think\facade\Config::set($data, $name);
        }
    }
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'addon' . DIRECTORY_SEPARATOR . 'helpers.php';

function get_array_unique_id_list($list, $need_sort = false) {
    $list = array_unique($list);
    $list = array_map('intval', $list);
    $list = array_filter($list);
    $list = array_values($list);
    $need_sort && sort($list);
    return $list;
}

function mac_tpl_vod_type_cover($typeId)
{
    $typeId = (int) $typeId;
    $tplconfig = $GLOBALS['mctheme'];
    $theme = isset($tplconfig['theme']) && is_array($tplconfig['theme']) ? $tplconfig['theme'] : [];

    $pageRows = $theme['list_cover']['vod'] ?? [];
    if (!is_array($pageRows)) {
        $pageRows = [];
    }
    $pageMap = [];
    foreach ($pageRows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $id = (string) $row['id'];
        $c = isset($row['cover']) ? (string) $row['cover'] : 'v';
        $pageMap[$id] = ($c === 'h') ? 'h' : 'v';
    }

    $typeList = (new \app\common\model\Type())->getCache('type_list');
    if (!is_array($typeList)) {
        $typeList = [];
    }

    $resolve = function ($startId, array $map) use ($typeList) {
        $tid = (int) $startId;
        while ($tid > 0) {
            if (isset($map[(string) $tid])) {
                return $map[(string) $tid];
            }
            if (!isset($typeList[$tid])) {
                break;
            }
            $tid = (int) ($typeList[$tid]['type_pid'] ?? 0);
        }

        return null;
    };

    $hit = $resolve($typeId, $pageMap);
    if ($hit !== null) {
        return $hit;
    }

    return 'v';
}

function mac_tpl_manga_cover()
{
    $tplconfig = $GLOBALS['mctheme'];
    $theme = isset($tplconfig['theme']) && is_array($tplconfig['theme']) ? $tplconfig['theme'] : [];
    $raw = isset($theme['list_cover']['manga']) ? (string) $theme['list_cover']['manga'] : 'v';

    return ($raw === 'h') ? 'h' : 'v';
}

function mac_tpl_art_cover()
{
    $tplconfig = $GLOBALS['mctheme'];
    $theme = isset($tplconfig['theme']) && is_array($tplconfig['theme']) ? $tplconfig['theme'] : [];
    $raw = isset($theme['list_cover']['art']) ? (string) $theme['list_cover']['art'] : 'v';

    return ($raw === 'h') ? 'h' : 'v';
}

function mac_tpl_vod_playlink_on()
{
    $mc = isset($GLOBALS['mctheme']) && is_array($GLOBALS['mctheme']) ? $GLOBALS['mctheme'] : (config('mctheme') ?: []);
    $theme = isset($mc['theme']) && is_array($mc['theme']) ? $mc['theme'] : [];
    if (empty($theme['playlink']) || !is_array($theme['playlink'])) {
        return false;
    }
    $btn = $theme['playlink']['btn'] ?? null;
    if ($btn === null || $btn === '') {
        return false;
    }
    return $btn === '1' || $btn === 1 || $btn === true;
}

function mac_vod_play_tagwall_payload($info)
{
    $info = is_array($info) ? $info : [];
    $split = function ($raw, $pattern) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split($pattern, $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $parts)));
    };
    $hotRaw = '';
    if (!empty($GLOBALS['config']['app']['search_hot'])) {
        $hotRaw = (string) $GLOBALS['config']['app']['search_hot'];
    }
    $hot = array_slice($split($hotRaw, '/[,，\s]+/u'), 0, 48);
    $local = [];
    if (!empty($info['vod_tag'])) {
        $local = array_merge($local, $split($info['vod_tag'], '/[,，\/|、\s]+/u'));
    }
    if (!empty($info['vod_class'])) {
        $local = array_merge($local, $split($info['vod_class'], '/[,，\/|、\s]+/u'));
    }
    if (count($local) === 0 && !empty($info['type']['type_name'])) {
        $local[] = trim((string) $info['type']['type_name']);
    }
    $local = array_slice(array_values(array_unique(array_filter($local))), 0, 40);
    $enabled = (count($hot) + count($local)) > 0;
    $json = json_encode(['hot' => $hot, 'local' => $local], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

    return ['enabled' => $enabled, 'json' => $json];
}

function mac_theme_index_hotvod_tabs(array $theme)
{
    $tabsCfg = isset($theme['hotvod']['tabs']) && is_array($theme['hotvod']['tabs']) ? $theme['hotvod']['tabs'] : [];
    if (count($tabsCfg) === 0) {
        return [];
    }

    $typeModel = (new \app\common\model\Type());
    $out = [];

    for ($i = 0; $i < 4; $i++) {
        if (!isset($tabsCfg[$i]) || !is_array($tabsCfg[$i])) {
            continue;
        }
        $row = $tabsCfg[$i];
        $id = isset($row['id']) ? trim((string) $row['id']) : '';
        if ($id === '') {
            continue;
        }

        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        if ($name === '') {
            $vo2 = $typeModel->getCacheInfo((int) $id);
            $name = !empty($vo2['type_name']) ? (string) $vo2['type_name'] : '';
        }

        $out[] = [
            'id' => $id,
            'name' => mac_tpl_text($name),
            'icon_l' => isset($row['icon_l']) ? trim((string) $row['icon_l']) : '',
            'icon_b' => isset($row['icon_b']) ? trim((string) $row['icon_b']) : '',
        ];
    }

    return $out;
}

function slog($logs)
{
    $ymd = date('Y-m-d-H');
    $now = date('Y-m-d H:i:s');
    $toppath = "./log/$ymd.txt";
    $ts = @fopen($toppath,"a+");
    @fputs($ts, $now .' '. $logs ."\r\n");
    @fclose($ts);
}

function mac_string_is_ip($string) {
    return preg_match('/^(\d{1,3}\.){3}\d{1,3}(:\d{1,5})?$/', $string) === 1;
}

function mac_return($msg,$code=1,$data=''){
    if(is_array($msg)){
        return json_encode($msg);
    }
    else {
        $rs = ['code' => $code, 'msg' => $msg, 'data'=>'' ];
        if(is_array($data)) $rs['data'] = $data;
        return json_encode($rs);
    }
}

function mac_run_statistics()
{
    $t2 = microtime(true) - MAC_START_TIME;
    $size = memory_get_usage();
    $memory = mac_format_size($size);
    unset($unit);
    return 'Processed in: '.round($t2,4).' second(s),&nbsp;' . $memory . ' Mem On.';
}

function mac_format_size($s=0)
{
    if($s==0){ return '0 kb'; }
    $unit=array('b','kb','mb','gb','tb','pb');
    return round($s/pow(1024,($i=floor(log($s,1024)))),2).' '.$unit[$i];
}

function mac_read_file($f)
{
    return @file_get_contents($f);
}

function mac_write_file($f,$c='')
{
    $dir = dirname($f);
    if(!is_dir($dir)){
        mac_mkdirss($dir);
    }
    return @file_put_contents($f, $c);
}

function mac_mkdirss($path,$mode=0777)
{
    if (!is_dir(dirname($path))){
        mac_mkdirss(dirname($path));
    }
    if(!file_exists($path)){
        return mkdir($path,$mode);
    }
    return true;
}

function mac_rmdirs($dirname, $withself = true)
{
    if (!is_dir($dirname))
        return false;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo)
    {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    if ($withself)
    {
        @rmdir($dirname);
    }
    return true;
}

function mac_arr2file($f,$arr='')
{
    if(is_array($arr)){
        $con = var_export($arr,true);
    } else{
        $con = $arr;
    }
    $con = "<?php\nreturn $con;";
    $res = mac_write_file($f, $con);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($f, true);
    }
    return $res;
}

function mac_save_config_data($f, $arr = '')
{
    if (!is_array($arr)) {
        return false;
    }
    return mac_arr2file($f, $arr);
}

function mac_replace_text($txt,$type=1)
{
    if($type==1){
        return str_replace('#',Chr(13),$txt);
    }
    return str_replace(chr(13),'#',str_replace(chr(10),'',$txt));
}

function mac_hash_password($raw)
{
    return password_hash((string)$raw, PASSWORD_BCRYPT);
}

function mac_verify_password($raw, $stored)
{
    $stored = (string)$stored;
    if ($stored === '') {
        return false;
    }

    if (strncmp($stored, '$2', 2) === 0) {
        return password_verify((string)$raw, $stored);
    }

    $raw_str = (string)$raw;
    $formatted = htmlspecialchars(urldecode(trim($raw_str)));
    if (hash_equals($stored, md5($raw_str))) {
        return true;
    }
    if (hash_equals($stored, md5($formatted))) {
        return true;
    }

    if (!preg_match('/^[0-9a-f]{32}$/', $stored) && ($stored === $raw_str || $stored === $formatted)) {
        return true;
    }

    return false;
}

function mac_password_needs_rehash($stored)
{
    $stored = (string)$stored;
    if ($stored === '') {
        return false;
    }
    if (strncmp($stored, '$2', 2) === 0) {
        return password_needs_rehash($stored, PASSWORD_BCRYPT);
    }
    return true;
}

function mac_pwd_column_fits_hash($table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $fits = false;
    try {
        $full = config('database.connections.mysql.prefix') . $table;
        $rows = \think\facade\Db::query("SHOW COLUMNS FROM `{$full}` WHERE Field = '" . addslashes($column) . "'");
        if (!empty($rows) && isset($rows[0]['Type'])) {
            $type = strtolower($rows[0]['Type']);
            if (preg_match('/(?:var)?char\s*\(\s*(\d+)\s*\)/', $type, $m)) {
                $fits = ((int)$m[1] >= 60);
            } elseif (strpos($type, 'text') !== false || strpos($type, 'blob') !== false) {
                $fits = true;
            }
        }
    } catch (\Exception $e) {
        $fits = false;
    }
    $cache[$key] = $fits;
    return $fits;
}

function mac_hash_password_for_column($raw, $table, $column)
{
    if (mac_pwd_column_fits_hash($table, $column)) {
        return mac_hash_password($raw);
    }
    return md5((string)$raw);
}

function mac_compress_html($s){
    $s = str_replace(array("\r\n","\n","\t"), array('','','') , $s);
    $pattern = array (
        "/> *([^ ]*) *</",
        "/[\s]+/",
        "/<!--[\\w\\W\r\\n]*?-->/",
        "/ \"/",
        "'/\*[^*]*\*/'"
    );
    $replace = array (
        ">\\1<",
        " ",
        "",
        "\"",
        ""
    );
    return preg_replace($pattern, $replace, $s);
}

function mac_build_regx($regstr,$regopt)
{
    return '/'.str_replace([
        '/',
        '$',
        '+',
        '-',
        '{',
    ],[
        '\/',
        '\$',
        '\+',
        '\-',
        '\{',
    ],$regstr).'/'.$regopt;
}

function mac_reg_replace($str,$rule,$value)
{
    $res='';
    $rule = mac_build_regx($rule,"is");
    if (!empty($str)){
        $res = preg_replace($rule,$value,$str);
    }
    return $res;
}

function mac_reg_match($str,$rule)
{
    $res='';
    $rule = mac_build_regx($rule,"is");
    preg_match_all($rule,$str,$mc);
    $mfv=$mc[1];
    foreach($mfv as $f=>$v){
        $res = trim(preg_replace("/[ \r\n\t\f]{1,}/"," ",$v));
        break;
    }
    unset($mc);
    return $res;
}

function mac_redirect($url,$obj='')
{
    echo '<script>'.$obj.'location.href="' .$url .'";</script>';
    exit;
}

function mac_alert($str)
{
    echo '<script>alert("' .$str. '\t\t");history.go(-1);</script>';
}

function mac_alert_url($str,$url)
{
    echo '<script>alert("' .$str. '\t\t");location.href="' .$url .'";</script>';
}

function mac_jump($url,$sec=0)
{
    echo '<script>setTimeout(function (){location.href="'.$url.'";},'.($sec*1000).');</script><span>'.lang('pause').''.$sec.''.lang('continue_in_second').'  >>>  </span><a href="'.$url.'" >'.lang('browser_jump').'</a><br>';
}

function mac_echo($str)
{
    echo $str.'<br>';
    ob_flush();flush();
}

function mac_day($t,$f='',$c='#FF0000')
{
    if(empty($t)) { return ''; }
    if(is_numeric($t)){
        $t = date('Y-m-d H:i:s',$t);
    }
    $now = date('Y-m-d',time());
    if($f=='color' && strpos(','.$t,$now)>0){
        return '<font color="' .$c. '">' .$t. '</font>';
    }
    return  $t;
}

function mac_friend_date($time)
{
    if (!$time)
        return false;
    $fdate = '';
    $d = time() - intval($time);
    $ld = $time - mktime(0, 0, 0, 0, 0, date('Y'));
    $md = $time - mktime(0, 0, 0, date('m'), 0, date('Y'));
    $byd = $time - mktime(0, 0, 0, date('m'), date('d') - 2, date('Y'));
    $yd = $time - mktime(0, 0, 0, date('m'), date('d') - 1, date('Y'));
    $dd = $time - mktime(0, 0, 0, date('m'), date('d'), date('Y'));
    $td = $time - mktime(0, 0, 0, date('m'), date('d') + 1, date('Y'));
    $atd = $time - mktime(0, 0, 0, date('m'), date('d') + 2, date('Y'));
    if ($d == 0) {
        $fdate = lang('just');
    } else {
        switch ($d) {
            case $d < $atd:
                $fdate = date('Y'.lang('year').'m'.lang('month').'d'.lang('day'), $time);
                break;
            case $d < $td:
                $fdate = lang('day_after_tomorrow') . date('H:i', $time);
                break;
            case $d < 0:
                $fdate = lang('tomorrow') . date('H:i', $time);
                break;
            case $d < 60:
                $fdate = $d . lang('seconds_ago');
                break;
            case $d < 3600:
                $fdate = floor($d / 60) . lang('minutes_ago');
                break;
            case $d < $dd:
                $fdate = floor($d / 3600) . lang('hours_ago');
                break;
            case $d < $yd:
                $fdate = lang('yesterday') . date('H:i', $time);
                break;
            case $d < $byd:
                $fdate = lang('day_before_yesterday') . date('H:i', $time);
                break;
            case $d < $md:
                $fdate = date('m'.lang('month').'d'.lang('day').' H:i', $time);
                break;
            case $d < $ld:
                $fdate = date('m'.lang('month').'d'.lang('day'), $time);
                break;
            default:
                $fdate = date('Y'.lang('year').'m'.lang('month').'d'.lang('day'), $time);
                break;
        }
    }
    return $fdate;
}

function mac_get_time_span($sn)
{
    $lastTime = session($sn);

    if(empty($lastTime)){
        $lastTime= "1228348800";
    }
    $res = time() - intval($lastTime);
    session($sn,time());
    return $res;
}

function mac_get_rndstr($length=32,$f='')
{
    $pattern = "234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    if($f=='num'){
        $pattern = '1234567890';
    }
    elseif($f=='letter'){
        $pattern = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    }
    $len = strlen($pattern) -1;
    $res='';
    for($i=0; $i<$length; $i++){
        $res .= $pattern[mt_rand(0,$len)];
    }
    if (str_starts_with($res, '0')) {
        $res = mt_rand(1, 9) . substr($res, 1);
    }
    return $res;
}

function mac_convert_encoding($str,$nfate,$ofate){
    if ($ofate=="UTF-8"){ return $str; }
    if ($ofate=="GB2312"){ $ofate="GBK"; }

    if(function_exists("mb_convert_encoding")){
        $str=mb_convert_encoding($str,$nfate,$ofate);
    }
    else{
        $ofate.="//IGNORE";
        $str=iconv($nfate ,$ofate ,$str);
    }
    return $str;
}

function mac_get_refer()
{
    return isset($_SERVER["HTTP_REFERER"]) ? trim(urldecode($_SERVER["HTTP_REFERER"])) : '';
}

function mac_extends_list($flag)
{
    $path = APP_PATH . 'common/extend/' . $flag;
    $file_list = glob($path . '/*.php',GLOB_NOSORT );
    $res=[];
    $res['ext_list'] = [];
    $res['ext_html'] = '';
    foreach($file_list as $k=>$v) {
        $cl = str_replace([$path . '/', '.php'], '', $v);
        $cp = 'app\\common\\extend\\'.$flag.'\\' . $cl;
        if (class_exists($cp)) {
            $c = new $cp;
            $tk = 'admin/extend/' . $flag . '_' . strtolower($cl);
            $tv = lang($tk);
            $res['ext_list'][$cl] = $tv === $tk ? $c->name : $tv;
            if (defined('ENTRANCE') && ENTRANCE === 'admin' && file_exists(APP_PATH . 'admin/view/extend/'.$flag.'/'.strtolower($cl) .'.html')) {
                $res['ext_html'] .= View::fetch('admin@extend/'.$flag.'/' . strtolower($cl));
            }
        }
    }
    return $res;
}

function mac_send_sms($to,$code,$type_flag,$type_des,$msg)
{
    if(empty($GLOBALS['config']['sms']['type'])){
        return ['code'=>9005,'msg'=> lang('sms_not_config')];
    }
    $pattern = "/^1[345789][0-9]{9}$/";
    if(!preg_match($pattern,$to)){
        return ['code'=>999,'msg'=>lang('phone_format_err')];
    }
    if(empty($code)){
        return ['code'=>998,'msg'=>lang('title_not_empty')];
    }
    if(empty($type_flag)){
        return ['code'=>997,'msg'=>lang('tpl_not')];
    }

    $cp = 'app\\common\\extend\\sms\\' . ucfirst($GLOBALS['config']['sms']['type']);
    if (class_exists($cp)) {
        $c = new $cp;
        return $c->submit($to,$code,$type_flag,$type_des,$msg);
    }
    else{
        return ['code'=>991,'msg'=>lang('sms_not')];
    }
}

function mac_send_mail($to,$title,$body,$conf=[])
{
    if(empty($GLOBALS['config']['email']['type'])){
        return ['code'=>9005,'msg'=>lang('email_not_config')];
    }
    $pattern = '/\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*/';
    if(!preg_match( $pattern, $to)){
        return ['code'=>999,'msg'=>lang('email_format_err')];
    }
    if(empty($title)){
        return ['code'=>998,'msg'=>lang('title_not_empty')];
    }
    if(empty($body)){
        return ['code'=>997,'msg'=>lang('body_not_empty')];
    }

    $cp = 'app\\common\\extend\\email\\' . ucfirst($GLOBALS['config']['email']['type']);
    if (class_exists($cp)) {
        $c = new $cp;
        return $c->submit($to,$title,$body,$conf);
    }
    else{
        return ['code'=>991,'msg'=>lang('email_not')];
    }
}

function mac_send_push($channel, $title, $content, array $context = [], array $config = [])
{
    $channel = preg_replace('/[^a-z0-9]/', '', strtolower((string)$channel));
    if ($channel === '') {
        return ['code' => 999, 'msg' => lang('admin/monitor/push_channel_invalid')];
    }
    $cp = 'app\\common\\extend\\push\\' . ucfirst($channel);
    if (!class_exists($cp)) {
        return ['code' => 991, 'msg' => lang('admin/monitor/push_driver_not_found')];
    }
    $c = new $cp;
    return $c->submit($title, $content, $context, $config);
}

function mac_domain_host($url)
{
    $host = strtolower(trim((string)$url));
    if ($host === '') {
        return '';
    }
    $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host);
    $host = ltrim($host, '/');
    $host = substr($host, 0, strcspn($host, '/?#'));
    if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        return '';
    }
    return $host;
}

function mac_check_back_link($url)
{
    $res=[];
    $res['code'] = 0;
    $res['msg'] = lang('param_err');

    if(empty($url)){
        return json($res);
    }

    $site_url = $GLOBALS['config']['site']['site_url'];
    $site_wapurl = $GLOBALS['config']['site']['site_wapurl'];
    $html = mac_curl_get($url, [], '', false);
    $msg = '';
    $code = 1;

    $ok = lang('back_link').lang('normal');
    $err = lang('back_link').lang('abnormal');

    $msg .= '['.$site_url.']';
    if(strpos($html,$site_url)!==false){
        $code=1;
        $msg .=$ok;
    }
    else{
        $code=101;
        $msg .=$err;
    }

    $msg .= '，['.$site_wapurl.']';
    if(strpos($html,$site_wapurl)!==false){
        $code =1;
        $msg .=$ok;
    }
    else{
        $code=101;
        $msg .=$err;
    }
    $res['code'] = $code;
    $res['msg'] = $msg;

    return $res;
}

function mac_list_to_tree($list, $pk='id',$pid = 'pid',$child = 'child',$root=0)
{
    $tree = array();
    if(is_array($list)) {
        $refer = array();
        foreach ($list as $key => $data) {
            $refer[$data[$pk]] =& $list[$key];
        }

        foreach ($list as $key => $data) {
            $parentId = $data[$pid];

            if ($root == $parentId) {
                $tree[] =& $list[$key];
            }else{
                if (isset($refer[$parentId])) {
                    $parent =& $refer[$parentId];
                    $parent[$child][] =& $list[$key];
                }
            }
        }
    }
    return $tree;
}

function mac_str_correct($str,$from,$to)
{
    return str_replace($from,$to,$str);
}

function mac_buildregx($regstr,$regopt)
{
    return '/'.str_replace('/','\/',$regstr).'/'.$regopt;
}

function mac_tpl_file($templateFile)
{
    if (empty($templateFile)) {
        return '';
    }
    $templateFile = str_replace('.html', '', $templateFile);
    return $GLOBALS['MAC_ROOT_TEMPLATE'] . $templateFile . '.html';
}

function mac_tpl_exists($templateFile)
{
    $path = mac_tpl_file($templateFile);
    return $path !== '' && is_file($path);
}

function mac_make_paging_size($html, $tag, $default = 0)
{
    if ($html === false || $html === '' || $html === null) {
        return $default;
    }
    $tag = preg_quote($tag, '/');
    $labelRule = '\{maccms:' . $tag . '([^}]*paging="yes"[^}]*)\}';
    $labelRule = mac_buildregx($labelRule, 'is');
    if (!preg_match_all($labelRule, $html, $arr)) {
        return $default;
    }
    foreach ($arr[1] as $attrs) {
        if (preg_match('/\bnum=["\'](\d+)["\']/', $attrs, $numMatch)) {
            return intval($numMatch[1]);
        }
    }
    return $default;
}

function mac_em_replace($s)
{
    return preg_replace("/\[em:(\d{1,})?\]/","<img src=\"". MAC_PATH ."static/images/face/$1.gif\" border=0/>",$s);
}

function mac_page_param($record_total, $page_size, $page_current, $page_url,$page_half=5)
{
    $page_param = array();
    $page_num = array();

    if ($record_total == 0) {
        return ['record_total'=>0];
    }
    if(empty($page_half)){
        $page_half=5;
    }

    $page_param['record_total'] = $record_total;
    $page_param['page_current'] = $page_current;

    $page_total = ceil($record_total / $page_size);
    $page_param['page_total'] = $page_total;
    $page_param['page_sp'] = MAC_PAGE_SP;

    $page_prev = $page_current - 1;
    if ($page_prev <= 0) {
        $page_prev = 1;
    }
    $page_next = $page_current + 1;
    if ($page_next > $page_total) {
        $page_next = $page_total;
    }
    $page_param['page_prev'] = $page_prev;
    $page_param['page_next'] = $page_next;

    if ($page_total <= $page_half) {
        for ($i = 1; $i <= $page_total; $i++) {
            $page_num[$i] = $i;
        }
    } else {
        $page_num_left = floor($page_half / 2);
        $page_num_right = $page_total - $page_half;

        if ($page_current <= $page_num_left) {
            for ($i = 1; $i <= $page_half; $i++) {
                $page_num[$i] = $i;
            }
        } elseif ($page_current > $page_num_right) {
            for ($i = ($page_num_right + 0); $i <= $page_total; $i++) {
                $page_num[$i] = $i;
            }
        } else {
            for ($i = ($page_current - $page_num_left); $i <= ($page_current + $page_num_left); $i++) {
                $page_num[$i] = $i;
            }
        }
    }
    $page_param['page_num'] = $page_num;
    $page_param['page_num_min'] = count($page_num) ? min($page_num) : 1;
    $page_param['page_num_max'] = count($page_num) ? max($page_num) : 1;
    $page_param['page_url'] = $page_url;

    return $page_param;
}

function mac_curl_post($url,$data,$heads=array(),$cookie='',$verify_ssl=true)
{
    $ch = @curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.101 Safari/537.36');
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADER,0);
    curl_setopt($ch, CURLOPT_REFERER, $url);
    if($verify_ssl){
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    }
    else{
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    if(!empty($cookie)){
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if(count($heads)>0){
        curl_setopt ($ch, CURLOPT_HTTPHEADER , $heads );
    }
    $response = @curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if($verify_ssl && $errno){
        return false;
    }
    return $response;
}
function mac_curl_get($url,$heads=array(),$cookie='',$verify_ssl=true)
{
    $ch = @curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.101 Safari/537.36');

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADER,0);
    curl_setopt($ch, CURLOPT_REFERER, $url);
    curl_setopt($ch, CURLOPT_POST, 0);
    if($verify_ssl){
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    }
    else{
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 1);
    }
    if(!empty($cookie)){
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if(count($heads)>0){
        curl_setopt ($ch, CURLOPT_HTTPHEADER , $heads );
    }
    $response = @curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if($verify_ssl && $errno){
        return false;
    }
    return $response;
}

function mac_substring($str, $lenth, $start=0)
{
    $str = mac_scalar_string($str);
    $len = strlen($str);
    $r = array();
    $n = 0;
    $m = 0;

    for($i=0;$i<$len;$i++){
        $byte = ord($str[$i]);
        if ($byte < 0x80) {
            $char = $str[$i];
        } else if (($byte & 0xE0) === 0xC0) {
            $char = substr($str, $i, 2);
            $i += 1;
        } else if (($byte & 0xF0) === 0xE0) {
            $char = substr($str, $i, 3);
            $i += 2;
        } else if (($byte & 0xF8) === 0xF0) {
            $char = substr($str, $i, 4);
            $i += 3;
        } else {
            $char = ' ';
        }

        if ($n < $start){
            $n++;
        }
        else{
            $r[] = $char;
            if (++$m >= $lenth){
                break;
            }
        }
    }
    return  join('',$r);
}

function mac_array2xml($arr,$level=1)
{
    $s = $level == 1 ? "<xml>" : '';
    foreach($arr as $tagname => $value) {
        if (is_numeric($tagname)) {
            $tagname = $value['TagName'];
            unset($value['TagName']);
        }
        if(!is_array($value)) {
            $s .= "<{$tagname}>".(!is_numeric($value) ? '<![CDATA[' : '').$value.(!is_numeric($value) ? ']]>' : '')."</{$tagname}>";
        } else {
            $s .= "<{$tagname}>" . mac_array2xml($value, $level + 1)."</{$tagname}>";
        }
    }
    $s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
    return $level == 1 ? $s."</xml>" : $s;
}

function mac_xml2array($xml)
{
    libxml_disable_entity_loader(true);
    $result= json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    return $result;
}

function mac_array_rekey($arr,$key)
{
    $list = [];
    foreach($arr as $k=>$v){
        $list[$v[$key]] = $v;
    }
    return $list;
}

function mac_array_filter($arr,$str)
{
    if(!is_array($arr)){
        $arr = explode(',',$arr);
    }
    $arr = array_filter($arr);
    if(empty($arr)){
        return false;
    }
    $new_str = str_replace($arr,'*',$str);
    return $new_str != $str;
}

function mac_parse_sql($sql='',$limit=0,$prefix=[])
{
    $from = '';
    $to = '';

    if (!empty($prefix)) {
        $to   = current($prefix);
        $from = current(array_flip($prefix));
    }

    if ($sql != '') {
        $pure_sql = [];

        $comment = false;

        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $sql = explode("\n", trim($sql));
        $cnm = base64_decode('YeeJiOadg+aJgOaciW1hZ2ljYmxhY2vvvIzmupDnoIFodHRwczovL2dpdGh1Yi5jb20vbWFnaWNibGFjaw==');
        foreach ($sql as $key => $line) {
            if ($line == '') {
                continue;
            }

            if (preg_match("/^(#|--)/", $line)) {
                continue;
            }

            if (preg_match("/^\/\*(.*?)\*\//", $line)) {
                continue;
            }

            if (substr($line, 0, 2) == '/*') {
                $comment = true;
                continue;
            }

            if (substr($line, -2) == '*/') {
                $comment = false;
                continue;
            }

            if ($comment) {
                continue;
            }

            if ($from != '') {
                $line = str_replace('`'.$from, '`'.$to, $line);
            }
            if ($line == 'BEGIN;' || $line =='COMMIT;') {
                continue;
            }
            array_push($pure_sql, $line);
        }

        if ($limit == 1) {
            return implode("",$pure_sql);
        }

        $pure_sql = implode("\n",$pure_sql);
        $pure_sql = explode(";\n", $pure_sql);
        return $pure_sql;
    } else {
        return $limit == 1 ? '' : [];
    }
}

function mac_interface_type()
{
    $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'interface_type';
    $data = \think\facade\Cache::get($key);
    if(empty($data)){
        $config = config('maccms.interface');
        $vodtype = str_replace([chr(10),chr(13)],['','#'],$config['vodtype']);
        $arttype = str_replace([chr(10),chr(13)],['','#'],$config['arttype']);
        $actortype = str_replace([chr(10),chr(13)],['','#'],$config['actortype']);
        $websitetype = str_replace([chr(10),chr(13)],['','#'],$config['websitetype']);
        $mangatype = str_replace([chr(10),chr(13)],['','#'],isset($config['mangatype']) ? $config['mangatype'] : '');

        $data =[];
        $type_arr = explode('#',$vodtype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['vodtype'][$to] = $from;
        }

        $type_arr = explode('#',$arttype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['arttype'][$to] = $from;
        }

        $type_arr = explode('#',$actortype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['actortype'][$to] = $from;
        }

        $type_arr = explode('#',$websitetype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['websitetype'][$to] = $from;
        }

        if(!empty($mangatype)){
            $type_arr = explode('#',$mangatype);
            foreach($type_arr as $k=>$v){
                if(strpos($v,'=')!==false){
                    list($from, $to) = explode('=', $v);
                    $data['mangatype'][$to] = $from;
                }
            }
        }
        if(empty($data['mangatype'])){
            $data['mangatype'] = [];
        }

        \think\facade\Cache::set($key,$data);
    }

    $type_list = (new \app\common\model\Type())->getCache('type_list');
    $type_names = [];
    foreach($type_list as $k=>$v){
        $type_names[$v['type_name']] = $v['type_id'];
    }

    foreach($data['vodtype'] as $k=>$v){
        $data['vodtype'][$k] = (int)$type_names[$v];
    }
    foreach($data['arttype'] as $k=>$v){
        $data['arttype'][$k] = (int)$type_names[$v];
    }
    foreach($data['actortype'] as $k=>$v){
        $data['actortype'][$k] = (int)$type_names[$v];
    }
    foreach($data['websitetype'] as $k=>$v){
        $data['websitetype'][$k] = (int)$type_names[$v];
    }
    if(!empty($data['mangatype'])){
        foreach($data['mangatype'] as $k=>$v){
            $data['mangatype'][$k] = (int)$type_names[$v];
        }
    }
    return $data;
}

function mac_rep_pse_rnd($psearr,$txt,$id=0)
{
    if(empty($psearr)){
        return $txt;
    }
    $i=count($psearr);
    if(empty($txt)){
        if(empty($id)){
            $r = mt_rand(0,$i-1);
        }
        else{
            $r = $id % $i;
        }
        $res= $psearr[$r];
    }
    else{
        if(empty($id)){
            $id = crc32($txt);
        }
        $j=mb_strpos($txt,"<br>");
        $k=mb_strlen($txt);
        if($j==0){ $j=mb_strpos($txt,"<br/>"); }
        if($j==0){ $j=mb_strpos($txt,"<br />"); }
        if($j==0){ $j=mb_strpos($txt,"</p>"); }
        if($j==0){ $j=mb_strpos($txt,"。"); }
        if($j==0){ $j=mb_strpos($txt,"！"); }
        if($j==0){ $j=mb_strpos($txt,"!"); }
        if($j==0){ $j=mb_strpos($txt,"？"); }
        if($j==0){ $j=mb_strpos($txt,"?"); }
        if($j>0){
            $res= mac_substring($txt,$j-1) . $psearr[$id % $i] . mac_substring($txt,$k-$j,$j);
        }
        else{
            $res= $psearr[$id % $i]. $txt;
        }
    }
    return $res;
}

function mac_txt_explain($txt, $decode = false)
{
    $placeholder = '___HTML_ENTITY_HASH___';
    $txt = preg_replace('/&#(\d+);/', $placeholder . '$1;', $txt);
    $txt = preg_replace('/&#x([0-9a-fA-F]+);/', $placeholder . 'x$1;', $txt);
    $txtarr = explode('#', $txt);
    foreach($txtarr as &$item) {
        $item = str_replace($placeholder, '&#', $item);
    }
    unset($item);
    $data=[];
    foreach($txtarr as $v){
        if (stripos($v, '=') === false) {
            continue;
        }
        list($from, $to) = explode('=', $v, 2);
        if ($decode === true && stripos($from, '&') !== false && stripos($from, ';') !== false) {
            $from = html_entity_decode($from, ENT_QUOTES, 'UTF-8');
        }
        if ($decode === true && stripos($to, '&') !== false && stripos($to, ';') !== false) {
            $to = html_entity_decode($to, ENT_QUOTES, 'UTF-8');
        }
        $data['from'][] = $from;
        $data['to'][] = $to;
    }
    return $data;
}

function mac_rep_pse_syn($psearr,$txt)
{
    if(empty($txt)){ $txt=""; }
    if(is_array($psearr['from']) && is_array($psearr['to'])){
        $txt = str_replace($psearr['from'],$psearr['to'],$txt);
    }
    return $txt;
}

function mac_get_tag($title,$content){
    $url = base64_decode('aHR0cDovL2FwaS5kcGxheWVyc3RhdGljLmNvbQ==').'/keyword/index?name='.rawurlencode($title).'&txt='.rawurlencode($title).rawurlencode(mac_substring(strip_tags($content),200));
    $data = mac_curl_get($url);
    $json = @json_decode($data,true);
    if($json){
        if($json['code']==1){
            return implode(',',$json['data']);
        }
    }
    return false;
}

function mac_get_client_ip()
{
    static $final;
    if (!is_null($final)) {
        return $final;
    }
    $final = '0.0.0.0';

    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

    $trustedProxies = [];
    $tpCfg = config('maccms.trusted_proxies');
    if (is_string($tpCfg)) {
        $tpCfg = trim($tpCfg);
        if ($tpCfg !== '') {
            foreach (explode(',', $tpCfg) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $trustedProxies[] = $p;
                }
            }
        }
    } elseif (is_array($tpCfg)) {
        foreach ($tpCfg as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $trustedProxies[] = $p;
            }
        }
    }

    $isTrustedProxy = false;
    if ($remoteAddr !== '' && !empty($trustedProxies)) {
        foreach ($trustedProxies as $proxy) {
            if (mac_ip_in_cidr($remoteAddr, $proxy)) {
                $isTrustedProxy = true;
                break;
            }
        }
    }

    if ($isTrustedProxy) {
        $headerCandidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_ALI_CDN_REAL_IP',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_PROXY_USER',
        ];
        foreach ($headerCandidates as $key) {
            $val = isset($_SERVER[$key]) ? trim((string)$_SERVER[$key]) : '';
            if ($key === 'HTTP_X_REAL_IP' && $val === '') {
                $val = trim((string)getenv('HTTP_X_REAL_IP'));
            }
            if ($val === '') {
                continue;
            }
            if (filter_var($val, FILTER_VALIDATE_IP) !== false) {
                $final = $val;
                return $final;
            }
            if (strpos($val, ',') !== false) {
                foreach (explode(',', $val) as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        $final = $candidate;
                        return $final;
                    }
                }
            }
        }
    }

    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
        $final = $remoteAddr;
    }

    return $final;
}

function mac_ip_in_cidr($ip, $range)
{
    $ip = trim((string)$ip);
    $range = trim((string)$range);
    if ($ip === '' || $range === '') {
        return false;
    }
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }

    $parts = explode('/', $range, 2);
    $subnet = trim($parts[0]);
    $bits = isset($parts[1]) ? (int)trim($parts[1]) : -1;

    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }

    $bytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
        return false;
    }
    if ($remainder > 0) {
        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        if ((ord($ipBin[$bytes]) & ord($mask)) !== (ord($subnetBin[$bytes]) & ord($mask))) {
            return false;
        }
    }
    return true;
}

function mac_get_ip_long($ip_addr = '')
{
    $ip_addr = !empty($ip_addr) ? $ip_addr : mac_get_client_ip();
    $ip_long = sprintf('%u',ip2long($ip_addr));
    if ($ip_long < 0 || $ip_long >= 0xFFFFFFFF) {
        $ip_long = 0;
    }
    return $ip_long;
}

function mac_get_uniqid_code($code_prefix='')
{
    $code_prefix = strtoupper($code_prefix);
    $now_date = date('YmdHis');
    $now_time = rand(100000, 999999);
    return $code_prefix . $now_date . $now_time;
}

function mac_escape($string, $in_encoding = 'UTF-8',$out_encoding = 'UCS-2') {
    $return = '';
    if (function_exists('mb_get_info')) {
        for($x = 0; $x < mb_strlen ( $string, $in_encoding ); $x ++) {
            $str = mb_substr ( $string, $x, 1, $in_encoding );
            if (strlen ( $str ) > 1) {
                $return .= '%u' . strtoupper ( bin2hex ( mb_convert_encoding ( $str, $out_encoding, $in_encoding ) ) );
            } else {
                $return .= '%' . strtoupper ( bin2hex ( $str ) );
            }
        }
    }
    return $return;
}
function mac_unescape($str)
{
    $ret = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i ++)
    {
        if ($str[$i] == '%' && $str[$i + 1] == 'u')
        {
            $val = hexdec(substr($str, $i + 2, 4));
            if ($val < 0x7f)
                $ret .= chr($val);
            else
                if ($val < 0x800)
                    $ret .= chr(0xc0 | ($val >> 6)) .
                        chr(0x80 | ($val & 0x3f));
                else
                    $ret .= chr(0xe0 | ($val >> 12)) .
                        chr(0x80 | (($val >> 6) & 0x3f)) .
                        chr(0x80 | ($val & 0x3f));
            $i += 5;
        } else
            if ($str[$i] == '%')
            {
                $ret .= urldecode(substr($str, $i, 3));
                $i += 2;
            } else
                $ret .= $str[$i];
    }
    return $ret;
}

function mac_get_mid_code($data)
{
    $arr = [
        1  => 'vod',
        2  => 'art',
        3  => 'topic',
        4  => 'comment',
        5  => 'gbook',
        6  => 'user',
        7  => 'label',
        8  => 'actor',
        9  => 'role',
        10 => 'plot',
        11 => 'website',
        12 => 'manga',
    ];
    return $arr[$data];
}
function mac_get_mid_text($data)
{
    $arr = [
        1  => lang('vod'),
        2  => lang('art'),
        3  => lang('topic'),
        4  => lang('comment'),
        5  => lang('gbook'),
        6  => lang('user'),
        7  => lang('label'),
        8  => lang('actor'),
        9  => lang('role'),
        10 => lang('plot'),
        11 => lang('website'),
        12 => lang('manga'),
    ];
    return $arr[$data];
}
function mac_get_mid($controller)
{
    $controller=strtolower($controller);
    $arr = [
        'vod'     => 1,
        'art'     => 2,
        'topic'   => 3,
        'comment' => 4,
        'gbook'   => 5,
        'user'    => 6,
        'label'   => 7,
        'actor'   => 8,
        'role'    => 9,
        'plot'    => 10,
        'website' => 11,
        'manga'   => 12,
    ];
    return isset($arr[$controller]) ? $arr[$controller] : 0;
}

function mac_user_has_digg($mid, $id)
{
    $mid = (int) $mid;
    $id = (int) $id;
    if ($id < 1 || $mid < 1) {
        return 0;
    }
    $pre = mac_get_mid_code($mid);
    if ($pre === null || $pre === '') {
        return 0;
    }
    $cookie = $pre . '-digg-' . $id;

    return !empty(cookie($cookie)) ? 1 : 0;
}

function mac_user_fav_state($userId, $ulogMid, $rid)
{
    $userId = (int) $userId;
    $ulogMid = (int) $ulogMid;
    $rid = (int) $rid;
    if ($userId < 1 || $rid < 1 || $ulogMid < 1) {
        return ['is_fav' => 0, 'fav_ulog_id' => 0];
    }
    $row = (new \app\common\model\Ulog())->where([
        'user_id'   => $userId,
        'ulog_mid'  => $ulogMid,
        'ulog_type' => 2,
        'ulog_rid'  => $rid,
    ])->field('ulog_id')->find();
    if (!empty($row['ulog_id'])) {
        return ['is_fav' => 1, 'fav_ulog_id' => (int) $row['ulog_id']];
    }

    return ['is_fav' => 0, 'fav_ulog_id' => 0];
}

function mac_get_aid($controller,$action='')
{
    $controller=strtolower($controller);
    $action=strtolower($action);
    $key = $controller.'/'.$action;

    $arr=['index'=>1,'map'=>2,'rss'=>3,'gbook'=>4,'comment'=>5,'user'=>6,'label'=>7,'vod'=>10,'art'=>20,'manga'=>120,'topic'=>30,'actor'=>80,'role'=>90,'plot'=>100,'website'=>110,'live'=>130];
    $res = isset($arr[$controller]) ? $arr[$controller] : 0;

    $arr=[
        'vod/type'=>11,'vod/show'=>12,'vod/search'=>13,'vod/search_hub'=>13,'vod/detail'=>14,'vod/play'=>15,'vod/down'=>16,'vod/role'=>17,'vod/plot'=>18,
        'art/type'=>21,'art/show'=>22,'art/search'=>23,'art/detail'=>24,'art/read'=>25,
        'manga/type'=>121,'manga/show'=>122,'manga/search'=>123,'manga/detail'=>124,'manga/play'=>125,
        'topic/search'=>33,'topic/detail'=>34,
        'actor/type'=>81,'actor/show'=>82,'actor/search'=>83,'actor/detail'=>84,
        'role/show'=>92,'role/search'=>93,'role/detail'=>94,
        'plot/search'=>103,'plot/detail'=>104,
        'website/type'=>111,'website/show'=>112,'website/search'=>113,'website/detail'=>114,
        'live/show'=>131,'live/play'=>132,
    ];
    if(!empty($arr[$key])){
        $res= $arr[$key];
    }
    return $res;
}

function mac_get_user_status_text($data)
{
    $arr = [
        0 => lang('disable'),
        1 => lang('enable'),
    ];
    return $arr[$data];
}
function mac_get_user_flag_text($data)
{
    $arr = [
        0 => lang('counting_points'),
        1 => lang('counting_times'),
        2 => lang('counting_ips'),
    ];
    return $arr[$data];
}

function mac_get_ulog_type_text($data)
{
    $arr = [
        1 => lang('browse'),
        2 => lang('collect'),
        3 => lang('want_see'),
        4 => lang('play'),
        5 => lang('down'),
    ];
    return $arr[$data];
}

function mac_get_plog_type_text($data)
{
    $arr = [
        1 => lang('integral_recharge'),
        2 => lang('registration_promotion'),
        3 => lang('visit_promotion'),
        4 => lang('one_level_distribution'),
        5 => lang('two_level_distribution'),
        6 => lang('three_level_distribution'),
        7 => lang('points_upgrade'),
        8 => lang('integral_consumption'),
        9 => lang('integral_withdrawal'),
        10 => lang('plog_sign_milestone_reward'),
        11 => lang('plog_task_sign_reward'),
        12 => lang('plog_mall_exchange'),
        13 => lang('plog_seckill'),
    ];
    return isset($arr[$data]) ? $arr[$data] : '';
}

function mac_get_card_sale_status_text($data)
{
    $arr = [
        0 => lang('not_sale'),
        1 => lang('sold'),
    ];
    return $arr[$data];
}

function mac_get_card_use_status_text($data)
{
    $arr = [
        0 => lang('not_used'),
        1 => lang('used'),
    ];
    return $arr[$data];
}

function mac_get_mall_goods_type_text($data)
{
    $arr = [
        'vip' => lang('mall/type_vip'),
        'card' => lang('mall/type_card'),
        'download_quota' => lang('mall/type_download_quota'),
    ];
    return isset($arr[$data]) ? $arr[$data] : '';
}

function mac_get_order_status_text($data)
{
    $arr = [
        0 => lang('not_paid'),
        1 => lang('paid'),
    ];
    return $arr[$data];
}

function mac_get_user_portrait($user_id = null)
{
    $res = MAC_PATH . 'static/images/touxiang.png';
    if ($user_id === null && !empty($GLOBALS['user']['user_id'])) {
        $user_id = (int)$GLOBALS['user']['user_id'];
    }
    if(!empty($user_id)){
        $res2 = 'upload/user/'.($user_id % 10 ). '/'.$user_id.'.jpg';
        if(file_exists(ROOT_PATH . $res2)){
            $res = MAC_PATH . $res2;
        }
    }
    return $res;
}

function mac_scalar_string($val, $default = '')
{
    if (is_string($val)) {
        return $val;
    }
    if (is_array($val) || is_object($val)) {
        return $default;
    }
    if ($val === null || $val === false) {
        return $default;
    }
    return (string)$val;
}

function mac_filter_html($str)
{
    $str = mac_scalar_string($str);
    return strip_tags($str);
}

function mac_filter_xss($str)
{
    $str = mac_scalar_string($str);
    $trimmed_str = trim($str);
    if (!empty($trimmed_str)) {
        $is_url = false;

        if (preg_match('/^(https?:\/\/|ftp:\/\/|\/\/|mac:)/i', $trimmed_str)) {
            $is_url = true;
        }
        elseif (strpos($trimmed_str, '://') !== false) {
            $is_url = true;
        }
        elseif (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}(\/|\?|&|=|$)/', $trimmed_str)) {
            $is_url = true;
        }

        if ($is_url) {
            return trim(strip_tags($trimmed_str));
        }
    }

    return trim(htmlspecialchars(strip_tags($trimmed_str), ENT_QUOTES));
}

function mac_restore_htmlfilter($str) {
    if (stripos($str, '&amp;') !== false) {
        return htmlspecialchars_decode($str, ENT_QUOTES);
    }
    return $str;
}

function mac_format_text($str, $allow_space = false)
{
    $finder = array('/', '，', '|', '、', ',,', ',,,');
    if ($allow_space === false) {
        $finder[] = ' ';
    }
    return str_replace($finder, ',', $str);
}
function mac_format_count($str)
{
    $arr = explode(',',$str);
    return count($arr);
}

function mac_txt_merge($txt,$str)
{
    if(empty($str)){
        return $txt;
    }
    if($GLOBALS['config']['collect']['vod']['class_filter'] !='0') {
        if (mb_strlen($str) > 2) {
            $str = str_replace([lang('slice')], [''], $str);
        }
        if (mb_strlen($str) > 2) {
            $str = str_replace([lang('drama')], [''], $str);
        }
    }
    $txt = mac_format_text($txt);
    $str = mac_format_text($str);
    $arr1 = explode(',',$txt);
    $arr2 = explode(',',$str);
    $arr = array_merge($arr1,$arr2);
    return join(',',array_unique( array_filter($arr)));
}

function mac_array_check_num($arr)
{
    if(!is_array($arr)){
        return false;
    }
    $res = true;
    foreach($arr as $a){
        if(!is_numeric($a)){
            $res=false;
            break;
        }
    }
    return $res;
}

function mac_like_arr($s)
{
    $tmp = explode(',',$s);
    $like_arr = [];
    foreach($tmp as $v){
        $like_arr[] = '%'.$v.'%';
    }
    return $like_arr;
}

function mac_search_wd_like($wd)
{
    $wd = trim((string)$wd);
    if ($wd === '') {
        return [];
    }
    $patterns = \app\common\util\OpenccConverter::likePatterns($wd);
    if (count($patterns) > 4) {
        $patterns = array_slice($patterns, 0, 4);
    }
    if (empty($patterns)) {
        return ['like', '%' . $wd . '%'];
    }
    if (count($patterns) === 1) {
        return ['like', $patterns[0]];
    }

    return ['like', $patterns, 'OR'];
}

function mac_art_list($art_title,$art_note,$art_content)
{
    $art_title_list = [];
    $art_note_list = [];
    $art_content_list = [];
    if(!empty($art_title)) {
        $art_title_list = explode('$$$', $art_title);
    }
    if(!empty($art_note)) {
        $art_note_list = explode('$$$', $art_note);
    }
    if(!empty($art_content)) {
        $art_content_list = explode('$$$', $art_content);
    }
    $res_list = [];
    foreach($art_content_list as $k=>$v){
        $res_list[$k+1] = [
            'page'=> $k+1,
            'title'=>$art_title_list[$k],
            'note'=>$art_note_list[$k],
            'content'=>$v,
        ];
    }
    return $res_list;
}

function mac_plot_list($vod_plot_name,$vod_plot_detail)
{
    $vod_plot_name_list = [];
    $vod_plot_detail_list = [];

    if(!empty($vod_plot_name)) {
        $vod_plot_name_list = explode('$$$', $vod_plot_name);
    }
    if(!empty($vod_plot_detail)) {
        $vod_plot_detail_list = explode('$$$', $vod_plot_detail);
    }

    $res_list = [];
    foreach($vod_plot_name_list as $k=>$v){
        $res_list[$k + 1] = [
                'name' => $vod_plot_name_list[$k],
                'detail' => $vod_plot_detail_list[$k],
        ];
    }
    return $res_list;
}

function mac_play_list($vod_play_from,$vod_play_url,$vod_play_server,$vod_play_note,$flag='play')
{
    $vod_play_from_list = [];
    $vod_play_url_list = [];
    $vod_play_server_list = [];
    $vod_play_note_list = [];

    if(!empty($vod_play_from)) {
        $vod_play_from_list = explode('$$$', $vod_play_from);
    }
    if(!empty($vod_play_url)) {
        $vod_play_url_list = explode('$$$', $vod_play_url);
    }
    if(!empty($vod_play_server)) {
        $vod_play_server_list = explode('$$$', $vod_play_server);
    }
    if(!empty($vod_play_note)) {
        $vod_play_note_list = explode('$$$', $vod_play_note);
    }

    if($flag=='play'){
        $player_list = config('vodplayer');
    }
    else{
        $player_list = config('voddowner');
    }
    $server_list = config('vodserver');

    $res_list = [];
    $sort=[];
    foreach($vod_play_from_list as $k=>$v){
        $server = (string)$vod_play_server_list[$k];
        $urls = mac_play_list_one($vod_play_url_list[$k],$v);

        $player_info = $player_list[$v] ?? [];
        $server_info = $server_list[$server] ?? [];
        if(($player_info['status'] ?? '') == '1') {
            $sort[] = $player_info['sort'];
            $res_list[$k + 1] = [
                'sid' => $k + 1,
                'player_info' => $player_info,
                'server_info' => $server_info,
                'from' => $v,
                'url' => $vod_play_url_list[$k],
                'server' => $server,
                'note' => $vod_play_note_list[$k],
                'url_count' => count($urls),
                'urls' => $urls,
            ];
        }
    }

    if( (ENTRANCE!='admin' && MAC_PLAYER_SORT=='1') ||  $GLOBALS['ismake']=='1' ){
        array_multisort($sort, SORT_DESC, SORT_FLAG_CASE , $res_list);
        $tmp=[];
        foreach($res_list as $k=>$v){
            $tmp[$v['sid']] = $v;
        }
        $res_list = $tmp;
    }
    return $res_list;
}

function new_stripslashes($string) {
    if(!is_array($string)) return stripslashes($string);
    foreach($string as $key => $val) $string[$key] = new_stripslashes($val);
    return $string;
}

function mac_screenshot_list($screenshot)
{
    $url_list = array();
    $array_url = explode('#',$screenshot);
    foreach($array_url as $key=>$val){
        if(empty($val)) continue;

        list($title, $url) = array_pad(explode('$', $val), 2, '');
        if ( empty($url) ) {
            $url_list[$key+1]['name'] = ($key+1);
            $url_list[$key+1]['url'] = $title;
        }else{
            $url_list[$key+1]['name'] = $title;
            $url_list[$key+1]['url'] = $url;
        }
    }
    return $url_list;
}

function mac_play_list_one($url_one, $from_one, $server_one=''){
    $url_list = array();
    $array_url = explode('#',$url_one);
    foreach($array_url as $key=>$val){
        if(empty($val)) continue;

        list($title, $url, $from) = array_pad(explode('$', $val), 3, '');
        if ( empty($url) ) {
            $url_list[$key+1]['name'] = lang('the').($key+1).lang('episode');
            $url_list[$key+1]['url'] = $server_one.$title;
        }else{
            $url_list[$key+1]['name'] = $title;
            $url_list[$key+1]['url'] = $server_one.$url;
        }
        if(empty($from)){
            $from = $from_one;
        }
        $url_list[$key+1]['from'] = (string)$from;
        $url_list[$key+1]['nid'] = $key+1;
    }
    return $url_list;
}

function mac_manga_list($manga_play_from,$manga_play_url,$manga_play_server,$manga_play_note)
{
    $manga_play_from_list = [];
    $manga_play_url_list = [];
    $manga_play_server_list = [];
    $manga_play_note_list = [];

    if(!empty($manga_play_from)) {
        $manga_play_from_list = explode('$$$', $manga_play_from);
    }
    if(!empty($manga_play_url)) {
        $manga_play_url_list = explode('$$$', $manga_play_url);
    }
    if(!empty($manga_play_server)) {
        $manga_play_server_list = explode('$$$', $manga_play_server);
    }
    if(!empty($manga_play_note)) {
        $manga_play_note_list = explode('$$$', $manga_play_note);
    }

    $res_list = [];
    foreach($manga_play_from_list as $k=>$v){
        $server = (string)$manga_play_server_list[$k];
        $urls = mac_play_list_one($manga_play_url_list[$k],$v);

        $res_list[$k + 1] = [
            'sid' => $k + 1,
            'from' => $v,
            'url' => $manga_play_url_list[$k],
            'server' => $server,
            'note' => $manga_play_note_list[$k],
            'url_count' => count($urls),
            'urls' => $urls,
        ];
    }
    return $res_list;
}

function mac_filter_words($p)
{
    $config = config('maccms.app');
    $arr = explode(",",$config['filter_words']);
    if(is_array($p)){
        foreach($p as $k=>$v){
            $p[$k] = str_replace($arr,"***",$v);
        }
    }
    else{
        $p = str_replace($arr,"***",$p);
    }
    return $p;
}

function mac_long2ip($ip){
    $ip = long2ip($ip);
    $reg2 = '~(\d+)\.(\d+)\.(\d+)\.(\d+)~';
    return preg_replace($reg2, "$1.$2.*.*", $ip);
}
function mac_default($s,$def='')
{
    if(empty($s)){
        return $def;
    }
    return $s;
}
function mac_num_fill($num)
{
    if($num<10){
        $num = '0' . $num;
    }
    return $num;
}

function mac_multisort($arr,$col_sort,$sort_order,$col_status='',$status_val='')
{
    $sort=[];
    foreach($arr as $k=>$v){
        if($col_status!='' && $v[$col_status] != $status_val){
            unset($arr[$k]);
       } else {
            $sort[] = isset($v[$col_sort]) ? $v[$col_sort] : 0;
       }
    }
    array_multisort($sort, $sort_order, SORT_FLAG_CASE, $arr);
    return $arr;
}

function mac_get_body($text,$start,$end)
{
    if(empty($text)){ return false; }
    if(empty($start)){ return false; }
    if(empty($end)){ return false; }

    $start=stripslashes($start);
    $end=stripslashes($end);

    if(strpos($text,$start)!=""){
        $str = substr($text,strpos($text,$start)+strlen($start));
        $str = substr($str,0,strpos($str,$end));
    }
    else{
        $str='';
    }
    return $str;
}

function mac_find_array($text,$start,$end)
{
    $start=stripslashes($start);
    $end=stripslashes($end);
    if(empty($text)){ return false; }
    if(empty($start)){ return false; }
    if(empty($end)){ return false; }

    $start = str_replace(["(",")","'","?"],["\(","\)","\'","\?"],$start);
    $end = str_replace(["(",")","'","?"],["\(","\)","\'","\?"],$end);

    $labelRule = $start."(.*?)".$end;
    $labelRule = mac_buildregx($labelRule,"is");
    preg_match_all($labelRule,$text,$tmparr);
    $tmparrlen=count($tmparr[1]);
    $rc=false;
    $str='';
    $arr=[];
    for($i=0;$i<$tmparrlen;$i++) {
        if($rc){ $str .= "{array}"; }
        $str .= $tmparr[1][$i];
        $rc=true;
    }

    if(empty($str)) { return false ;}
    $str=str_replace($start,"",$str);
    $str=str_replace($end,"",$str);
    if(empty($str)) { return false ;}
    return $str;
}

function mac_param_url(){
    $input = input() ;
    $param = [];
    $tmp = $_REQUEST;
    
    $input = array_merge($input,$tmp);
    $param['page'] = intval($input['page']) <1 ? 1 : intval($input['page']);
    $param['ajax'] = intval($input['ajax']);
    $param['tid'] = intval($input['tid']);
    $param['mid'] = intval($input['mid']);
    $param['rid'] = intval($input['rid']);
    $param['pid'] = intval($input['pid']);
    $param['sid'] = intval($input['sid']);
    $param['nid'] = intval($input['nid']);
    $param['uid'] = intval($input['uid']);
    $param['level'] = intval($input['level']);
    $param['score'] = intval($input['score']);
    $param['limit'] = intval($input['limit']);

    $param['id'] = htmlspecialchars(urldecode(trim($input['id'])));
    $param['ids'] = htmlspecialchars(urldecode(trim($input['ids'])));
    $param['wd'] = htmlspecialchars(urldecode(trim($input['wd'])));
    $param['en'] = htmlspecialchars(urldecode(trim($input['en'])));
    $param['state'] = htmlspecialchars(urldecode(trim($input['state'])));
    $param['area'] = htmlspecialchars(urldecode(trim($input['area'])));
    $param['year'] = htmlspecialchars(urldecode(trim($input['year'])));
    $param['lang'] = htmlspecialchars(urldecode(trim($input['lang'])));
    if ($param['lang'] !== '' && in_array(strtolower($param['lang']), mac_content_lang_allow_list(), true)) {
        $param['lang'] = '';
    }
    $param['letter'] = htmlspecialchars(trim($input['letter']));
    $param['actor'] = htmlspecialchars(urldecode(trim($input['actor'])));
    $param['director'] = htmlspecialchars(urldecode(trim($input['director'])));
    $param['tag'] = htmlspecialchars(urldecode(trim($input['tag'])));
    $param['class'] = htmlspecialchars(urldecode(trim($input['class'])));
    $param['order'] = htmlspecialchars(urldecode(trim($input['order'])));
    $param['by'] = htmlspecialchars(urldecode(trim($input['by'])));
    $param['file'] = htmlspecialchars(urldecode(trim($input['file'])));
    $param['name'] = htmlspecialchars(urldecode(trim($input['name'])));
    $param['url'] = htmlspecialchars(urldecode(trim($input['url'])));
    $param['type'] = htmlspecialchars(urldecode(trim($input['type'])));
    $param['sex'] = htmlspecialchars(urldecode(trim($input['sex'])));
    $param['version'] = htmlspecialchars(urldecode(trim($input['version'])));
    $param['blood'] = htmlspecialchars(urldecode(trim($input['blood'])));
    $param['starsign'] = htmlspecialchars(urldecode(trim($input['starsign'])));
    $param['domain'] = htmlspecialchars(urldecode(trim($input['domain'])));

    return $param;
}

function mac_get_page($page)
{
    if(empty($page)) {
        $param = mac_param_url();
        $page = $param['page'];
    }
    return $page;
}

function mac_tpl_fetch($model,$tpl,$def='')
{
    return $model . '/' . ( empty($tpl) ? $def  : str_replace('.html','',$tpl) );
}

function mac_get_order($order,$param)
{
    if(!empty($param['order'])) {
        $order = $param['order'];
    }
    if(!in_array($order, ['asc', 'desc'])) {
        $order = 'desc';
    }
    return $order;
}

function mac_url_img($url)
{
    $url = mac_scalar_string($url);
    if ($url === '') {
        return '';
    }
    if(substr($url,0,4) == 'mac:'){
        $protocol = $GLOBALS['config']['upload']['protocol'];
        if(empty($protocol)){
            $protocol = 'http';
        }
        $url = str_replace('mac:', $protocol.':',$url);
    }
    elseif(substr($url,0,4) != 'http' && substr($url,0,2) != '//' && substr($url,0,1) != '/'){
        if($GLOBALS['config']['upload']['mode']=='remote'){
            $url = $GLOBALS['config']['upload']['remoteurl'] . $url;
        }
        else{
            $url = MAC_PATH . $url;
        }
    }
    elseif(!empty($GLOBALS['config']['upload']['img_key']) && preg_match('/'.$GLOBALS['config']['upload']['img_key'].'/',$url)){
        $url = $GLOBALS['config']['upload']['img_api'] . '' . $url;
    }
    $url = mac_filter_xss($url);
    $url = str_replace('&quot;&gt;', '', $url);
    $url = str_replace('&amp;', '&', $url);
    return $url;
}

function mac_theme_design()
{
    static $cache = [];

    $preview = 0;
    if (function_exists('request')) {
        $req = request();
        if (is_object($req)) {
            $preview = !empty($req->param('td_preview')) ? 1 : 0;
        }
    }

    $key = $preview ? 'draft' : 'pub';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $data = [];
    $tpl = isset($GLOBALS['config']['site']['template_dir']) ? $GLOBALS['config']['site']['template_dir'] : '';
    if ($tpl !== '' && defined('ROOT_PATH')) {
        $file = ROOT_PATH . 'template/' . $tpl . '/theme-design' . ($preview ? '.draft' : '') . '.json';
        if (is_file($file)) {
            $json = @file_get_contents($file);
            if ($json !== false && $json !== '') {
                $arr = json_decode($json, true);
                if (is_array($arr)) {
                    $data = $arr;
                }
            }
        }
    }

    $cache[$key] = $data;
    return $data;
}

function mac_td_section($key)
{
    static $map = null;
    if ($map === null) {
        $map = array();
        $td = mac_theme_design();
        if (!empty($td['homepage']['sections']) && is_array($td['homepage']['sections'])) {
            foreach ($td['homepage']['sections'] as $s) {
                if (!empty($s['key'])) {
                    $map[$s['key']] = $s;
                }
            }
        }
    }
    return isset($map[$key]) ? $map[$key] : array();
}

function mac_url_content_img($content)
{
    $content = mac_scalar_string($content);
    $protocol = $GLOBALS['config']['upload']['protocol'];
    if(empty($protocol)){
        $protocol = 'http';
    }
    $content = str_replace('mac:',$protocol.':',$content);
    if(!empty($GLOBALS['config']['upload']['img_key'])){
        $rule = mac_buildregx("<img[^>]*src\s*=\s*['" . chr(34) . "]?([\w/\-\:.]*)['" . chr(34) . "]?[^>]*>", "is");
        preg_match_all($rule, $content, $matches);
        if(is_array($matches[1])){
            foreach ($matches[1] as $f => $matchfieldstr) {
                $img_src = trim(preg_replace("/[ \r\n\t\f]{1,}/", " ", $matchfieldstr));
                if(preg_match('/'.$GLOBALS['config']['upload']['img_key'].'/',$img_src)){
                    $content = str_replace($img_src,$GLOBALS['config']['upload']['img_api'] . '' . $img_src,$content);
                }
            }
        }
    }
    return $content;
}

function mac_alphaID($in, $to_num=false, $pad_up=false, $passKey='')
{
    $key = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if (!empty($passKey)) {
        for ($n = 0; $n<strlen($key); $n++) {
            $i[] = substr($key,$n ,1);
        }
        $len = strlen($key);
        $passhash = hash('sha256',$passKey);
        $passhash = (strlen($passhash) < $len)
            ? hash('sha512',$passKey)
            : $passhash;
        for ($n=0; $n < $len; $n++) {
            $p[] = substr($passhash, $n ,1);
        }
        array_multisort($p, SORT_DESC, $i);
        $key = implode($i);
    }
    $base = strlen($key);
    if ($to_num) {
        $out = 0;
        $len = strlen($in);
        for ($t = 0; $t < $len; $t++) {
            $char = substr($in, $t, 1);
            $pos = strpos($key, $char);
            if ($pos === false) {
                $pos = 0;
            }
            $out = $out * $base + $pos;
        }
        if (is_numeric($pad_up)) {
            if ($pad_up > 1) {
                $out -= pow($base, $pad_up - 1);
            }
        }
    } else {
        if (is_numeric($pad_up)) {
            if ($pad_up > 1) {
                $in += pow($base, $pad_up - 1);
            }
        }
        $out = "";
        while ($in > 0) {
            $remainder = $in % $base;
            $out = substr($key, $remainder, 1) . $out;
            $in = ($in - $remainder) / $base;
        }
    }
    return $out;
}

function mac_url($model,$param=[],$info=[])
{
    foreach($param as $k=>$v){
        if(empty($v)){
            unset($param[$k]);
        }
    }

    if(!isset($param['page'])) $param['page']=1;

    if($param['page'] == 1){
        $param['page']='';
    }

    ksort($param); 

    $config = $GLOBALS['config'];
    
    $is_static_mode = isset($GLOBALS['ismake']) && $GLOBALS['ismake'] == '1';
    
    $replace_from = ['{id}','{en}','{page}','{type_id}','{type_en}','{type_pid}','{type_pen}','{md5}','{year}','{month}','{day}','{sid}','{nid}'];
    $replace_to = [];
    $page_sp = $config['path']['page_sp'];
    $path = '';

    switch ($model)
    {
        case 'index/index':
            if($config['view']['index'] == 2){
                $path = 'index';
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
            }
            else{
                $url = url($model,$param);
                if($url=='/PAGELINK.html'){
                    $url = '/index-PAGELINK.html';
                }
            }
            break;
        case 'map/index':
            if($config['view']['map'] == 2){
                $path = 'map';
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
            }
            else{
                $url = url($model,$param);
            }
            break;
        case strpos($model,'rss/')!==false:
            if($config['view']['rss'] == 2){
                $path = $model;
                if($param['page'] !=''){
                    $path .= $page_sp . $param['page'];
                }

                $path .= '.xml';
            }
            else{
                $url = url($model,$param,'xml');
            }
            break;
        case strpos($model,'label/')!==false:
            if($config['view']['label'] == 2){
                $file = substr($model, 6);
                if(strpos($file, '.') !== false){
                    $file = explode('.', $file)[0];
                }
                $path = !empty($config['path']['label']) ? $config['path']['label'] : 'label/{file}/index';
                if(substr($path, strlen($path) - 1, 1) == '/'){
                    $path .= 'index';
                }
                $path = str_replace('{file}', $file, $path);
            }
            else{
                $url = url($model,$param);
            }
            break;
        case 'vod/show':
        case 'art/show':
        case 'actor/show':
        case 'website/show':
            switch($config['rewrite']['type_id'])
            {
                case 1:
                    $id = $info['type_en'];
                    break;
                case 2:
                    $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                    break;
                default:
                    $id = $info['type_id'];
                    break;
            }
            if(!empty($id)){
                $param['id'] = $id;
            }
            $url = url($model,$param);
            break;
        case 'vod/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? ''),
            ];
            if($config['view']['vod_type'] == 2){
                $path = $config['path']['vod_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['type_id']);
                if($param['page'] !=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'vod/detail':
            $replace_to = [$info['vod_id'],$info['vod_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['vod_detail'] == 2){
                $path = $config['path' ]['vod_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }

                $url = url($model,['id'=> $id ]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'manga/detail':
            $replace_to = [$info['manga_id'],$info['manga_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['manga_detail'] == 2){
                $path = $config['path' ]['manga_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['manga_id']);
            }
            else{
                switch($config['rewrite']['manga_id'])
                {
                    case 1:
                        $id = $info['manga_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['manga_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['manga_id'];
                        break;
                }

                $url = url($model,['id'=> $id ]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['manga_time']),date('m',$info['manga_time']),date('d',$info['manga_time'])]);
            break;
        case 'vod/play':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? ''),

            ];
            if($config['view']['vod_play'] >=2){
                $path = $config['path' ]['vod_play'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
                if($config['view']['vod_play'] ==2){
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $param['sid'] . '-' . $param['nid'] ;
                }
                elseif($config['view']['vod_play'] ==3){
                    $path .= $config['path']['page_sp'] . $param['sid'] . $config['path']['page_sp'] . $param['nid'] ;
                }
                elseif($config['view']['vod_play'] ==4){
                    $path .= $config['path']['page_sp'] .''. $param['sid'] . $config['path']['page_sp'] . '1';
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $param['sid'] . '-' . $param['nid'] ;
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'sid'=>$param['sid'],'nid'=>$param['nid']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time']),$param['sid'],$param['nid']]);
            break;
        case 'manga/play':
            $replace_to = [
                $info['manga_id'],$info['manga_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? ''),
            ];
            if(isset($config['view']['manga_play']) && $config['view']['manga_play'] >= 2){
                $path = $config['path']['manga_play'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['manga_id']);
                if($config['view']['manga_play'] == 2){
                    $path.= '.'.$config['path']['suffix'];
                    $path .= '?'.$info['manga_id'].'-'.$param['sid'].'-'.$param['nid'];
                }
                elseif($config['view']['manga_play'] == 3){
                    $path .= $config['path']['page_sp'].$param['sid'].$config['path']['page_sp'].$param['nid'];
                }
            }else{
                switch($config['rewrite']['manga_id'])
                {
                    case 1:
                        $id = $info['manga_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['manga_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['manga_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'sid'=>$param['sid'],'nid'=>$param['nid']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['manga_time']),date('m',$info['manga_time']),date('d',$info['manga_time']),$param['sid'],$param['nid']]);
            break;
        case 'vod/down':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['vod_down'] >= 2){
                $path = $config['path' ]['vod_down'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
                if($config['view']['vod_down'] ==2){
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $param['sid'] . '-' . $param['nid'] ;
                }
                elseif($config['view']['vod_down'] ==3){
                    $path .= $config['path']['page_sp'] . $param['sid'] . $config['path']['page_sp'] . $param['nid'] ;
                }
                elseif($config['view']['vod_down'] ==4){
                    $path .= $config['path']['page_sp'] .''. $param['sid'] . $config['path']['page_sp'] . '1';
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $param['sid'] . '-' . $param['nid'] ;
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'sid'=>$param['sid'],'nid'=>$param['nid']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time']),$param['sid'],$param['nid']]);
            break;
        case 'vod/role':
            $replace_to = [$info['vod_id'],$info['vod_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['vod_role'] == 2){
                $path = $config['path' ]['vod_role'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }   
                $replace_to[] = md5($info['vod_id']);
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'vod/plot':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],$param['page'],
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['vod_plot'] == 2){
                $path = $config['path' ]['vod_plot'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'art/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? ''),
            ];
            if($config['view']['art_type'] == 2){
                $path = $config['path']['art_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['type_id']);
                }
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'art/detail':
            $replace_to = [
                $info['art_id'],$info['art_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['art_detail'] == 2){
                $path = $config['path' ]['art_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['art_id']);
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['art_id'])
                {
                    case 1:
                        $id = $info['art_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['art_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['art_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['art_time']),date('m',$info['art_time']),date('d',$info['art_time'])]);
            break;
        case 'topic/index':
            if($config['view']['topic_index'] == 2){
                $path = $config['path' ]['topic_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'topic/detail':
            $replace_to = [$info['topic_id'],$info['topic_en'],'','','','',''];
            if($config['view']['topic_detail'] == 2){
                $path = $config['path' ]['topic_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['topic_id']);
                }
            }
            else{
                switch($config['rewrite']['topic_id'])
                {
                    case 1:
                        $id = $info['topic_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['topic_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['topic_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'actor/index':
            if($config['view']['actor_index'] == 2){
                $path = $config['path' ]['actor_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'actor/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? ''),
            ];
            if($config['view']['actor_type'] == 2){
                $path = $config['path']['actor_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['type_id']);
                }
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'actor/detail':
            $replace_to = [$info['actor_id'],$info['actor_en'],'','','','',''];
            if($config['view']['actor_detail'] == 2){
                $path = $config['path' ]['actor_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['actor_id']);
                }
            }
            else{
                switch($config['rewrite']['actor_id'])
                {
                    case 1:
                        $id = $info['actor_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['actor_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['actor_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'role/index':
            if($config['view']['role_index'] == 2){
                $path = $config['path' ]['role_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'role/detail':
            $replace_to = [$info['role_id'],$info['actor_en'],'','','','',''];
            if($config['view']['role_detail'] == 2){
                $path = $config['path' ]['role_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['role_id']);
                }
            }
            else{
                switch($config['rewrite']['role_id'])
                {
                    case 1:
                        $id = $info['role_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['role_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['role_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'plot/index':
            if($config['view']['plot_index'] == 2){
                $path = $config['path' ]['plot_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'plot/detail':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],'',
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? '')
            ];
            if($config['view']['plot_detail'] == 2){
                $path = $config['path' ]['plot_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['vod_id']);
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'website/index':
            if($config['view']['website_index'] == 2){
                $path = $config['path' ]['website_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'website/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],($info['type']['type_en'] ?? ''),($info['type_1']['type_id'] ?? ''),($info['type_1']['type_en'] ?? ''),
            ];
            if($config['view']['website_type'] == 2){
                $path = $config['path']['website_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['type_id']);
                }
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'website/detail':
            $replace_to = [$info['website_id'],$info['website_en'],'','','','',''];
            if($config['view']['website_detail'] == 2){
                $path = $config['path' ]['website_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['website_id']);
                }
            }
            else{
                switch($config['rewrite']['website_id'])
                {
                    case 1:
                        $id = $info['website_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['website_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['website_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'gbook/index':
            $url = url($model,['page'=>$param['page']]);
            break;
        case 'comment/index':
            $url = url($model,['page'=>$param['page']]);
            break;
        default:
            $url = url($model,$param);
            break;
    }
    if(!empty($path)) {
        $path = str_replace($replace_from, $replace_to, $path);
        $path = str_replace('//', '/', $path);
        $delimiter = false;
        if(substr($path,strlen($path)-6) =='/index'){
            $delimiter = true;
            $path = substr($path,0, strlen($path)-5);
        }

        if($delimiter==false && strpos($path,'.')===false){
            $path.= '.'. $config['path']['suffix'];
        }
        $url = $path;
        if(substr($path,0,1)!='/') {
            $url = MAC_PATH . $path;
        }
    }
    else{
        if(ENTRANCE!='index'){
            $sto= MAC_PATH ;
            if($config['rewrite']['status']==0){
                $sto = MAC_PATH .'index.php/';
            }
            if(!empty(IN_FILE)){
                $url = str_replace(IN_FILE.'/',$sto,$url);
                $url = str_replace(ENTRANCE.'/','',$url);
            }
        }
        elseif($config['rewrite']['status']==0 && strpos($url,'index.php')===false){
            if(MAC_PATH !='/'){
                $url = str_replace(MAC_PATH,'/',$url);
            }
            $url = MAC_PATH. 'index.php' . $url;
        }
        elseif($config['rewrite']['status']==1 && strpos($url,'index.php')!==false){
            $url = str_replace('index.php/','',$url);
        }

        if($config['rewrite']['suffix_hide']==1){
            $url = str_replace('.html','/',$url);
            if(strpos($model,'/show')===false && strpos($model,'/search')===false) {
                $url = str_replace(['-/','_/','-.','_.'],'/',$url);
            }
        }
        else{
            if(strpos($model,'search')===false && strpos($model,'show')===false ) {
                $url = str_replace(['-.', '/.'], '.', $url);
            }
        }
    }

    if($is_static_mode && !empty($url)) {
        if(strpos($url, '/index.php/') === 0) {
            $url = preg_replace('/\/index\.php\/[^\/]+\.php\/admin\-/', '/index.php/', $url);
            $url = str_replace('/admin-', '/', $url);
        }
        if(strpos($url, 'admin-') !== false) {
            $url = str_replace('admin-', '', $url);
        }
        if(strpos($url, 'index-') !== false) {
            $url = str_replace('index-', '', $url);
        }
    }

    if (strpos($url, 'page=') !== false) {
        $url = preg_replace('/[?&]page=$/', '', $url);
    }

    return $url;
}
function mac_url_page($url,$num)
{
    $url = str_replace(MAC_PAGE_SP.'PAGELINK',($num>1 ? MAC_PAGE_SP.$num : ''),$url);
    $url = str_replace('PAGELINK',$num,$url);
    return $url;
}

function mac_url_create($str,$type='actor',$flag='vod',$ac='search',$sp='&nbsp;')
{
    if(!$str){
        return '未知';
    }
    $res = [];
    $base_finder = array(' / ', '/', '|', ',', '，', ',,');
    $str = str_replace($base_finder, ',', $str);
    $str = trim($str, ',');
    $arr = [];
    foreach (explode(',', $str) as $tag) {
        if (preg_match("/[\x{2E80}-\x{9FFF}]+/u", $tag) && str_contains($tag, ' ')) {
            foreach (explode(' ', $tag) as $tag_exp) {
                $arr[] = $tag_exp;
            }
        } else {
            $arr[] = $tag;
        }
    }
    foreach ($arr as $k => $v) {
        $res[$k] = '<a href="'.mac_url($flag.'/'.$ac,[$type=>$v]).'" target="_blank">'.$v.'</a>'.$sp;
    }
    return implode('',$res);
}

function mac_url_search($param=[],$flag='vod')
{
    return mac_url($flag.'/search',$param);
}

function mac_url_type($info,$param=[],$flag='type')
{
    $tab = 'vod';
    if(!is_array($info)){
        $mid = $GLOBALS['mid'] ?? 1;
        if($mid == 2) $tab = 'art';
        elseif($mid == 8) $tab = 'actor';
        elseif($mid == 11) $tab = 'website';
        elseif($mid == 12) $tab = 'manga';
        if(empty($param['id']) && !empty($GLOBALS['type_id'])) $param['id'] = $GLOBALS['type_id'];
        return mac_url($tab.'/'.$flag,$param);
    }
    if($info['type_mid'] == 1){
    }
    else if($info['type_mid'] == 2) {
        $tab ='art';
    }
    else if($info['type_mid'] == 8) {
        $tab ='actor';
    }
    else if($info['type_mid'] == 11) {
        $tab ='website';
    }
    else if($info['type_mid'] == 12) {
        if(empty($param['id'])){
            $param['id'] = $info['type_id'];
        }
        return mac_url('manga/'.$flag,$param,$info);
    }
    if(empty($param['id'])){
        $param['id'] = $info['type_id'];
    }

    return mac_url($tab.'/'.$flag,$param,$info);
}

function mac_url_topic_index($param=[])
{
    return mac_url('topic/index',['page'=>$param['page']]);
}

function mac_url_topic_detail($info)
{
    return mac_url('topic/detail',[],$info);
}

function mac_url_role_index($param=[])
{
    return mac_url('role/index',['page'=>$param['page']]);
}

function mac_url_role_detail($info)
{
    return mac_url('role/detail',[],$info);
}
function mac_url_actor_index($param=[])
{
    return mac_url('actor/index',['page'=>$param['page']]);
}
function mac_url_actor_detail($info)
{
    return mac_url('actor/detail',[],$info);
}
function mac_url_actor_search($param)
{
    return mac_url('actor/search',$param);
}
function mac_url_label($file)
{
    $file = str_replace('\\', '/', (string)$file);
    if(strpos($file, '.') !== false){
        $file = explode('.', $file)[0];
    }
    return mac_url('label/'.$file);
}

function mac_url_plot_index($param=[])
{
    return mac_url('plot/index',['page'=>$param['page']]);
}
function mac_url_plot_detail($info,$param=[])
{
    return mac_url('plot/detail',['page'=>$param['page']],$info);
}
function mac_url_vod_plot($info,$param=[])
{
    return mac_url('vod/plot',$param,$info);
}
function mac_url_vod_role($info,$param=[])
{
    return mac_url('vod/role',$param,$info);
}
function mac_url_website_index($param=[])
{
    return mac_url('website/index',['page'=>$param['page']]);
}
function mac_url_website_detail($info)
{
    return mac_url('website/detail',[],$info);
}
function mac_url_website_search($param)
{
    return mac_url('website/search',$param);
}
function mac_url_art_index($param=[])
{
    return mac_url('art/index',['page'=>$param['page']]);
}
function mac_url_art_detail($info,$param=[])
{
    return mac_url('art/detail',['page'=>$param['page']],$info);
}
function mac_url_art_search($param)
{
    return mac_url('art/search',$param);
}
function mac_url_vod_index($param=[])
{
    return mac_url('vod/index',['page'=>$param['page']]);
}
function mac_url_vod_detail($info)
{
    return mac_url('vod/detail',[],$info);
}
function mac_url_manga_detail($info)
{
    return mac_url('manga/detail',[],$info);
}
function mac_url_manga_play($info,$param=[])
{
    if($param=='first'){
        if(empty($info['manga_page_list']) || !is_array($info['manga_page_list'])){
            return '';
        }
        $sid = intval(key($info['manga_page_list']));
        if(empty($info['manga_page_list'][$sid]['urls']) || !is_array($info['manga_page_list'][$sid]['urls'])){
            return '';
        }
        $nid = intval(key($info['manga_page_list'][$sid]['urls']));
        if($sid==0 || $nid==0){
            return '';
        }
        $param = [];
        $param['sid'] = $sid;
        $param['nid'] = $nid;
    }
    if(intval($param['sid'])<1){
        $param['sid'] = 1;
    }
    if(intval($param['nid'])<1){
        $param['nid'] = 1;
    }

    return mac_url('manga/play',['sid'=>$param['sid'],'nid'=>$param['nid']],$info);
}
function mac_url_manga_down($info,$param=[])
{
    if($param=='first'){
        if(empty($info['manga_page_list']) || !is_array($info['manga_page_list'])){
            return '';
        }
        $sid = intval(key($info['manga_page_list']));
        if(empty($info['manga_page_list'][$sid]['urls']) || !is_array($info['manga_page_list'][$sid]['urls'])){
            return '';
        }
        $nid = intval(key($info['manga_page_list'][$sid]['urls']));
        if($sid==0 || $nid==0){
            return '';
        }
        $param = [];
        $param['sid'] = $sid;
        $param['nid'] = $nid;
    }

    if(intval($param['sid'])<1){
        $param['sid'] = 1;
    }
    if(intval($param['nid'])<1){
        $param['nid'] = 1;
    }

    return mac_url('manga/down',['sid'=>$param['sid'],'nid'=>$param['nid']],$info);
}
function mac_url_vod_search($param)
{
    return mac_url('vod/search',$param);
}
function mac_url_vod_play($info,$param=[])
{
    if($param=='first'){
        $sid = intval(key($info['vod_play_list']));
        $nid = intval(key($info['vod_play_list'][$sid]['urls']));
        if($sid==0 || $nid==0){
            return '';
        }
        $param=[];
        $param['sid'] = $sid;
        $param['nid'] = $nid;
    }
    if(intval($param['sid'])<1){
        $param['sid'] =1;
    }
    if(intval($param['nid'])<1){
        $param['nid']=1;
    }

    return mac_url('vod/play',['sid'=>$param['sid'],'nid'=>$param['nid']],$info);
}

function mac_url_vod_down($info,$param=[])
{
    if($param=='first'){
        $sid = intval(key($info['vod_down_list']));
        $nid = intval(key($info['vod_down_list'][$sid]['urls']));
        if($sid==0 || $nid==0){
            return '';
        }
        $param=[];
        $param['sid'] = $sid;
        $param['nid'] = $nid;
    }

    if(intval($param['sid'])<1){
        $param['sid'] =1;
    }
    if(intval($param['nid'])<1){
        $param['nid']=1;
    }

    return mac_url('vod/down',['sid'=>$param['sid'],'nid'=>$param['nid']],$info);
}

function mac_label_website_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['website_id']==1){
        $where['website_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['website_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['website_id'] = ['eq',$param['id']];
    }
    $where['website_status'] = ['eq',1];
    $res = (new \app\common\model\Website())->infoData($where,'*',1);

    $GLOBALS['type_id'] = $res['info']['type_id'];
    $GLOBALS['type_pid'] = $res['info']['type']['type_pid'];
    return $res;
}
function mac_label_actor_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['actor_id']==1){
        $where['actor_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['actor_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['actor_id'] = ['eq',$param['id']];
    }
    $where['actor_status'] = ['eq',1];
    $res = (new \app\common\model\Actor())->infoData($where,'*',1);

    $GLOBALS['type_id'] = $res['info']['type_id'];
    $GLOBALS['type_pid'] = $res['info']['type']['type_pid'];
    return $res;
}
function mac_label_role_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['role_id']==1){
        $where['role_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['role_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['role_id'] = ['eq',$param['id']];
    }
    $where['role_status'] = ['eq',1];
    $res = (new \app\common\model\Role())->infoData($where,'*',1);

    $GLOBALS['type_id'] = isset($res['info']['data']['type_id']) ? $res['info']['data']['type_id'] : 0;
    $GLOBALS['type_pid'] = isset($res['info']['data']['type_id_1']) ? $res['info']['data']['type_id_1'] : 0;
    return $res;
}
function mac_label_topic_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['topic_id']==1){
        $where['topic_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['topic_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['topic_id'] = ['eq',$param['id']];
    }
    $where['topic_status'] = ['eq',1];
    $res = (new \app\common\model\Topic())->infoData($where,'*',1);
    return $res;
}
function mac_label_art_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['art_id']==1){
        $where['art_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['art_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['art_id'] = ['eq',$param['id']];
    }
    $where['art_status'] = ['eq',1];
    $res = (new \app\common\model\Art())->infoData($where,'*',1);
    if($res['code'] ==1){
        if($param['page']>$res['info']['art_page_total']){ $param['page'] = $res['info']['art_page_total']; }
    }
    $GLOBALS['type_id'] = $res['info']['type_id'];
    $GLOBALS['type_pid'] = $res['info']['type']['type_pid'];

    return $res;
}
function mac_label_manga_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['manga_id']==1){
        $where['manga_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['manga_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['manga_id'] = ['eq',$param['id']];
    }
    $where['manga_status'] = ['eq',1];
    $res = (new \app\common\model\Manga())->infoData($where,'*',1);
    if($res['code'] != 1){
        return $res;
    }
    $GLOBALS['type_id'] = $res['info']['type_id'];
    $GLOBALS['type_pid'] = $res['info']['type']['type_pid'];

    return $res;
}
function mac_label_vod_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['vod_id']==1){
        $where['vod_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['vod_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['vod_id'] = ['eq',$param['id']];
    }
    $where['vod_status'] = ['eq',1];
    $res = (new \app\common\model\Vod())->infoData($where,'*',1);

    $GLOBALS['type_id'] = $res['info']['type_id'];
    $GLOBALS['type_pid'] = $res['info']['type']['type_pid'];
    return $res;
}

function mac_label_vod_role($param)
{
    $where = [];
    $where['role_rid'] = $param['rid'];
    $where['role_status'] = ['eq',1];
    $order='role_sort desc,role_id desc';
    $res = (new \app\common\model\Role())->listData($where,$order,1,999,0,'*',0,0);
    return $res;
}

function mac_label_type($param, $type_id_specified)
{
    if ($type_id_specified > 0) {
        $type_id = $type_id_specified;
    } else {
        if($GLOBALS['config']['rewrite']['type_id']==1){
        }
        else{
            if($GLOBALS['config']['rewrite']['type_id']==2) {
                $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
            }
        }
        $type_id = $param['id'];
    }
    $type_info = (new \app\common\model\Type())->getCacheInfo($type_id);
    if (empty($type_info)) {
        return [];
    }

    $GLOBALS['type_id'] =$type_info['type_id'];
    $GLOBALS['type_pid'] = $type_info['type_pid'];

    $parent = (new \app\common\model\Type())->getCacheInfo($type_info['type_pid']);
    $type_info['parent'] = $parent;
    return $type_info;
}

function mac_data_count($tid=0,$range='all',$flag='vod')
{
    if(!in_array($flag,['vod','art','actor','role','topic','website'])) {
        $flag='vod';
    }
    if(!in_array($range,['all','today','min'])){
        $range='all';
    }

    $data = (new \app\common\model\Extend())->dataCount();
    if ($tid > 0 && in_array($flag, ['vod', 'art'])) {
        $key = 'type_' . $range . '_' . $tid;
    } else {
        $key = $flag . '_' . $range;
    }
    return intval($data[$key] ?? 0);
}

function mac_get_popedom_filter($group_type_list, $type_list = [])
{
    if (empty($type_list)) {
        $type_list = (new \app\common\model\Type())->getCache('type_list');
    }
    $type_keys = array_keys($type_list);
    $group_type_list = array_map('trim', explode(',', trim($group_type_list, ',')));
    $group_keys = [];
    foreach ($group_type_list as $group_type) {
        $group_keys = array_merge($group_keys, explode(',', $group_type));
    }
    $group_keys = get_array_unique_id_list($group_keys);
    $cha_keys = array_diff($type_keys, $group_keys);
    return implode(',', $cha_keys);
}

function mac_get_popedom_type_visible($popedom = 1)
{
    static $cache = [];
    $popedom = (int)$popedom;
    if (isset($cache[$popedom])) {
        return $cache[$popedom];
    }

    $visible = [];
    $group_ids = isset($GLOBALS['user']['group_id']) ? (string)$GLOBALS['user']['group_id'] : '1';
    $group_list = (new \app\common\model\Group())->getCache('group_list');
    $type_list = (new \app\common\model\Type())->getCache('type_list');
    if (empty($group_list) || empty($type_list)) {
        $cache[$popedom] = $visible;
        return $visible;
    }

    foreach (explode(',', $group_ids) as $group_id) {
        $group_id = trim($group_id);
        if ($group_id === '' || empty($group_list[$group_id])) {
            continue;
        }
        $group_type = ',' . trim((string)($group_list[$group_id]['group_type'] ?? ''), ',') . ',';
        $group_popedom = $group_list[$group_id]['group_popedom'] ?? [];
        foreach ($type_list as $type_id => $type_info) {
            if (strpos($group_type, ',' . $type_id . ',') === false
                || empty($group_popedom[$type_id][$popedom])) {
                continue;
            }
            $visible[$type_id] = true;
            $pid = (int)($type_info['type_pid'] ?? 0);
            while ($pid > 0 && !empty($type_list[$pid]) && empty($visible[$pid])) {
                $visible[$pid] = true;
                $pid = (int)$type_list[$pid]['type_pid'];
            }
        }
    }

    $cache[$popedom] = $visible;
    return $visible;
}

function mac_type_nav_visible($type_id, $popedom = 1)
{
    $visible = mac_get_popedom_type_visible($popedom);
    return isset($visible[(int)$type_id]);
}

function mac_get_vip_exclusive_type_ids()
{
    $cache_flag = $GLOBALS['config']['app']['cache_flag'] ?? 'maccms';
    $key = $cache_flag . '_vip_exclusive_type_ids';
    $list = \think\facade\Cache::get($key);
    if (is_array($list)) {
        return $list;
    }
    $group_list = (new \app\common\model\Group())->getCache('group_list');
    $type_list = (new \app\common\model\Type())->getCache('type_list');
    if (empty($group_list) || empty($type_list)) {
        return [];
    }
    $guest_ids = [];
    $member_ids = [];
    $vip_ids = [];
    $content_mids = [1 => 1, 2 => 1, 12 => 1];
    foreach ($type_list as $type_id => $type_info) {
        $mid = $type_info['type_mid'] ?? 0;
        if (empty($content_mids[$mid])) {
            continue;
        }
        foreach ($group_list as $gid => $group) {
            $has = strpos(',' . ($group['group_type'] ?? ''), ',' . $type_id . ',') !== false
                && !empty($group['group_popedom'][$type_id][3]);
            if (!$has) {
                continue;
            }
            if ($gid == 1) {
                $guest_ids[$type_id] = 1;
            } elseif ($gid == 2) {
                $member_ids[$type_id] = 1;
            } else {
                $vip_ids[$type_id] = 1;
            }
        }
    }
    $ab = $guest_ids + $member_ids;
    $exclusive = array_diff_key($vip_ids, $ab);
    $list = array_keys($exclusive);
    \think\facade\Cache::set($key, $list);
    return $list;
}

function mac_append_type_is_vip_exclusive_for_rows(&$list)
{
    if ($list instanceof \think\Collection) {
        $list = $list->toArray();
    }
    if (!is_array($list) || empty($list)) {
        return;
    }
    $vip_exclusive = mac_get_vip_exclusive_type_ids();
    foreach ($list as &$row) {
        $row['type_is_vip_exclusive'] = in_array((int)($row['type_id'] ?? 0), $vip_exclusive, true) ? 1 : 0;
    }
    unset($row);
}

function mac_content_read_points_amount($pre, array $info)
{
    $pre = strtolower((string)$pre);
    if (!in_array($pre, ['art', 'manga'], true)) {
        return 0;
    }
    $typeKey = $pre . '_points_type';
    $ptype = isset($GLOBALS['config']['user'][$typeKey]) ? (string)$GLOBALS['config']['user'][$typeKey] : '0';
    if ($ptype === '1') {
        return (int)($info[$pre . '_points'] ?? 0);
    }
    $detail = (int)($info[$pre . '_points_detail'] ?? 0);
    if ($detail > 0) {
        return $detail;
    }

    return (int)($info[$pre . '_points'] ?? 0);
}

function mac_vod_type_filter_ids_for_list($typeId)
{
    $typeId = (int)$typeId;
    if ($typeId <= 0) {
        return [];
    }
    $type_list = (new \app\common\model\Type())->getCache('type_list');
    if (empty($type_list) || !is_array($type_list)) {
        return [$typeId];
    }
    $tmp_arr = explode(',', (string)$typeId);
    $ids = [];
    foreach ($type_list as $v2) {
        if (!is_array($v2)) {
            continue;
        }
        if (in_array($v2['type_id'] . '', $tmp_arr) || in_array($v2['type_pid'] . '', $tmp_arr)) {
            $ids[] = (int)$v2['type_id'];
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    return !empty($ids) ? $ids : [$typeId];
}

function reset_html_filename($htmlfile)
{
    $htmlpath = './';
    if(substr($htmlfile,strlen($htmlfile)-1,1)=='/'){
        $htmlfile .= 'index';
    }

    if(strpos($htmlfile,'.') ===false){
        $htmlfile .= '.'. $GLOBALS['config']['path']['suffix'];
    }

    if(strpos($htmlfile,'?')!==false){
        $htmlfile = substr($htmlfile,0,strpos($htmlfile,'?'));
    }
    $htmlfile   =   $htmlpath.$htmlfile;
    $htmlfile = str_replace('//','/', $htmlfile);

    if(MAC_PATH !='/'){
        $htmlfile = str_replace('.'.MAC_PATH, './', $htmlfile);
    }

    $htmlfile = str_replace('//','/', $htmlfile);
    return $htmlfile;
}

function mac_unicode_encode($str, $encoding = 'UTF-8', $prefix = '&#', $postfix = ';') {
    $str = iconv($encoding, 'UCS-2', $str);
    $arrstr = str_split($str, 2);
    $unistr = '';
    for($i = 0, $len = count($arrstr); $i < $len; $i++) {
        $dec = hexdec(bin2hex($arrstr[$i]));
        $unistr .= $prefix . $dec . $postfix;
    }
    return $unistr;
}
function mac_unicode_decode($unistr, $encoding = 'UTF-8', $prefix = '&#', $postfix = ';') {
    $arruni = explode($prefix, $unistr);
    $unistr = '';
    for($i = 1, $len = count($arruni); $i < $len; $i++) {
        if (strlen($postfix) > 0) {
            $arruni[$i] = substr($arruni[$i], 0, strlen($arruni[$i]) - strlen($postfix));
        }
        $temp = intval($arruni[$i]);
        $unistr .= ($temp < 256) ? chr(0) . chr($temp) : chr($temp / 256) . chr($temp % 256);
    }
    return iconv('UCS-2', $encoding, $unistr);
}

function mac_escape_param($param)
{
    if(is_array($param)){
        foreach($param as $k=>$v){
            if(!is_numeric($v) && !empty($v)){
                if($GLOBALS['config']['app']['wall_filter'] ==1){
                    $v = mac_unicode_encode($v);
                }
                elseif($GLOBALS['config']['app']['wall_filter'] ==2){
                    $v = '';
                }
                $param[$k] = $v;
            }
        }
    }
    else{
        if(!is_numeric($param) && !empty($param)){
            if($GLOBALS['config']['app']['wall_filter'] ==1){
                $param = mac_unicode_encode($param);
            }
            elseif($GLOBALS['config']['app']['wall_filter'] ==2){
                $param = '';
            }
        }
    }
    return $param;
}

function mac_search_len_check($param)
{
    $psm = array('wd','tag','class','letter','name','state','level','area','lang','version','actor','director','starsign','blood');
    foreach($psm as $v){
        if(mb_strlen($param[$v]) > $GLOBALS['config']['app']['search_len']){
            $param[$v] = mac_substring($param[$v],$GLOBALS['config']['app']['search_len']);
        }
    }
    return $param;
}

function mac_no_cahche()
{
    @header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    @header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . 'GMT');
    @header('Cache-Control: no-cache, must-revalidate');
    @header('Pragma: no-cache');
}

function mac_filter_tags($rs)
{
    $rex = array('{:','<script','<iframe','<frameset','<object','onerror');
    if(is_array($rs)){
        foreach($rs as $k2=>$v2){
            if(!is_numeric($v2)){
                $rs[$k2] = str_ireplace($rex,'*',$rs[$k2]);
            }
        }
    }
    else{
        if(!is_numeric($rs)){
            $rs = str_ireplace($rex,'*',$rs);
        }
    }
    return $rs;
}

if (!function_exists('is_really_writable')) {
    function is_really_writable($file)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return is_writable($file);
        }
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === false) {
                return false;
            }
            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return true;
        } elseif (!is_file($file) or ($fp = @fopen($file, 'ab')) === false) {
            return false;
        }
        fclose($fp);
        return true;
    }
}
if (!function_exists('rmdirs')) {
    function rmdirs($dirname, $withself = true)
    {
        if (!is_dir($dirname)) {
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        if ($withself) {
            @rmdir($dirname);
        }
        return true;
    }
}
if (!function_exists('copydirs')) {
    function copydirs($source, $dest)
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            ) as $item
        ) {
            if ($item->isDir()) {
                $sontDir = $dest . DS . $iterator->getSubPathName();
                if (!is_dir($sontDir)) {
                    mkdir($sontDir, 0755, true);
                }
            } else {
                copy($item, $dest . DS . $iterator->getSubPathName());
            }
        }
    }
}

function mac_admin_csrf_token() {
    $t = \think\facade\Session::get('admin_csrf');
    if (!is_string($t) || $t === '') {
        try {
            $t = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $t = md5(uniqid('mac_admin_csrf_', true) . mt_rand());
        }
        \think\facade\Session::set('admin_csrf', $t);
    }
    return $t;
}

function mac_strip_tags($string) {
    $pattern = '/&([a-zA-Z0-9#]+);/';
    
    $string = preg_replace_callback($pattern, function($matches) {
        if ($matches[0] === '&lt;') {
            return '<';
        }
        if ($matches[0] === '&gt;') {
            return '>';
        }
        if ($matches[0] === '&nbsp;') {
            return ' ';
        }
        if ($matches[0] === '&amp;') {
            return '&';
        }
        return $matches[0];
    }, $string);
    
    return strip_tags($string);
}

function mac_content_lang_fields($contentType)
{
    $map = [
        'vod'   => ['vod_name', 'vod_sub', 'vod_tag', 'vod_remarks', 'vod_class', 'vod_area', 'vod_lang', 'vod_version', 'vod_state', 'vod_blurb', 'vod_content'],
        'art'   => ['art_name', 'art_sub', 'art_tag', 'art_remarks', 'art_class', 'art_blurb', 'art_title', 'art_note', 'art_content'],
        'manga' => ['manga_name', 'manga_sub', 'manga_tag', 'manga_remarks', 'manga_class', 'manga_blurb', 'manga_content'],
        'actor' => ['actor_name', 'actor_alias', 'actor_area', 'actor_birtharea', 'actor_starsign', 'actor_school', 'actor_remarks', 'actor_works', 'actor_tag', 'actor_class', 'actor_blurb', 'actor_content'],
        'website' => ['website_name', 'website_sub', 'website_area', 'website_lang', 'website_tag', 'website_class', 'website_remarks', 'website_blurb', 'website_content'],
        'live'  => ['live_name', 'live_sub', 'live_blurb', 'live_content'],
        'type'  => ['type_name', 'type_title', 'type_key', 'type_des', 'type_extend'],
        'topic' => ['topic_name', 'topic_sub', 'topic_remarks', 'topic_type', 'topic_tag', 'topic_blurb', 'topic_content', 'topic_title', 'topic_key', 'topic_des'],
        'link'  => ['link_name'],
        'task'  => ['task_name', 'task_desc'],
    ];
    return isset($map[$contentType]) ? $map[$contentType] : [];
}

function mac_content_lang_xss_fields($contentType)
{
    $map = [
        'art'   => ['art_name', 'art_sub', 'art_tag', 'art_class', 'art_blurb', 'art_remarks'],
        'manga' => ['manga_name', 'manga_sub', 'manga_tag', 'manga_class', 'manga_blurb', 'manga_remarks'],
        'actor' => ['actor_name', 'actor_alias', 'actor_area', 'actor_birtharea', 'actor_starsign', 'actor_school', 'actor_remarks', 'actor_works', 'actor_tag', 'actor_class', 'actor_blurb'],
        'website' => ['website_name', 'website_sub', 'website_area', 'website_lang', 'website_tag', 'website_class', 'website_remarks', 'website_blurb'],
        'type'  => ['type_name', 'type_title', 'type_key', 'type_des'],
        'topic' => ['topic_name', 'topic_sub', 'topic_type', 'topic_title', 'topic_key', 'topic_des', 'topic_blurb', 'topic_remarks', 'topic_tag', 'topic_content'],
    ];
    return isset($map[$contentType]) ? $map[$contentType] : [];
}

function mac_site_lang()
{
    $candidates = [
        $GLOBALS['config']['app']['lang'] ?? '',
        config('app.default_lang'),
        config('lang.default_lang'),
    ];
    foreach ($candidates as $value) {
        if (is_string($value) && $value !== '') {
            return strtolower($value);
        }
    }
    return 'zh-cn';
}

function mac_lang_registry()
{
    static $reg = null;
    if ($reg !== null) {
        return $reg;
    }
    $files = (array)glob(APP_PATH . 'lang' . DIRECTORY_SEPARATOR . '*.php');
    $codes = [];
    foreach ($files as $f) {
        $code = strtolower(basename($f, '.php'));
        if (strpos($code, '.bak') === false) {
            $codes[] = $code;
        }
    }
    sort($codes);

    $reg = [];
    foreach ($codes as $i => $code) {
        $head = (string)file_get_contents(APP_PATH . 'lang' . DIRECTORY_SEPARATOR . $code . '.php', false, null, 0, 4096);
        $name = mac_lang_meta_read($head, 'lang_name');
        $sort = mac_lang_meta_read($head, 'lang_sort');
        $pngs = array_values(array_filter(array_map('trim', explode(',', mac_lang_meta_read($head, 'lang_flag_png')))));
        $reg[$code] = [
            'code'      => $code,
            'name'      => $name !== '' ? $name : strtoupper($code),
            'flag'      => mac_lang_meta_read($head, 'lang_flag'),
            'dir'       => mac_lang_meta_read($head, 'lang_dir') ?: 'ltr',
            'ui'        => mac_lang_meta_read($head, 'lang_ui'),
            'legacy'    => mac_lang_meta_read($head, 'lang_legacy') ?: substr($code, 0, 2),
            'flag_pngs' => $pngs,
            'sort'      => $sort === '' ? 100 + $i : (int)$sort,
        ];
    }
    uasort($reg, function ($a, $b) {
        return $a['sort'] === $b['sort'] ? strcmp($a['code'], $b['code']) : $a['sort'] - $b['sort'];
    });
    return $reg;
}

function mac_lang_meta_read($head, $key)
{
    if (!preg_match('/\'' . $key . '\'\s*=>\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $head, $m)) {
        return '';
    }
    return stripcslashes(trim($m[1]));
}

function mac_lang_codes()
{
    return array_keys(mac_lang_registry());
}

function mac_lang_meta($code)
{
    $code = strtolower(trim((string)$code));
    $reg = mac_lang_registry();
    return $reg[$code] ?? null;
}

function mac_content_lang_codes()
{
    return mac_lang_codes();
}

function mac_tpl_text($text)
{
    $text = (string) $text;
    if ($text === '') {
        return $text;
    }
    $lang = \app\common\model\ContentLang::getCurrent();
    if ($lang === mac_content_lang_default()) {
        return $text;
    }
    static $map = [
        '电影' => ['zh-tw' => '電影', 'en-us' => 'Movies', 'ja-jp' => '映画', 'ko-kr' => '영화', 'ru-ru' => 'Фильмы'],
        '电视剧' => ['zh-tw' => '電視劇', 'en-us' => 'TV Series', 'ja-jp' => 'ドラマ', 'ko-kr' => '드라마', 'ru-ru' => 'Сериалы'],
        '短剧' => ['zh-tw' => '短劇', 'en-us' => 'Short Drama', 'ja-jp' => 'ショートドラマ', 'ko-kr' => '숏드라마', 'ru-ru' => 'Короткие драмы'],
        '动漫' => ['zh-tw' => '動漫', 'en-us' => 'Anime', 'ja-jp' => 'アニメ', 'ko-kr' => '애니메이션', 'ru-ru' => 'Аниме'],
        '综艺' => ['zh-tw' => '綜藝', 'en-us' => 'Variety', 'ja-jp' => 'バラエティ', 'ko-kr' => '예능', 'ru-ru' => 'Шоу'],
        '体育' => ['zh-tw' => '體育', 'en-us' => 'Sports', 'ja-jp' => 'スポーツ', 'ko-kr' => '스포츠', 'ru-ru' => 'Спорт'],
        '午夜' => ['zh-tw' => '午夜', 'en-us' => 'Midnight', 'ja-jp' => 'ミッドナイト', 'ko-kr' => '미드나잇', 'ru-ru' => 'Полночь'],
        '小说' => ['zh-tw' => '小說', 'en-us' => 'Novels', 'ja-jp' => '小説', 'ko-kr' => '소설', 'ru-ru' => 'Романы'],
        '漫画' => ['zh-tw' => '漫畫', 'en-us' => 'Manga', 'ja-jp' => '漫画', 'ko-kr' => '만화', 'ru-ru' => 'Манга'],
        '直播' => ['zh-tw' => '直播', 'en-us' => 'Live', 'ja-jp' => 'ライブ', 'ko-kr' => '라이브', 'ru-ru' => 'Прямой эфир'],
        '专题' => ['zh-tw' => '專題', 'en-us' => 'Topics', 'ja-jp' => '特集', 'ko-kr' => '특집', 'ru-ru' => 'Подборки'],
        '首页' => ['zh-tw' => '首頁', 'en-us' => 'Home', 'ja-jp' => 'ホーム', 'ko-kr' => '홈', 'ru-ru' => 'Главная'],
        '会员' => ['zh-tw' => '會員', 'en-us' => 'Member', 'ja-jp' => '会員', 'ko-kr' => '회원', 'ru-ru' => 'Кабинет'],
        '热门' => ['zh-tw' => '熱門', 'en-us' => 'Hot', 'ja-jp' => '人気', 'ko-kr' => '인기', 'ru-ru' => 'Популярное'],
        '热门推荐' => ['zh-tw' => '熱門推薦', 'en-us' => 'Hot Picks', 'ja-jp' => 'おすすめ', 'ko-kr' => '인기 추천', 'ru-ru' => 'Рекомендуем'],
        '热门榜' => ['zh-tw' => '熱門榜', 'en-us' => 'Trending', 'ja-jp' => '人気ランキング', 'ko-kr' => '인기 차트', 'ru-ru' => 'В тренде'],
        '荧幕热星' => ['zh-tw' => '螢幕熱星', 'en-us' => 'Screen Stars', 'ja-jp' => '注目スター', 'ko-kr' => '스크린 스타', 'ru-ru' => 'Звёзды экрана'],
        '推荐明星' => ['zh-tw' => '推薦明星', 'en-us' => 'Featured Stars', 'ja-jp' => 'おすすめスター', 'ko-kr' => '추천 스타', 'ru-ru' => 'Рекомендуемые звёзды'],
        '友情链接' => ['zh-tw' => '友情連結', 'en-us' => 'Friend Links', 'ja-jp' => '相互リンク', 'ko-kr' => '친구 링크', 'ru-ru' => 'Партнёрские ссылки'],
        '排行榜' => ['zh-tw' => '排行榜', 'en-us' => 'Rankings', 'ja-jp' => 'ランキング', 'ko-kr' => '랭킹', 'ru-ru' => 'Рейтинги'],
        '热播排行榜' => ['zh-tw' => '熱播排行榜', 'en-us' => 'Trending Now', 'ja-jp' => '人気ランキング', 'ko-kr' => '인기 랭킹', 'ru-ru' => 'Популярное'],
        '排行模块' => ['zh-tw' => '排行模組', 'en-us' => 'Rankings', 'ja-jp' => 'ランキング', 'ko-kr' => '랭킹', 'ru-ru' => 'Рейтинги'],
        '推荐' => ['zh-tw' => '推薦', 'en-us' => 'Recommended', 'ja-jp' => 'おすすめ', 'ko-kr' => '추천', 'ru-ru' => 'Рекомендуемое'],
        '最新' => ['zh-tw' => '最新', 'en-us' => 'Latest', 'ja-jp' => '最新', 'ko-kr' => '최신', 'ru-ru' => 'Последние'],
        '换一换' => ['zh-tw' => '換一換', 'en-us' => 'Shuffle', 'ja-jp' => '入れ替え', 'ko-kr' => '바꾸기', 'ru-ru' => 'Обновить'],
        '更多' => ['zh-tw' => '更多', 'en-us' => 'More', 'ja-jp' => 'もっと見る', 'ko-kr' => '더보기', 'ru-ru' => 'Ещё'],
        '央视频道' => ['zh-tw' => '央視頻道', 'en-us' => 'CCTV Channels', 'ja-jp' => 'CCTVチャンネル', 'ko-kr' => 'CCTV 채널', 'ru-ru' => 'Каналы CCTV'],
        '卫视频道' => ['zh-tw' => '衛視頻道', 'en-us' => 'Satellite Channels', 'ja-jp' => '衛星チャンネル', 'ko-kr' => '위성 채널', 'ru-ru' => 'Спутниковые каналы'],
        '地方频道' => ['zh-tw' => '地方頻道', 'en-us' => 'Local Channels', 'ja-jp' => '地方チャンネル', 'ko-kr' => '지역 채널', 'ru-ru' => 'Местные каналы'],
        '港澳台' => ['zh-tw' => '港澳台', 'en-us' => 'HK · Macau · Taiwan', 'ja-jp' => '港澳台', 'ko-kr' => '홍콩·마카오·대만', 'ru-ru' => 'Гонконг, Макао, Тайвань'],
        '游客' => ['zh-tw' => '遊客', 'en-us' => 'Guest', 'ja-jp' => 'ゲスト', 'ko-kr' => '게스트', 'ru-ru' => 'Гость'],
        '默认会员' => ['zh-tw' => '預設會員', 'en-us' => 'Regular Member', 'ja-jp' => '一般会員', 'ko-kr' => '일반 회원', 'ru-ru' => 'Стандартный участник'],
        'VIP会员' => ['zh-tw' => 'VIP會員', 'en-us' => 'VIP Member', 'ja-jp' => 'VIP会員', 'ko-kr' => 'VIP 회원', 'ru-ru' => 'VIP-участник'],
        '免费短剧电影电视剧、小说漫画 - 最新全集在线观看' => [
            'zh-tw' => '免費短劇電影電視劇、小說漫畫 - 最新全集線上看',
            'en-us' => 'Free Short Dramas, Movies & TV Series, Novels and Manga - Watch Online',
            'ja-jp' => '無料ショートドラマ・映画・ドラマ・小説・漫画 - 最新話をオンライン視聴',
            'ko-kr' => '무료 숏드라마·영화·드라마·소설·만화 - 최신 전편 온라인 시청',
            'ru-ru' => 'Бесплатные короткие драмы, фильмы, сериалы, романы и манга — смотрите онлайн',
        ],
    ];

    return isset($map[$text][$lang]) ? $map[$text][$lang] : $text;
}

function mac_front_string($key, $default = '')
{
    $key = (string) $key;
    if ($key === '') {
        return $default;
    }
    static $packs = [];
    $lang = \app\common\model\ContentLang::getCurrent();
    if (!isset($packs[$lang])) {
        $meta = mac_lang_meta($lang);
        $ui = !empty($meta['ui']) ? $meta['ui'] : '';
        $file = $ui === '' ? '' : ROOT_PATH . 'template/default/asset/language/strings_' . $ui . '.properties';
        $packs[$lang] = [];
        if ($file !== '' && is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                        continue;
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $k = trim(substr($line, 0, $pos));
                    if ($k !== '') {
                        $packs[$lang][$k] = trim(substr($line, $pos + 1));
                    }
                }
            }
        }
    }
    return isset($packs[$lang][$key]) && $packs[$lang][$key] !== '' ? $packs[$lang][$key] : $default;
}

function mac_content_lang_default()
{
    $val = $GLOBALS['config']['app']['content_lang_default'] ?? '';
    return in_array($val, mac_content_lang_codes(), true) ? $val : mac_content_lang_first();
}

function mac_content_lang_first()
{
    $codes = mac_content_lang_codes();
    return empty($codes) ? '' : $codes[0];
}

function mac_content_lang_allow_list()
{
    $raw = $GLOBALS['config']['app']['content_lang_list'] ?? '';
    if (empty($raw)) {
        return [];
    }
    return array_values(array_intersect(mac_content_lang_codes(), array_filter(array_map('trim', explode('|', $raw)))));
}

function mac_front_lang_list()
{
    $urlBase = (defined('MAC_PATH_TEMPLATE') && defined('MAC_PATH')) ? MAC_PATH_TEMPLATE : '';
    $dirBase = $urlBase === '' ? '' : ROOT_PATH . substr($urlBase, strlen(MAC_PATH));

    $langDir = ROOT_PATH . 'template/default/asset/language/';

    $current = \app\common\model\ContentLang::getCurrent();

    $list = [];
    foreach (mac_content_lang_allow_list() as $code) {
        $meta = mac_lang_meta($code);
        if ($meta === null) {
            continue;
        }
        $flag = '';
        if ($dirBase !== '') {
            foreach ($meta['flag_pngs'] as $png) {
                if (is_file($dirBase . 'images/la/' . $png . '.png')) {
                    $flag = $urlBase . 'images/la/' . $png . '.png';
                    break;
                }
            }
        }
        $list[] = [
            'code'   => $code,
            'name'   => $meta['name'],
            'emoji'  => $meta['flag'],
            'legacy' => $meta['legacy'],
            'flag'   => $flag,
            'ui'     => $meta['ui'] !== '' && is_file($langDir . 'strings_' . $meta['ui'] . '.properties') ? $meta['ui'] : '',
            'active' => $code === $current ? 1 : 0,
        ];
    }
    return $list;
}

function mac_get_content_lang()
{
    $default = mac_content_lang_default();
    $allow   = mac_content_lang_allow_list();
    if (empty($allow)) {
        return $default;
    }

    $cookieName = 'mac_content_lang';
    $candidate  = '';
    $fromGet    = false;

    $get = input('get.lang', '');
    if (!empty($get)) {
        $candidate = strtolower($get);
        $fromGet   = true;
    } elseif (!empty($_COOKIE[$cookieName])) {
        $candidate = strtolower($_COOKIE[$cookieName]);
    } elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        preg_match('/^([a-z\d\-]+)/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $matches);
        $candidate = isset($matches[1]) ? strtolower($matches[1]) : '';
    }

    if (empty($candidate) || !in_array($candidate, $allow, true)) {
        $candidate = $default;
    }

    if ($fromGet && $candidate !== ($_COOKIE[$cookieName] ?? '')) {
        try {
            setcookie($cookieName, $candidate, time() + 86400 * 30, '/');
        } catch (\Exception $e) {
        }
    }

    return $candidate;
}

function mac_content_lang_overlay($contentType, array $row, $lang = null)
{
    if (empty(mac_content_lang_allow_list())) {
        return $row;
    }
    $lang = $lang ?: \app\common\model\ContentLang::getCurrent();
    if ($lang === mac_content_lang_default()) {
        return $row;
    }
    $fields = mac_content_lang_fields($contentType);
    if (empty($fields)) {
        return $row;
    }
    $contentId = $row[$contentType . '_id'] ?? 0;
    if (empty($contentId)) {
        return $row;
    }
    $translated = (new \app\common\model\ContentLang())->getFields($contentType, $contentId, $lang);
    if (empty($translated)) {
        return $row;
    }
    foreach ($fields as $field) {
        if (!empty($translated[$field])) {
            $row[$field] = $translated[$field];
        }
    }
    return $row;
}

function mac_content_translate_available()
{
    if (\think\facade\Event::hasListener('content_translate')) {
        return true;
    }
    return \app\common\util\Translate::available();
}

function mac_content_translate($text, $from, $to, $context = [])
{
    if (trim((string)$text) === '') {
        return '';
    }
    if (mac_content_translate_available()) {
        $params = [
            'text'    => $text,
            'from'    => $from,
            'to'      => $to,
            'context' => $context,
        ];
        try {
            $result = \think\facade\Event::trigger('content_translate', $params, true);
        } catch (\Exception $e) {
            $result = null;
        }
        if (is_string($result) && $result !== '') {
            return $result;
        }
    }
    if (\app\common\util\Translate::available()) {
        $result = \app\common\util\Translate::translate($text, $from, $to);
        return is_string($result) ? $result : '';
    }
    return '';
}

function mac_content_translate_rate_limit($adminId, $perMinute = 40, $perHour = 600)
{
    if (!mac_content_translate_available()) {
        return true;
    }
    $adminId = (int)$adminId;
    if ($adminId <= 0) {
        return false;
    }
    $minKey  = 'admin_content_translate_rl_min:'  . $adminId . ':' . (int)floor(time() / 60);
    $hourKey = 'admin_content_translate_rl_hour:' . $adminId . ':' . (int)floor(time() / 3600);
    $nMin  = (int)\think\facade\Cache::get($minKey, 0);
    if ($nMin >= $perMinute) {
        return false;
    }
    $nHour = (int)\think\facade\Cache::get($hourKey, 0);
    if ($nHour >= $perHour) {
        return false;
    }
    \think\facade\Cache::set($minKey, $nMin + 1, 70);
    \think\facade\Cache::set($hourKey, $nHour + 1, 3700);
    return true;
}

function mac_config_lang_overlay(array $item, array $fields, $lang = null)
{
    $lang = $lang ?: \app\common\model\ContentLang::getCurrent();
    if ($lang === mac_content_lang_default()) {
        return $item;
    }
    if (empty($item['content_lang'][$lang]) || !is_array($item['content_lang'][$lang])) {
        return $item;
    }
    foreach ($fields as $field) {
        if (!empty($item['content_lang'][$lang][$field])) {
            $item[$field] = $item['content_lang'][$lang][$field];
        }
    }
    return $item;
}

function mac_ui_lang()
{
    $set = strtolower((string)\think\facade\Lang::getLangSet());
    if ($set === '') {
        return 'zh-CN';
    }
    $parts = explode('-', $set);
    $first = array_shift($parts);
    return empty($parts) ? $first : $first . '-' . strtoupper(implode('-', $parts));
}

function mac_html_lang()
{
    $parts = explode('-', strtolower((string)\app\common\model\ContentLang::getCurrent()));
    $first = array_shift($parts);
    return empty($parts) ? $first : $first . '-' . strtoupper(implode('-', $parts));
}

function mac_ui_lang_dir()
{
    $set = strtolower((string)\think\facade\Lang::getLangSet());
    $meta = mac_lang_meta($set);
    if ($meta !== null) {
        return $meta['dir'] === 'rtl' ? 'rtl' : 'ltr';
    }
    $rtl = ['ar', 'fa', 'he', 'ur', 'ps', 'ku', 'sd', 'ug', 'yi', 'dv'];
    foreach ($rtl as $p) {
        if ($set === $p || strpos($set, $p . '-') === 0) {
            return 'rtl';
        }
    }
    return 'ltr';
}

function mac_asset_ver($path)
{
    $file = ROOT_PATH . 'static/' . ltrim((string) $path, '/');
    $time = is_file($file) ? (int) filemtime($file) : 0;
    if ($time > 0) {
        return (string) $time;
    }
    $ver = config('version');
    return isset($ver['code']) ? (string) $ver['code'] : 'v10';
}

function mac_tpl_asset_ver($path)
{
    $base = realpath(ROOT_PATH . 'template' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'asset');
    $rel = str_replace('\\', '/', (string) $path);
    $file = $base === false ? false : realpath($base . '/' . ltrim($rel, '/'));
    if ($file !== false && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $base) . '/') === 0
        && is_file($file)) {
        $time = (int) filemtime($file);
        if ($time > 0) {
            return (string) $time;
        }
    }
    $ver = config('version');
    return isset($ver['code']) ? (string) $ver['code'] : 'v10';
}
