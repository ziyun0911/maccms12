<?php
namespace app\common\util;

use app\common\model\PushSubscription;
use think\facade\Db;

class PushDispatcher
{
    const RECORD_SIZE = 4096;
    const JWT_TTL = 43200;
    const PUSH_TTL = 2419200;

    public static function base64urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode($data)
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function generateVapidKeys()
    {
        if (!function_exists('openssl_pkey_new')) {
            return false;
        }
        $conf = [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];
        $res = @openssl_pkey_new($conf);
        if ($res === false) {
            return false;
        }
        $details = openssl_pkey_get_details($res);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            return false;
        }
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
        $public  = "\x04" . $x . $y;
        return [
            'public'  => self::base64urlEncode($public),
            'private' => self::base64urlEncode($d),
        ];
    }

    protected static function conf()
    {
        $cfg = config('maccms');
        return isset($cfg['push']) && is_array($cfg['push']) ? $cfg['push'] : [];
    }

    public static function isReady()
    {
        $c = self::conf();
        return !empty($c['enable'])
            && !empty($c['vapid_public'])
            && !empty($c['vapid_private'])
            && function_exists('openssl_pkey_derive')
            && function_exists('hash_hkdf')
            && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    protected static function log($msg)
    {
        if (function_exists('trace')) {
            @trace('[PushDispatcher] ' . $msg, 'info');
        }
    }

    public static function sendToUser($userId, $payload)
    {
        if (!self::isReady()) {
            return ['code' => 0, 'sent' => 0, 'failed' => 0, 'msg' => 'push_not_ready'];
        }
        $model = new PushSubscription();
        $subs = $model->listByUser($userId);
        return self::dispatch($subs, $payload);
    }

    public static function broadcastToAll($payload)
    {
        if (!self::isReady()) {
            return ['code' => 0, 'sent' => 0, 'failed' => 0, 'msg' => 'push_not_ready'];
        }
        $model = new PushSubscription();
        $subs = $model->listAll();
        return self::dispatch($subs, $payload);
    }

    public static function enqueueBroadcast($payload)
    {
        $payload = self::normalizePayload($payload);
        $now = time();
        $queueId = Db::name('push_queue')->insertGetId([
            'title'       => mb_substr($payload['title'], 0, 255, 'UTF-8'),
            'body'        => mb_substr($payload['body'], 0, 255, 'UTF-8'),
            'url'         => substr($payload['url'], 0, 512),
            'icon'        => substr($payload['icon'], 0, 255),
            'sent'        => 0,
            'failed'      => 0,
            'last_id'     => 0,
            'status'      => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return ['code' => 1, 'msg' => 'queued', 'queue_id' => (int)$queueId];
    }

    public static function runQueue($batchSize = 100, $maxSubsPerRun = 500)
    {
        if (!self::isReady()) {
            return ['code' => 0, 'msg' => 'push_not_ready', 'processed' => 0];
        }
        $batchSize     = max(1, intval($batchSize));
        $maxSubsPerRun = max($batchSize, intval($maxSubsPerRun));

        $model = new PushSubscription();
        $processed = 0;

        while ($processed < $maxSubsPerRun) {
            $row = Db::name('push_queue')
                ->where('status', 'in', [0, 1])
                ->order('queue_id asc')
                ->find();
            if (empty($row)) {
                break;
            }
            if (intval($row['status']) === 0) {
                Db::name('push_queue')->where('queue_id', $row['queue_id'])
                    ->update(['status' => 1, 'update_time' => time()]);
            }

            $lastId = intval($row['last_id']);
            $limit  = min($batchSize, $maxSubsPerRun - $processed);
            $subs   = $model->listAfterId($lastId, $limit);
            $cnt    = count($subs);

            if ($cnt === 0) {
                Db::name('push_queue')->where('queue_id', $row['queue_id'])
                    ->update(['status' => 2, 'update_time' => time()]);
                continue;
            }

            $res = self::dispatch($subs, [
                'title' => $row['title'],
                'body'  => $row['body'],
                'url'   => $row['url'],
                'icon'  => $row['icon'],
            ]);

            $maxId = $lastId;
            foreach ($subs as $s) {
                $sid = intval($s['subscription_id']);
                if ($sid > $maxId) {
                    $maxId = $sid;
                }
            }

            $update = [
                'last_id'     => $maxId,
                'sent'        => intval($row['sent']) + intval($res['sent']),
                'failed'      => intval($row['failed']) + intval($res['failed']),
                'update_time' => time(),
            ];
            if ($cnt < $limit) {
                $update['status'] = 2;
            }
            Db::name('push_queue')->where('queue_id', $row['queue_id'])->update($update);
            $row['last_id'] = $maxId;
            $row['sent']    = $update['sent'];
            $row['failed']  = $update['failed'];

            $processed += $cnt;
        }

        return ['code' => 1, 'msg' => 'ok', 'processed' => $processed];
    }

    protected static function dispatch($subs, $payload)
    {
        $sent = 0;
        $failed = 0;
        $cleaned = 0;
        $payload = self::normalizePayload($payload);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $conf = self::conf();
        $vapidPublic  = (string)$conf['vapid_public'];
        $vapidPrivate = (string)$conf['vapid_private'];
        $subject = isset($conf['vapid_subject']) && $conf['vapid_subject'] !== ''
            ? (string)$conf['vapid_subject'] : 'mailto:admin@example.com';

        $privPem = self::buildEcPrivatePem($vapidPrivate, $vapidPublic);
        if ($privPem === false) {
            self::log('invalid vapid private key');
            return ['code' => 0, 'sent' => 0, 'failed' => 0, 'msg' => 'vapid_key_invalid'];
        }
        $jwtCache = [];

        foreach ($subs as $sub) {
            $endpoint = isset($sub['endpoint']) ? (string)$sub['endpoint'] : '';
            $p256dh   = isset($sub['p256dh']) ? (string)$sub['p256dh'] : '';
            $auth     = isset($sub['auth']) ? (string)$sub['auth'] : '';
            if ($endpoint === '' || $p256dh === '' || $auth === '') {
                $failed++;
                continue;
            }
            if (!PushSubscription::isAllowedEndpoint($endpoint)) {
                $failed++;
                self::log('blocked non-whitelisted endpoint=' . substr($endpoint, 0, 60));
                continue;
            }
            try {
                $aud = self::audienceFromEndpoint($endpoint);
                if (!isset($jwtCache[$aud])) {
                    $jwtCache[$aud] = self::createVapidJwt($aud, $subject, $privPem);
                }
                $jwt = $jwtCache[$aud];
                if ($jwt === false) {
                    $failed++;
                    continue;
                }

                $body = self::encryptPayload($json, $p256dh, $auth);
                if ($body === false) {
                    $failed++;
                    continue;
                }

                $status = self::postToEndpoint($endpoint, $body, $jwt, $vapidPublic);
                if ($status === 404 || $status === 410) {
                    self::cleanup($endpoint);
                    $cleaned++;
                    $failed++;
                } elseif ($status >= 200 && $status < 300) {
                    $sent++;
                } else {
                    $failed++;
                    self::log('push failed status=' . $status . ' endpoint=' . substr($endpoint, 0, 60));
                }
            } catch (\Exception $e) {
                $failed++;
                self::log('push exception: ' . $e->getMessage());
            } catch (\Throwable $e) {
                $failed++;
                self::log('push throwable: ' . $e->getMessage());
            }
        }

        self::log('dispatch subs=' . count($subs) . " sent={$sent} failed={$failed} cleaned={$cleaned}");
        if ($sent === 0 && $failed > 0) {
            $code = 0;
            $msg  = 'all_failed';
        } elseif ($failed > 0) {
            $code = 1;
            $msg  = 'partial';
        } else {
            $code = 1;
            $msg  = 'ok';
        }
        return [
            'code'   => $code,
            'sent'   => $sent,
            'failed' => $failed,
            'msg'    => $msg,
        ];
    }

    public static function cleanup($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return;
        }
        Db::name('push_subscription')->where('endpoint', $endpoint)->delete();
    }

    protected static function normalizePayload($payload)
    {
        if (!is_array($payload)) {
            $payload = ['title' => (string)$payload];
        }
        return [
            'title' => isset($payload['title']) ? (string)$payload['title'] : '',
            'body'  => isset($payload['body']) ? (string)$payload['body'] : '',
            'url'   => isset($payload['url']) ? (string)$payload['url'] : '/',
            'icon'  => isset($payload['icon']) ? (string)$payload['icon'] : '',
        ];
    }

    protected static function audienceFromEndpoint($endpoint)
    {
        $p = parse_url($endpoint);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $aud = $p['scheme'] . '://' . $p['host'];
        if (!empty($p['port'])) {
            $aud .= ':' . $p['port'];
        }
        return $aud;
    }

    protected static function createVapidJwt($aud, $subject, $privPem)
    {
        if ($aud === '') {
            return false;
        }
        $header = self::base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64urlEncode(json_encode([
            'aud' => $aud,
            'exp' => time() + self::JWT_TTL,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $claims;

        $derSig = '';
        $ok = openssl_sign($signingInput, $derSig, $privPem, OPENSSL_ALGO_SHA256);
        if (!$ok || $derSig === '') {
            return false;
        }
        $rawSig = self::derToRawSignature($derSig);
        if ($rawSig === false) {
            return false;
        }
        return $signingInput . '.' . self::base64urlEncode($rawSig);
    }

    protected static function derToRawSignature($der)
    {
        $off = 0;
        $len = strlen($der);
        if ($len < 8 || ord($der[$off++]) !== 0x30) {
            return false;
        }
        $seqLen = ord($der[$off++]);
        if ($seqLen & 0x80) {
            $n = $seqLen & 0x7f;
            $seqLen = 0;
            while ($n-- > 0 && $off < $len) {
                $seqLen = ($seqLen << 8) | ord($der[$off++]);
            }
        }
        if (ord($der[$off++]) !== 0x02) {
            return false;
        }
        $rLen = ord($der[$off++]);
        $r = substr($der, $off, $rLen);
        $off += $rLen;
        if ($off >= $len || ord($der[$off++]) !== 0x02) {
            return false;
        }
        $sLen = ord($der[$off++]);
        $s = substr($der, $off, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        if (strlen($r) > 32 || strlen($s) > 32) {
            return false;
        }
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    protected static function encryptPayload($plaintext, $p256dhB64, $authB64)
    {
        $uaPublic = self::base64urlDecode($p256dhB64);
        $authSecret = self::base64urlDecode($authB64);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            return false;
        }

        $localKey = @openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($localKey === false) {
            return false;
        }
        $localDetails = openssl_pkey_get_details($localKey);
        if ($localDetails === false || !isset($localDetails['ec']['x'], $localDetails['ec']['y'])) {
            return false;
        }
        $asPublic = "\x04"
            . str_pad($localDetails['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($localDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $peerPem = self::buildEcPublicPem($uaPublic);
        if ($peerPem === false) {
            return false;
        }
        $peerKey = openssl_pkey_get_public($peerPem);
        if ($peerKey === false) {
            return false;
        }
        $ecdh = openssl_pkey_derive($peerKey, $localKey, 256);
        if ($ecdh === false || strlen($ecdh) === 0) {
            return false;
        }
        $ecdh = str_pad($ecdh, 32, "\0", STR_PAD_LEFT);

        $info = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm  = hash_hkdf('sha256', $ecdh, 32, $info, $authSecret);

        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $record = $plaintext . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return false;
        }

        $header = $salt
            . pack('N', self::RECORD_SIZE)
            . chr(65)
            . $asPublic;
        return $header . $cipher . $tag;
    }

    protected static function postToEndpoint($endpoint, $body, $jwt, $vapidPublic)
    {
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . self::PUSH_TTL,
            'Authorization: vapid t=' . $jwt . ',k=' . $vapidPublic,
            'Content-Length: ' . strlen($body),
        ];
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => 5,
                'protocol_version' => 1.1,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $http_response_header = [];
        $resp = @file_get_contents($endpoint, false, $ctx);
        if (empty($http_response_header) || !isset($http_response_header[0])) {
            return 0;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    protected static function buildEcPrivatePem($privB64, $pubB64)
    {
        $d = self::base64urlDecode($privB64);
        $pub = self::base64urlDecode($pubB64);
        if (strlen($d) !== 32 || strlen($pub) !== 65 || $pub[0] !== "\x04") {
            return false;
        }
        $algId = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $der = "\x30\x81\x87\x02\x01\x00\x30\x13" . $algId
            . "\x04\x6d\x30\x6b\x02\x01\x01\x04\x20" . $d
            . "\xa1\x44\x03\x42\x00" . $pub;
        return self::derToPem($der, 'PRIVATE KEY');
    }

    protected static function buildEcPublicPem($pub)
    {
        if (strlen($pub) !== 65 || $pub[0] !== "\x04") {
            return false;
        }
        $algId = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $der = "\x30\x59\x30\x13" . $algId . "\x03\x42\x00" . $pub;
        return self::derToPem($der, 'PUBLIC KEY');
    }

    protected static function derToPem($der, $label)
    {
        $b64 = base64_encode($der);
        $pem = "-----BEGIN {$label}-----\n"
            . chunk_split($b64, 64, "\n")
            . "-----END {$label}-----\n";
        return $pem;
    }
}
