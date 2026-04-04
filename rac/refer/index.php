<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../affiliate_network/domain.php';

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$productId = (string)($_GET['product'] ?? '');
$ref = (string)($_GET['ref'] ?? '');
$error = null;
$data = null;

try {
    $data = aff_refer_bootstrap($pdo, $productId, $ref);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        $result = aff_trigger_contact($pdo, $productId, $ref, [
            'client_name' => (string)($_POST['client_name'] ?? ''),
            'client_phone' => (string)($_POST['client_phone'] ?? ''),
        ]);
        if (!empty($result['redirect_url'])) {
            header('Location: ' . $result['redirect_url']);
            exit;
        }
        $error = 'No fue posible generar el enlace de contacto.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RAC · Referido</title>
  <style>
    body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#0a0800;color:#f8edd2}
    .wrap{max-width:760px;margin:0 auto;padding:24px 14px 48px}.card{background:linear-gradient(180deg,rgba(255,250,238,.98) 0%,rgba(247,239,222,.96) 100%);color:#241c10;border-radius:22px;padding:22px;box-shadow:0 18px 40px rgba(0,0,0,.3)}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:20px}.brand .lamp{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,#ffd700,#ff8c00);color:#1b1200;font-size:24px}
    h1,h2,h3,p{margin:0}.sub{color:#6a604d}.price{font-size:30px;font-weight:900;color:#241c10;margin-top:10px}.pill{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(80,70,50,.08);color:#5d533f;font-weight:700;font-size:12px;margin:14px 0 0}
    .cta{margin-top:18px;display:grid;gap:12px}.btn{border:none;border-radius:14px;padding:14px 16px;font-weight:800;font-size:14px;cursor:pointer}.btn.primary{background:linear-gradient(135deg,#ffd700,#ff8c00);color:#241700}.field{display:grid;gap:6px}.field input{border:1px solid rgba(92,78,56,.24);border-radius:12px;padding:12px 14px;font-size:14px}.error{margin-top:14px;padding:14px;border-radius:14px;background:rgba(239,83,80,.14);color:#ffd7d7}.hero{display:grid;gap:18px}.emoji{font-size:54px}.meta{display:grid;gap:8px}
    .media{width:100%;border-radius:18px;overflow:hidden;background:#f4e6c8;display:grid;place-items:center;min-height:220px}.media img{display:block;width:100%;height:auto;object-fit:cover}
    .cta-row{display:flex;gap:10px;flex-wrap:wrap}.note{padding:12px 14px;border-radius:14px;background:rgba(255,215,0,.10);color:#55482f}
  </style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="lamp">🪔</div><div><h1>RAC</h1><p class="sub">Red de Afiliados Cuba</p></div></div>
  <?php if ($error): ?>
    <div class="card"><h2>Enlace no válido</h2><p class="sub" style="margin-top:10px;">No fue posible abrir esta referencia RAC.</p><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div></div>
  <?php else: ?>
    <div class="card hero">
      <?php if (!empty($data['product']['hasImage'])): ?>
        <div class="media">
          <picture>
            <?php if (!empty($data['product']['imageWebpUrl'])): ?>
              <source srcset="<?= htmlspecialchars($data['product']['imageWebpUrl'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp">
            <?php endif; ?>
            <img loading="lazy" src="<?= htmlspecialchars(($data['product']['imageUrl'] ?: $data['product']['imageWebpUrl']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($data['product']['name'] ?? 'Producto RAC', ENT_QUOTES, 'UTF-8') ?>">
          </picture>
        </div>
      <?php else: ?>
        <div class="emoji"><?= htmlspecialchars($data['product']['image'] ?? '📦', ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div class="meta">
        <div class="sub">Producto recomendado por <?= htmlspecialchars($data['gestor']['name'] ?? 'Gestor RAC', ENT_QUOTES, 'UTF-8') ?></div>
        <h2><?= htmlspecialchars($data['product']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="sub"><?= htmlspecialchars($data['product']['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        <div class="price"><?= number_format((float)($data['product']['price'] ?? 0), 0, ',', '.') ?> CUP</div>
        <?php if (!empty($data['product']['couponLabel'])): ?><div class="pill">🎁 <?= htmlspecialchars($data['product']['couponLabel'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <div class="pill">🔐 Código de traza <?= htmlspecialchars($data['maskedRef'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="note">Al pulsar contacto, RAC registra el lead y bloquea la comisión desde la garantía del dueño.</div>
      </div>
      <form method="post" class="cta">
        <div class="field"><label>Tu nombre</label><input type="text" name="client_name" placeholder="Nombre del cliente"></div>
        <div class="field"><label>Tu teléfono</label><input type="text" name="client_phone" placeholder="Teléfono o WhatsApp"></div>
        <button class="btn primary" type="submit">💬 Contactar al vendedor</button>
        <div class="cta-row">
          <div class="pill">🏪 <?= htmlspecialchars($data['owner']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          <div class="pill">⭐ Reputación <?= htmlspecialchars((string)($data['owner']['reputationScore'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
          <?php if (!empty($data['owner']['zone'])): ?><div class="pill">📍 <?= htmlspecialchars($data['owner']['zone'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        </div>
      </form>
      <div class="sub">Dueño: <?= htmlspecialchars($data['owner']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?> · Zona: <?= htmlspecialchars($data['owner']['zone'] ?? '', ENT_QUOTES, 'UTF-8') ?> · Reputación: <?= htmlspecialchars((string)($data['owner']['reputationScore'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
