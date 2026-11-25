<?php
namespace app\index\controller;

use think\facade\Db;

class Chatroom extends Base
{
    public function index()
    {
        $vod_id = intval($this->request->param('vod_id'));
        $rows = [];
        $rooms = [];
        $vod = null;
        $last_id = 0;

        $chatModel = new \app\common\model\Chatroom();

        if ($vod_id > 0) {
            $res = $chatModel->getNewMessages($vod_id, 0, 50);
            if (!empty($res['info']['rows'])) {
                $rows = $res['info']['rows'];
            }
            if (!empty($rows)) {
                $last = end($rows);
                $last_id = intval($last['chat_id']);
            }
            $vod = Db::name('vod')
                ->field('vod_id,vod_name,vod_remarks')
                ->where('vod_id', $vod_id)
                ->find();
        } else {
            $list = Db::name('chatroom')
                ->field('chat_id,vod_id,user_id,user_name,chat_content,chat_time')
                ->where('chat_status', 1)
                ->order('chat_id', 'desc')
                ->limit(50)
                ->select()->toArray();
            $rows = array_reverse($list);
            foreach ($rows as &$r) {
                $r['user_portrait'] = mac_get_user_portrait($r['user_id']);
                $r['chat_time_text'] = date('H:i:s', intval($r['chat_time']));
            }
            unset($r);
            $rooms = $this->activeRooms();
        }

        foreach ($rows as &$r) {
            $r['user_name_text'] = $this->safeText(isset($r['user_name']) ? $r['user_name'] : '', '游客');
            $r['chat_content_text'] = $this->safeText(isset($r['chat_content']) ? $r['chat_content'] : '', '');
            $r['user_initial'] = mb_substr($r['user_name_text'], 0, 1, 'UTF-8');
            if (empty($r['chat_time_text'])) {
                $r['chat_time_text'] = !empty($r['chat_time']) ? date('H:i:s', intval($r['chat_time'])) : '';
            }
        }
        unset($r);

        $vod_url = '';
        $vod_name = '';
        if (!empty($vod)) {
            $vod_name = $this->safeText($vod['vod_name'], '影片');
            $vod_url = $this->sitePath() . 'index.php/vod/detail/id/' . intval($vod['vod_id']) . '.html';
        }

        $this->assign([
            'chat_vod_id'    => $vod_id,
            'chat_vod_name'  => $vod_name,
            'chat_vod_url'   => $vod_url,
            'chat_rows'      => $rows,
            'chat_rooms'     => $rooms,
            'chat_last_id'   => $last_id,
            'chat_page_url'  => $this->sitePath() . 'index.php/chatroom.html',
            'chat_login_url' => $this->sitePath() . 'index.php/user/login.html',
        ]);

        return $this->label_fetch('chatroom/index');
    }

    private function activeRooms()
    {
        $group = Db::name('chatroom')
            ->field('vod_id, COUNT(*) AS cnt, MAX(chat_time) AS last_time')
            ->where('chat_status', 1)
            ->where('vod_id', '>', 0)
            ->group('vod_id')
            ->order('last_time desc')
            ->limit(8)
            ->select()->toArray();

        if (empty($group)) {
            return [];
        }

        $ids = array_map('intval', array_column($group, 'vod_id'));
        $names = Db::name('vod')->whereIn('vod_id', $ids)->column('vod_name', 'vod_id');

        $rooms = [];
        foreach ($group as $g) {
            $id = intval($g['vod_id']);
            if ($id <= 0) {
                continue;
            }
            $rooms[] = [
                'vod_id'        => $id,
                'vod_name_text' => $this->safeText(isset($names[$id]) ? $names[$id] : '', '影片 #' . $id),
                'cnt'           => intval($g['cnt']),
            ];
        }

        return $rooms;
    }

    private function safeText($str, $default = '')
    {
        $str = trim((string)$str);
        if ($str === '') {
            return $default;
        }
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    private function sitePath()
    {
        $path = defined('MAC_PATH') ? MAC_PATH : (isset($GLOBALS['config']['path']['path']) ? $GLOBALS['config']['path']['path'] : '/');
        $path = '/' . trim((string)$path, '/');
        return $path === '/' ? '/' : $path . '/';
    }
}
