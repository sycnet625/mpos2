<?php
// ARCHIVO: accounting_journal.php
// DESCRIPCIÓN: Gestión de Asientos Manuales (Libro Diario)
// REQUIERE: accounting_accounts.php (para la API de cuentas)

require_once 'db.php';
session_start();

// 1. INSTALACIÓN AUTOMÁTICA DE TABLA DIARIO
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contabilidad_diario (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asiento_id VARCHAR(20) NOT NULL,
        fecha DATE NOT NULL,
        cuenta VARCHAR(20) NOT NULL,
        detalle VARCHAR(255) NOT NULL,
        debe DECIMAL(12,2) DEFAULT 0,
        haber DECIMAL(12,2) DEFAULT 0,
        creado_por VARCHAR(50),
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(asiento_id),
        INDEX(fecha),
        INDEX(cuenta)
    )");
} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }

// 2. PROCESAR GUARDADO DE ASIENTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if ($_GET['api'] === 'save') {
        try {
            $pdo->beginTransaction();
            
            // Generar ID único para el grupo de movimientos (Asiento)
            $asientoID = date('Ymd-His') . '-' . rand(100,999);
            $fecha = $input['fecha'];
            $glosa = $input['glosa'];
            $items = $input['items'];
            $user = $_SESSION['admin_name'] ?? 'Sistema';

            // Validar Cuadre
            $tDebe = 0; $tHaber = 0;
            foreach ($items as $it) {
                $tDebe += floatval($it['debe']);
                $tHaber += floatval($it['haber']);
            }
            // Pequeña tolerancia para errores de redondeo flotante
            if (abs($tDebe - $tHaber) > 0.01) {
                throw new Exception("El asiento no cuadra. Diferencia: " . ($tDebe - $tHaber));
            }

            // Insertar filas
            $stmt = $pdo->prepare("INSERT INTO contabilidad_diario (asiento_id, fecha, cuenta, detalle, debe, haber, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($items as $it) {
                if ($it['debe'] > 0 || $it['haber'] > 0) {
                    // Detalle individual o glosa general
                    $det = !empty($it['concepto']) ? $it['concepto'] : $glosa;
                    $stmt->execute([$asientoID, $fecha, $it['cuenta'], $det, $it['debe'], $it['haber'], $user]);
                }
            }

            $pdo->commit();
            echo json_encode(['status'=>'success', 'msg'=>'Asiento registrado: ' . $asientoID]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Libro Diario</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="assets/js/vue.min.js"></script> <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .entry-card { border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 10px; }
        .total-box { font-weight: bold; font-size: 1.1rem; }
        .balanced { color: #198754; }
        .unbalanced { color: #dc3545; }
        .table-input { border: none; background: transparent; width: 100%; outline: none; }
        .table-input:focus { background: #fff; box-shadow: inset 0 0 0 1px #0d6efd; border-radius: 4px; }
    </style>
</head>
<body class="p-4">

<div id="app" class="container">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-book-journal-whills text-primary"></i> Libro Diario</h3>
            <p class="text-muted mb-0">Registro manual de operaciones contables</p>
        </div>
        <div class="d-flex gap-2">
            <a href="accounting_reports_print.php" target="_blank" class="btn btn-outline-dark"><i class="fas fa-chart-pie"></i> Reportes</a>
            <a href="accounting_accounts.php" class="btn btn-outline-secondary"><i class="fas fa-sitemap"></i> Cuentas</a>
            <a href="pos.php" class="btn btn-primary">Volver al POS</a>
        </div>
    </div>

    <div class="card entry-card bg-white p-4 mb-4">
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="fw-bold small text-muted">Fecha Contable</label>
                <input type="date" v-model="fecha" class="form-control">
            </div>
            <div class="col-md-9">
                <label class="fw-bold small text-muted">Descripción General (Glosa)</label>
                <input type="text" v-model="glosa" class="form-control" placeholder="Ej: Registro de Nómina del Mes de Febrero...">
            </div>
        </div>

        <table class="table table-bordered align-middle">
            <thead class="table-light text-center small">
                <tr>
                    <th width="35%">Cuenta Contable</th>
                    <th width="25%">Concepto (Opcional)</th>
                    <th width="15%">Debe</th>
                    <th width="15%">Haber</th>
                    <th width="5%"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="index">
                    <td>
                        <select v-model="row.cuenta" class="form-select form-select-sm border-0 bg-light">
                            <option value="">- Seleccionar -</option>
                            <option v-for="acc in accountList" :value="acc.codigo">
                                {{ acc.codigo }} - {{ acc.nombre }}
                            </option>
                        </select>
                    </td>
                    <td><input type="text" v-model="row.concepto" class="table-input px-2" :placeholder="glosa"></td>
                    <td>
                        <input type="number" step="0.01" v-model.number="row.debe" class="table-input text-end px-2" 
                               @input="row.haber = 0" placeholder="0.00">
                    </td>
                    <td>
                        <input type="number" step="0.01" v-model.number="row.haber" class="table-input text-end px-2" 
                               @input="row.debe = 0" placeholder="0.00">
                    </td>
                    <td class="text-center">
                        <button class="btn btn-link text-danger p-0" @click="removeRow(index)" v-if="rows.length > 1">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>
                </tr>
            </tbody>
            <tfoot class="bg-light">
                <tr>
                    <td colspan="2">
                        <button class="btn btn-sm btn-link text-decoration-none fw-bold" @click="addRow">
                            <i class="fas fa-plus-circle"></i> Agregar Línea
                        </button>
                    </td>
                    <td class="text-end fw-bold">{{ totalDebe.toFixed(2) }}</td>
                    <td class="text-end fw-bold">{{ totalHaber.toFixed(2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <div class="d-flex justify-content-between align-items-center mt-2 p-3 rounded" 
             :class="isBalanced ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10'">
            
            <div class="d-flex align-items-center">
                <div v-if="isBalanced" class="text-success fw-bold me-3">
                    <i class="fas fa-check-circle fa-lg me-2"></i> Asiento Cuadrado
                </div>
                <div v-else class="text-danger fw-bold me-3">
                    <i class="fas fa-exclamation-triangle fa-lg me-2"></i> Descuadre: {{ difference.toFixed(2) }}
                </div>
            </div>

            <button class="btn btn-lg btn-primary px-5" :disabled="!isBalanced || rows[0].cuenta == ''" @click="saveEntry">
                <i class="fas fa-save me-2"></i> Registrar Asiento
            </button>
        </div>
    </div>

    <div class="card entry-card p-3">
        <h5 class="fw-bold mb-3">Últimos Movimientos</h5>
        <div class="table-responsive">
            <table class="table table-sm table-hover small">
                <thead class="table-light">
                    <tr><th>ID</th><th>Fecha</th><th>Cuenta</th><th>Detalle</th><th class="text-end">Debe</th><th class="text-end">Haber</th></tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT * FROM contabilidad_diario ORDER BY id DESC LIMIT 10");
                    while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td class="text-muted"><?php echo $row['asiento_id']; ?></td>
                        <td><?php echo $row['fecha']; ?></td>
                        <td class="fw-bold text-primary"><?php echo $row['cuenta']; ?></td>
                        <td><?php echo $row['detalle']; ?></td>
                        <td class="text-end"><?php echo ($row['debe']>0) ? number_format($row['debe'],2) : '-'; ?></td>
                        <td class="text-end"><?php echo ($row['haber']>0) ? number_format($row['haber'],2) : '-'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-2">
            <a href="accounting_export.php?type=diario" class="btn btn-sm btn-outline-dark"><i class="fas fa-file-csv"></i> Exportar Completo (CSV)</a>
        </div>
    </div>

</div>

<script>
new Vue({
    el: '#app',
    data: {
        fecha: new Date().toISOString().split('T')[0],
        glosa: '',
        rows: [
            { cuenta: '', concepto: '', debe: 0, haber: 0 },
            { cuenta: '', concepto: '', debe: 0, haber: 0 }
        ],
        accountList: []
    },
    computed: {
        totalDebe() { return this.rows.reduce((sum, row) => sum + Number(row.debe || 0), 0); },
        totalHaber() { return this.rows.reduce((sum, row) => sum + Number(row.haber || 0), 0); },
        difference() { return Math.abs(this.totalDebe - this.totalHaber); },
        isBalanced() { return this.difference < 0.01 && this.totalDebe > 0; }
    },
    mounted() {
        this.loadAccounts();
    },
    methods: {
        loadAccounts() {
            // Reutilizamos la API que ya tenías en accounting_accounts.php
            fetch('accounting_accounts.php?api=list')
                .then(r => r.json())
                .then(data => {
                    // Solo mostramos cuentas de ultimo nivel (opcional, pero recomendado)
                    // Por ahora mostramos todas para flexibilidad
                    this.accountList = data; 
                });
        },
        addRow() {
            this.rows.push({ cuenta: '', concepto: '', debe: 0, haber: 0 });
        },
        removeRow(index) {
            this.rows.splice(index, 1);
        },
        saveEntry() {
            if (!confirm("¿Confirma registrar este asiento contable?")) return;

            const payload = {
                fecha: this.fecha,
                glosa: this.glosa,
                items: this.rows
            };

            fetch('accounting_journal.php?api=save', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    alert("Asiento registrado con éxito ID: " + res.msg);
                    window.location.reload(); // Recargar para limpiar y ver historial
                } else {
                    alert("Error: " + res.msg);
                }
            });
        }
    }
});
</script>


<?php include_once 'menu_master.php'; ?>
</body>
</html>

