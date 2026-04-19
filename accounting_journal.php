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
            
            $asientoID = date('Ymd-His') . '-' . rand(100,999);
            $fecha = $input['fecha'];
            $glosa = $input['glosa'];
            $items = $input['items'];
            $user = $_SESSION['admin_name'] ?? 'Sistema';

            $tDebe = 0; $tHaber = 0;
            foreach ($items as $it) {
                $tDebe += floatval($it['debe']);
                $tHaber += floatval($it['haber']);
            }
            if (abs($tDebe - $tHaber) > 0.01) {
                throw new Exception("El asiento no cuadra. Diferencia: " . ($tDebe - $tHaber));
            }

            $stmt = $pdo->prepare("INSERT INTO contabilidad_diario (asiento_id, fecha, cuenta, detalle, debe, haber, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($items as $it) {
                if ($it['debe'] > 0 || $it['haber'] > 0) {
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

// Cargar ultimos movimientos para mostrar en historial
$recentMovements = [];
try {
    $stmt = $pdo->query("SELECT * FROM contabilidad_diario ORDER BY id DESC LIMIT 20");
    $recentMovements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $recentMovements = [];
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Libro Diario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        body { background-color: #f4f6f9; }
        .table-input { border: none; background: transparent; width: 100%; outline: none; }
        .table-input:focus { background: #fff; box-shadow: inset 0 0 0 1px #0d6efd; border-radius: 4px; }
        .table thead th { white-space: nowrap; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div id="app" class="container-fluid shell inventory-shell py-4 py-lg-5">
    
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Contabilidad</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-book-journal-whills me-2"></i>Libro Diario</h1>
                <p class="mb-3 text-white-50">Registro manual de operaciones contables con cuadre automatico.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Sistema') ?></span>
                    <span class="kpi-chip"><i class="fas fa-calendar me-1"></i><?= date('Y-m-d') ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="accounting_reports_print.php" target="_blank" class="btn btn-outline-light"><i class="fas fa-chart-pie me-1"></i>Reportes</a>
                <a href="accounting_accounts.php" class="btn btn-outline-light"><i class="fas fa-sitemap me-1"></i>Cuentas</a>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <div v-if="flash.msg" class="alert mb-4" :class="flash.ok ? 'alert-success' : 'alert-danger'">
        {{ flash.msg }}
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Asiento</div>
                        <h2 class="h5 fw-bold mb-0">Nuevo Registro Contable</h2>
                    </div>
                    <span class="soft-pill" v-if="isBalanced"><i class="fas fa-check"></i>Cuadrado</span>
                    <span class="soft-pill bg-danger text-white" v-else><i class="fas fa-exclamation-triangle"></i>Descuadre: {{ difference.toFixed(2) }}</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Fecha Contable</label>
                        <input type="date" v-model="fecha" class="form-control">
                    </div>
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Descripcion General (Glosa)</label>
                        <input type="text" v-model="glosa" class="form-control" placeholder="Ej: Registro de Nomina del Mes...">
                    </div>
                </div>

                <div class="table-responsive border rounded-4 bg-white mb-3" style="max-height: 300px; overflow:auto;">
                    <table class="table align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Cuenta</th>
                                <th>Concepto</th>
                                <th class="text-end">Debe</th>
                                <th class="text-end">Haber</th>
                                <th class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in rows" :key="index">
                                <td class="p-1">
                                    <select v-model="row.cuenta" class="form-select form-select-sm">
                                        <option value="">- Seleccionar -</option>
                                        <option v-for="acc in accountList" :value="acc.codigo">
                                            {{ acc.codigo }} - {{ acc.nombre }}
                                        </option>
                                    </select>
                                </td>
                                <td class="p-1">
                                    <input type="text" v-model="row.concepto" class="table-input px-2" :placeholder="glosa || 'Concepto'">
                                </td>
                                <td class="p-1">
                                    <input type="number" step="0.01" v-model.number="row.debe" class="table-input text-end px-2" 
                                           @input="row.haber = 0" placeholder="0.00">
                                </td>
                                <td class="p-1">
                                    <input type="number" step="0.01" v-model.number="row.haber" class="table-input text-end px-2" 
                                           @input="row.debe = 0" placeholder="0.00">
                                </td>
                                <td class="text-center p-1">
                                    <button class="btn btn-sm btn-outline-danger" @click="removeRow(index)" v-if="rows.length > 1">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="2">
                                    <button class="btn btn-sm btn-link text-decoration-none fw-bold" @click="addRow">
                                        <i class="fas fa-plus-circle"></i> Agregar Linea
                                    </button>
                                </td>
                                <td class="text-end fw-bold" :class="totalDebe > 0 ? 'text-primary' : ''">{{ totalDebe.toFixed(2) }}</td>
                                <td class="text-end fw-bold" :class="totalHaber > 0 ? 'text-success' : ''">{{ totalHaber.toFixed(2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="tiny text-muted">
                        <span v-if="isBalanced" class="text-success"><i class="fas fa-check-circle me-1"></i>Asiento cuadrado</span>
                        <span v-else class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Debe = Haber para poder guardar</span>
                    </div>
                    <button class="btn btn-primary fw-bold" :disabled="!isBalanced || rows[0].cuenta == ''" @click="saveEntry">
                        <i class="fas fa-save me-1"></i> Registrar Asiento
                    </button>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Historial</div>
                        <h2 class="h5 fw-bold mb-0">Ultimos Movimientos</h2>
                    </div>
                    <span class="soft-pill"><i class="fas fa-history me-1"></i><?= count($recentMovements) ?> registros</span>
                </div>

                <div class="table-responsive border rounded-4 bg-white" style="max-height: 500px; overflow:auto;">
                    <table class="table align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>ID</th>
                                <th>Fecha</th>
                                <th>Cuenta</th>
                                <th>Detalle</th>
                                <th class="text-end">Debe</th>
                                <th class="text-end">Haber</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentMovements)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">Sin movimientos registrados.</td></tr>
                            <?php else: foreach ($recentMovements as $row): ?>
                            <tr>
                                <td class="tiny text-muted"><?php echo htmlspecialchars($row['asiento_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['fecha']); ?></td>
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['cuenta']); ?></td>
                                <td><?php echo htmlspecialchars($row['detalle']); ?></td>
                                <td class="text-end"><?php echo ($row['debe'] > 0) ? number_format($row['debe'], 2) : '-'; ?></td>
                                <td class="text-end"><?php echo ($row['haber'] > 0) ? number_format($row['haber'], 2) : '-'; ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end mt-3">
                    <a href="accounting_export.php?type=diario" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-file-csv me-1"></i> Exportar Completo (CSV)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const app = new Vue({
    el: '#app',
    data: {
        flash: { ok: true, msg: '' },
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
            fetch('accounting_accounts.php?api=list')
                .then(r => r.json())
                .then(data => {
                    this.accountList = data;
                })
                .catch(err => {
                    this.flash = { ok: false, msg: 'Error cargando cuentas: ' + err.message };
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
                    this.flash = { ok: true, msg: 'Asiento registrado: ' + res.msg };
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    this.flash = { ok: false, msg: res.msg };
                }
            })
            .catch(err => {
                this.flash = { ok: false, msg: 'Error: ' + err.message };
            });
        },
        ok(msg) { this.flash = { ok: true, msg }; },
        raise(msg) { this.flash = { ok: false, msg }; }
    }
});
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
