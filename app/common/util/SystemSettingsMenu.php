<?php

namespace app\common\util;

class SystemSettingsMenu
{
    public static function get($config, $path, $default = null)
    {
        $node = $config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public static function featureState($enabled, $configured)
    {
        if (!$enabled) {
            return 'off';
        }
        return $configured ? 'on' : 'warn';
    }

    public static function tip($key, $limit = 140)
    {
        $text = lang($key);
        if (!is_string($text) || $text === '' || $text === $key) {
            return '';
        }
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', $text);
        $text = preg_replace('/\{\$[^}]*\}/u', '', $text);
        if (!is_string($text)) {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') > $limit) {
            $text = mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }
        return $text;
    }

    public static function build($config, ?callable $can = null)
    {
        $groupNames = [
            'base'    => lang('admin/system/config/base'),
            'user'    => lang('menu/configuser'),
            'content' => lang('menu/configcollect'),
            'media'   => lang('menu/configupload'),
            'connect' => lang('menu/configconnect'),
            'notify'  => lang('menu/configemail'),
            'ai'      => lang('admin/system/config/ai_section'),
            'system'  => lang('menu/system'),
        ];
        $stateText = [
            'on'   => lang('admin/safety/checkup_status_on'),
            'off'  => lang('admin/safety/checkup_status_off'),
            'warn' => lang('admin/safety/checkup_badge_warn'),
        ];

        $commentOn = (string)self::get($config, 'comment.status', '0') === '1'
            || (string)self::get($config, 'gbook.status', '0') === '1';

        $interfaceOn = (string)self::get($config, 'interface.status', '0') === '1';
        $apiOn = false;
        foreach (['vod', 'art', 'actor', 'role', 'website', 'manga', 'publicapi'] as $module) {
            if ((string)self::get($config, 'api.' . $module . '.status', '0') === '1') {
                $apiOn = true;
                break;
            }
        }

        $uploadMode = trim((string)self::get($config, 'upload.mode', ''));
        $rewriteOn = (string)self::get($config, 'rewrite.status', '0') === '1';

        $weixinOn = (string)self::get($config, 'weixin.status', '0') === '1';

        $emailType = trim((string)self::get($config, 'email.type', 'phpmailer'));
        $emailHost = $emailType !== ''
            ? trim((string)self::get($config, 'email.' . $emailType . '.host', ''))
            : '';
        $smsType = trim((string)self::get($config, 'sms.type', ''));
        $smsReady = false;
        if ($smsType !== '') {
            $smsCfg = self::get($config, 'sms.' . $smsType, []);
            if (is_array($smsCfg)) {
                foreach ($smsCfg as $value) {
                    if (is_scalar($value) && trim((string)$value) !== '') {
                        $smsReady = true;
                        break;
                    }
                }
            }
        }
        $pushOn = (string)self::get($config, 'push.enable', '0') === '1';
        $pushReady = trim((string)self::get($config, 'push.vapid_public', '')) !== ''
            && trim((string)self::get($config, 'push.vapid_private', '')) !== '';

        $seoOn = (string)self::get($config, 'ai_seo.enabled', '0') === '1';
        $seoReady = trim((string)self::get($config, 'ai_seo.api_key', '')) !== '';
        $coverOn = (string)self::get($config, 'ai_cover.enabled', '0') === '1';
        $coverReady = trim((string)self::get($config, 'ai_cover.api_key', '')) !== '';
        $searchOn = (string)self::get($config, 'ai_search.enabled', '0') === '1';
        $searchReady = trim((string)self::get($config, 'ai_search.api_key', '')) !== '';
        $themeAiOn = (string)self::get($config, 'theme_ai.enabled', '0') === '1';
        $themeAiReady = trim((string)self::get($config, 'theme_ai.api_key', '')) !== '';
        $contentOn = (string)self::get($config, 'ai_content.enabled', '0') === '1';
        $contentReuse = (string)self::get($config, 'ai_content.use_ai_search_credentials', '0') === '1';
        $contentReady = $contentReuse
            ? $searchReady
            : trim((string)self::get($config, 'ai_content.api_key', '')) !== '';
        $analyticsOn = (string)self::get($config, 'analytics.server_track', '0') === '1'
            || (string)self::get($config, 'analytics.track_region', '0') === '1';

        $assistantOn = (string)self::get($config, 'admin_assistant.enabled', '0') === '1';
        $userAccessOn = (string)self::get($config, 'monitor.user_access_log_enabled', '0') === '1';

        $defs = [
            [
                'key' => 'config', 'group' => 'base',
                'title' => lang('menu/config'), 'icon' => 'layui-icon-website',
                'action' => 'config', 'query' => [], 'state' => '',
                'tip' => '',
            ],
            [
                'key' => 'config_user', 'group' => 'user',
                'title' => lang('admin/system/configuser/title'), 'icon' => 'layui-icon-user',
                'action' => 'configuser', 'query' => [], 'state' => '',
                'tip' => self::tip('admin/system/configuser/tip'),
            ],
            [
                'key' => 'config_comment', 'group' => 'user',
                'title' => lang('menu/configcomment'), 'icon' => 'layui-icon-duihua',
                'action' => 'configuser', 'query' => ['tab' => 'comment'],
                'state' => $commentOn ? 'on' : 'off',
                'tip' => '',
            ],
            [
                'key' => 'config_collect', 'group' => 'content',
                'title' => lang('admin/system/configcollect/title'), 'icon' => 'layui-icon-template-1',
                'action' => 'configcollect', 'query' => [], 'state' => '',
                'tip' => '',
            ],
            [
                'key' => 'config_interface', 'group' => 'content',
                'title' => lang('menu/configinterface'), 'icon' => 'layui-icon-lianjie',
                'action' => 'configcollect', 'query' => ['tab' => 'interface'],
                'state' => $interfaceOn ? 'on' : 'off',
                'tip' => self::tip('admin/system/configinterface/tip'),
            ],
            [
                'key' => 'config_api', 'group' => 'content',
                'title' => lang('menu/configapi'), 'icon' => 'layui-icon-app',
                'action' => 'configcollect', 'query' => ['tab' => 'api'],
                'state' => $apiOn ? 'on' : 'off',
                'tip' => '',
            ],
            [
                'key' => 'config_upload', 'group' => 'media',
                'title' => lang('admin/system/configupload/title'), 'icon' => 'layui-icon-uploadfile',
                'action' => 'config', 'query' => ['tab' => 'upload'],
                'state' => $uploadMode !== '' ? 'on' : 'off',
                'tip' => self::tip('admin/system/configupload/tip'),
            ],
            [
                'key' => 'config_url', 'group' => 'media',
                'title' => lang('menu/configurl'), 'icon' => 'layui-icon-lianjie',
                'action' => 'config', 'query' => ['tab' => 'url'],
                'state' => $rewriteOn ? 'on' : 'off',
                'tip' => '',
            ],
            [
                'key' => 'config_play', 'group' => 'media',
                'title' => lang('menu/configplay'), 'icon' => 'layui-icon-play',
                'action' => 'config', 'query' => ['tab' => 'play'], 'state' => '',
                'tip' => self::tip('admin/system/configplay/tip'),
            ],
            [
                'key' => 'config_connect', 'group' => 'connect',
                'title' => lang('admin/system/configconnect/title'), 'icon' => 'layui-icon-chat',
                'action' => 'configconnect', 'query' => [], 'state' => '',
                'tip' => self::tip('admin/system/configconnect/tip'),
            ],
            [
                'key' => 'config_weixin', 'group' => 'connect',
                'title' => lang('menu/configweixin'), 'icon' => 'layui-icon-chat',
                'action' => 'configconnect', 'query' => ['tab' => 'weixin'],
                'state' => $weixinOn ? 'on' : 'off',
                'tip' => self::tip('admin/system/configweixin/tip'),
            ],
            [
                'key' => 'config_pay', 'group' => 'connect',
                'title' => lang('menu/configpay'), 'icon' => 'layui-icon-rmb',
                'action' => 'configconnect', 'query' => ['tab' => 'pay'], 'state' => '',
                'tip' => '',
            ],
            [
                'key' => 'config_email', 'group' => 'notify',
                'title' => lang('admin/system/configemail/title'), 'icon' => 'layui-icon-note',
                'action' => 'configemail', 'query' => [],
                'state' => $emailHost !== '' ? 'on' : 'off',
                'tip' => self::tip('admin/system/configemail/tip'),
            ],
            [
                'key' => 'config_sms', 'group' => 'notify',
                'title' => lang('menu/configsms'), 'icon' => 'layui-icon-shouji',
                'action' => 'configsms', 'query' => [],
                'state' => $smsReady ? 'on' : 'off',
                'tip' => self::tip('admin/system/configsms/tip'),
            ],
            [
                'key' => 'config_push', 'group' => 'notify',
                'title' => lang('menu/configpush'), 'icon' => 'layui-icon-notice',
                'action' => 'configpush', 'query' => [],
                'state' => self::featureState($pushOn, $pushReady),
                'tip' => self::tip('admin/system/configpush/tip'),
            ],
            [
                'key' => 'config_seo', 'group' => 'ai',
                'title' => lang('menu/configseo'), 'icon' => 'layui-icon-search',
                'action' => 'config', 'query' => ['tab' => 'seo'], 'state' => '',
                'tip' => '',
            ],
            [
                'key' => 'config_aiseo', 'group' => 'ai',
                'title' => lang('menu/configaiseo'), 'icon' => 'layui-icon-senior',
                'action' => 'config', 'query' => ['tab' => 'aiseo'],
                'state' => self::featureState($seoOn, $seoReady),
                'tip' => self::tip('admin/system/configaiseo/tip'),
            ],
            [
                'key' => 'config_aicover', 'group' => 'ai',
                'title' => lang('menu/configaicover'), 'icon' => 'layui-icon-tupian',
                'action' => 'config', 'query' => ['tab' => 'aicover'],
                'state' => self::featureState($coverOn, $coverReady),
                'tip' => self::tip('admin/system/configaicover/tip'),
            ],
            [
                'key' => 'config_aisearch', 'group' => 'ai',
                'title' => lang('menu/configaisearch'), 'icon' => 'layui-icon-search',
                'action' => 'configaisearch', 'query' => [],
                'state' => self::featureState($searchOn, $searchReady),
                'tip' => self::tip('admin/system/configaisearch/tip'),
            ],
            [
                'key' => 'config_themeai', 'group' => 'ai',
                'title' => lang('menu/configthemeai'), 'icon' => 'layui-icon-theme',
                'action' => 'configthemeai', 'query' => [],
                'state' => self::featureState($themeAiOn, $themeAiReady),
                'tip' => self::tip('admin/system/configthemeai/tip'),
            ],
            [
                'key' => 'config_aicontent', 'group' => 'ai',
                'title' => lang('admin/system/configaicontent'), 'icon' => 'layui-icon-read',
                'action' => 'configaicontent', 'query' => [],
                'state' => self::featureState($contentOn, $contentReady),
                'tip' => '',
            ],
            [
                'key' => 'config_analytics', 'group' => 'ai',
                'title' => lang('admin/system/configanalytics'), 'icon' => 'layui-icon-baobiao',
                'action' => 'configanalytics', 'query' => [],
                'state' => $analyticsOn ? 'on' : 'off',
                'tip' => '',
            ],
            [
                'key' => 'config_assistant', 'group' => 'system',
                'title' => lang('menu/configassistant'), 'icon' => 'layui-icon-headset',
                'action' => 'configassistant', 'query' => [],
                'state' => $assistantOn ? 'on' : 'off',
                'tip' => self::tip('admin/system/configassistant/tip'),
            ],
            [
                'key' => 'config_useraccess', 'group' => 'system',
                'title' => lang('menu/configuseraccess'), 'icon' => 'layui-icon-survey',
                'action' => 'configuseraccess', 'query' => [],
                'state' => $userAccessOn ? 'on' : 'off',
                'tip' => '',
            ],
        ];

        $items = [];
        foreach ($defs as $def) {
            if ($can !== null && !$can($def['action'])) {
                continue;
            }
            $state = isset($def['state']) ? $def['state'] : '';
            $query = isset($def['query']) ? $def['query'] : [];
            $items[] = [
                'key'        => $def['key'],
                'group'      => $def['group'],
                'group_name' => isset($groupNames[$def['group']]) ? $groupNames[$def['group']] : $groupNames['base'],
                'title'      => $def['title'],
                'tip'        => isset($def['tip']) ? $def['tip'] : '',
                'icon'       => $def['icon'],
                'action'     => $def['action'],
                'url'        => (string)url('system/' . $def['action'], $query),
                'state'      => $state,
                'state_text' => isset($stateText[$state]) ? $stateText[$state] : '',
            ];
        }
        return $items;
    }
}
