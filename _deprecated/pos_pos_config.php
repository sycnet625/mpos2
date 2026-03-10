<?php
// ARCHIVO: /var/www/palweb/api/pos_config.php

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 0);
require_once 'db.php';

$configFile = __DIR__ . '/pos.cfg';
$defaultConfig = [
    "tienda_nombre" => "MI TIENDA",
    "direccion" => "Dirección",
    "telefono" => "",
    "mensaje_final" => "Gracias",
    "id_empresa" => 1, "id_sucursal" => 1, "id_almacen" => 1,
    "mensajeria_tarifa_km" => 150, // NUEVO CAMPO DEFAULT
    "mostrar_materias_primas" => false,
    "mostrar_servicios" => true,
    "categorias_ocultas" => [],
    "cajeros" => [["nombre" => "Admin", "pin" => "0000"]]
];

$currentConfig = $defaultConfig;
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $currentConfig = array_merge($defaultConfig, $loaded);
}

// Lógica de Guardado
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $newConfig = [
            "tienda_nombre" => trim($_POST['tienda_nombre']),
            "direccion" => trim($_POST['direccion']),
            "telefono" => trim($_POST['telefono']),
            "mensaje_final" => trim($_POST['mensaje_final']),
            "id_empresa" => intval($_POST['id_empresa']),
            "id_sucursal" => intval($_POST['id_sucursal']),
            "id_almacen" => intval($_POST['id_almacen']),
            "mensajeria_tarifa_km" => floatval($_POST['mensajeria_tarifa_km']), // GUARDAR TARIFA
            "mostrar_materias_primas" => isset($_POST['mostrar_materias_primas']),
            "mostrar_servicios" => isset($_POST['mostrar_servicios']),
            "categorias_ocultas" => $_POST['categorias_ocultas'] ?? [],
            "cajeros" => []
        ];

        if (isset($_POST['cajero_nombre'])) {
            for ($i = 0; $i < count($_POST['cajero_nombre']); $i++) {
                if(!empty($_POST['cajero_nombre'][$i])) {
                    $newConfig['cajeros'][] = ["nombre" => $_POST['cajero_nombre'][$i], "pin" => $_POST['cajero_pin'][$i]];
                }
            }
        }
        if(empty($newConfig['cajeros'])) $newConfig['cajeros'][] = ["nombre"=>"Admin", "pin"=>"0000"];

        file_put_contents($configFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $currentConfig = $newConfig;
        $msg = "Guardado correctamente.";
    } catch (Exception $e) { $msg = "Error: ".$e->getMessage(); }
}

// Obtener categorías para ocultar
try {
    $dbCategories = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE activo=1")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $dbCategories = []; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Configuración</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>body{background:#f4f6f9;font-family:'Segoe UI',sans-serif}.card{border:none;box-shadow:0 4px 6px rgba(0,0,0,0.05);margin-bottom:20px}</style>
</head>
<body class="p-4">
<div class="container" style="max-width:900px">
    <div class="d-flex justify-content-between mb-4"><h3><i class="fas fa-cogs"></i> Configuración</h3><a href="dashboard.php" class="btn btn-secondary">Volver</a></div>
    <?php if($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
    
    <form method="POST">
        <div class="card"><div class="card-header fw-bold text-primary">Negocio</div><div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Nombre</label><input type="text" name="tienda_nombre" class="form-control" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>" required></div>
            <div class="col-md-6"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>"></div>
            <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>"></div>
            <div class="col-12"><label class="form-label">Mensaje Ticket</label><input type="text" name="mensaje_final" class="form-control" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>"></div>
        </div></div>

        <div class="card"><div class="card-header fw-bold text-danger">Técnico y Envíos</div><div class="card-body row g-3">
            <div class="col-md-3"><label class="form-label">ID Empresa</label><input type="number" name="id_empresa" class="form-control" value="<?php echo $currentConfig['id_empresa']; ?>"></div>
            <div class="col-md-3"><label class="form-label">ID Sucursal</label><input type="number" name="id_sucursal" class="form-control" value="<?php echo $currentConfig['id_sucursal']; ?>"></div>
            <div class="col-md-3"><label class="form-label">ID Almacén</label><input type="number" name="id_almacen" class="form-control" value="<?php echo $currentConfig['id_almacen']; ?>"></div>
            <div class="col-md-3"><label class="form-label text-success fw-bold">Tarifa KM ($)</label><input type="number" name="mensajeria_tarifa_km" class="form-control border-success" step="0.01" value="<?php echo $currentConfig['mensajeria_tarifa_km']; ?>"></div>
        </div></div>

        <div class="card"><div class="card-header fw-bold text-info">Cajeros</div><div class="card-body">
            <div id="cajerosList">
                <?php foreach($currentConfig['cajeros'] as $c): ?>
                <div class="row g-2 mb-2 item">
                    <div class="col-5"><input type="text" name="cajero_nombre[]" class="form-control" value="<?php echo $c['nombre']; ?>"></div>
                    <div class="col-5"><input type="text" name="cajero_pin[]" class="form-control" value="<?php echo $c['pin']; ?>"></div>
                    <div class="col-2"><button type="button" class="btn btn-outline-danger" onclick="this.closest('.item').remove()">X</button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addCajero()">+ Agregar</button>
        </div></div>

        <button type="submit" class="btn btn-primary btn-lg w-100 shadow">GUARDAR</button>
    </form>
</div>
<script>
function addCajero(){
    const d=document.createElement('div');d.className='row g-2 mb-2 item';
    d.innerHTML='<div class="col-5"><input type="text" name="cajero_nombre[]" class="form-control"></div><div class="col-5"><input type="text" name="cajero_pin[]" class="form-control"></div><div class="col-2"><button type="button" class="btn btn-outline-danger" onclick="this.closest(\'.item\').remove()">X</button></div>';
    document.getElementById('cajerosList').appendChild(d);
}
</script>
</body>
</html>
