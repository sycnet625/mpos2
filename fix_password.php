<?php
// ARCHIVO: fix_password.php
// UTILIDAD: Resetear contraseña de administrador con hash compatible
require_once 'db.php';

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user']);
    $pass = $_POST['new_pass'];

    if (!empty($user) && !empty($pass)) {
        try {
            // 1. Verificar si el usuario existe
            $stmt = $pdo->prepare("SELECT id FROM users WHERE nombre = ?");
            $stmt->execute([$user]);
            
            if ($stmt->fetch()) {
                // 2. Encriptar contraseña con el estándar actual de PHP
                $hash = password_hash($pass, PASSWORD_DEFAULT);

                // 3. Actualizar en BD
                $update = $pdo->prepare("UPDATE users SET password = ? WHERE nombre = ?");
                $update->execute([$hash, $user]);

                $msg = "<div class='alert alert-success'>
                            <i class='fas fa-check-circle'></i> Contraseña actualizada correctamente.<br>
                            Ya puedes <a href='login.php'>iniciar sesión</a>.
                        </div>";
            } else {
                $msg = "<div class='alert alert-danger'>El usuario <b>$user</b> no existe en la tabla 'users'.</div>";
            }
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>Error SQL: " . $e->getMessage() . "</div>";
        }
    } else {
        $msg = "<div class='alert alert-warning'>Por favor llena ambos campos.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reparar Acceso</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #e9ecef; display: flex; align-items: center; justify-content: center; height: 100vh; }</style>
</head>
<body>
    <div class="card shadow" style="width: 400px;">
        <div class="card-header bg-warning text-dark fw-bold text-center">
            RESETEAR CONTRASEÑA ADMIN
        </div>
        <div class="card-body">
            <?php echo $msg; ?>
            <form method="POST">
                <div class="mb-3">
                    <label>Usuario (nombre en BD):</label>
                    <input type="text" name="user" class="form-control" placeholder="Ej: admin" required>
                </div>
                <div class="mb-3">
                    <label>Nueva Contraseña:</label>
                    <input type="text" name="new_pass" class="form-control" placeholder="Ej: 123456" required>
                </div>
                <button type="submit" class="btn btn-dark w-100">Actualizar Hash</button>
            </form>
        </div>
        <div class="card-footer text-center small text-muted">
            ⚠️ Borra este archivo al terminar
        </div>
    </div>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

