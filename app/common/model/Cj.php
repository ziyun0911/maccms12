<?php
namespace app\common\model;
use think\facade\Db;

class Cj extends Base {

    public function listData($tab,$where,$order,$page,$limit=20)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $total = Db::name($tab)->where($where)->count();
        $list = Db::name($tab)->where($where)->order($order)->page($page)->limit($limit)->select();
        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function infoData($tab,$where=[],$field='*')
    {
        if(empty($tab) || empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = Db::name($tab)->field($field)->where($where)->find();

        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }

        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        $data['lastdate'] = time();
        try {
            if(!empty($data['nodeid'])){
                $where=[];
                $where['nodeid'] = ['eq',$data['nodeid']];
                $res = Db::name('cj_node')->where($where)->update($data);
            }
            else{
                $data['urlpage'] = isset($data['urlpage']) ? (string)$data['urlpage'] : '';
                $data['page_base'] = isset($data['page_base']) ? (string)$data['page_base'] : '';
                $data['sourcecharset'] = isset($data['sourcecharset']) ? (string)$data['sourcecharset'] : 'utf-8';
                $data['customize_config'] = isset($data['customize_config']) ? (string)$data['customize_config'] : '';
                $data['program_config'] = isset($data['program_config']) ? (string)$data['program_config'] : '';
                $res = Db::name('cj_node')->insert($data);
            }
        } catch (\Throwable $e) {
            return $this->writeFailed('Cj::saveData', $e, 'save_err', 1002);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err')];
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        try {
            //删除node
            $res = Db::name('cj_node')->where($where)->delete();
            //删除history
            $list = Db::name('cj_content')->field('url')->where($where)->select();
            foreach ($list as $k => $v) {
                $md5 = md5($v['url']);
                Db::name('cj_history')->where('md5',$md5)->delete();
            }
            //删除content
            $res = Db::name('cj_content')->where($where)->delete();
        } catch (\Throwable $e) {
            return $this->writeFailed('Cj::delData', $e, 'del_err', 1001);
        }

        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err')];
        }
        return ['code'=>1,'msg'=>lang('del_ok')];
    }


}