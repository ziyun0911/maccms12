<?php
namespace app\common\util;

class DoubanExternalSourceProvider implements ExternalSourceProviderInterface
{
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getCode()
    {
        return 'douban';
    }

    public function getLabel()
    {
        return 'Douban';
    }

    public function search($keyword, array $options = [])
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            return [];
        }
        $limit = max(1, min(20, intval(isset($options['limit']) ? $options['limit'] : 8)));
        $urlTpl = trim((string)$this->get('search_url', 'https://movie.douban.com/j/subject_suggest?q=__query__'));
        if ($urlTpl === '') {
            return [];
        }
        $url = str_replace(
            ['{query}', '{limit}', '__query__', '__limit__'],
            [rawurlencode($keyword), (string)$limit, rawurlencode($keyword), (string)$limit],
            $urlTpl
        );
        $json = $this->requestJson($url);
        if (empty($json)) {
            return [];
        }
        return array_slice($this->normalizeSearchRows($json), 0, $limit);
    }

    public function fetchRecent(array $options = [])
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $want = max(1, min(1200, intval(isset($options['limit']) ? $options['limit'] : 20)));
        $urlTpl = $this->decodeAmp(trim((string)$this->get('recent_url', 'https://movie.douban.com/j/search_subjects?type=movie&tag=%E7%83%AD%E9%97%A8&page_limit=__limit__&page_start=0')));
        if ($urlTpl === '') {
            return [];
        }
        $types = $this->splitList($this->get('recent_types', 'movie,tv'));
        $tags = $this->splitList($this->get('recent_tags', '热门,最新,经典'));
        if ($types === [] || $tags === []) {
            $types = ['movie'];
            $tags = ['热门'];
        }
        $out = [];
        $seen = [];
        foreach ($types as $type) {
            foreach ($tags as $tag) {
                if (count($out) >= $want) {
                    break 2;
                }
                $rest = $want - count($out);
                $tagVal = (strpos($tag, '%') === false) ? rawurlencode($tag) : $tag;
                $url = str_replace(
                    ['{limit}', '__limit__', '{type}', '__type__', '{tag}', '__tag__'],
                    [(string)$rest, (string)$rest, rawurlencode($type), rawurlencode($type), $tagVal, $tagVal],
                    $urlTpl
                );
                $json = $this->requestJson($url);
                if (!is_array($json)) {
                    continue;
                }
                $rows = isset($json['subjects']) && is_array($json['subjects']) ? $json['subjects'] : [];
                foreach ($this->normalizeRecentRows($rows) as $row) {
                    if (isset($seen[$row['item_key']])) {
                        continue;
                    }
                    $seen[$row['item_key']] = 1;
                    $out[] = $row;
                    if (count($out) >= $want) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    private function decodeAmp($url)
    {
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $url) {
                break;
            }
            $url = $next;
        }
        return $url;
    }

    private function splitList($raw)
    {
        $raw = (string)$raw;
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[,;\s]+/u', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    private function isEnabled()
    {
        return (string)$this->get('enabled', '0') === '1';
    }

    private function get($key, $default = '')
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    private function requestJson($url)
    {
        $headers = [
            'Accept: application/json',
            'Referer: '.trim((string)$this->get('referer', 'https://movie.douban.com/')),
            'User-Agent: '.trim((string)$this->get('user_agent', 'Mozilla/5.0')),
        ];
        $resp = HttpClient::curlPostWithTimeout($url, '', $headers, 12, false);
        if ($resp === false || $resp === '') {
            return [];
        }
        $json = json_decode((string)$resp, true);
        return is_array($json) ? $json : [];
    }

    private function normalizeSearchRows(array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)(isset($row['id']) ? $row['id'] : ''));
            $title = trim((string)(isset($row['title']) ? $row['title'] : ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $url = trim((string)(isset($row['url']) ? $row['url'] : ''));
            if ($url === '') {
                $url = 'https://movie.douban.com/subject/'.$id.'/';
            }
            $subTitle = trim((string)(isset($row['sub_title']) ? $row['sub_title'] : ''));
            $year = trim((string)(isset($row['year']) ? $row['year'] : ''));
            $rate = floatval(isset($row['rate']) ? $row['rate'] : 0);
            $snippet = $subTitle;
            if ($year !== '') {
                $snippet .= ($snippet === '' ? '' : ' | ') . $year;
            }
            if ($rate > 0) {
                $snippet .= ($snippet === '' ? '' : ' | ') . '★ ' . round($rate, 1);
            }
            $out[] = [
                'provider_code' => 'douban',
                'item_key' => 'subject_'.$id,
                'item_mid' => 1,
                'item_title' => $title,
                'item_subtitle' => $subTitle,
                'item_snippet' => $snippet,
                'item_url' => $url,
                'item_cover' => (string)(isset($row['cover']) ? $row['cover'] : ''),
                'item_score' => $rate,
                'item_release_date' => $year,
                'item_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $out;
    }

    private function normalizeRecentRows(array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)(isset($row['id']) ? $row['id'] : ''));
            $title = trim((string)(isset($row['title']) ? $row['title'] : ''));
            $url = trim((string)(isset($row['url']) ? $row['url'] : ''));
            if ($id === '' || $title === '') {
                continue;
            }
            if ($url === '') {
                $url = 'https://movie.douban.com/subject/'.$id.'/';
            }
            $rate = floatval(isset($row['rate']) ? $row['rate'] : 0);
            $snippet = '';
            if ($rate > 0) {
                $snippet = '★ ' . round($rate, 1);
            }
            $out[] = [
                'provider_code' => 'douban',
                'item_key' => 'subject_'.$id,
                'item_mid' => 1,
                'item_title' => $title,
                'item_subtitle' => 'Douban',
                'item_snippet' => $snippet,
                'item_url' => $url,
                'item_cover' => (string)(isset($row['cover']) ? $row['cover'] : ''),
                'item_score' => $rate,
                'item_release_date' => '',
                'item_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $out;
    }
}

