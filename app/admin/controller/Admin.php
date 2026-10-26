<?php
namespace app\admin\controller;
use think\facade\Db;

class Admin extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : $param['page'];
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : $param['limit'];
        $where=[];
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['admin_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='admin_id desc';
        $res = (new \app\common\model\Admin())->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';

        $this->assign('admin',$this->_admin);

        $this->assign('param',$param);
        $this->assign('title',lang('admin/admin/title'));
        return $this->fetch('admin@admin/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            if(!in_array('index/welcome',$param['admin_auth'])){
                $param['admin_auth'][] = 'index/welcome';
            }
            if (in_array('safety/checkup', $param['admin_auth'], true)
                && !in_array('safety/fix', $param['admin_auth'], true)) {
                $param['admin_auth'][] = 'safety/fix';
            }
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            $res = (new \app\common\model\Admin())->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');

        $where=[];
        $where['admin_id'] = ['eq',$id];

        $res = (new \app\common\model\Admin())->infoData($where);
        $this->assign('info',$res['info']);

        //权限列表
        $menus = @include MAC_ADMIN_COMM . 'auth.php';

        foreach($menus as $k1=>$v1){
            $all = [];
            $cs = [];
            $menus[$k1]['ck'] = '';
            foreach($v1['sub'] as $k2=>$v2){
                $one = $v2['controller'] . '/' . $v2['action'];
                $menus[$k1]['sub'][$k2]['url'] = url($one);
                $menus[$k1]['sub'][$k2]['ck']= '';
                $all[] = $one;

                // 内置 auth.php 的节点名已是 lang() 结果；这里兼容第三方写法：
                // "--" 前缀（隐藏标记）去掉后若仍是一个语言键，就替换成当前语言的译名。
                $label = $v2['name'];
                if (strpos($label, '--') === 0) {
                    $label = substr($label, 2);
                }
                if (!preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}]/u', $label)) {
                    $translated = lang($label);
                    if ($translated !== $label) {
                        $label = $translated;
                    }
                }
                $menus[$k1]['sub'][$k2]['name'] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

                if(strpos(','.$res['info']['admin_auth'],$one)>0){
                    $cs[] = $one;
                    $menus[$k1]['sub'][$k2]['ck'] = 'checked';
                }
                // 欢迎页是登录后必达项，权限列表里固定勾选且不可取消；按 controller/action 判定，
                // 不按菜单键数字判定——键会随分组调整重排，数字判断迟早误伤同名键的其他节点。
                if($v2['controller']==='index' && $v2['action']==='welcome'){
                    $menus[$k1]['sub'][$k2]['ck'] = ' checked  readonly="readonly" ';
                }
            }
            if($all == $cs){
                $menus[$k1]['ck'] = 'checked';
            }
        }
        $this->assign('menus',$menus);


        $this->assign('title',lang('admin/admin/title'));
        return $this->fetch('admin@admin/info');
    }

    public function del()
    {
        $param = input();
        $ids = $param['ids'];

        if(!empty($ids)){
            $where=[];
            $where['admin_id'] = ['in',$ids];
            if(!is_array($ids)) {
                $ids = explode(',', $ids);
            }
            if(in_array($this->_admin['admin_id'],$ids)){
                return $this->error(lang('admin/admin/del_cur_err'));
            }
            $res = (new \app\common\model\Admin())->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        $param = input();
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];

        if(!empty($ids) && in_array($col,['admin_status']) && in_array($val,['0','1'])){
            $where=[];
            $where['admin_id'] = ['in',$ids];

            $res = (new \app\common\model\Admin())->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

}
