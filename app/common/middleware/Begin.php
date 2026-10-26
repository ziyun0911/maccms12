<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

class Begin
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
