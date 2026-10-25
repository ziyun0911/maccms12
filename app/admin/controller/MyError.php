<?php
namespace app\admin\controller;

/**
 * 后台未命中控制器的兜底（config/route.php 的 empty_controller）。
 * 继承 Base 后仍走登录校验与权限校验，未登录先跳登录页；动作不存在时由
 * app\common\controller\All::__call 返回后台 jump 错误页，而不是框架调试页。
 */
class MyError extends Base
{
}
