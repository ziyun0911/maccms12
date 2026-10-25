<?php
namespace app\install\controller;

use app\common\controller\All;

/**
 * 安装模块未命中控制器的兜底（config/route.php 的 empty_controller）。
 * All::__call 对非前台入口返回 {code:1001} JSON + HTTP 404，避免框架调试页暴露请求数据。
 */
class MyError extends All
{
}
