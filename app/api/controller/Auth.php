<?php

namespace app\api\controller;

use app\common\util\JwtService;
use think\facade\Db;
use think\Request;

class Auth extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function me(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();

        if ($check['code'] > 1) {
            return json([
                'code' => 1,
                'msg'  => 'ok',
                'info' => [
                    'is_login'        => 0,
                    'user_id'         => 0,
                    'user_name'       => '',
                    'nick_name'       => '',
                    'group_id'        => 0,
                    'group_name'      => '',
                    'points'          => 0,
                    'user_portrait'   => '',
                    'vip_expire_time' => 0,
                ],
            ]);
        }

        $user = $check['info'];
        $groupName = '';
        if (!empty($user['group'])) {
            $groupName = $user['group']['group_name'] ?? '';
        }

        $groupIds = explode(',', $user['group_id']);
        $primaryGroupId = intval($groupIds[0]);

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'info' => [
                'is_login'        => 1,
                'user_id'         => intval($user['user_id']),
                'user_name'       => $user['user_name'] ?? '',
                'nick_name'       => $user['user_nick_name'] ?? '',
                'group_id'        => $primaryGroupId,
                'group_name'      => $groupName,
                'points'          => intval($user['user_points'] ?? 0),
                'user_portrait'   => mac_get_user_portrait($user['user_id']),
                'vip_expire_time' => intval($user['user_end_time'] ?? 0),
            ],
        ]);
    }

    public function permission(Request $request)
    {
        $param = $request->param();
        $mid = intval($param['mid'] ?? 0);
        $id  = intval($param['id'] ?? 0);
        $action = trim($param['action'] ?? '');

        if ($mid < 1 || $id < 1) {
            return json([
                'code' => 1001,
                'msg'  => '参数错误: mid 和 id 必须',
            ]);
        }

        $allowedActions = ['play', 'read', 'download', 'comment', 'favorite'];
        if (!empty($action) && !in_array($action, $allowedActions)) {
            return json([
                'code' => 1001,
                'msg'  => '参数错误: action 值无效',
            ]);
        }

        $check = (new \app\common\model\User())->checkLogin();
        $isLogin = ($check['code'] == 1) ? 1 : 0;
        $user = $isLogin ? $check['info'] : null;

        $isVip = 0;
        $userPoints = 0;
        $userGroupIds = [];
        if ($isLogin && $user) {
            $userPoints = intval($user['user_points'] ?? 0);
            $userGroupIds = array_map('intval', explode(',', $user['group_id']));
            if (max($userGroupIds) >= 3 && intval($user['user_end_time'] ?? 0) > time()) {
                $isVip = 1;
            }
        }

        $resource = null;
        $resourcePoints = 0;
        $resourceStatus = 0;
        $denyReason = '';

        switch ($mid) {
            case 1:
                $resource = Db::name('vod')->field('vod_id,vod_status,vod_points_play,vod_points_down,vod_pwd_play,vod_pwd_down,type_id')->where('vod_id', $id)->find();
                if ($resource) {
                    $resourceStatus = intval($resource['vod_status'] ?? 0);
                    $resourcePoints = intval($resource['vod_points_play'] ?? 0);
                }
                break;
            case 2:
                $resource = Db::name('art')->field('art_id,art_status,art_points,art_points_detail,type_id')->where('art_id', $id)->find();
                if ($resource) {
                    $resourceStatus = intval($resource['art_status'] ?? 0);
                    $detailPoints = intval($resource['art_points_detail'] ?? 0);
                    $resourcePoints = $detailPoints > 0 ? $detailPoints : intval($resource['art_points'] ?? 0);
                }
                break;
            case 6:
                $resource = Db::name('manga')->field('manga_id,manga_status,manga_points,type_id')->where('manga_id', $id)->find();
                if ($resource) {
                    $resourceStatus = intval($resource['manga_status'] ?? 0);
                    $resourcePoints = intval($resource['manga_points'] ?? 0);
                }
                break;
            default:
                return json([
                    'code' => 1001,
                    'msg'  => '参数错误: mid 不支持',
                ]);
        }

        if (empty($resource)) {
            return json([
                'code' => 1,
                'msg'  => 'ok',
                'info' => [
                    'is_login'     => $isLogin,
                    'is_vip'       => $isVip,
                    'resource'     => ['mid' => $mid, 'id' => $id],
                    'can_play'     => 0,
                    'can_read'     => 0,
                    'can_download' => 0,
                    'can_comment'  => 0,
                    'can_favorite' => 0,
                    'deny_reason'  => 'RESOURCE_NOT_FOUND',
                ],
            ]);
        }

        if ($resourceStatus != 1) {
            return json([
                'code' => 1,
                'msg'  => 'ok',
                'info' => [
                    'is_login'     => $isLogin,
                    'is_vip'       => $isVip,
                    'resource'     => ['mid' => $mid, 'id' => $id],
                    'can_play'     => 0,
                    'can_read'     => 0,
                    'can_download' => 0,
                    'can_comment'  => 0,
                    'can_favorite' => 0,
                    'deny_reason'  => 'RESOURCE_OFFLINE',
                ],
            ]);
        }

        $typeId = intval($resource['type_id'] ?? 0);
        $typeIsVipExclusive = 0;
        if ($typeId > 0) {
            $typeInfo = Db::name('type')->field('type_id,type_extend')->where('type_id', $typeId)->find();
            if ($typeInfo && !empty($typeInfo['type_extend'])) {
                $typeExtend = json_decode($typeInfo['type_extend'], true);
                if (is_array($typeExtend)) {
                    $typeIsVipExclusive = intval($typeExtend['type_is_vip_exclusive'] ?? 0);
                }
            }
        }

        $canPlay     = 1;
        $canRead     = 1;
        $canDownload = 1;
        $canComment  = 1;
        $canFavorite = 1;

        if (!$isLogin) {
            $canComment  = 0;
            $canFavorite = 0;
            $denyReason  = 'NOT_LOGIN';

            if ($resourcePoints > 0) {
                $canPlay     = 0;
                $canRead     = 0;
                $canDownload = 0;
            }

            if ($typeIsVipExclusive) {
                $canPlay     = 0;
                $canRead     = 0;
                $canDownload = 0;
                $denyReason  = 'VIP_REQUIRED';
            }
        } else {
            if ($typeIsVipExclusive && !$isVip) {
                $canPlay     = 0;
                $canRead     = 0;
                $canDownload = 0;
                $denyReason  = 'VIP_REQUIRED';
            }

            if ($resourcePoints > 0 && !$isVip) {
                if ($userPoints < $resourcePoints) {
                    $canPlay     = 0;
                    $canRead     = 0;
                    $denyReason  = 'POINTS_NOT_ENOUGH';
                }
            }

            if ($mid == 1 && !$isVip) {
                $downPoints = intval($resource['vod_points_down'] ?? 0);
                if ($downPoints > 0 && $userPoints < $downPoints) {
                    $canDownload = 0;
                    if (empty($denyReason)) {
                        $denyReason = 'POINTS_NOT_ENOUGH';
                    }
                }
            }

            if ($typeId > 0) {
                $groupIdStr = $user['group_id'] ?? '1';
                if (!(new \app\common\model\User())->popedom($typeId, 2, $groupIdStr)) {
                    $canPlay = 0;
                    $canRead = 0;
                    if (empty($denyReason)) {
                        $denyReason = 'GROUP_PERMISSION_DENIED';
                    }
                }
                if (!(new \app\common\model\User())->popedom($typeId, 3, $groupIdStr)) {
                    $canDownload = 0;
                    if (empty($denyReason)) {
                        $denyReason = 'GROUP_PERMISSION_DENIED';
                    }
                }
            }
        }

        if ($canPlay && $canRead && $canDownload && $canComment && $canFavorite) {
            $denyReason = '';
        }

        $result = [
            'is_login'     => $isLogin,
            'is_vip'       => $isVip,
            'resource'     => ['mid' => $mid, 'id' => $id],
            'can_play'     => $canPlay,
            'can_read'     => $canRead,
            'can_download' => $canDownload,
            'can_comment'  => $canComment,
            'can_favorite' => $canFavorite,
            'deny_reason'  => $denyReason,
        ];

        if (!empty($action)) {
            $actionMap = [
                'play'     => 'can_play',
                'read'     => 'can_read',
                'download' => 'can_download',
                'comment'  => 'can_comment',
                'favorite' => 'can_favorite',
            ];
            $key = $actionMap[$action] ?? '';
            if ($key) {
                $filtered = [
                    'is_login'     => $isLogin,
                    'is_vip'       => $isVip,
                    'resource'     => ['mid' => $mid, 'id' => $id],
                    $key           => $result[$key],
                    'deny_reason'  => $result[$key] ? '' : $denyReason,
                ];
                $result = $filtered;
            }
        }

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'info' => $result,
        ]);
    }

    public function jwt(Request $request)
    {
        if (!JwtService::isEnabled()) {
            return json(['code' => 1004, 'msg' => 'JWT disabled']);
        }
        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => 'POST required']);
        }
        $param = $request->param();
        $res = (new \app\common\model\User())->login(
            [
                'user_name' => isset($param['user_name']) ? $param['user_name'] : '',
                'user_pwd'  => isset($param['user_pwd']) ? $param['user_pwd'] : '',
                'verify'    => isset($param['verify']) ? $param['verify'] : '',
                'openid'    => '',
                'col'       => '',
            ],
            ['set_cookie' => false, 'return_meta' => true]
        );
        if (!isset($res['code']) || (int)$res['code'] !== 1) {
            return json([
                'code' => isset($res['code']) ? (int)$res['code'] : 1003,
                'msg'  => isset($res['msg']) ? (string)$res['msg'] : 'login failed',
            ]);
        }
        $meta = isset($res['meta']) && is_array($res['meta']) ? $res['meta'] : null;
        if ($meta === null || empty($meta['user_id']) || empty($meta['user_random'])) {
            return json(['code' => 1005, 'msg' => 'Internal error']);
        }
        $token = JwtService::encode((int)$meta['user_id'], (string)$meta['user_random']);
        if ($token === '') {
            return json(['code' => 1005, 'msg' => 'Token issue failed']);
        }
        \app\common\model\UserAccessLog::record('api_token', [
            'user_id' => (int)$meta['user_id'],
        ]);

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'info' => [
                'token_type'   => 'Bearer',
                'access_token' => $token,
                'expires_in'   => JwtService::getTtl(),
            ],
        ]);
    }
}
