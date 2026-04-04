<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);
aff_session_start_if_needed();

if (aff_is_authenticated()) {
    header('Location: /affiliate_network.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        aff_login($pdo, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        header('Location: /affiliate_network.php');
        exit;
    } catch (Throwable $e) {
        $error = 'Credenciales inválidas o usuario suspendido.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acceso RAC</title>
  <link rel="stylesheet" href="/affiliate_network/styles.css">
</head>
<body class="rac-auth-page">
  <main class="rac-auth-wrap">
    <section class="card rac-auth-card">
      <h1>Acceso RAC</h1>
      <p class="sub">Inicia sesión con tus credenciales propias del módulo para entrar como dueño, gestor o admin.</p>
      <?php if ($error !== ''): ?>
      <div class="toast error" style="position:static;display:block;margin:0 0 16px 0;opacity:1;transform:none;pointer-events:auto;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post" class="stack">
        <label class="field">
          <span>Usuario</span>
          <input class="input" type="text" name="username" autocomplete="username" required>
        </label>
        <label class="field">
          <span>Contraseña</span>
          <input class="input" type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn primary" type="submit">Entrar a RAC</button>
      </form>
      <div class="card" style="margin-top:16px">
        <div class="sub" style="margin-bottom:8px">Credenciales demo iniciales</div>
        <div class="item"><strong>Admin:</strong> `racadmin` / `123456`</div>
        <div class="item"><strong>Dueño:</strong> `dueno.d0042` / `123456`</div>
        <div class="item"><strong>Gestor:</strong> `gestor.g001` / `123456`</div>
      </div>
    </section>
  </main>
</body>
</html>
