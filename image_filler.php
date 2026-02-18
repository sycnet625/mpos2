<?php
// ARCHIVO: /var/www/palweb/api/image_filler_pexels.php
// DESCRIPCIÓN: Rellenar imágenes usando PEXELS API (Gratis y Fácil)

// ==========================================
// 1. CONFIGURACIÓN
// ==========================================
// Pega aquí tu clave de Pexels:
define('PEXELS_API_KEY', 'RIF7J3NM9hhMei93oSqqlg7JPeC9qfuIiGF2eR8jDPWMPyLOxM8UnLM4'); 

$localImgPath = '/home/marinero/product_images/';

set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Cargar Configuración
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);

// DB
try { require_once 'db.php'; } catch (Exception $e) { die("Error DB"); }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pexels Image Loader</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #111; color: #fff; padding: 20px; }
        .log { background: #222; padding: 10px; margin-bottom: 5px; border-radius: 4px; border-left: 5px solid #444; display:flex; align-items:center; justify-content:space-between;}
        .success { border-left-color: #2ecc71; }
        .error { border-left-color: #e74c3c; }
        .warn { border-left-color: #f1c40f; }
        a { color: #3498db; text-decoration: none; }
    </style>
</head>
<body>
<h1>📸 Buscador Pexels (Stock Photos)</h1>
<p>Buscando fotos profesionales para tus productos...</p>
<hr>

<?php
// Obtener productos sin imagen (o todos si quieres probar)
$sql = "SELECT codigo, nombre, categoria FROM productos WHERE activo = 1 AND id_empresa = $EMP_ID";
$stmt = $pdo->query($sql);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contador = 0;

foreach ($productos as $p) {
    $codigo = $p['codigo'];
    $nombre = $p['nombre'];
    // Limpiamos el nombre para buscar mejor (quitamos numeros o marcas raras)
    $queryBusqueda = limpiarNombre($nombre . " " . $p['categoria']); 
    
    $archivoDestino = $localImgPath . $codigo . '.jpg';

    // Si ya existe, saltar
    if (file_exists($archivoDestino) && filesize($archivoDestino) > 0) {
        // echo "<div class='log'>📦 $nombre: Ya tiene foto</div>";
        continue;
    }

    echo "<div class='log'>";
    echo "<span>🔍 Buscando: <strong>$queryBusqueda</strong> ($nombre)</span>";

    // --- LLAMADA A PEXELS ---
    $url = "https://api.pexels.com/v1/search?query=" . urlencode($queryBusqueda) . "&per_page=1&orientation=square";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: " . PEXELS_API_KEY
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $json = json_decode($response, true);
        
        if (isset($json['photos'][0]['src']['medium'])) {
            $imgUrl = $json['photos'][0]['src']['medium']; // URL de la foto
            
            // Descargar
            $imgData = @file_get_contents($imgUrl);
            if ($imgData) {
                file_put_contents($archivoDestino, $imgData);
                echo "<span style='color:#2ecc71'>✅ DESCARGADA</span>";
                $contador++;
            } else {
                echo "<span style='color:#e74c3c'>❌ Error guardando</span>";
            }
        } else {
            echo "<span style='color:#f1c40f'>⚠️ Sin resultados en Pexels</span>";
            // FALLBACK: Si Pexels no tiene, usamos el generador de color
            usarPlaceholder($nombre, $archivoDestino);
            echo " <small>(Usando genérico)</small>";
        }
    } else {
        echo "<span style='color:#e74c3c'>❌ Error API ($httpCode). Revisa tu Clave.</span>";
    }

    echo "</div>";
    flush();
    // Pexels permite 200/hora, vamos rápido pero con pausa pequeña
    usleep(200000); // 0.2 segundos
}

echo "<br><h3>Fin. Se descargaron $contador imágenes.</h3>";

// FUNCIONES AUXILIARES

function limpiarNombre($str) {
    // Quitar cosas como "330ml", "1kg", caracteres raros para buscar mejor en inglés/español
    $str = preg_replace('/[0-9]+(ml|kg|g|L|lb)/i', '', $str); 
    return trim($str);
}

function usarPlaceholder($nombre, $path) {
    $hash = md5($nombre);
    $bg = substr($hash, 0, 6);
    $url = "https://placehold.co/600x600/$bg/FFF.jpg?text=" . urlencode($nombre);
    $data = @file_get_contents($url);
    if($data) file_put_contents($path, $data);
}
?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

