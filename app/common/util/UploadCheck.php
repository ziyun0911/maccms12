<?php
namespace app\common\util;

use think\file\UploadedFile;

class UploadCheck
{
    public static function checkError($file, array $exts, int $max_size = 0): string
    {
        if (!($file instanceof UploadedFile)) {
            return lang('admin/upload/no_input_file');
        }
        if (!$file->isValid()) {
            return lang('admin/upload/upload_faild');
        }
        if ($max_size > 0 && $file->getSize() > $max_size) {
            return lang('admin/upload/size_err');
        }
        if (!in_array(strtolower($file->getOriginalExtension()), $exts, true)) {
            return lang('admin/upload/forbidden_ext');
        }

        return '';
    }
}
