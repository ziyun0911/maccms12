<?php
namespace app\common\middleware;

use Closure;
use think\Request;

class AddonInit
{
    public function handle(Request $request, Closure $next)
    {
        addon_hook('app_init');
        return $next($request);
    }
}
