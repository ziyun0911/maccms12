<?php
namespace app\api\controller;

/**
 * API 未命中控制器的兜底（config/route.php 的 empty_controller）。
 * 动作不存在时由 app\common\controller\All::__call 返回 {code:1001} JSON + HTTP 404，
 * 与接口统一的失败结构一致，不再吐框架调试页。
 */
class MyError extends Base
{
}
