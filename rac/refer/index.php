<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../affiliate_network/domain.php';

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$productId = (string)($_GET['product'] ?? '');
$ref = (string)($_GET['ref'] ?? '');
$gestorId = (string)($_GET['gestor'] ?? '');
$campaign = (string)($_GET['campaign'] ?? '');
$error = null;
$data = null;
$publicMode = 'product';

try {
    if ($gestorId !== '' && $productId === '' && $ref === '') {
        $publicMode = 'gestor';
        $data = aff_public_gestor_landing($pdo, $gestorId);
    } elseif ($campaign !== '' && $productId === '' && $ref === '') {
        $publicMode = 'campaign';
        $data = ['campaign' => $campaign, 'products' => aff_public_campaign_products($pdo, $campaign)];
    } else {
        $data = aff_refer_bootstrap($pdo, $productId, $ref);
    }
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
    .cta{margin-top:18px;display:grid;gap:12px}.btn{border:none;border-radius:14px;padding:14px 16px;font-weight:800;font-size:14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn.primary{background:linear-gradient(135deg,#ffd700,#ff8c00);color:#241700}.btn.secondary{background:linear-gradient(135deg,#fff3cf,#ffd27b);color:#3d2c06}.btn.ghost{background:rgba(80,70,50,.08);color:#4c402c}.field{display:grid;gap:6px}.field input{border:1px solid rgba(92,78,56,.24);border-radius:12px;padding:12px 14px;font-size:14px}.error{margin-top:14px;padding:14px;border-radius:14px;background:rgba(239,83,80,.14);color:#ffd7d7}.hero{display:grid;gap:18px}.emoji{font-size:54px}.meta{display:grid;gap:8px}
    .media{width:100%;border-radius:18px;overflow:hidden;background:#f4e6c8;display:grid;place-items:center;min-height:220px}.media img{display:block;width:100%;height:auto;object-fit:cover}
    .cta-row{display:flex;gap:10px;flex-wrap:wrap}.note{padding:12px 14px;border-radius:14px;background:rgba(255,215,0,.10);color:#55482f}.benefits{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.benefit{padding:12px 14px;border-radius:14px;background:rgba(255,248,220,.92);color:#3f331e;font-weight:700}.promo-band{padding:14px 16px;border-radius:16px;background:linear-gradient(135deg,#fff4d8,#ffd27b);color:#3c2a00;font-weight:800}.catalog-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.catalog-card{background:linear-gradient(180deg,rgba(255,250,238,.98) 0%,rgba(247,239,222,.96) 100%);color:#241c10;border-radius:18px;padding:18px;box-shadow:0 18px 40px rgba(0,0,0,.24)}.catalog-card .media{min-height:160px}.catalog-card h3{margin:10px 0 4px;font-size:18px}.catalog-card p{margin:0;color:#655844;font-size:13px;line-height:1.5}
  </style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="lamp">🪔</div><div><h1>RAC</h1><p class="sub">Red de Afiliados Cuba</p></div></div>
  <?php if ($error): ?>
    <div class="card"><h2>Enlace no válido</h2><p class="sub" style="margin-top:10px;">No fue posible abrir esta referencia RAC.</p><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div></div>
  <?php elseif ($publicMode === 'gestor'): ?>
    <div class="card hero">
      <div class="promo-band">🪔 Selección pública del gestor <?= htmlspecialchars($data['gestor']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?> · Código <?= htmlspecialchars($data['gestor']['maskedCode'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
      <div class="sub">Conversión <?= htmlspecialchars((string)($data['gestor']['conversions'] ?? 0), ENT_QUOTES, 'UTF-8') ?> · Rating <?= htmlspecialchars((string)($data['gestor']['rating'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="catalog-grid">
        <?php foreach (($data['products'] ?? []) as $product): ?>
          <div class="catalog-card">
            <?php if (!empty($product['hasImage'])): ?><div class="media"><img loading="lazy" src="<?= htmlspecialchars(($product['imageThumbUrl'] ?: $product['imageUrl']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"></div><?php else: ?><div class="emoji"><?= htmlspecialchars($product['image'] ?? '📦', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <h3><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="price"><?= number_format((float)$product['price'], 0, ',', '.') ?> CUP</div>
            <?php if (!empty($product['couponLabel'])): ?><div class="pill">🎁 <?= htmlspecialchars($product['couponLabel'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <div class="cta" style="margin-top:12px"><a class="btn primary" href="<?= htmlspecialchars($product['referLink'], ENT_QUOTES, 'UTF-8') ?>">🛒 Ver oferta</a></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php elseif ($publicMode === 'campaign'): ?>
    <div class="card hero">
      <div class="promo-band">📣 <?= htmlspecialchars(($data['meta']['campaignName'] ?? 'Campaña RAC'), ENT_QUOTES, 'UTF-8') ?></div>
      <?php if (!empty($data['meta']['heroText'])): ?><div class="sub"><?= htmlspecialchars($data['meta']['heroText'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <div class="catalog-grid">
        <?php foreach (($data['products'] ?? []) as $product): ?>
          <div class="catalog-card">
            <?php if (!empty($product['hasImage'])): ?><div class="media"><img loading="lazy" src="<?= htmlspecialchars(($product['imageThumbUrl'] ?: $product['imageUrl']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"></div><?php else: ?><div class="emoji"><?= htmlspecialchars($product['image'] ?? '📦', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <h3><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars($product['ownerName'] . ' · ' . $product['zone'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="price"><?= number_format((float)$product['price'], 0, ',', '.') ?> CUP</div>
            <?php if (!empty($product['couponLabel'])): ?><div class="pill">🎁 <?= htmlspecialchars($product['couponLabel'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
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
        <div class="promo-band"><?= htmlspecialchars($data['commercial']['headline'] ?? 'Compra guiada con traza RAC', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($data['commercial']['subheadline'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="price"><?= number_format((float)($data['product']['price'] ?? 0), 0, ',', '.') ?> CUP</div>
        <?php if (!empty($data['product']['couponLabel'])): ?><div class="pill">🎁 <?= htmlspecialchars($data['product']['couponLabel'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <div class="pill">🔐 Código de traza <?= htmlspecialchars($data['maskedRef'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="benefits">
          <?php foreach (($data['commercial']['benefits'] ?? []) as $benefit): ?>
            <div class="benefit">✨ <?= htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endforeach; ?>
        </div>
        <div class="note">Al pulsar contacto, RAC registra el lead y bloquea la comisión desde la garantía del dueño.</div>
      </div>
      <form method="post" class="cta">
        <div class="field"><label>Tu nombre</label><input type="text" name="client_name" placeholder="Nombre del cliente"></div>
        <div class="field"><label>Tu teléfono</label><input type="text" name="client_phone" placeholder="Teléfono o WhatsApp"></div>
        <button class="btn primary" type="submit">💬 Contactar al vendedor</button>
        <a class="btn secondary" href="https://wa.me/?text=<?= rawurlencode('Quiero esta oferta RAC: ' . ($data['product']['name'] ?? 'Producto RAC') . ' ' . ('https://' . ($_SERVER['HTTP_HOST'] ?? 'www.palweb.net') . $_SERVER['REQUEST_URI'])) ?>" target="_blank" rel="noopener">📨 Reenviar oferta</a>
        <div class="cta-row">
          <button class="btn ghost" type="button" id="copyReferLink">📋 Copiar enlace</button>
          <button class="btn ghost" type="button" id="shareReferLink">📤 Compartir</button>
          <a class="btn ghost" href="https://wa.me/?text=<?= rawurlencode(($data['product']['name'] ?? 'Producto RAC') . ' ' . ('https://' . ($_SERVER['HTTP_HOST'] ?? 'www.palweb.net') . $_SERVER['REQUEST_URI'])) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
        </div>
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
<script>
(function(){
  var shareUrl = window.location.href;
  var shareTitle = <?= json_encode(($data['product']['name'] ?? 'Producto RAC') . ' · RAC', JSON_UNESCAPED_UNICODE) ?>;
  var shareText = <?= json_encode('Mira este producto en RAC: ' . ($data['product']['name'] ?? 'Producto RAC'), JSON_UNESCAPED_UNICODE) ?>;
  var copyBtn = document.getElementById('copyReferLink');
  var shareBtn = document.getElementById('shareReferLink');
  if(copyBtn){
    copyBtn.addEventListener('click', function(){
      if(navigator.clipboard){ navigator.clipboard.writeText(shareUrl); }
      copyBtn.textContent = '✅ Enlace copiado';
      setTimeout(function(){ copyBtn.textContent = '📋 Copiar enlace'; }, 2200);
    });
  }
  if(shareBtn){
    shareBtn.addEventListener('click', function(){
      if(navigator.share){
        navigator.share({ title: shareTitle, text: shareText, url: shareUrl }).catch(function(){});
      } else if(navigator.clipboard){
        navigator.clipboard.writeText(shareUrl);
        shareBtn.textContent = '✅ Enlace copiado';
        setTimeout(function(){ shareBtn.textContent = '📤 Compartir'; }, 2200);
      }
    });
  }
})();
</script>
</body>
</html>
