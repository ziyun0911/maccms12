<?php
namespace app\common\util;

use think\file\UploadedFile;

/**
 * 上传文件的体积/扩展名校验。
 * TP8 的 UploadedFile 删掉了 TP5 的 rule()/validate()/checkExt()/getError()，
 * 后台几个「上传后读文件」的入口各自都要做同一套判断，集中在这里避免五份写法漂移。
 */
class UploadCheck
{
    /**
     * @param mixed      $file     request()->file() 的返回值
     * @param array      $exts     允许的扩展名（小写，不含点）
     * @param int        $max_size 字节上限，0 表示不限制
     * @return string 通过为空串，失败为可直接展示的提示文案
     */
    public static function checkError($file, array $exts, int $max_size = 0): string
    {
        // 表单名写成 file[] 时 TP8 返回数组，直接往下调方法会 fatal
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
