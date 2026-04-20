<?php
// ARCHIVO: profile_api.php
// DESCRIPCIÓN: API para gestión del perfil de usuario actual (Cambio de contraseña)

ini_set('display_errors', 0);
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'msg' => 'Sesión no iniciada o caducada.']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['status' => 'error', 'msg' => 'ID de usuario no encontrado en la sesión.']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'change_password') {
    $input = json_decode(file_get_contents('php://input'), true);
    $current_pass = $input['current_pass'] ?? '';
    $new_pass = $input['new_pass'] ?? '';

    if (empty($current_pass) || empty($new_pass)) {
        echo json_encode(['status' => 'error', 'msg' => 'Por favor complete ambos campos.']);
        exit;
    }
    if (strlen($new_pass) < 8 || !preg_match('/[A-Z]/', $new_pass) || !preg_match('/\d/', $new_pass)) {
        echo json_encode(['status' => 'error', 'msg' => 'La nueva contraseña debe tener al menos 8 caracteres, una mayúscula y un número.']);
        exit;
    }

    try {
        // 1. Obtener contraseña actual de la BD
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['status' => 'error', 'msg' => 'Usuario no encontrado en la base de datos.']);
            exit;
        }

        // 2. Verificar contraseña actual
        if (!password_verify($current_pass, $user['password'])) {
            echo json_encode(['status' => 'error', 'msg' => 'La contraseña actual es incorrecta.']);
            exit;
        }

        // 3. Hashear y actualizar nueva contraseña
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        if ($update->execute([$new_hash, $user_id])) {
            echo json_encode(['status' => 'success', 'msg' => 'Contraseña actualizada correctamente.']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Error al actualizar en la base de datos.']);
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Error de sistema: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'msg' => 'Acción no permitida.']);
exit;
