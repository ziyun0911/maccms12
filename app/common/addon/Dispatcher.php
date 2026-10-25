<?php
namespace app\common\addon;

use think\exception\HttpException;

/**
 * /addons/{addon}/{controller}/{action} 请求分发。
 */
class Dispatcher
{
    public function run($addon = '', $controller = 'index', $action = 'index')
    {
        $addon      = strtolower(trim((string)$addon));
        $controller = trim((string)$controller);
        $action     = trim((string)$action);

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $addon)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $controller)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $action)) {
            throw new HttpException(404, 'addon not found');
        }

        $info = addon_info($addon);
        if (empty($info['state'])) {
            throw new HttpException(404, 'addon not found');
        }

        $class = 'addons\\' . $addon . '\\controller\\' . ucfirst($controller);
        if (!class_exists($class)) {
            throw new HttpException(404, 'addon controller not found');
        }

        $instance = new $class();
        if (!method_exists($instance, $action)) {
            throw new HttpException(404, 'addon action not found');
        }

        $instance->_initialize();
        $result = $instance->{$action}();

        return $result instanceof \think\Response ? $result : response($result);
    }
}
