<?php
namespace app\common\validate;
use think\facade\Session;
use think\Validate;

class Token extends Validate
{
    protected $rule =   [
        '__token__'  =>  'require|token',
    ];

    protected $message  =   [
        '__token__.require' => 'illegal_request',
        '__token__.token'   => 'token_err'
    ];

    public function token($value, string $rule, array $data): bool
    {
        $stable = Session::get('admin_csrf');
        if (is_string($stable) && $stable !== '') {
            $body = is_string($value) ? $value : '';
            if ($body !== '' && hash_equals($stable, $body)) {
                return true;
            }
            $header = $this->request ? $this->request->header('X-CSRF-Token') : '';
            $header = is_string($header) ? $header : '';
            if ($header !== '' && hash_equals($stable, $header)) {
                return true;
            }
        }

        return parent::token($value, $rule, $data);
    }
}
