<?php
// ARCHIVO: /var/www/palweb/api/login.php

// ── Cookie de sesión segura ───────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',   // Login no necesita tráfico cross-site
]);
session_start();

// ── Cabeceras de Seguridad HTTP ──────────────────────────────────────────────
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header("Alt-Svc: h2=\":443\"; ma=86400");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
// ─────────────────────────────────────────────────────────────────────────────
require_once 'db.php';

// --- Rate Limiting por IP ---
function rl_file($ip) {
    return sys_get_temp_dir() . '/palweb_rl_' . substr(md5($ip), 0, 16) . '.json';
}
function rl_get($ip) {
    $f = rl_file($ip);
    if (!file_exists($f)) return ['count' => 0, 'first' => time()];
    $d = json_decode(file_get_contents($f), true);
    if (!$d || (time() - ($d['first'] ?? 0)) > 600) return ['count' => 0, 'first' => time()];
    return $d;
}
function rl_fail($ip) {
    $d = rl_get($ip);
    $d['count']++;
    file_put_contents(rl_file($ip), json_encode($d), LOCK_EX);
    return $d['count'];
}
function rl_clear($ip) {
    $f = rl_file($ip);
    if (file_exists($f)) @unlink($f);
}
// --- Fin Rate Limiting ---

$error = '';
$rl_ip = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Aplicar delay si hay 5+ intentos fallidos previos (ventana: 10 min)
    $rl = rl_get($rl_ip);
    if ($rl['count'] >= 5) {
        sleep(min(2 * ($rl['count'] - 4), 30));
    }

    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';

    if (empty($user) || empty($pass)) {
        $error = "Por favor, ingresa usuario y contraseña.";
    } else {
        try {
            // CORRECCIÓN: Se cambió 'username' por 'nombre' que es lo que existe en tu BD
            $stmt = $pdo->prepare("SELECT id, nombre, password FROM users WHERE nombre = :nombre");
            $stmt->execute(['nombre' => $user]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_data && password_verify($pass, $user_data['password'])) {
                rl_clear($rl_ip);
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user'] = $user_data['nombre']; // CORRECCIÓN: Usar 'nombre'
                $_SESSION['user_id'] = $user_data['id'];

                header('Location: dashboard.php');
                exit;
            } else {
                rl_fail($rl_ip);
                $error = "Usuario o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            // Capturar error sin exponer detalles internos
            $error = "Error de sistema. Contacte al administrador.";
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ZM015S9N6M"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-ZM015S9N6M');
</script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Admin | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            background: #f8f9fa;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }
        .login-body {
            padding: 40px 30px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <h3 class="fw-bold text-primary mb-0"><i class="fas fa-user-shield me-2"></i>Admin Panel PALWEB</h3>
        <p class="text-muted small mb-0">Ingresa tus credenciales</p>
    </div>
    <div class="login-body">
        <?php if($error): ?>
            <div class="alert alert-danger text-center p-2 mb-3 small"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">Usuario</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-user text-muted"></i></span>
                    <input type="text" name="user" class="form-control" placeholder="Ej: admin" required autofocus>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold small text-muted">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="pass" class="form-control" placeholder="••••••" required>
                </div>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary fw-bold py-2">Ingresar <i class="fas fa-sign-in-alt ms-2"></i></button>
            </div>
        </form>
    </div>
    <div class="text-center pb-4">
        <a href="shop.php" class="text-decoration-none small text-muted">← Volver a la Tienda</a>
    </div>
</div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

