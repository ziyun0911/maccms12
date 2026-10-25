<?php
namespace app\common\middleware;

use Closure;
use think\Request;

/**
 * 已启用插件的 app_init 钩子。
 */
class AddonInit
{
    public function handle(Request $request, Closure $next)
    {
        addon_hook('app_init');
        return $next($request);
    }
}
