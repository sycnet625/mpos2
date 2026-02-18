<?php
// ARCHIVO: /var/www/palweb/api/pin_auth.php
// Sistema de autenticación con PIN - Separado del POS
session_start();
require_once 'db.php';

// --- Rate Limiting por IP ---
function rl_file($ip) {
    return sys_get_temp_dir() . '/palweb_rl_' . substr(md5($ip), 0, 16) . '.json';
}
function rl_get($ip) {
    $f = rl_file($ip);
    if (!file_exists($f)) return ['count' => 0, 'first' => time()];
    $d = json_decode(file_get_contents($f), true);
    if (!$d || (time() - ($d['first'] ?? 0)) > 600) return ['count' => 0, 'first' => time()];
    return $d;
}
function rl_fail($ip) {
    $d = rl_get($ip);
    $d['count']++;
    file_put_contents(rl_file($ip), json_encode($d), LOCK_EX);
    return $d['count'];
}
function rl_clear($ip) {
    $f = rl_file($ip);
    if (file_exists($f)) @unlink($f);
}
// --- Fin Rate Limiting ---

// Cargar configuración
$configFile = __DIR__ . '/pos.cfg';
$config = [
    "tienda_nombre" => "MI TIENDA",
    "cajeros" => [["nombre" => "Admin", "pin" => "0000"]],
    "id_sucursal" => 1,
    "id_almacen" => 1
];

if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

// Si ya hay sesión válida, redirigir al POS
if (isset($_SESSION['cajero']) && isset($_SESSION['cajero_pin'])) {
    header('Location: pos.php');
    exit;
}

// Obtener fecha contable de sesión activa
$fechaContable = date('Y-m-d');
try {
    $stmtSesion = $pdo->prepare("
        SELECT fecha_contable, id
        FROM caja_sesiones 
        WHERE estado = 'ABIERTA' 
        AND id_sucursal = ? 
        ORDER BY fecha_apertura DESC 
        LIMIT 1
    ");
    $stmtSesion->execute([$config['id_sucursal']]);
    $sesionActiva = $stmtSesion->fetch(PDO::FETCH_ASSOC);
    
    if ($sesionActiva) {
        $fechaContable = $sesionActiva['fecha_contable'];
        $idCaja = $sesionActiva['id'];
    } else {
        $idCaja = null;
    }
} catch (Exception $e) {
    error_log("Error obteniendo sesión: " . $e->getMessage());
    $idCaja = null;
}

$error = '';
$rl_ip = $_SERVER['REMOTE_ADDR'];

// Procesar PIN enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    // Aplicar delay si hay 5+ intentos fallidos previos (ventana: 10 min)
    $rl = rl_get($rl_ip);
    if ($rl['count'] >= 5) {
        sleep(min(2 * ($rl['count'] - 4), 30));
    }

    $pinIngresado = $_POST['pin'];
    $cajeroEncontrado = null;

    foreach ($config['cajeros'] as $cajero) {
        if ($cajero['pin'] === $pinIngresado) {
            $cajeroEncontrado = $cajero;
            break;
        }
    }

    if ($cajeroEncontrado) {
        rl_clear($rl_ip);
        // PIN correcto - Crear sesión (sin guardar el PIN)
        session_regenerate_id(true);
        $_SESSION['cajero'] = $cajeroEncontrado['nombre'];
        $_SESSION['fecha_contable'] = $fechaContable;
        $_SESSION['id_sucursal'] = $config['id_sucursal'];
        $_SESSION['id_almacen'] = $config['id_almacen'];
        $_SESSION['id_caja'] = $idCaja;

        // Redirigir al POS
        header('Location: pos.php');
        exit;
    } else {
        rl_fail($rl_ip);
        $error = 'PIN_INCORRECTO';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔐 Autenticación - <?php echo htmlspecialchars($config['tienda_nombre']); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-container {
            background: white;
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 450px;
            width: 90%;
            animation: slideUp 0.4s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-circle {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .logo-circle i {
            font-size: 40px;
            color: white;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin: 0 0 10px 0;
        }
        .subtitle {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 30px;
        }
        .pin-dots {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 30px;
        }
        .pin-dot {
            width: 20px;
            height: 20px;
            border: 3px solid #ddd;
            border-radius: 50%;
            transition: all 0.2s ease;
        }
        .pin-dot.filled {
            background: #667eea;
            border-color: #667eea;
            transform: scale(1.1);
        }
        .pin-keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        .pin-btn {
            padding: 20px;
            font-size: 24px;
            font-weight: 600;
            border: 2px solid #eee;
            background: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            color: #333;
        }
        .pin-btn:hover {
            background: #f8f9fa;
            transform: scale(1.05);
            border-color: #667eea;
        }
        .pin-btn:active {
            transform: scale(0.95);
            background: #e9ecef;
        }
        .btn-zero {
            grid-column: span 2;
        }
        .btn-clear {
            background: #fff5f5;
            color: #dc3545;
            border-color: #ffcdd2;
        }
        .btn-clear:hover {
            background: #ffe5e5;
            border-color: #dc3545;
        }
        .error-message {
            background: #fff5f5;
            color: #dc3545;
            padding: 12px;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            margin-top: 15px;
            border: 2px solid #ffcdd2;
            animation: shake 0.4s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        .success-message {
            background: #f0fdf4;
            color: #16a34a;
            padding: 12px;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            margin-top: 15px;
            border: 2px solid #bbf7d0;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            border: 1px solid #e9ecef;
        }
        .info-box small {
            display: block;
            color: #666;
            margin-bottom: 5px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 0.85rem;
        }
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="logo-container">
            <div class="logo-circle">
                <i class="fas fa-lock"></i>
            </div>
            <h1><?php echo htmlspecialchars($config['tienda_nombre']); ?></h1>
            <div class="subtitle">Ingrese su PIN de 4 dígitos</div>
        </div>

        <div class="pin-dots">
            <div class="pin-dot" id="dot1"></div>
            <div class="pin-dot" id="dot2"></div>
            <div class="pin-dot" id="dot3"></div>
            <div class="pin-dot" id="dot4"></div>
        </div>

        <div class="pin-keypad">
            <button class="pin-btn" onclick="addDigit('1')">1</button>
            <button class="pin-btn" onclick="addDigit('2')">2</button>
            <button class="pin-btn" onclick="addDigit('3')">3</button>
            <button class="pin-btn" onclick="addDigit('4')">4</button>
            <button class="pin-btn" onclick="addDigit('5')">5</button>
            <button class="pin-btn" onclick="addDigit('6')">6</button>
            <button class="pin-btn" onclick="addDigit('7')">7</button>
            <button class="pin-btn" onclick="addDigit('8')">8</button>
            <button class="pin-btn" onclick="addDigit('9')">9</button>
            <button class="pin-btn btn-zero" onclick="addDigit('0')">0</button>
            <button class="pin-btn btn-clear" onclick="clearPIN()">
                <i class="fas fa-backspace"></i>
            </button>
        </div>

        <form method="POST" id="pinForm" class="hidden">
            <input type="hidden" name="pin" id="pinInput">
        </form>

        <div id="errorMessage" class="<?php echo $error ? 'error-message' : 'hidden'; ?>">
            <?php if ($error === 'PIN_INCORRECTO'): ?>
                <i class="fas fa-times-circle"></i> PIN incorrecto. Intente de nuevo.
            <?php endif; ?>
        </div>

        <div class="info-box">
            <small><i class="fas fa-info-circle"></i> Información del Sistema</small>
            <div class="info-item">
                <span>Sucursal:</span>
                <strong>#<?php echo $config['id_sucursal']; ?></strong>
            </div>
            <div class="info-item">
                <span>Almacén:</span>
                <strong>#<?php echo $config['id_almacen']; ?></strong>
            </div>
            <div class="info-item">
                <span>Fecha Contable:</span>
                <strong><?php echo date('d/m/Y', strtotime($fechaContable)); ?></strong>
            </div>
            <?php if (!$idCaja): ?>
            <div class="info-item text-danger">
                <span><i class="fas fa-exclamation-triangle"></i> Sin sesión de caja abierta</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // =============================================
        // SISTEMA DE SONIDOS
        // =============================================
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        const audioCtx = new AudioContext();

        function playSound(type) {
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            
            switch(type) {
                case 'click':
                    oscillator.frequency.value = 800;
                    gainNode.gain.value = 0.1;
                    oscillator.start();
                    oscillator.stop(audioCtx.currentTime + 0.05);
                    break;
                    
                case 'delete':
                    oscillator.frequency.value = 400;
                    gainNode.gain.value = 0.15;
                    oscillator.start();
                    oscillator.stop(audioCtx.currentTime + 0.08);
                    break;
                    
                case 'error':
                    oscillator.type = 'sawtooth';
                    oscillator.frequency.value = 200;
                    gainNode.gain.value = 0.2;
                    oscillator.start();
                    oscillator.stop(audioCtx.currentTime + 0.3);
                    break;
                    
                case 'success':
                    // Sonido de éxito (dos tonos)
                    oscillator.frequency.value = 800;
                    gainNode.gain.value = 0.15;
                    oscillator.start();
                    oscillator.stop(audioCtx.currentTime + 0.1);
                    
                    setTimeout(() => {
                        const osc2 = audioCtx.createOscillator();
                        const gain2 = audioCtx.createGain();
                        osc2.connect(gain2);
                        gain2.connect(audioCtx.destination);
                        osc2.frequency.value = 1000;
                        gain2.gain.value = 0.15;
                        osc2.start();
                        osc2.stop(audioCtx.currentTime + 0.15);
                    }, 100);
                    break;
            }
        }

        // =============================================
        // SISTEMA DE PIN
        // =============================================
        const CAJEROS = <?php echo json_encode($config['cajeros']); ?>;
        let currentPIN = '';

        function addDigit(digit) {
            if (currentPIN.length < 4) {
                playSound('click');
                currentPIN += digit.toString();
                updateDisplay();
                
                // Auto-verificar al completar 4 dígitos
                if (currentPIN.length === 4) {
                    setTimeout(verifyPIN, 300);
                }
            }
        }

        function clearPIN() {
            playSound('delete');
            currentPIN = '';
            updateDisplay();
            document.getElementById('errorMessage').classList.add('hidden');
        }

        function updateDisplay() {
            for (let i = 1; i <= 4; i++) {
                const dot = document.getElementById('dot' + i);
                if (i <= currentPIN.length) {
                    dot.classList.add('filled');
                } else {
                    dot.classList.remove('filled');
                }
            }
        }

        function verifyPIN() {
            const cajero = CAJEROS.find(c => c.pin === currentPIN);
            
            if (cajero) {
                playSound('success');
                
                // Mostrar mensaje de éxito
                const errorEl = document.getElementById('errorMessage');
                errorEl.className = 'success-message';
                errorEl.innerHTML = '<i class="fas fa-check-circle"></i> Acceso concedido. Redirigiendo...';
                
                // Enviar formulario
                document.getElementById('pinInput').value = currentPIN;
                
                setTimeout(() => {
                    document.getElementById('pinForm').submit();
                }, 800);
            } else {
                playSound('error');
                
                // Mostrar error
                const errorEl = document.getElementById('errorMessage');
                errorEl.className = 'error-message';
                errorEl.innerHTML = '<i class="fas fa-times-circle"></i> PIN incorrecto. Intente de nuevo.';
                
                // Shake effect en dots
                const dots = document.querySelectorAll('.pin-dot');
                dots.forEach(dot => {
                    dot.style.animation = 'shake 0.4s ease';
                });
                
                setTimeout(() => {
                    dots.forEach(dot => {
                        dot.style.animation = '';
                    });
                    clearPIN();
                }, 1500);
            }
        }

        // Soporte de teclado
        document.addEventListener('keydown', function(e) {
            if (e.key >= '0' && e.key <= '9') {
                e.preventDefault();
                addDigit(e.key);
            } else if (e.key === 'Backspace' || e.key === 'Delete') {
                e.preventDefault();
                clearPIN();
            } else if (e.key === 'Enter' && currentPIN.length === 4) {
                e.preventDefault();
                verifyPIN();
            }
        });

        // Reproducir sonido de error si viene de POST con error
        <?php if ($error === 'PIN_INCORRECTO'): ?>
        window.addEventListener('load', function() {
            playSound('error');
        });
        <?php endif; ?>

        console.log('Sistema de autenticación cargado');
        console.log('Cajeros disponibles:', <?php echo count($config['cajeros']); ?>);
    </script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

