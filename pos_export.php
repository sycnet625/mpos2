<?php
// ARCHIVO: /var/www/palweb/api/pos_export.php
require_once 'db.php';

// --- CONFIGURACIÓN ---
$localPath = '/home/marinero/product_images/'; // Ruta definida en pos.php y products_table.php

// OBTENER CATEGORÍAS PARA EL FILTRO
$cats = $pdo->query("SELECT DISTINCT categoria FROM productos ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

// --- PROCESAR DESCARGAS (CSV o ZIP) ---
if (isset($_GET['action'])) {
    $empresaID  = intval($_GET['f_empresa']  ?? 1);
    $almacenID  = intval($_GET['f_almacen']  ?? 1);
    
    // Construcción dinámica de filtros
    $where = ["p.id_empresa = :emp"];
    $params = [':emp' => $empresaID];

    if (!empty($_GET['f_activo'])) { $where[] = "p.activo = :act"; $params[':act'] = $_GET['f_activo'] == '1' ? 1 : 0; }
    if (!empty($_GET['f_elab']))   { $where[] = "p.es_elaborado = :elab"; $params[':elab'] = $_GET['f_elab'] == '1' ? 1 : 0; }
    if (!empty($_GET['f_mat']))    { $where[] = "p.es_materia_prima = :mat"; $params[':mat'] = $_GET['f_mat'] == '1' ? 1 : 0; }
    if (!empty($_GET['f_serv']))   { $where[] = "p.es_servicio = :serv"; $params[':serv'] = $_GET['f_serv'] == '1' ? 1 : 0; }
    if (!empty($_GET['f_coc']))    { $where[] = "p.es_cocina = :coc"; $params[':coc'] = $_GET['f_coc'] == '1' ? 1 : 0; }
    if (!empty($_GET['f_cat']))    { $where[] = "p.categoria = :cat"; $params[':cat'] = $_GET['f_cat']; }

    $sql = "SELECT p.*, COALESCE(s.cantidad, 0) as stock_actual 
            FROM productos p 
            LEFT JOIN stock_almacen s ON p.codigo = s.id_producto AND s.id_almacen = :alm
            WHERE " . implode(" AND ", $where) . " ORDER BY p.nombre ASC";
    
    $params[':alm'] = $almacenID;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- OPCIÓN A: EXPORTAR CSV ---
    if ($_GET['action'] == 'download_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=export_productos_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

        fputcsv($output, [
            'SKU_CODIGO', 'NOMBRE', 'PRECIO_VENTA', 'COSTO', 'CATEGORIA', 
            'ACTIVO', 'STOCK_MINIMO', 'FECHA_VENCIMIENTO', 
            'ES_ELABORADO', 'ES_MATERIA_PRIMA', 'ES_SERVICIO', 'ES_COCINA',
            'UNIDAD_MEDIDA', 'DESCRIPCION', 'IMPUESTO', 'PESO', 'COLOR',
            'STOCK_ACTUAL'
        ]);

        foreach ($results as $r) {
            fputcsv($output, [
                $r['codigo'], $r['nombre'], $r['precio'], $r['costo'], $r['categoria'],
                $r['activo'], $r['stock_minimo'], $r['fecha_vencimiento'],
                $r['es_elaborado'], $r['es_materia_prima'], $r['es_servicio'], $r['es_cocina'],
                $r['unidad_medida'], $r['descripcion'], $r['impuesto'], $r['peso'], $r['color'],
                $r['stock_actual']
            ]);
        }
        fclose($output);
        exit();
    }

    // --- OPCIÓN B: EXPORTAR IMÁGENES ZIP ---
    if ($_GET['action'] == 'download_zip') {
        if (!class_exists('ZipArchive')) {
            die("Error: La extensión ZipArchive no está habilitada en este servidor.");
        }

        $zipName = 'imagenes_productos_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        
        // Crear archivo temporal
        $tempZip = tempnam(sys_get_temp_dir(), 'zip');

        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $count = 0;
            foreach ($results as $r) {
                $sku = $r['codigo'];
                $imageFile = $localPath . $sku . '.jpg';
                
                if (file_exists($imageFile)) {
                    // Añadir al ZIP con el nombre del SKU
                    $zip->addFile($imageFile, $sku . '.jpg');
                    $count++;
                }
            }
            $zip->close();

            if ($count === 0) {
                die("No se encontraron imágenes para los productos seleccionados.");
            }

            // Entregar archivo
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($tempZip));
            readfile($tempZip);
            unlink($tempZip); // Borrar temporal
            exit();
        } else {
            die("No se pudo crear el archivo ZIP.");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Exportador de Catálogo | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style> 
        body { background-color: #f1f5f9; font-family: 'Segoe UI', sans-serif; } 
        .card-export { max-width: 850px; margin: 40px auto; border-radius: 15px; border:none; box-shadow: 0 10px 25px rgba(0,0,0,0.05); } 
        .btn-zip { background-color: #6f42c1; color: white; }
        .btn-zip:hover { background-color: #59359a; color: white; }
    </style>
</head>
<body>
<div class="container">
    <div class="card card-export">
        <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
            <h4 class="mb-0"><i class="fas fa-file-export me-2"></i> Exportar Base de Datos</h4>
            <a href="pos_import.php" class="btn btn-sm btn-outline-light">Volver al Importador</a>
        </div>
        <div class="card-body p-4">
            <form method="GET" id="exportForm">
                <div class="row g-3">
                    <div class="col-md-4"><label class="small fw-bold">Empresa ID</label><input type="number" name="f_empresa" class="form-control" value="1"></div>
                    <div class="col-md-4"><label class="small fw-bold">Sucursal ID</label><input type="number" name="f_sucursal" class="form-control" value="1"></div>
                    <div class="col-md-4"><label class="small fw-bold">Almacén (para Stock)</label><input type="number" name="f_almacen" class="form-control" value="1"></div>
                    
                    <div class="col-md-6">
                        <label class="small fw-bold">Categoría</label>
                        <select name="f_cat" class="form-select">
                            <option value="">-- Todas las Categorías --</option>
                            <?php foreach($cats as $c) echo "<option value='$c'>$c</option>"; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="small fw-bold">Estado Activo</label>
                        <select name="f_activo" class="form-select"><option value="">Todos</option><option value="1">Solo Activos</option><option value="0">Solo Inactivos</option></select>
                    </div>

                    <div class="col-12 mt-4"><h6 class="border-bottom pb-2 fw-bold text-muted">Filtros de Tipo</h6></div>
                    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="f_elab" value="1" id="c1"><label class="form-check-label small" for="c1">Solo Elaborados</label></div></div>
                    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="f_mat" value="1" id="c2"><label class="form-check-label small" for="c2">Solo Materia Prima</label></div></div>
                    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="f_serv" value="1" id="c3"><label class="form-check-label small" for="c3">Solo Servicios</label></div></div>
                    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="f_coc" value="1" id="c4"><label class="form-check-label small" for="c4">Solo para Cocina</label></div></div>
                </div>

                <div class="row g-3 mt-5">
                    <div class="col-md-6">
                        <button type="submit" name="action" value="download_csv" class="btn btn-success btn-lg w-100 fw-bold shadow-sm">
                            <i class="fas fa-file-excel me-2"></i> EXPORTAR EXCEL (.CSV)
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" name="action" value="download_zip" class="btn btn-zip btn-lg w-100 fw-bold shadow-sm">
                            <i class="fas fa-file-archive me-2"></i> DESCARGAR IMÁGENES (ZIP)
                        </button>
                    </div>
                </div>
            </form>
            <div class="mt-4 alert alert-light border small text-muted">
                <i class="fas fa-info-circle me-1"></i> La descarga de imágenes buscará archivos <b>.jpg</b> en el servidor que coincidan con el <b>Código SKU</b> de los productos filtrados.
            </div>
        </div>
    </div>
</div>



<?php include_once 'menu_master.php'; ?>
</body>
</html>


