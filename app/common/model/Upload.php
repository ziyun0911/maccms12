<?php
namespace app\common\model;

use app\common\util\Ftp as ftpOper;
use app\common\util\Image as Gd;
use think\facade\Log;

class Upload extends Base {
    public function api($file_path,$config)
    {
        if(empty($config)){
            return $file_path;
        }

        if ($config['mode'] == '2') {
            $config['mode'] = 'upyun';
        }
        elseif ($config['mode'] == '3'){
            $config['mode'] = 'qiniu';
        }
        elseif ($config['mode'] == '4') {
            $config['mode'] = 'ftp';
        }
        elseif ($config['mode'] == '5') {
            $config['mode'] = 'weibo';
        }

        if(!in_array($config['mode'],['local','remote'])){
            $cp = 'app\\common\\extend\\upload\\' . ucfirst($config['mode']);
            if (class_exists($cp)) {
                $c = new $cp($config);
                $file_path = $c->submit($file_path);
            }
        }

        return str_replace(['http:','https:'],'mac:',$file_path);
    }

    public function upload($p=[])
    {
        $param = input();
        if(!empty($p)){
            $param = array_merge($param,$p);
        }

        $param['from'] = empty($param['from']) ? '' : $param['from'];
        $param['input'] = empty($param['input']) ? 'file' : $param['input'];
        $param['flag'] = empty($param['flag']) ? 'vod' : $param['flag'];
        $param['thumb'] = empty($param['thumb']) ? '0' : $param['thumb'];
        $param['thumb_class'] = empty($param['thumb_class']) ? '' : $param['thumb_class'];
        $param['user_id'] = empty($param['user_id']) ? '0' : $param['user_id'];
        $base64_img = $param['imgdata'] ?? '';
        $data = [];
        $config = (array)config('maccms.site');
        $pre= $config['install_dir'];
        $upload_image_ext = 'jpg,jpeg,png,gif,webp';
        $upload_file_ext = 'doc,docx,xls,xlsx,ppt,pptx,pdf,wps,txt,rar,zip,torrent';
        $upload_media_ext = 'rm,rmvb,avi,mkv,mp4,mp3';
        $add_rnd = false;
        $config = (array)config('maccms.upload');

        if(!empty($param['from'])){
            $cp = 'app\\common\\extend\\editor\\' . ucfirst($param['from']);
            if (class_exists($cp)) {
                $c = new $cp;
                $c->front($param);
            }
            else{
                return self::upload_return(lang('admin/upload/not_find_extend'), '');
            }
        }
        else{
            $pre='';
        }

        $_upload_path = ROOT_PATH . 'upload' . '/' . $param['flag'] . '/' ;
        $_save_path = 'upload'. '/' . $param['flag'] . '/';
        if($param['flag']=='user'){
            $uniq = $param['user_id'] % 10;
            $_upload_path .= $uniq .'/';
            $_save_path .= $uniq .'/';
            $_save_name = $param['user_id'] . '.jpg';

            if(!is_dir($_upload_path)){
                @mkdir($_upload_path, 0755, true);
            }
        }
        else{
            $ymd = date('Ymd');
            $n_dir = $ymd;
            for($i=1;$i<=100;$i++){
                $n_dir = $ymd .'-'.$i;
                $path1 = $_upload_path . $n_dir. '/';
                if(file_exists($path1)){
                    $farr = glob($path1.'*.*');
                    if($farr){
                        $fcount = count($farr);
                        if($fcount>999){
                            continue;
                        }
                        else{
                            break;
                        }
                    }
                    else{
                        break;
                    }
                }
                else{
                    break;
                }
            }
            $_save_name = $n_dir . '/' . md5(microtime(true));
        }

        if(!empty($base64_img)){
            if(preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_img, $result)){
                $img_ext = strtolower($result[2]);
                if(in_array($img_ext, explode(',', $upload_image_ext))){
                    $type = 'image';
                    if (strpos($_save_name, '.') === false) {
                        $_save_name .= '.' . $img_ext;
                    }
                    $abs_path = Gd::locate($_save_path . $_save_name, false);
                    if (!is_dir(dirname($abs_path))) {
                        @mkdir(dirname($abs_path), 0755, true);
                    }
                    if(!file_put_contents($abs_path, base64_decode(str_replace($result[1], '', $base64_img)))){
                        return self::upload_return(lang('admin/upload/upload_faild'), $param['from']);
                    }
                    $file_size = round(filesize($abs_path)/1024, 2);
                }
                else {
                    return self::upload_return(lang('admin/upload/forbidden_ext'), $param['from']);
                }
            }
            else{
                return self::upload_return(lang('admin/upload/no_input_file'), $param['from']);
            }
        }
        else {
            $file = request()->file($param['input']);
            if (empty($file)) {
                return self::upload_return(lang('admin/upload/no_input_file'), $param['from']);
            }
            if ($file->getMime() == 'text/x-php') {
                return self::upload_return(lang('admin/upload/forbidden_ext'), $param['from']);
            }

            $ext = strtolower($file->getOriginalExtension());
            if (in_array($ext, explode(',', $upload_image_ext))) {
                $type = 'image';
            } elseif (in_array($ext, explode(',', $upload_file_ext))) {
                $type = 'file';
            } elseif (in_array($ext, explode(',', $upload_media_ext))) {
                $type = 'media';
            } else {
                return self::upload_return(lang('admin/upload/forbidden_ext'), $param['from']);
            }

            $sub_dir = dirname($_save_name);
            if ($sub_dir === '.' || $sub_dir === '') {
                $sub_dir = '';
            }
            $save_dir = rtrim($_upload_path, '/\\') . ($sub_dir === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub_dir));
            if (!is_dir($save_dir) && !@mkdir($save_dir, 0755, true)) {
                Log::error('Upload::upload mkdir failed dir=' . $save_dir);
                return self::upload_return(lang('admin/upload/upload_faild'), $param['from']);
            }
            $save_name = basename($_save_name);
            if (strpos($save_name, '.') === false && $ext !== '') {
                $save_name .= '.' . $ext;
            }
            try {
                $upfile = $file->move($save_dir, $save_name);
            } catch (\Throwable $e) {
                Log::error('Upload::upload move failed name=' . $save_name . ' err=' . $e->getMessage());
                return self::upload_return(lang('admin/upload/upload_faild'), $param['from']);
            }
            $abs_path = $upfile->getPathname();
            if (!is_file($abs_path)) {
                return self::upload_return(lang('admin/upload/upload_faild'), $param['from']);
            }
            $file_size = round($upfile->getSize() / 1024, 2);
            $_save_name = ($sub_dir === '' ? '' : $sub_dir . '/') . $save_name;
            unset($upfile);
        }

        $resource = fopen($abs_path, 'rb');
        $fileSize = filesize($abs_path);
        fseek($resource, 0);
        if ($fileSize>512){
            $hexCode = bin2hex(fread($resource, 512));
            fseek($resource, $fileSize - 512);
            $hexCode .= bin2hex(fread($resource, 512));
        } else {
            $hexCode = bin2hex(fread($resource, $fileSize));
        }
        fclose($resource);
        if(preg_match("/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is", $hexCode)){
            return self::upload_return(lang('admin/upload/upload_safe'), $param['from']);
        }

        $file_count = 1;
        $data = [
            'file'  => $_save_path.$_save_name,
            'type'  => $type,
            'size'  => $file_size,
            'flag' => $param['flag'],
            'ctime' => request()->time(),
            'thumb_class'=>$param['thumb_class'],
        ];

        $data['thumb'] = [];
        if($param['flag']=='user'){
            $add_rnd=true;
            $file = $_save_path . $_save_name;
            $new_thumb = $param['user_id'] .'.jpg';
            $new_file = $_save_path . $new_thumb;
            $t_size = explode('x', strtolower((string)($GLOBALS['config']['user']['portrait_size'] ?? '100x100')));
            $pw = (int)$t_size[0];
            $ph = (int)($t_size[1] ?? $t_size[0]);
            if ($pw <= 0) {
                $pw = $ph;
            }
            if ($ph <= 0) {
                $ph = $pw;
            }
            try {
                $target = Gd::locate('./' . $new_file, false);
                Gd::open('./' . $file)->thumb($pw, $ph, 6)->save($target);
                $file_size = round((filesize($target) ?: 0) / 1024, 2);
            }
            catch(\Throwable $e){
                Log::error('Upload::upload portrait failed file=' . $file . ' err=' . $e->getMessage());
                return self::upload_return(lang('admin/upload/make_thumb_faild'), $param['from']);
            }
            $update = [];
            $update['user_portrait'] = $new_file;
            $where = [];
            $where['user_id'] = $GLOBALS['user']['user_id'];
            $res = (new \app\common\model\User())->where($where)->update($update);
            if ($res === false) {
                return self::upload_return(lang('index/portrait_err'), $param['from']);
            }
        }
        else {
            if ($type == 'image') {
                if ($config['watermark'] == 1) {
                    (new \app\common\model\Image())->watermark($data['file'], $config, $param['flag']);
                }
                if ($param['thumb'] == 1 && $config['thumb'] == 1) {
                    $dd = (new \app\common\model\Image())->makethumb($data['file'], $config, $param['flag']);
                    if (is_array($dd)) {
                        $data = array_merge($data, $dd);
                    }
                }
            }
        }

        if ($config['mode'] == 2) {
            $config['mode'] = 'upyun';
        }
        elseif ($config['mode'] == 3){
            $config['mode'] = 'qiniu';
        }
        elseif ($config['mode'] == 4) {
            $config['mode'] = 'ftp';
        }
        elseif ($config['mode'] == 5) {
            $config['mode'] = 'weibo';
        }

        $config['mode'] = strtolower($config['mode']);

        if(!in_array($config['mode'],['local','remote'])){
            $data['file'] = (new \app\common\model\Upload())->api($data['file'],$config);
            if(!empty($data['thumb'])){
                $data['thumb'][0]['file'] = (new \app\common\model\Upload())->api($data['thumb'][0]['file'],$config);
            }
        }
        if(!empty($param['from'])){
            if(substr($data['file'],0,4)!='http' && substr($data['file'],0,4)!='mac:'){
                $data['file']  =  $pre. $data['file'];
            }
            else{
                $data['file']  = mac_url_content_img($data['file']);
            }
        }

        $tmp = $data['file'];
        if((substr($tmp,0,7) == "/upload")){
            $tmp = substr($tmp,1);
        }
        if((substr($tmp,0,6) == "upload")){
            $annex = [];
            $annex['annex_file'] = $tmp;
            $r = (new \app\common\model\Annex())->infoData($annex);
            if($r['code']!==1){
                $annex['annex_type'] = $type;
                $annex['annex_size'] = $file_size;
                (new \app\common\model\Annex())->saveData($annex);
                $tmp = $data['thumb'][0]['file'];
                if(!empty($tmp)){
                    $file_size = filesize($tmp);
                    $annex = [];
                    $annex['annex_file'] = $tmp;
                    $r = (new \app\common\model\Annex())->infoData($annex);
                    if($r['code']!==1){
                        $annex['annex_type'] = $type;
                        $annex['annex_size'] = $file_size;
                        (new \app\common\model\Annex())->saveData($annex);
                    }
                }
            }
        }
        return self::upload_return(lang('admin/upload/upload_success'), $param['from'], 1, $data);
    }

    private function upload_return($info='',$from='',$status=0,$data=[])
    {
        $arr = [];
        if(!empty($from)){
            $cp = 'app\\common\\extend\\editor\\' . ucfirst($from);
            if (class_exists($cp)) {
                $c = new $cp;
                $arr = $c->back($info,$status,$data);
            }
        }
        elseif(ENTRANCE=='index'){
            $arr['msg'] = $info;
            $arr['code'] = $status;
            $arr['file'] = MAC_PATH .  $data['file'] . '?'. mt_rand(1000, 9999);
        }
        else{
            $arr['msg'] = $info;
            $arr['code'] = $status;
            $arr['data'] = $data;
        }
        return $arr;
    }
}