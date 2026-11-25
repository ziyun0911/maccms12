<?php
namespace app\common\util;

class HttpClient
{
    /**
     * 本机 PHP 的 php.ini 未配置 curl.cainfo / openssl.cafile，
     * 直接请求外部 HTTPS 会因缺少本地 CA 报 errno 60（unable to get local issuer certificate）。
     * libcurl >= 7.71 可改用系统原生证书存储；这里只切换 CA 来源，不关闭证书校验。
     */
    public static function applyNativeCa($ch)
    {
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            @curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
    }

    public static function curlPostWithTimeout($url, $data, $heads, $timeout, $isPost = true)
    {
        $ch = @curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(2, min(10, intval($timeout))));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, intval($timeout)));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_REFERER, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        self::applyNativeCa($ch);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, 1);
        }
        if (count($heads) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $heads);
        }
        $response = @curl_exec($ch);
        @curl_close($ch);
        return $response;
    }

    /**
     * 与 curlPostWithTimeout 相同的 POST 请求，额外返回 HTTP 状态码与 curl 错误码。
     * 供后台助手把笼统的「无响应」细化为可操作的诊断；不改动原方法的行为。
     */
    public static function curlPostWithDiagnostics($url, $data, $heads, $timeout)
    {
        $out = ['body' => '', 'http_code' => 0, 'errno' => 0, 'error' => ''];
        $ch = @curl_init();
        if ($ch === false) {
            $out['error'] = 'curl_init 失败';
            return $out;
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(2, min(10, intval($timeout))));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, intval($timeout)));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_REFERER, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        self::applyNativeCa($ch);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        if (count($heads) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $heads);
        }
        $body = @curl_exec($ch);
        $out['errno'] = (int)curl_errno($ch);
        $out['error'] = (string)curl_error($ch);
        $out['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        $out['body'] = $body === false ? '' : (string)$body;
        return $out;
    }

    public static function curlGetNoRedirect($url, $timeout, $heads = array())
    {
        $ch = @curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(2, min(10, intval($timeout))));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, intval($timeout)));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        self::applyNativeCa($ch);
        if (is_array($heads) && count($heads) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $heads);
        }
        $response = @curl_exec($ch);
        @curl_close($ch);
        return $response;
    }
}