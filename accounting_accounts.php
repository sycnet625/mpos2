<?php
// ARCHIVO: /var/www/palweb/api/accounting_accounts.php
// VERSIÓN: 10.0 (CRUD COMPLETO + GESTIÓN DE SUBCUENTAS)

ini_set('display_errors', 0);
require_once 'db.php';

// 1. AUTO-INSTALACIÓN TABLA
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contabilidad_cuentas (
        codigo VARCHAR(20) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        tipo ENUM('ACTIVO','PASIVO','PATRIMONIO','INGRESO','COSTO','GASTO') NOT NULL,
        nivel INT DEFAULT 1, 
        padre_codigo VARCHAR(20) NULL,
        INDEX(padre_codigo)
    )");
} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }

// 2. API JSON
if (isset($_GET['api'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        // LISTAR
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode($pdo->query("SELECT * FROM contabilidad_cuentas ORDER BY codigo ASC")->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        
        // GUARDAR (CREAR O EDITAR)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $mode = $_GET['api']; // 'add' o 'update'

            if ($mode === 'add') {
                $codigo = trim($input['codigo']);
                // Auto-detectar nivel y padre
                $parts = explode('.', $codigo);
                $nivel = count($parts);
                $padre = ($nivel > 1) ? implode('.', array_slice($parts, 0, -1)) : null;

                // Validar que el padre exista si es subcuenta
                if ($padre) {
                    $exists = $pdo->prepare("SELECT COUNT(*) FROM contabilidad_cuentas WHERE codigo = ?");
                    $exists->execute([$padre]);
                    if ($exists->fetchColumn() == 0) throw new Exception("Error: La cuenta padre ($padre) no existe.");
                }

                $stmt = $pdo->prepare("INSERT INTO contabilidad_cuentas (codigo, nombre, tipo, nivel, padre_codigo) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$codigo, $input['nombre'], $input['tipo'], $nivel, $padre]);
                echo json_encode(['status'=>'success', 'msg'=>'Cuenta creada correctamente.']);
            }
            
            elseif ($mode === 'update') {
                // Solo permitimos editar nombre y tipo (El código es PK y afectaría integridad)
                $stmt = $pdo->prepare("UPDATE contabilidad_cuentas SET nombre = ?, tipo = ? WHERE codigo = ?");
                $stmt->execute([$input['nombre'], $input['tipo'], $input['codigo']]);
                echo json_encode(['status'=>'success', 'msg'=>'Cuenta actualizada.']);
            }
            
            elseif ($mode === 'delete') {
                $codigo = $input['codigo'];
                
                // 1. Verificar si tiene hijos
                $hijos = $pdo->prepare("SELECT COUNT(*) FROM contabilidad_cuentas WHERE padre_codigo = ?");
                $hijos->execute([$codigo]);
                if ($hijos->fetchColumn() > 0) throw new Exception("No se puede borrar: Tiene sub-cuentas dependientes.");

                // 2. Verificar si tiene movimientos en diario
                $movs = $pdo->prepare("SELECT COUNT(*) FROM contabilidad_diario WHERE cuenta LIKE ?");
                $movs->execute([$codigo . '%']); // Busca exacto o como prefijo en asientos viejos
                if ($movs->fetchColumn() > 0) throw new Exception("No se puede borrar: Tiene asientos contables registrados.");

                $stmt = $pdo->prepare("DELETE FROM contabilidad_cuentas WHERE codigo = ?");
                $stmt->execute([$codigo]);
                echo json_encode(['status'=>'success', 'msg'=>'Cuenta eliminada.']);
            }
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Plan de Cuentas</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .level-1 { font-weight: bold; background-color: #e9ecef; }
        .level-2 { padding-left: 30px !important; color: #495057; font-weight: 500; }
        .level-3 { padding-left: 60px !important; color: #6c757d; font-style: italic; }
        .tree-line { border-left: 2px solid #dee2e6; padding-left: 10px; }
        .action-btn { width: 32px; height: 32px; padding: 0; line-height: 30px; text-align: center; }
    </style>
</head>
<body class="p-4">
<div id="app" class="container bg-white p-4 rounded shadow">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="m-0 text-primary"><i class="fas fa-sitemap me-2"></i> Plan de Cuentas (CRUD)</h3>
        <div>
            <button class="btn btn-success me-2" @click="openModal('add')"><i class="fas fa-plus me-1"></i> Nueva Cuenta Mayor</button>
            <a href="pos_accounting.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Código</th>
                    <th>Nombre de la Cuenta</th>
                    <th>Tipo</th>
                    <th>Nivel</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in accounts" :key="c.codigo" :class="'level-' + Math.min(c.nivel, 3)">
                    <td class="font-monospace">{{c.codigo}}</td>
                    <td>
                        <span v-if="c.nivel > 1" class="tree-line">{{c.nombre}}</span>
                        <span v-else>{{c.nombre}}</span>
                    </td>
                    <td><span class="badge" :class="getBadgeClass(c.tipo)">{{c.tipo}}</span></td>
                    <td><span class="badge bg-light text-dark border">{{c.nivel}}</span></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary action-btn me-1" title="Crear Sub-cuenta" @click="addSubAccount(c)">
                            <i class="fas fa-level-down-alt"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning action-btn me-1" title="Editar" @click="editAccount(c)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger action-btn" title="Eliminar" @click="deleteAccount(c.codigo)">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
                <tr v-if="accounts.length === 0"><td colspan="5" class="text-center p-4 text-muted">No hay cuentas registradas.</td></tr>
            </tbody>
        </table>
    </div>

    <div class="modal fade" id="accountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ modalTitle }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Código</label>
                        <input v-model="form.codigo" class="form-control" placeholder="Ej: 601.1" :disabled="mode === 'update'" ref="inputCodigo">
                        <small class="text-muted" v-if="mode === 'add'">Use puntos para separar niveles (Ej: 101.01)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nombre</label>
                        <input v-model="form.nombre" class="form-control" placeholder="Nombre de la cuenta">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tipo / Naturaleza</label>
                        <select v-model="form.tipo" class="form-select">
                            <option value="ACTIVO">ACTIVO (1xx)</option>
                            <option value="PASIVO">PASIVO (2xx)</option>
                            <option value="PATRIMONIO">PATRIMONIO (6xx)</option>
                            <option value="INGRESO">INGRESO (4xx)</option>
                            <option value="COSTO">COSTO (5xx)</option>
                            <option value="GASTO">GASTO (7xx)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="save">Guardar</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
new Vue({
    el: '#app',
    data: { 
        accounts: [], 
        form: { codigo:'', nombre:'', tipo:'ACTIVO' }, 
        mode: 'add', // 'add' o 'update'
        modalTitle: 'Nueva Cuenta',
        modalInstance: null
    },
    mounted() { 
        this.loadAccounts(); 
        this.modalInstance = new bootstrap.Modal(document.getElementById('accountModal'));
    },
    methods: {
        loadAccounts() { 
            fetch('accounting_accounts.php?api=list').then(r=>r.json()).then(d => this.accounts = d); 
        },
        
        openModal(mode) {
            this.mode = mode;
            this.modalTitle = (mode === 'add') ? 'Nueva Cuenta' : 'Editar Cuenta';
            if(mode === 'add') this.form = { codigo:'', nombre:'', tipo:'ACTIVO' };
            this.modalInstance.show();
            // Auto focus al código si es nuevo
            if(mode === 'add') setTimeout(() => this.$refs.inputCodigo.focus(), 500);
        },

        addSubAccount(parent) {
            this.mode = 'add';
            this.modalTitle = 'Nueva Sub-cuenta de ' + parent.codigo;
            // Pre-llenar código padre y tipo
            this.form = { 
                codigo: parent.codigo + '.', 
                nombre: '', 
                tipo: parent.tipo 
            };
            this.modalInstance.show();
            setTimeout(() => this.$refs.inputCodigo.focus(), 500);
        },

        editAccount(acc) {
            this.mode = 'update';
            this.modalTitle = 'Editar: ' + acc.codigo;
            // Clonar objeto para no modificar la vista en tiempo real antes de guardar
            this.form = { ...acc };
            this.modalInstance.show();
        },

        save() {
            if(!this.form.codigo || !this.form.nombre) return alert("Complete los campos obligatorios");
            
            fetch(`accounting_accounts.php?api=${this.mode}`, {
                method: 'POST', 
                body: JSON.stringify(this.form)
            })
            .then(r => r.json())
            .then(res => { 
                if(res.status === 'success') { 
                    this.loadAccounts(); 
                    this.modalInstance.hide();
                } else {
                    alert(res.msg); 
                }
            })
            .catch(e => alert("Error de conexión"));
        },

        deleteAccount(code) {
            if(!confirm(`¿Seguro que desea eliminar la cuenta ${code}?\nEsta acción es irreversible.`)) return;
            
            fetch('accounting_accounts.php?api=delete', {
                method: 'POST', 
                body: JSON.stringify({codigo: code})
            })
            .then(r => r.json())
            .then(res => { 
                if(res.status === 'success') this.loadAccounts(); 
                else alert(res.msg); 
            });
        },

        getBadgeClass(t) {
            const map = { 
                ACTIVO: 'bg-primary', PASIVO: 'bg-danger', PATRIMONIO: 'bg-warning text-dark', 
                INGRESO: 'bg-success', COSTO: 'bg-secondary', GASTO: 'bg-info text-dark' 
            };
            return map[t] || 'bg-secondary';
        }
    }
});
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
