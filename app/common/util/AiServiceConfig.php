<?php
namespace app\common\util;

/**
 * 全站 AI 模块连接信息的唯一解析入口。
 *
 * 服务商信息（provider / api_base / api_key / model）唯一来源是「主题 AI 设置」theme_ai：
 *   - 文本类：ai_search（AI 搜索）、ai_seo（AI SEO / UEditor AI 工具栏）、ai_content（AI 标注）
 *   - 图像类：ai_cover（AI 视频封面），优先用 theme_ai 的 image_* 图像凭据，留空时回退文本凭据
 * 各配置段只保存自己的业务参数（启用开关、超时、尺寸、限流等）。
 * 直读磁盘配置，避免框架配置缓存导致「主题 AI 刚保存、其它模块还用旧服务商」。
 */
class AiServiceConfig
{
    /** 需要跟随主题 AI 文本凭据的配置段 */
    public static function textSections()
    {
        return ['ai_search', 'ai_seo', 'ai_content'];
    }

    /** 需要跟随主题 AI 图像凭据的配置段 */
    public static function imageSections()
    {
        return ['ai_cover'];
    }

    /** 读取磁盘上的全量配置（不依赖框架函数，Web 与命令行均可调用） */
    public static function disk()
    {
        $file = dirname(__DIR__, 2) . '/extra/maccms.php';
        if (!is_file($file)) {
            return [];
        }
        $disk = include $file;
        return is_array($disk) ? $disk : [];
    }

    public static function theme()
    {
        $disk = self::disk();
        return isset($disk['theme_ai']) && is_array($disk['theme_ai']) ? $disk['theme_ai'] : [];
    }

    private static function section($key)
    {
        $disk = self::disk();
        return isset($disk[$key]) && is_array($disk[$key]) ? $disk[$key] : [];
    }

    /** 把完整业务端点回退到基础层级（服务类会自行拼接路径） */
    private static function baseOf($base)
    {
        $base = rtrim(trim((string)$base), '/');
        return preg_replace('#/(chat/completions|messages|images/generations|models)$#i', '', $base);
    }

    /**
     * 由主题 AI 配置生成文本模块的 4 个连接字段。
     * 保存逻辑与运行时读取共用，保证两处口径一致。
     */
    public static function fromTheme($theme)
    {
        $theme = is_array($theme) ? $theme : [];
        $provider = strtolower(trim((string)(isset($theme['provider']) ? $theme['provider'] : 'openai')));
        // 下游服务类按 openai / claude 判断请求格式
        $provider = $provider === 'claude' ? 'claude' : 'openai';

        $base = self::baseOf(isset($theme['api_base']) ? $theme['api_base'] : '');
        $model = trim((string)(isset($theme['model']) ? $theme['model'] : ''));
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }
        return [
            'provider' => $provider,
            'api_base' => $base,
            'api_key' => trim((string)(isset($theme['api_key']) ? $theme['api_key'] : '')),
            'model' => $model,
        ];
    }

    /**
     * 由主题 AI 配置生成图像模块的 4 个连接字段。
     * 图像地址 / 密钥 / 模型单独留空时，回退到同一份文本凭据，保证密钥只有一份来源。
     * provider 固定 openai：AI 视频封面服务类实现的是 OpenAI Images 请求格式。
     */
    public static function fromThemeImage($theme)
    {
        $theme = is_array($theme) ? $theme : [];
        $text = self::fromTheme($theme);

        $imgBase = trim((string)(isset($theme['image_api_base']) ? $theme['image_api_base'] : ''));
        if ($imgBase === '') {
            $imgBase = $text['api_base'];
        }
        $imgKey = trim((string)(isset($theme['image_api_key']) ? $theme['image_api_key'] : ''));
        if ($imgKey === '') {
            $imgKey = $text['api_key'];
        }
        $imgModel = trim((string)(isset($theme['image_model']) ? $theme['image_model'] : ''));
        if ($imgModel === '') {
            $imgModel = 'gpt-image-1';
        }
        return [
            'provider' => 'openai',
            'api_base' => self::baseOf($imgBase),
            'api_key' => $imgKey,
            'model' => $imgModel,
        ];
    }

    /** AI 搜索：业务参数 + 归口后的连接字段 */
    public static function search()
    {
        return array_merge(self::section('ai_search'), self::fromTheme(self::theme()));
    }

    /** AI SEO（含 UEditor AI 工具栏） */
    public static function seo()
    {
        return array_merge(self::section('ai_seo'), self::fromTheme(self::theme()));
    }

    /** AI 内容标注 */
    public static function content()
    {
        return array_merge(self::section('ai_content'), self::fromTheme(self::theme()));
    }

    /** AI 视频封面：使用图像凭据 */
    public static function cover()
    {
        return array_merge(self::section('ai_cover'), self::fromThemeImage(self::theme()));
    }

    /** 保存主题 AI 时，四段各自需要覆盖写入的连接字段 */
    public static function syncRows($theme)
    {
        $text = self::fromTheme($theme);
        $image = self::fromThemeImage($theme);
        $out = [];
        foreach (self::textSections() as $key) {
            $out[$key] = $text;
        }
        foreach (self::imageSections() as $key) {
            $out[$key] = $image;
        }
        return $out;
    }

    /**
     * 把连接字段同步写入一份完整配置数组（供各保存接口复用）。
     * 只覆盖连接字段，不动业务参数；配置段不存在时仅创建连接字段。
     */
    public static function syncAll(array $config, $theme = null)
    {
        if ($theme === null) {
            $theme = isset($config['theme_ai']) && is_array($config['theme_ai'])
                ? $config['theme_ai']
                : self::theme();
        }
        foreach (self::syncRows($theme) as $key => $row) {
            if (!isset($config[$key]) || !is_array($config[$key])) {
                $config[$key] = [];
            }
            $config[$key] = array_merge($config[$key], $row);
        }
        return $config;
    }

    /** 页面只读摘要：密钥只返回是否配置与尾号，不返回完整值 */
    public static function summary($theme = null)
    {
        if ($theme === null) {
            $theme = self::theme();
        }
        $text = self::fromTheme($theme);
        $image = self::fromThemeImage($theme);
        return [
            'provider' => $text['provider'],
            'api_base' => $text['api_base'],
            'model' => $text['model'],
            'key_saved' => $text['api_key'] !== '' ? 1 : 0,
            'key_tail' => $text['api_key'] !== '' ? substr($text['api_key'], -6) : '',
            'cover_provider' => 'openai',
            'cover_api_base' => $image['api_base'],
            'cover_model' => $image['model'],
            'cover_key_saved' => $image['api_key'] !== '' ? 1 : 0,
            'cover_key_tail' => $image['api_key'] !== '' ? substr($image['api_key'], -6) : '',
        ];
    }
}
