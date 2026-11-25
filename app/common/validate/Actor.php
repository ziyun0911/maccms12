<?php
namespace app\common\validate;

use think\Validate;

class Actor extends Validate
{
    protected $rule = [
        'actor_name'     => 'require',
        'type_id'        => 'require',
        // 生日写入校验：必须能归一化为合法的 YYYY-MM-DD
        'actor_birthday' => 'birthdayFormat',
    ];

    protected $message = [
        'actor_name.require'              => 'validate/require_name',
        'type_id.require'                 => 'validate/require_type',
        'actor_birthday.birthdayFormat'   => '生日格式不正确，请填写 YYYY-MM-DD（如 1987-10-16）或留空',
    ];

    /**
     * 自定义验证规则（ThinkPHP 按 Str::camel(规则名) 定位方法，共 5 个参数）。
     * 空值由框架自动跳过（该规则不含 require），此处保留判断便于独立调用。
     * 中文日期、点/斜杠分隔、缺「日」的截断残留等，只要能归一化成合法日期即放行，
     * 由模型层负责转成 YYYY-MM-DD 后再写库。
     */
    protected function birthdayFormat($value, $rule, $data = [], $field = '', $title = ''): bool
    {
        if ($value === '' || $value === null) {
            return true;
        }
        return \mac_normalize_birthday($value) !== '';
    }

    protected $scene = [
        'add'  => ['actor_name', 'type_id', 'actor_birthday'],
        'edit' => ['actor_name', 'type_id', 'actor_birthday'],
    ];
}
