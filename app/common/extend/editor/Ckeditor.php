<?php
namespace app\common\extend\editor;

class Ckeditor {
    public $name = 'Ckeditor';
    public $ver = '1.0';

    public function front($param)
    {
    }

    public function back($info='',$status=0,$data=[])
    {
        $arr=[];
        if ($status == 1) {
            $arr['uploaded'] = 1;
            $arr['fileName'] = '';
            $arr['url'] = $data['file'];
        } else {
            $arr['uploaded'] = 0;
            $arr['error']['msg'] = $info;
        }
        echo json_encode($arr, 1);
        exit;
    }
}
