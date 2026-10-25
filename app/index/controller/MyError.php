<?php
namespace app\index\controller;

/**
 * config/route.php 的 empty_controller 指向这里：前台未命中的控制器由本类兜底，
 * 动作分发交给 app\common\controller\All::__call（渲染主题 404 页），
 * 否则 TP8 会抛「controller not exists」调试页，把 GET/POST/Cookie/Session 全打印出来。
 * 继承 Base 才能拿到前台的 maccms 变量、内容语言与用户身份，错误页才有完整站点外观。
 */
class MyError extends Base
{
}
