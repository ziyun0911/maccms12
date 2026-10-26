<?php
namespace app\admin\controller;

use think\Request;
use app\common\util\OpenApiSpec;

class ApiDoc extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    public function index()
    {
        $this->assign('title', lang('admin/apidoc/title'));
        $this->assign('api_base', $this->apiBaseUrl());
        return $this->fetch('apidoc/index');
    }

    public function openapi()
    {
        $base_url = $this->apiBaseUrl();
        $cache_key = 'apidoc_openapi_' . md5($base_url);
        $spec = \think\facade\Cache::get($cache_key);
        if (empty($spec) || !is_array($spec)) {
            $builder = new OpenApiSpec();
            $spec = $builder->build($base_url);
            \think\facade\Cache::set($cache_key, $spec, 3600);
        }

        return json($spec, 200, [], [
            'json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ]);
    }

    private function apiBaseUrl()
    {
        $request = request();
        $base = $request->root();
        if (strpos($base, '.') !== false) {
            $dir = rtrim(dirname($base), '/\\');
        } else {
            $dir = rtrim($base, '/');
        }
        if ($dir === '.' || $dir === '\\' || $dir === '/') {
            $dir = '';
        }
        $domain = $request->domain();
        if (empty($domain)) {
            return ($dir === '' ? '' : $dir) . '/api.php';
        }
        return $domain . $dir . '/api.php';
    }
}
