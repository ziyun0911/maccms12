<?php
namespace app\index\controller;

class Live extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function show()
    {
        return $this->label_fetch('live/show');
    }

    public function play()
    {
        $param = mac_param_url();
        $live_id = 0;
        if (isset($param['id'])) {
            $live_id = (int)$param['id'];
        }
        $this->assign('param', $param);
        $this->assign('live_id', $live_id);
        return $this->label_fetch('live/play');
    }
}
