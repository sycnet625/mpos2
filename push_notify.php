<?php
// ARCHIVO: push_notify.php
// Función auxiliar global para disparar notificaciones push.
// Uso: push_notify($pdo, 'operador', '🛒 Nuevo pedido', 'Juan Pérez — $1500', '/marinero/reservas.php');
// tipos: 'operador' | 'cocina' | 'cliente' | 'todos'
//   operador → personal del negocio (menu_master.php)
//   cocina   → pantalla de cocina (cocina.php)
//   cliente  → compradores suscritos desde shop.php (marketing/promociones)
//   todos    → todos los dispositivos suscritos

if (!class_exists('PushManager')) {
    require_once __DIR__ . '/push_manager.php';
}

function push_notify_is_enabled(string $eventKey): bool
{
    if ($eventKey === '') return true;
    $configFile = __DIR__ . '/pos.cfg';
    if (!is_file($configFile)) return true;
    $cfg = json_decode(file_get_contents($configFile), true) ?: [];
    $settings = is_array($cfg['notification_type_settings'] ?? null) ? $cfg['notification_type_settings'] : [];
    if (!array_key_exists($eventKey, $settings)) return true;
    return !empty($settings[$eventKey]);
}

function push_notify(
    PDO $pdo,
    string $tipo,
    string $titulo,
    string $cuerpo = '',
    string $url = '',
    string $eventKey = ''
): void
{
    try {
        if (!push_notify_is_enabled($eventKey)) return;

        // ── Auto-crear tablas si no existen ──────────────────────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            endpoint    TEXT NOT NULL,
            p256dh      VARCHAR(500) NOT NULL,
            auth        VARCHAR(200) NOT NULL,
            tipo        VARCHAR(30)  NOT NULL DEFAULT 'operador'
                        COMMENT 'operador | cocina | todos',
            device_name VARCHAR(150),
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_push   TIMESTAMP NULL,
            UNIQUE KEY uk_ep (endpoint(500))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            tipo       VARCHAR(30)  NOT NULL,
            event_key  VARCHAR(80)  NOT NULL DEFAULT '',
            titulo     VARCHAR(200) NOT NULL,
            cuerpo     TEXT,
            url        VARCHAR(500),
            leida      TINYINT(1)  DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tipo_leida (tipo, leida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("ALTER TABLE push_notifications ADD COLUMN IF NOT EXISTS event_key VARCHAR(80) NOT NULL DEFAULT '' AFTER tipo");

        // ── Persistir notificación ────────────────────────────────────────
        $pdo->prepare(
            "INSERT INTO push_notifications (tipo, event_key, titulo, cuerpo, url) VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $tipo,
            mb_substr($eventKey, 0, 80),
            mb_substr($titulo, 0, 200),
            mb_substr($cuerpo, 0, 1000),
            mb_substr($url,    0, 500),
        ]);

        // ── Obtener subscripciones destino ────────────────────────────────
        if ($tipo === 'todos') {
            $subs = $pdo->query(
                "SELECT id, endpoint, p256dh, auth FROM push_subscriptions"
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $pdo->prepare(
                "SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE tipo = ?"
            );
            $st->execute([$tipo]);
            $subs = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($subs)) return;

        // ── Enviar pings VAPID ────────────────────────────────────────────
        $vapid   = PushManager::ensureKeys();
        $pm      = new PushManager($vapid['publicKey'], $vapid['privateKey']);
        $results = $pm->sendToAll($subs);

        // ── Limpiar subscripciones caducadas (410 Gone / 404) ────────────
        foreach ($results as $subId => $code) {
            if ($code === 410 || $code === 404) {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$subId]);
            } elseif ($code >= 200 && $code < 300) {
                $pdo->prepare("UPDATE push_subscriptions SET last_push = NOW() WHERE id = ?")->execute([$subId]);
            }
        }

    } catch (Throwable $e) {
        error_log('[push_notify] ' . $e->getMessage());
    }
}
