<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 入口/模块守卫中间件
 *
 * TP8 多应用模式下，入口文件已通过 http->name() 绑定应用
 * （admin.php→admin、api.php→api、install.php→install、index.php→index），
 * 无需再按 dispatch 做模块重定向。此处保留一个极轻量守卫，保证接口形态一致。
 */
class Begin
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
