<?php
// ARCHIVO: /var/www/palweb/api/db_debug.php
// ⚠️ MODO DIOS V7.1: PAGINACIÓN + TEMA OSCURO + FILTROS
// Descripción: Editor de BD con diseño Dark Mode recuperado y paginación real server-side.

ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once 'db.php';

// 1. LISTAR TABLAS
$dbTables = [];
try {
    $stmt = $pdo->query("SHOW TABLES");
    $dbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $dbTables = []; }

// --- API BACKEND ---
if (isset($_GET['action'])) {
    // Limpiar buffer
    while (ob_get_level()) ob_end_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'];

    try {
        $table = $_GET['table'] ?? ($input['table'] ?? '');
        
        if ($action !== 'update' && (empty($table) || !in_array($table, $dbTables))) {
             throw new Exception("Tabla no seleccionada o inválida.");
        }

        // A. CARGAR DATOS (CON PAGINACIÓN)
        if ($action === 'load') {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            if ($page < 1) $page = 1;
            if ($limit < 1) $limit = 50;
            $offset = ($page - 1) * $limit;

            // 1. Detectar PK
            $stmtPK = $pdo->prepare("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
            $stmtPK->execute();
            $keys = $stmtPK->fetchAll(PDO::FETCH_ASSOC);
            $pk = !empty($keys) ? $keys[0]['Column_name'] : null;

            // 2. Contar Total (Para la paginación)
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM `$table`");
            $totalRecords = $stmtCount->fetchColumn();
            $totalPages = ceil($totalRecords / $limit);

            // 3. Obtener Datos
            $orderBy = $pk ? "ORDER BY `$pk` DESC" : ""; 
            $sql = "SELECT * FROM `$table` $orderBy LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = !empty($rows) ? array_keys($rows[0]) : [];
            
            if (empty($columns)) {
                $stmtCol = $pdo->query("DESCRIBE `$table`");
                $columns = $stmtCol->fetchAll(PDO::FETCH_COLUMN);
            }

            echo json_encode([
                'pk' => $pk,
                'columns' => $columns,
                'rows' => $rows,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_records' => $totalRecords,
                    'total_pages' => $totalPages
                ]
            ]);
            exit;
        }

        // B. ACTUALIZAR CELDA
        if ($action === 'update') {
            if (!$input) throw new Exception("No payload");
            $tbl = $input['table'];
            $pkField = $input['pk'];
            $idVal = $input['id'];
            $col = $input['col'];
            $val = $input['val'];

            if (!in_array($tbl, $dbTables)) throw new Exception("Seguridad: Tabla inválida.");

            $sql = "UPDATE `$tbl` SET `$col` = ? WHERE `$pkField` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$val, $idVal]);

            echo json_encode(['status' => 'ok']);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Debugger - Dark Mode</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        /* TEMA OSCURO (DARK MODE) RESTAURADO */
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: 'Segoe UI', monospace;
            font-size: 0.85rem;
        }

        /* Contenedor Principal */
        .table-container {
            background-color: #1e1e1e;
            border: 1px solid #333;
            border-radius: 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            height: 85vh; /* Ocupar casi toda la pantalla */
        }

        /* Toolbar Superior */
        .toolbar {
            background-color: #252525;
            padding: 10px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Selects e Inputs Oscuros */
        .form-select, .form-control {
            background-color: #333;
            color: #fff;
            border: 1px solid #444;
        }
        .form-select:focus, .form-control:focus {
            background-color: #404040;
            color: #fff;
            border-color: #0d6efd;
            box-shadow: none;
        }

        /* Tabla */
        .table-wrapper {
            flex: 1;
            overflow: auto;
            position: relative;
        }
        table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border-color: #333;
        }
        
        th, td {
            border: 1px solid #333 !important;
            padding: 4px 8px;
            vertical-align: middle;
        }

        /* Encabezados Sticky */
        thead th {
            position: sticky;
            top: 0;
            background-color: #2c2c2c !important;
            color: #ccc;
            z-index: 10;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        /* Input de Filtro bajo el encabezado */
        .filter-row th {
            top: 30px; /* Ajuste aproximado según altura header */
            background-color: #252525 !important;
            padding: 2px;
            z-index: 9;
        }
        .filter-input {
            width: 100%;
            background: #1a1a1a;
            border: 1px solid #444;
            color: #0dcaf0; /* Cyan para diferenciar filtro */
            font-size: 0.75rem;
            padding: 2px 5px;
        }
        .filter-input::placeholder { color: #555; }

        /* Celdas Editables */
        .cell-input {
            width: 100%;
            min-width: 100px;
            background: transparent;
            border: none;
            color: #ddd;
            font-family: monospace;
            padding: 2px;
        }
        .cell-input:focus {
            background-color: #000;
            color: #fff;
            outline: 1px solid #0d6efd;
        }

        /* Columna PK */
        .pk-col input {
            color: #ffc107; /* Amarillo para ID */
            font-weight: bold;
            text-align: center;
            background: #2a2a2a;
        }

        /* Footer de Paginación */
        .pagination-footer {
            background-color: #252525;
            padding: 8px 15px;
            border-top: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
        }
        
        .page-link {
            background-color: #333;
            border-color: #444;
            color: #aaa;
        }
        .page-link:hover {
            background-color: #444;
            color: #fff;
        }
        .page-item.active .page-link {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .page-item.disabled .page-link {
            background-color: #222;
            border-color: #333;
            color: #555;
        }

        /* Estados de Guardado */
        .saving { color: #ffc107 !important; }
        .saved { color: #198754 !important; font-weight: bold; }
        .error { background-color: #58151c !important; color: #fff !important; }

        /* Scrollbars oscuros */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #1a1a1a; }
        ::-webkit-scrollbar-thumb { background: #444; border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="p-3">

    <div class="container-fluid h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="m-0 text-white"><i class="fas fa-terminal text-success"></i> DEBUGGER <span class="text-muted">| Dark Mode</span></h5>
            <div>
                <button id="btnForce" class="btn btn-danger btn-sm" onclick="retryLastSave()" style="display:none;">
                    <i class="fas fa-sync"></i> REINTENTAR GUARDADO
                </button>
                <a href="index.php" class="btn btn-outline-light btn-sm">Salir</a>
            </div>
        </div>

        <div class="table-container">
            <div class="toolbar">
                <div class="d-flex align-items-center gap-2">
                    <label class="text-muted small">Tabla:</label>
                    <select id="tableSelector" class="form-select form-select-sm" style="width: 200px;">
                        <option value="">Seleccionar...</option>
                        <?php foreach($dbTables as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="loadTable(1)">
                        <i class="fas fa-play"></i> Cargar
                    </button>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <label class="text-muted small">Registros:</label>
                    <select id="limitSelector" class="form-select form-select-sm" style="width: 80px;" onchange="loadTable(1)">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="500">500</option>
                    </select>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="table table-dark table-hover mb-0" id="dataTable">
                    <thead id="tableHead">
                        </thead>
                    <tbody id="tableBody">
                        <tr><td class="text-center text-muted p-5">Selecciona una tabla para comenzar...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination-footer" id="paginationFooter" style="display:none;">
                <div class="text-muted" id="recordsInfo">
                    </div>
                <nav>
                    <ul class="pagination pagination-sm m-0" id="paginationUl">
                        </ul>
                </nav>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="liveToast" class="toast align-items-center text-white bg-dark border-secondary" role="alert">
            <div class="d-flex">
                <div class="toast-body" id="toastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // ESTADO
    let currentTable = '';
    let currentPK = '';
    let lastErrorCtx = null;
    let currentPage = 1;
    let currentLimit = 50;

    const toastEl = document.getElementById('liveToast');
    const toast = new bootstrap.Toast(toastEl);
    
    function notify(msg, type = 'info') {
        const colors = { success: 'bg-success', danger: 'bg-danger', info: 'bg-dark' };
        toastEl.className = `toast align-items-center text-white border-0 ${colors[type] || 'bg-dark'}`;
        document.getElementById('toastMsg').innerText = msg;
        toast.show();
    }

    // CARGAR TABLA
    function loadTable(page = 1) {
        const table = document.getElementById('tableSelector').value;
        const limit = document.getElementById('limitSelector').value;
        if (!table) return;

        currentTable = table;
        currentPage = page;
        
        const tbody = document.getElementById('tableBody');
        const thead = document.getElementById('tableHead');
        tbody.style.opacity = '0.3'; // Efecto carga visual

        fetch(`db_debug.php?action=load&table=${table}&page=${page}&limit=${limit}`)
            .then(res => res.json())
            .then(data => {
                tbody.style.opacity = '1';
                
                if (data.error) {
                    notify(data.error, 'danger');
                    return;
                }

                currentPK = data.pk;
                
                // A. RENDERIZAR ENCABEZADOS Y FILTROS
                let headerHTML = '<tr>';
                let filterHTML = '<tr class="filter-row">'; // Fila de filtros restaurada
                
                data.columns.forEach(col => {
                    const isPK = (col === currentPK);
                    headerHTML += `<th class="${isPK?'text-warning':''}">${col} ${isPK?'🔑':''}</th>`;
                    // Input de filtro para cada columna
                    filterHTML += `<th><input type="text" class="filter-input" placeholder="Filtrar..." onkeyup="filterTable(this, ${data.columns.indexOf(col)})"></th>`;
                });
                
                headerHTML += '</tr>';
                filterHTML += '</tr>';
                thead.innerHTML = headerHTML + filterHTML;

                // B. RENDERIZAR DATOS
                let bodyHTML = '';
                if(data.rows.length === 0) {
                    bodyHTML = `<tr><td colspan="${data.columns.length}" class="text-center p-4 text-muted">Sin resultados en esta página</td></tr>`;
                } else {
                    data.rows.forEach(row => {
                        bodyHTML += '<tr>';
                        data.columns.forEach(col => {
                            const val = row[col] !== null ? row[col] : 'NULL';
                            const isPK = (col === currentPK);
                            const safeVal = String(val).replace(/"/g, '&quot;');
                            
                            // Input nativo
                            const input = `<input type="text" 
                                            class="cell-input" 
                                            value="${val !== 'NULL' ? safeVal : ''}" 
                                            placeholder="${val === 'NULL' ? 'NULL' : ''}"
                                            data-original="${safeVal}"
                                            data-col="${col}"
                                            data-id="${row[currentPK]}"
                                            ${isPK ? 'readonly' : 'onchange="saveCell(this)"'} 
                                           >`;
                            
                            bodyHTML += `<td class="${isPK ? 'pk-col' : ''}">${input}</td>`;
                        });
                        bodyHTML += '</tr>';
                    });
                }
                tbody.innerHTML = bodyHTML;

                // C. RENDERIZAR PAGINACIÓN
                renderPagination(data.pagination);
                document.getElementById('paginationFooter').style.display = 'flex';
            })
            .catch(err => {
                tbody.style.opacity = '1';
                notify("Error de conexión", "danger");
            });
    }

    // FILTRO CLIENT-SIDE (Para los datos visibles)
    window.filterTable = function(input, colIndex) {
        const filter = input.value.toUpperCase();
        const table = document.getElementById("dataTable");
        const tr = table.getElementsByTagName("tr");

        // Empezar en 2 porque 0 es header y 1 es filtros
        for (let i = 2; i < tr.length; i++) {
            const td = tr[i].getElementsByTagName("td")[colIndex];
            if (td) {
                // Buscamos dentro del input value
                const cellInput = td.querySelector('input');
                const txtValue = cellInput ? cellInput.value : td.textContent;
                
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    };

    function renderPagination(meta) {
        const start = ((meta.current_page - 1) * meta.per_page) + 1;
        const end = Math.min(start + meta.per_page - 1, meta.total_records);
        
        document.getElementById('recordsInfo').innerText = 
            `${start} - ${end} de ${meta.total_records} | Pág ${meta.current_page}/${meta.total_pages}`;

        const ul = document.getElementById('paginationUl');
        let html = '';

        // Prev
        html += `<li class="page-item ${meta.current_page == 1 ? 'disabled' : ''}">
                    <button class="page-link" onclick="loadTable(${meta.current_page - 1})"><i class="fas fa-chevron-left"></i></button>
                 </li>`;

        // Lógica comprimida de botones (1... 5 6 7 ... N)
        if (meta.total_pages <= 7) {
            for(let i=1; i<=meta.total_pages; i++) html += pageBtn(i, meta.current_page);
        } else {
            html += pageBtn(1, meta.current_page);
            if(meta.current_page > 3) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            
            let s = Math.max(2, meta.current_page - 1);
            let e = Math.min(meta.total_pages - 1, meta.current_page + 1);
            for(let i=s; i<=e; i++) html += pageBtn(i, meta.current_page);
            
            if(meta.current_page < meta.total_pages - 2) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            html += pageBtn(meta.total_pages, meta.current_page);
        }

        // Next
        html += `<li class="page-item ${meta.current_page == meta.total_pages ? 'disabled' : ''}">
                    <button class="page-link" onclick="loadTable(${meta.current_page + 1})"><i class="fas fa-chevron-right"></i></button>
                 </li>`;
        
        ul.innerHTML = html;
    }

    function pageBtn(num, current) {
        return `<li class="page-item ${num === current ? 'active' : ''}">
                    <button class="page-link" onclick="loadTable(${num})">${num}</button>
                </li>`;
    }

    // GUARDADO
    window.saveCell = function(input) {
        if (!currentPK) { alert("Tabla sin PK no editable"); return; }
        
        const originalVal = input.dataset.original;
        const newVal = input.value;
        input.classList.add('saving'); // Texto amarillo

        const payload = {
            table: currentTable, pk: currentPK,
            id: input.dataset.id, col: input.dataset.col, val: newVal
        };

        fetch('db_debug.php?action=update', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if(data.error) throw new Error(data.error);
            input.classList.remove('saving');
            input.classList.add('saved'); // Texto verde
            input.dataset.original = newVal;
            setTimeout(() => input.classList.remove('saved'), 1000);
            if(lastErrorCtx) { lastErrorCtx = null; document.getElementById('btnForce').style.display='none'; }
        })
        .catch(err => {
            input.classList.remove('saving');
            input.classList.add('error'); // Fondo rojo
            notify("Error guardando: " + err.message, "danger");
            lastErrorCtx = { input, payload };
            document.getElementById('btnForce').style.display='inline-block';
        });
    };

    window.retryLastSave = function() {
        if(!lastErrorCtx) return;
        saveCell(lastErrorCtx.input); // Reintentar misma lógica
    };

    // Listeners
    document.getElementById('tableSelector').addEventListener('change', () => loadTable(1));
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

