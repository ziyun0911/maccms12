<?php
namespace app\common\model;

use think\facade\Db;
use think\facade\Log;

class ContentLang extends Base
{
    protected $name = 'content_lang';
    protected $primaryId = 'id';

    private static $currentLang = null;

    public static function setCurrent($lang)
    {
        self::$currentLang = $lang ?: null;
    }

    public static function getCurrent()
    {
        return self::$currentLang ?: mac_content_lang_default();
    }

    public function getFields($contentType, $contentId, $langCode)
    {
        if (empty($contentId) || empty($langCode)) {
            return [];
        }
        try {
            $row = $this->where([
                'content_type' => $contentType,
                'content_id'   => (int)$contentId,
                'lang_code'    => $langCode,
            ])->find();
        } catch (\Exception $e) {
            return [];
        }
        if (empty($row) || empty($row['data'])) {
            return [];
        }
        $data = json_decode($row['data'], true);
        return is_array($data) ? $data : [];
    }

    public function getFieldsMap($contentType, $langCode, array $contentIds = [])
    {
        if (empty($langCode)) {
            return [];
        }
        $where = ['content_type' => $contentType, 'lang_code' => $langCode];
        if (!empty($contentIds)) {
            $where['content_id'] = ['in', array_map('intval', $contentIds)];
        }
        try {
            $rows = $this->where($where)->column('data', 'content_id');
        } catch (\Exception $e) {
            return [];
        }
        $map = [];
        foreach ($rows as $cid => $data) {
            $d = json_decode($data, true);
            if (is_array($d)) {
                $map[(int)$cid] = $d;
            }
        }
        return $map;
    }

    public function saveFields($contentType, $contentId, $langCode, array $fields, $source = 'manual', $status = 0)
    {
        $contentId = (int)$contentId;
        if (empty($contentId) || empty($langCode)) {
            return false;
        }

        $allow = mac_content_lang_fields($contentType);
        if (!empty($allow)) {
            $fields = array_intersect_key($fields, array_flip($allow));
        }
        if (empty($fields)) {
            return false;
        }

        $xssFields = mac_content_lang_xss_fields($contentType);
        foreach ($xssFields as $f) {
            if (isset($fields[$f]) && is_scalar($fields[$f])) {
                $fields[$f] = mac_filter_xss($fields[$f]);
            }
        }

        $row = [
            'content_type' => $contentType,
            'content_id'   => $contentId,
            'lang_code'    => $langCode,
            'data'         => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'source'       => $source,
            'status'       => (int)$status,
            'update_time'  => time(),
        ];

        try {
            return $this->upsertByUnique($row) !== false;
        } catch (\Exception $e) {
            Log::error('ContentLang::saveFields failed type=' . $contentType
                . ' id=' . $contentId . ' lang=' . $langCode . ' err=' . $e->getMessage());
            return false;
        }
    }

    public static function deleteByContent($contentType, array $contentIds)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contentIds))));
        if (empty($contentType) || empty($ids)) {
            return;
        }
        try {
            Db::name('content_lang')->where([
                'content_type' => $contentType,
                'content_id'   => ['in', $ids],
            ])->delete();
        } catch (\Exception $e) {
            Log::error('ContentLang::deleteByContent failed type=' . $contentType
                . ' ids=' . implode(',', $ids) . ' err=' . $e->getMessage());
        }
    }
}
