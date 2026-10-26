<?php
namespace app\common\util;

class RequestXssSanitizer
{
    public static function sanitizeDeep($data)
    {
        if (is_string($data)) {
            return self::cleanString($data);
        }
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = self::sanitizeDeep($v);
        }

        return $out;
    }

    public static function cleanString($s)
    {
        if ($s === '' || !is_string($s)) {
            return $s;
        }
        $s = str_replace("\0", '', $s);
        $s = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $s);
        $s = preg_replace('/<\s*script\b[^>]*>/i', '', $s);
        $s = preg_replace('/<\s*\/\s*script\s*>/i', '', $s);
        $s = preg_replace('/<\s*iframe\b[^>]*>.*?<\s*\/\s*iframe\s*>/is', '', $s);
        $s = preg_replace('/<\s*iframe\b[^>]*>/i', '', $s);
        $s = preg_replace('/\bon\w+\s*=/iu', 'data-blocked=', $s);
        $s = preg_replace('/\bjavascript\s*:/iu', 'blocked:', $s);
        $s = preg_replace('/\bvbscript\s*:/iu', 'blocked:', $s);
        $s = preg_replace('/\bdata\s*:\s*text\s*\/\s*html/is', 'blocked:', $s);

        return $s;
    }
}
