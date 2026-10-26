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

    /**
     * 优先认会话级稳定令牌（mac_admin_csrf_token 写入的 Session['admin_csrf']），认不出才退回框架的一次性令牌。
     *
     * 框架的 checkToken 验证通过即销毁 Session 里的 __token__，同页第二次保存会拿已消费的旧值重发；
     * 而 validate() 助手默认 failException=true，check() 直接抛 ValidateException，后台 AJAX 收到 500 调试页。
     * 稳定令牌不消耗，连续保存与多标签页不会互相失效。
     */
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
