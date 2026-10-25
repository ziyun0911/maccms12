<?php
namespace app\common\model;

use think\facade\Log;
use app\common\util\Image as Gd;

class Image extends Base {

    public function down_load($url, $config, $flag = 'vod')
    {
        if (substr($url, 0, 4) == 'http') {
            return $this->down_exec($url, $config, $flag);
        } else {
            return $url;
        }
    }

    public function down_exec($url, $config, $flag = 'vod')
    {
        $upload_image_ext = 'jpg,jpeg,png,gif,webp';
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        if (!in_array($ext, explode(',', $upload_image_ext))) {
            $ext = 'jpg';
        }
        // 远程图片来自采集源等第三方主机，可能自签/过期/域名不匹配，显式关闭校验以保兼容。
        $img = mac_curl_get($url, [], '', false);
        if (empty($img) || strlen($img) < 10) {
            return $url . '#err';
        }
        $file_name = md5(uniqid()) .'.' . $ext;
        // 上传附件路径
        $_upload_path = ROOT_PATH . 'upload' . '/' . $flag . '/';
        // 附件访问路径
        $_save_path = 'upload'. '/' . $flag . '/' ;
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

        $_upload_path .= $n_dir . '/';
        $_save_path .= $n_dir . '/';

        //附件访问地址
        $_file_path = $_save_path.$file_name;
        //写入文件
        $saved_img_path = $_upload_path . $file_name;
        $r = mac_write_file($saved_img_path, $img);
        if(!$r){
            return $url;
        }
        // 重新获取文件类型，不满足时，返回老链接
        $image_info = getimagesize($saved_img_path);
        $extension_hash = [
            '1'  => 'gif',
            '2'  => 'jpg',
            '3'  => 'png',
            '18' => 'webp',
        ];
        if (!isset($image_info[2]) || !isset($extension_hash[$image_info[2]])) {
            return $url . '#err';
        }
        $file_size = filesize($_upload_path.$file_name);
        // 水印
        if ($config['watermark'] == 1) {
            $this->watermark($_file_path,$config,$flag);
        }
        // 缩略图
        if ($config['thumb'] == 1) {
            $this->makethumb($_file_path,$config,$flag);
        }
        //上传到远程
        $_file_path = (new \app\common\model\Upload())->api($_file_path, $config);

        $tmp = $_file_path;
        if (str_starts_with($tmp, '/upload')) {
            $tmp = substr($tmp,1);
        }
        if (str_starts_with($tmp, 'upload')) {
            $annex = [];
            $annex['annex_file'] = $tmp;
            $annex['annex_type'] = 'image';
            $annex['annex_size'] = $file_size;
            (new \app\common\model\Annex())->saveData($annex);
        }
        return $_file_path;
    }

    public function watermark($file_path,$config,$flag='vod')
    {
        if(empty($config['watermark_font'])){
            $config['watermark_font'] = './static/font/test.ttf';
        }
        $content = (string)($config['watermark_content'] ?? '');
        if ($content === '') {
            return;
        }
        try {
            Gd::open('./' . $file_path)
                ->text($content, $config['watermark_font'], (int)($config['watermark_size'] ?? 16),
                    (string)($config['watermark_color'] ?? '#000000'), (int)($config['watermark_location'] ?? 9))
                ->save('./' . $file_path);
        }
        catch(\Throwable $e){
            // 原图已被上传成功，水印失败只降级不中断，但必须留痕
            Log::error('Image::watermark failed file=' . $file_path . ' err=' . $e->getMessage());
        }
    }

    public function makethumb($file_path,$config,$flag='vod',$new=1)
    {
        $thumb_type = (int)($config['thumb_type'] ?? 1);
        $data['thumb'] = [];
        if (!empty($config['thumb_size'])) {
            // 支持多种尺寸的缩略图
            $thumbs = explode(',', $config['thumb_size']);
            foreach ($thumbs as $v) {
                $t_size = explode('x', strtolower(trim($v)));
                $w = (int)$t_size[0];
                $h = (int)($t_size[1] ?? $t_size[0]);
                if ($w <= 0 || $h <= 0) {
                    continue;
                }
                $new_thumb = $file_path . '_' . $w . 'x' . $h . '.' . strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                if($new==0){
                    $new_thumb = $file_path;
                }
                // 每个尺寸都重新打开原图：thumb() 会就地改掉画布，复用同一个对象会让后面的
                // 尺寸基于上一级的缩略图再生成，越缩越糊。
                try {
                    $target = Gd::locate('./' . $new_thumb);
                    Gd::open('./' . $file_path)->thumb($w, $h, $thumb_type)->save($target);

                    $data['thumb'][] = [
                        'type'  => 'image',
                        'flag'  => $flag,
                        'file'  => $new_thumb,
                        'size'  => round((filesize($target) ?: 0) / 1024, 2),
                        'ctime' => request()->time(),
                    ];
                }
                catch(\Throwable $e){
                    Log::error('Image::makethumb failed file=' . $file_path . ' size=' . $w . 'x' . $h
                        . ' err=' . $e->getMessage());
                    continue;
                }

                if (($config['watermark'] ?? 0) == 1 && !empty($config['watermark_content'])) {// 开启文字水印
                    try {
                        Gd::open($target)
                            ->text((string)$config['watermark_content'], $config['watermark_font'] ?? './static/font/test.ttf',
                                (int)($config['watermark_size'] ?? 16), (string)($config['watermark_color'] ?? '#000000'))
                            ->save($target);
                    }
                    catch(\Throwable $e){
                        Log::error('Image::makethumb watermark failed file=' . $new_thumb . ' err=' . $e->getMessage());
                    }
                }
            }
        }
        return $data;
    }




}