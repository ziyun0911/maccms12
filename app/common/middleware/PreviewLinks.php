<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 主题设计预览中间件
 * （由 TP5 的 common\behavior\PreviewLinks 迁移）
 *
 * 仅在前台（ENTRANCE=index）且带 td_preview 时，向响应体注入内联脚本，
 * 使预览 iframe 内的站内链接保持 td_preview/td_theme 参数。
 */
class PreviewLinks
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (!defined('ENTRANCE') || ENTRANCE !== 'index') {
            return $response;
        }
        if (empty($_GET['td_preview'])) {
            return $response;
        }
        if (!$response instanceof Response) {
            return $response;
        }
        $content = $response->getContent();
        if (!is_string($content) || $content === '' || stripos($content, '</body>') === false) {
            return $response;
        }
        $theme = isset($_GET['td_theme']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['td_theme']) : '';

        $js = '<script>(function(){'
            . 'var TH=' . json_encode($theme) . ';'
            . 'document.addEventListener("click",function(e){'
            . 'var a=e.target&&e.target.closest?e.target.closest("a"):null;if(!a)return;'
            . 'var h=a.getAttribute("href");if(!h||h.charAt(0)==="#")return;'
            . 'if(/^(javascript:|mailto:|tel:|data:)/i.test(h))return;'
            . 'var u;try{u=new URL(a.href,location.href);}catch(x){return;}'
            . 'if(u.origin!==location.origin)return;'
            . 'if(u.searchParams.get("td_preview"))return;'
            . 'u.searchParams.set("td_preview","1");if(TH){u.searchParams.set("td_theme",TH);}'
            . 'a.setAttribute("href",u.pathname+u.search+u.hash);'
            . '},true);'
            . '})();</script>';

        $response->content(str_ireplace('</body>', $js . '</body>', $content));

        return $response;
    }
}
