<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: Listar categorías
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// POST: Crear o Editar
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Si es un FormData (no JSON body)
    if (!$data) {
        $data = $_POST;
    }

    $action = $data['action'] ?? 'create';
    $nombre = trim($data['nombre'] ?? '');
    $emoji  = $data['emoji'] ?? '';
    $color  = $data['color'] ?? '';
    $id     = intval($data['id'] ?? 0);

    try {
        if ($action === 'create') {
            if (!$nombre) {
                echo json_encode(['status' => 'error', 'msg' => 'El nombre es obligatorio']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO categorias (nombre, emoji, color) VALUES (?, ?, ?)");
            $stmt->execute([$nombre, $emoji, $color]);
            echo json_encode(['status' => 'success', 'msg' => 'Categoría creada', 'id' => $pdo->lastInsertId()]);
        } elseif ($action === 'update') {
            if (!$id) throw new Exception("ID inválido para actualización");
            if (!$nombre) {
                echo json_encode(['status' => 'error', 'msg' => 'El nombre es obligatorio']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE categorias SET nombre = ?, emoji = ?, color = ? WHERE id = ?");
            $stmt->execute([$nombre, $emoji, $color, $id]);
            echo json_encode(['status' => 'success', 'msg' => 'Categoría actualizada']);
        } elseif ($action === 'delete') {
            if (!$id) throw new Exception("ID inválido para eliminación");
            // Verificar si hay productos usando esta categoría (opcional, pero recomendado)
            // Por ahora solo borramos de la tabla categorias
            $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'msg' => 'Categoría eliminada']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}
?>