<?php
// ARCHIVO: push_manager.php
// Envío de Web Push (RFC 8292 VAPID) sin dependencias externas.
// Usa OpenSSL para firmar JWT ES256 y cURL multi para envíos paralelos.
// La notificación se envía como "ping" vacío: el SW la recoge via push_api.php.

class PushManager
{
    private string $publicKeyB64;   // VAPID public key — base64url (65 bytes sin comprimir)
    private string $privateKeyPem;  // VAPID private key — PEM ECDSA P-256
    private string $subject;        // mailto: o URL del emisor

    public function __construct(string $publicKeyB64, string $privateKeyPem, string $subject = 'mailto:admin@palweb.local')
    {
        $this->publicKeyB64  = $publicKeyB64;
        $this->privateKeyPem = $privateKeyPem;
        $this->subject       = $subject;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Generar par de claves VAPID (ejecutar solo una vez)
    // ─────────────────────────────────────────────────────────────────────
    public static function generateKeys(): array
    {
        $key     = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);

        // Punto no comprimido: 0x04 | X(32 bytes) | Y(32 bytes)
        $pubBytes = "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        openssl_pkey_export($key, $privPem);

        return [
            'publicKey'  => self::b64uEncode($pubBytes),
            'privateKey' => $privPem,
        ];
    }

    // Auto-genera claves y las persiste en pos.cfg si aún no existen
    public static function ensureKeys(): array
    {
        $cfgFile = __DIR__ . '/pos.cfg';
        $cfg     = json_decode(@file_get_contents($cfgFile) ?: '{}', true) ?? [];

        if (empty($cfg['vapid_public_key']) || empty($cfg['vapid_private_key'])) {
            $keys = self::generateKeys();
            $cfg['vapid_public_key']  = $keys['publicKey'];
            $cfg['vapid_private_key'] = $keys['privateKey'];
            file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return [
            'publicKey'  => $cfg['vapid_public_key'],
            'privateKey' => $cfg['vapid_private_key'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Enviar ping a múltiples subscripciones en paralelo (curl_multi)
    // Retorna [subscription_id => http_status_code]
    // ─────────────────────────────────────────────────────────────────────
    public function sendToAll(array $subscriptions): array
    {
        if (empty($subscriptions)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($subscriptions as $sub) {
            $endpoint = $sub['endpoint'];
            $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
            $jwt      = $this->buildJwt($audience);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: vapid t={$jwt},k={$this->publicKeyB64}",
                    'Content-Type: application/octet-stream',
                    'Content-Length: 0',
                    'TTL: 86400',
                    'Urgency: normal',
                ],
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['ch' => $ch, 'id' => $sub['id']];
        }

        // Ejecutar en paralelo
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running > 0 && $status === CURLM_OK);

        // Recoger resultados
        $results = [];
        foreach ($handles as $info) {
            $httpCode              = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
            $results[$info['id']] = $httpCode;
            curl_multi_remove_handle($mh, $info['ch']);
            curl_close($info['ch']);
        }
        curl_multi_close($mh);

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Construir JWT VAPID firmado con ES256
    // ─────────────────────────────────────────────────────────────────────
    private function buildJwt(string $audience): string
    {
        $header  = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64uEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,   // 12 horas
            'sub' => $this->subject,
        ]));

        $data = $header . '.' . $payload;
        $key  = openssl_pkey_get_private($this->privateKeyPem);
        openssl_sign($data, $derSig, $key, OPENSSL_ALGO_SHA256);

        return $data . '.' . self::b64uEncode(self::derToJwtSig($derSig));
    }

    // Convierte firma DER ECDSA → formato JWT ES256 (R|S de 32 bytes c/u)
    // DER: 0x30 <seqlen> 0x02 <rlen> <rbytes> 0x02 <slen> <sbytes>
    // Para P-256 seqlen < 128, por lo que la longitud es siempre 1 byte.
    private static function derToJwtSig(string $der): string
    {
        $pos = 2; // saltar 0x30 + 1 byte de longitud de secuencia

        // R
        $pos++;                         // saltar tag 0x02
        $rLen = ord($der[$pos++]);
        $r    = substr($der, $pos, $rLen);
        $pos += $rLen;

        // S
        $pos++;                         // saltar tag 0x02
        $sLen = ord($der[$pos++]);
        $s    = substr($der, $pos, $sLen);

        // Normalizar a exactamente 32 bytes (quitar 0x00 de relleno DER, rellenar a la izquierda)
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utilidades base64url
    // ─────────────────────────────────────────────────────────────────────
    public static function b64uEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $data): string
    {
        $pad = (4 - strlen($data) % 4) % 4;
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
    }
}
