<?php
// ARCHIVO: customer_display.php
require_once 'config_loader.php';
$insectType = $config['customer_display_insect'] ?? 'mosca';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Display | <?php echo $config['nombre_empresa'] ?? 'PalWeb'; ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/animate.min.css">
    <style>
        :root { --odoo-purple: #714B67; --odoo-bg: #f8f9fa; }
        body { background-color: var(--odoo-bg); height: 100vh; overflow: hidden; font-family: 'Segoe UI', sans-serif; transition: all 0.5s ease; }
        
        /* ESTILO DE LA MOSCA MEJORADO */
        #realistic-fly {
            position: fixed;
            width: 35px;
            height: 35px;
            z-index: 9999 !important;
            pointer-events: auto; /* Permitir click */
            cursor: crosshair;
            /* Transiciones removidas para control manual de zigzag */
            filter: drop-shadow(2px 5px 3px rgba(0,0,0,0.4));
            display: block;
        }
        .blood-spot {
            position: fixed;
            z-index: 9998;
            background-color: #8a0b0b;
            border-radius: 50%;
            pointer-events: none;
            opacity: 0.9;
        }
        .fly-wing { fill: rgba(255, 255, 255, 0.6); animation: wingShake 0.05s infinite; transform-origin: center; stroke: #ccc; stroke-width: 1; }
        .fly-leg { stroke: #000; stroke-width: 3; animation: legWiggle 0.1s infinite alternate; }
        .fly-body { fill: #111; }
        
        @keyframes wingShake { 0% { opacity: 0.3; transform: scaleY(1); } 50% { opacity: 0.9; transform: scaleY(1.2); } 100% { opacity: 0.3; transform: scaleY(1); } }
        @keyframes legWiggle { 0% { transform: rotate(5deg); } 100% { transform: rotate(-5deg); } }

        /* MARIPOSA MONARCA */
        .b-wing-l, .b-wing-r { transform-box: view-box; transform-origin: 50% 57%; animation: butterfly-flap 0.25s ease-in-out infinite alternate; }
        @keyframes butterfly-flap { from { transform: scaleX(1); } to { transform: scaleX(0.1); } }

        /* MARIQUITA */
        .lady-leg { stroke: #111; stroke-width: 2.5; animation: legWiggle 0.18s infinite alternate; }

        /* QR CODE */
        .qr-container { background: white; padding: 15px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); margin-bottom: 20px; display: inline-block; }
        .qr-label { color: white; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }

        .display-container { display: flex; height: 100%; transition: flex-direction 0.5s ease; }
        
        /* RESPONSIVE: HORIZONTAL (Default) */
        @media (orientation: landscape) {
            .display-container { flex-direction: row; }
            .brand-side { width: 30%; height: 100%; border-right: 5px solid rgba(0,0,0,0.1); }
            .cart-side { width: 70%; height: 100%; }
        }

        /* RESPONSIVE: VERTICAL */
        @media (orientation: portrait) {
            .display-container { flex-direction: column; }
            .brand-side { width: 100%; height: 35%; border-bottom: 5px solid rgba(0,0,0,0.1); }
            .cart-side { width: 100%; height: 65%; }
            .total-value { font-size: 3.5rem !important; }
            .qr-container img { width: 140px !important; height: 140px !important; }
        }

        .brand-side { background: var(--odoo-purple); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; text-align: center; z-index: 10; }
        .cart-side { background: white; padding: 30px; display: flex; flex-direction: column; position: relative; }
        .product-list { flex-grow: 1; overflow-y: auto; padding-right: 10px; }
        
        /* ANIMACIONES DINÁMICAS */
        .item-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 2px solid #f8f9fa; 
            padding: 15px 0; 
            transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .item-row.new-item { animation: slideInRight 0.6s both; }
        .item-row.removed-item { animation: explodeOut 0.5s both; pointer-events: none; }

        @keyframes explodeOut {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.5); opacity: 0; filter: blur(10px); }
        }

        .product-img-mini { width: 70px; height: 70px; object-fit: cover; border-radius: 12px; margin-right: 20px; background: #f0f0f0; border: 1px solid #eee; }
        .item-info { flex-grow: 1; display: flex; align-items: center; }
        .item-qty { font-weight: 900; color: var(--odoo-purple); margin-right: 15px; font-size: 2rem; min-width: 60px; }
        .item-details { display: flex; flex-direction: column; }
        .item-name { font-size: 1.6rem; font-weight: 800; color: #222; }
        .item-price { color: #666; font-size: 1.2rem; font-weight: 700; }
        .item-subtotal { font-weight: 900; text-align: right; font-size: 2rem; color: #000; min-width: 150px; }

        .summary-bar { display: flex; justify-content: space-between; padding: 15px 0; border-top: 2px solid #eee; font-size: 1.3rem; font-weight: 700; color: #777; }
        .total-section { border-top: 5px solid var(--odoo-purple); padding-top: 15px; }
        .total-row { display: flex; justify-content: space-between; align-items: center; }
        .total-label { font-size: 2rem; font-weight: 800; color: #333; }
        .total-value { font-size: 4.8rem; font-weight: 900; color: var(--odoo-purple); transition: all 0.3s ease; }

        .change-card { background: #e8f5e9; border: 3px dashed #2e7d32; border-radius: 15px; padding: 15px 25px; margin-top: 15px; display: flex; justify-content: space-between; align-items: center; }
        .change-text { font-size: 1.4rem; color: #2e7d32; font-weight: 800; }
        .change-amount { font-size: 3.2rem; color: #1b5e20; font-weight: 900; }

        .welcome-screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--odoo-purple); color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; z-index: 100; transition: all 0.8s ease; }
        .welcome-screen.hidden { transform: translateY(-100%); opacity: 0; }

        /* OVERLAY ACTIVAR SONIDO */
        #soundOverlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10001;
            backdrop-filter: blur(8px);
            transition: opacity 0.5s ease;
        }
        .sound-btn {
            padding: 20px 40px;
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            background: var(--odoo-purple);
            border: 3px solid white;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.2s;
        }
        .sound-btn:hover { transform: scale(1.1); }
    </style>
</head>
<body>

<div id="soundOverlay">
    <button class="sound-btn" onclick="enableAudio()">
        <i class="fas fa-volume-up"></i> ACTIVAR SONIDO
    </button>
</div>

<div id="realistic-fly">
<?php if ($insectType === 'mariposa'): ?>
    <!-- MARIPOSA MONARCA -->
    <svg viewBox="0 0 100 100">
        <!-- Ala izquierda (grupo completo: relleno + venas + puntos blancos) -->
        <g class="b-wing-l">
            <path d="M50,50 C35,30 15,15 5,28 C0,45 20,65 48,68" fill="#E8711A" stroke="#1a0a00" stroke-width="2.2"/>
            <path d="M50,62 C35,68 18,78 14,92 C28,100 44,85 50,75" fill="#E8711A" stroke="#1a0a00" stroke-width="2.2"/>
            <path d="M48,67 C35,52 18,38 10,28" stroke="#1a0a00" stroke-width="1.2" fill="none"/>
            <path d="M48,67 C28,58 12,52 5,40" stroke="#1a0a00" stroke-width="0.9" fill="none"/>
            <circle cx="9"  cy="30" r="2.2" fill="white"/>
            <circle cx="6"  cy="42" r="1.8" fill="white"/>
            <circle cx="14" cy="20" r="1.8" fill="white"/>
            <circle cx="17" cy="89" r="2"   fill="white"/>
        </g>
        <!-- Ala derecha -->
        <g class="b-wing-r">
            <path d="M50,50 C65,30 85,15 95,28 C100,45 80,65 52,68" fill="#E8711A" stroke="#1a0a00" stroke-width="2.2"/>
            <path d="M50,62 C65,68 82,78 86,92 C72,100 56,85 50,75" fill="#E8711A" stroke="#1a0a00" stroke-width="2.2"/>
            <path d="M52,67 C65,52 82,38 90,28" stroke="#1a0a00" stroke-width="1.2" fill="none"/>
            <path d="M52,67 C72,58 88,52 95,40" stroke="#1a0a00" stroke-width="0.9" fill="none"/>
            <circle cx="91" cy="30" r="2.2" fill="white"/>
            <circle cx="94" cy="42" r="1.8" fill="white"/>
            <circle cx="86" cy="20" r="1.8" fill="white"/>
            <circle cx="83" cy="89" r="2"   fill="white"/>
        </g>
        <!-- Cuerpo (encima de las alas) -->
        <ellipse cx="50" cy="60" rx="3.5" ry="20" fill="#1a0a00"/>
        <circle cx="50" cy="38" r="5" fill="#1a0a00"/>
        <!-- Antenas -->
        <path d="M48,34 Q41,22 36,16" stroke="#1a0a00" stroke-width="1.5" fill="none"/>
        <circle cx="36" cy="16" r="2.5" fill="#1a0a00"/>
        <path d="M52,34 Q59,22 64,16" stroke="#1a0a00" stroke-width="1.5" fill="none"/>
        <circle cx="64" cy="16" r="2.5" fill="#1a0a00"/>
    </svg>
<?php elseif ($insectType === 'mariquita'): ?>
    <!-- MARIQUITA ROJA -->
    <svg viewBox="0 0 100 100">
        <!-- Caparazón rojo -->
        <ellipse cx="50" cy="63" rx="28" ry="26" fill="#CC1010" stroke="#111" stroke-width="2"/>
        <!-- Línea divisoria central -->
        <line x1="50" y1="38" x2="50" y2="89" stroke="#111" stroke-width="2.5"/>
        <!-- 6 puntos negros (3 por lado) -->
        <circle cx="37" cy="54" r="6.5" fill="#111"/>
        <circle cx="63" cy="54" r="6.5" fill="#111"/>
        <circle cx="35" cy="70" r="5.5" fill="#111"/>
        <circle cx="65" cy="70" r="5.5" fill="#111"/>
        <circle cx="40" cy="84" r="4.5" fill="#111"/>
        <circle cx="60" cy="84" r="4.5" fill="#111"/>
        <!-- Cabeza negra -->
        <ellipse cx="50" cy="38" rx="17" ry="13" fill="#111"/>
        <!-- Ojos blancos con pupila -->
        <circle cx="44" cy="34" r="3.5" fill="white"/>
        <circle cx="56" cy="34" r="3.5" fill="white"/>
        <circle cx="44" cy="34" r="1.8" fill="#111"/>
        <circle cx="56" cy="34" r="1.8" fill="#111"/>
        <!-- Antenas -->
        <path d="M44,27 Q37,17 31,11" stroke="#111" stroke-width="1.8" fill="none"/>
        <circle cx="31" cy="11" r="2.8" fill="#111"/>
        <path d="M56,27 Q63,17 69,11" stroke="#111" stroke-width="1.8" fill="none"/>
        <circle cx="69" cy="11" r="2.8" fill="#111"/>
        <!-- Patas (3 por lado) -->
        <line class="lady-leg" x1="26" y1="56" x2="10" y2="46"/>
        <line class="lady-leg" x1="24" y1="70" x2="8"  y2="68"/>
        <line class="lady-leg" x1="28" y1="82" x2="14" y2="90"/>
        <line class="lady-leg" x1="74" y1="56" x2="90" y2="46"/>
        <line class="lady-leg" x1="76" y1="70" x2="92" y2="68"/>
        <line class="lady-leg" x1="72" y1="82" x2="86" y2="90"/>
    </svg>
<?php else: ?>
    <!-- MOSCA (original) -->
    <svg viewBox="0 0 100 100">
        <ellipse class="fly-body" cx="50" cy="55" rx="15" ry="25" />
        <circle class="fly-body" cx="50" cy="30" r="12" />
        <path class="fly-wing" d="M50 40 Q 90 10 95 40 T 50 60" />
        <path class="fly-wing" d="M50 40 Q 10 10 5 40 T 50 60" />
        <line class="fly-leg" x1="40" y1="45" x2="20" y2="35" />
        <line class="fly-leg" x1="60" y1="45" x2="80" y2="35" />
        <line class="fly-leg" x1="40" y1="65" x2="15" y2="75" />
        <line class="fly-leg" x1="60" y1="65" x2="85" y2="75" />
    </svg>
<?php endif; ?>
</div>

<!-- AUDIO CAMPANAS -->
<?php
    $chimeType = $config['customer_display_chime_type'] ?? 'mixkit_bell';
    $chimeUrls = [
        'mixkit_bell' => 'assets/audio/chime_bell.mp3',
        'cuckoo'      => 'assets/audio/chime_cuckoo.mp3',
        'church'      => 'assets/audio/chime_church.mp3',
    ];
    $chimeUrl = $chimeUrls[$chimeType] ?? $chimeUrls['mixkit_bell'];
?>
<audio id="bellChime" src="<?php echo $chimeUrl; ?>" preload="auto"></audio>
<audio id="halfHourBeep" src="assets/audio/chime_halfhour.mp3" preload="auto"></audio>

<div class="welcome-screen" id="welcomeScreen">
    <div class="qr-label"><i class="fas fa-mobile-alt me-2"></i> ¡Escanea y pide desde tu mesa!</div>
    <div class="qr-container">
        <?php 
            // Generar URL absoluta para el QR
            $host = $_SERVER['HTTP_HOST'];
            $uri = $_SERVER['REQUEST_URI'];
            $path = str_replace('customer_display.php', 'client_order.php', explode('?', $uri)[0]);
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $orderUrl = $protocol . "://" . $host . $path;
            
            // Usar un generador de QR más confiable (api.qrserver.com)
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($orderUrl);
        ?>
        <img src="<?php echo $qrUrl; ?>" alt="QR Pedido" style="width: 200px; height: 200px; display: block; margin: 0 auto; min-height: 200px; min-width: 200px;">
    </div>
    <h1 class="display-3 fw-bold mt-2"><?php echo $config['nombre_empresa'] ?? 'PalWeb'; ?></h1>
    <p class="fs-4 opacity-75 animate__animated animate__pulse animate__infinite">Listo para su pedido...</p>
    <div id="quoteBox" class="mt-4 p-4 text-center animate__animated animate__fadeIn" style="max-width: 80%; background: rgba(0,0,0,0.1); border-radius: 20px;">
        <i class="fas fa-quote-left mb-3 opacity-50"></i>
        <div id="quoteText" class="fs-3 fw-light italic" style="min-height: 80px;">Cargando inspiración...</div>
    </div>
</div>

<div class="display-container">
    <div class="brand-side">
        <div class="qr-container" style="padding: 10px; background: rgba(255,255,255,0.2);">
            <img src="<?php echo $qrUrl; ?>" style="width: 120px; height: 120px; filter: brightness(1.1);">
        </div>
        <h2 class="fw-bold m-0"><?php echo $config['nombre_empresa'] ?? 'PalWeb'; ?></h2>
        <p class="fs-6 opacity-75 mt-2">Pide desde tu móvil</p>
    </div>
    <div class="cart-side">
        <div class="product-list" id="productList"></div>
        <div class="summary-bar">
            <span>Artículos: <span id="countItems">0</span></span>
            <span>Subtotal: <span id="subtotalVal">$0.00</span></span>
        </div>
        <div class="total-section">
            <div class="total-row">
                <span class="total-label">TOTAL</span>
                <span class="total-value" id="grandTotal">$0.00</span>
            </div>
            <div id="changeContainer" style="display: none;">
                <div class="change-card animate__animated animate__tada">
                    <div class="change-text">SU VUELTO</div>
                    <div class="change-amount" id="changeAmount">$0.00</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const quotes = [
        "La vida es lo que pasa mientras estás ocupado haciendo otros planes. - John Lennon",
        "Tu tiempo es limitado, no lo malgastes viviendo la vida de otro. - Steve Jobs",
        "El éxito es ir de fracaso en fracaso sin perder el entusiasmo. - Winston Churchill",
        "La lógica te llevará de A a B. La imaginación a todas partes. - Albert Einstein",
        "No cuentes los días, haz que los días cuenten. - Muhammad Ali",
        "Si puedes soñarlo, puedes hacerlo. - Walt Disney",
        "Haz lo que puedas, con lo que tengas, donde estés. - Theodore Roosevelt",
        "Todo parece imposible hasta que se hace. - Nelson Mandela",
        "¿Por qué los pájaros vuelan al sur? Porque caminar sería muy tardado.",
        "¿Qué hace una abeja en el gimnasio? ¡Zumba!",
        "Había una vez un perro llamado Pegamento, se cayó y se pegó.",
        "¿Cuál es el colmo de un electricista? Que su mujer se llame Luz y sus hijos le sigan la corriente.",
        "¿Qué hace un tomate en el supermercado? ¡Tomando el fresco!",
        "¿Cómo se dice 'perro' en inglés? Dog. ¿Y 'veterinario'? Dog-tor.",
        "¿Por qué el mar es azul? Porque los peces hacen 'blue, blue, blue'."
        // ... (se pueden añadir más aquí)
    ];

    // Parámetros de comportamiento según el insecto (inyectados desde PHP)
    const INSECT_SPEED_MIN    = <?php echo $insectType === 'mariposa' ? '0.7'  : '2';    ?>;
    const INSECT_SPEED_MAX    = <?php echo $insectType === 'mariposa' ? '1.6'  : '5';    ?>;
    const INSECT_REST_PROB    = <?php echo $insectType === 'mariposa' ? '0.022' : '0.005'; ?>; // prob./frame
    const INSECT_REST_MIN     = <?php echo $insectType === 'mariposa' ? '5'    : '2';    ?>; // seg mín
    const INSECT_REST_MAX     = <?php echo $insectType === 'mariposa' ? '10'   : '3';    ?>; // seg máx
    const INSECT_ZIGZAG_AMP   = <?php echo $insectType === 'mariposa' ? '1.5'  : '4';    ?>;
    const INSECT_ZIGZAG_SPEED = <?php echo $insectType === 'mariposa' ? '0.07' : '0.2';  ?>;
    const INSECT_TARGET_MS    = <?php echo $insectType === 'mariposa' ? '4500' : '2000';  ?>; // ms entre targets

    // INSECTO ANIMADO
    const fly = document.getElementById('realistic-fly');
    let flyState = {
        x: Math.random() * (window.innerWidth - 50),
        y: Math.random() * (window.innerHeight - 50),
        targetX: Math.random() * (window.innerWidth - 50),
        targetY: Math.random() * (window.innerHeight - 50),
        angle: 0,
        speed: 3,
        mode: 'flying', // flying, resting, dead
        zigzagPhase: 0,
        restTimer: 0
    };

    // Inicializar posición
    fly.style.left = flyState.x + 'px';
    fly.style.top = flyState.y + 'px';

    // Manejo de clic (Matar mosca) - Usamos mousedown/touchstart para mejor respuesta
    function handleFlyInteraction(e) {
        // Prevenir comportamiento por defecto si es necesario, pero permitir propagación
        if(flyState.mode === 'dead') return;
        killFly(e);
    }
    
    fly.addEventListener('mousedown', handleFlyInteraction);
    fly.addEventListener('touchstart', handleFlyInteraction, {passive: true});

    function killFly(e) {
        flyState.mode = 'dead';
        
        // 1. Efecto visual de explosión
        fly.style.transition = 'all 0.1s ease-out';
        fly.style.transform = `scale(2) rotate(${Math.random() * 360}deg)`;
        fly.style.filter = 'blur(1px) brightness(0.5) sepia(1) hue-rotate(-50deg) saturate(5)'; // Color rojizo/quemado
        
        // 2. Manchas de sangre
        createBloodStains(flyState.x, flyState.y);

        // 3. Desaparecer y programar respawn
        setTimeout(() => {
            fly.style.display = 'none';
            // Reset estilos para cuando reaparezca
            fly.style.filter = 'drop-shadow(2px 5px 3px rgba(0,0,0,0.4))';
            fly.style.transform = 'scale(1)';
            fly.style.transition = 'none'; // Quitar transición para movimiento suave
            
            // Respawn en 2 minutos (120,000 ms)
            setTimeout(respawnFly, 120000);
        }, 200);
    }

    function createBloodStains(x, y) {
        const numDrops = 4 + Math.floor(Math.random() * 4); // 4 a 7 manchas
        for(let i=0; i<numDrops; i++) {
            const spot = document.createElement('div');
            spot.className = 'blood-spot';
            const size = 8 + Math.random() * 12; // Tamaño variado
            spot.style.width = size + 'px';
            spot.style.height = size + 'px';
            
            // Dispersión aleatoria cerca de la mosca
            const angle = Math.random() * Math.PI * 2;
            const dist = Math.random() * 40;
            const offsetX = Math.cos(angle) * dist;
            const offsetY = Math.sin(angle) * dist;
            
            spot.style.left = (x + offsetX + 10) + 'px'; 
            spot.style.top = (y + offsetY + 10) + 'px';
            
            // Forma irregular usando border-radius
            spot.style.borderRadius = `${30+Math.random()*70}% ${30+Math.random()*70}% ${30+Math.random()*70}% ${30+Math.random()*70}% / ${30+Math.random()*70}% ${30+Math.random()*70}% ${30+Math.random()*70}% ${30+Math.random()*70}%`;
            
            document.body.appendChild(spot);
            
            // Las manchas desaparecen gradualmente después de un tiempo (opcional, aquí duran hasta recarga)
            setTimeout(() => {
                 spot.style.transition = 'opacity 5s';
                 spot.style.opacity = '0';
                 setTimeout(() => spot.remove(), 5000);
            }, 60000); 
        }
    }

    function respawnFly() {
        flyState.mode = 'flying';
        // Empezar desde un borde aleatorio
        if(Math.random() > 0.5) {
            flyState.x = Math.random() > 0.5 ? -50 : window.innerWidth + 50;
            flyState.y = Math.random() * window.innerHeight;
        } else {
            flyState.x = Math.random() * window.innerWidth;
            flyState.y = Math.random() > 0.5 ? -50 : window.innerHeight + 50;
        }
        
        fly.style.display = 'block';
        fly.style.left = flyState.x + 'px';
        fly.style.top = flyState.y + 'px';
        pickNewTarget();
        requestAnimationFrame(flyLoop);
    }

    function pickNewTarget() {
        if(flyState.mode === 'dead') return;
        flyState.targetX = Math.random() * (window.innerWidth - 50);
        flyState.targetY = Math.random() * (window.innerHeight - 50);
        // Velocidad variable para realismo
        flyState.speed = INSECT_SPEED_MIN + Math.random() * (INSECT_SPEED_MAX - INSECT_SPEED_MIN);
    }

    // Cambiar objetivo periódicamente según el insecto
    setInterval(() => {
        if(flyState.mode === 'flying') pickNewTarget();
    }, INSECT_TARGET_MS);

    function flyLoop() {
        if(flyState.mode === 'dead') return;

        // Lógica de descanso (Resting)
        if(flyState.mode === 'resting') {
            flyState.restTimer--;
            if(flyState.restTimer <= 0) {
                flyState.mode = 'flying'; // Volver a volar
                pickNewTarget();
            }
            requestAnimationFrame(flyLoop);
            return;
        }

        // Posibilidad aleatoria de descansar (varía por insecto)
        if(Math.random() < INSECT_REST_PROB) {
            flyState.mode = 'resting';
            flyState.restTimer = 60 * (INSECT_REST_MIN + Math.random() * (INSECT_REST_MAX - INSECT_REST_MIN));
            requestAnimationFrame(flyLoop);
            return;
        }

        // Calcular vector hacia el objetivo
        const dx = flyState.targetX - flyState.x;
        const dy = flyState.targetY - flyState.y;
        const dist = Math.sqrt(dx*dx + dy*dy);

        if(dist > 10) {
            // Movimiento base hacia el objetivo
            const vx = (dx / dist) * flyState.speed;
            const vy = (dy / dist) * flyState.speed;

            // Zigzag / Espiral (amplitud y velocidad varían por insecto)
            flyState.zigzagPhase += INSECT_ZIGZAG_SPEED;
            const zigZagAmp = INSECT_ZIGZAG_AMP;
            
            // Vector perpendicular normalizado (-vy, vx) pero usando velocidad base 1
            const pX = -(vy/flyState.speed) * Math.sin(flyState.zigzagPhase) * zigZagAmp;
            const pY = (vx/flyState.speed) * Math.sin(flyState.zigzagPhase) * zigZagAmp;

            flyState.x += vx + pX;
            flyState.y += vy + pY;

            // Calcular ángulo de rotación basado en el movimiento real
            const angle = Math.atan2(vy + pY, vx + pX) * (180 / Math.PI) + 90;
            flyState.angle = angle;
        } else {
            // Si llegó cerca del objetivo, buscar uno nuevo inmediatamente
            pickNewTarget();
        }

        // Mantener dentro de la pantalla (rebote suave)
        if(flyState.x < 0) flyState.x = 0;
        if(flyState.x > window.innerWidth - 40) flyState.x = window.innerWidth - 40;
        if(flyState.y < 0) flyState.y = 0;
        if(flyState.y > window.innerHeight - 40) flyState.y = window.innerHeight - 40;

        // Aplicar posición
        fly.style.left = flyState.x + 'px';
        fly.style.top = flyState.y + 'px';
        fly.style.transform = `rotate(${flyState.angle}deg)`;

        requestAnimationFrame(flyLoop);
    }

    // Iniciar loop
    requestAnimationFrame(flyLoop);

    // QUOTES
    function rotateQuote() {
        const textEl = document.getElementById('quoteText');
        const boxEl = document.getElementById('quoteBox');
        if(!boxEl) return;
        boxEl.classList.remove('animate__fadeIn');
        boxEl.classList.add('animate__fadeOut');
        setTimeout(() => {
            textEl.innerText = quotes[Math.floor(Math.random() * quotes.length)];
            boxEl.classList.remove('animate__fadeOut');
            boxEl.classList.add('animate__fadeIn');
        }, 800);
    }
    setInterval(rotateQuote, 12000);
    rotateQuote();

    // ACTIVAR AUDIO (Browser unlock)
    function enableAudio() {
        const overlay = document.getElementById('soundOverlay');
        if(!overlay) return;

        // Tocar un sonido corto para desbloquear el audio context
        const bell = document.getElementById('bellChime');
        bell.volume = 0; // Silencio para el desbloqueo
        bell.play().then(() => {
            bell.pause();
            bell.volume = 1; // Restaurar volumen
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 500);
        }).catch(e => {
            console.log("Audio no activado por el navegador (requiere click)");
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 500);
        });
    }

    // Auto-cerrar overlay si no se oprime en 30 segundos
    setTimeout(enableAudio, 30000);

    // LÓGICA DE CAMPANADAS
    const bell = document.getElementById('bellChime');
    const beep = document.getElementById('halfHourBeep');
    let lastChimeHour = -1;
    let lastBeepMinute = -1;

    function playChimes(count) {
        if (count <= 0) return;
        bell.currentTime = 0;
        bell.play().catch(e => console.log("Audio block", e));
        if (count > 1) {
            bell.addEventListener('ended', function onEnded() {
                bell.removeEventListener('ended', onEnded);
                playChimes(count - 1);
            });
        }
    }

    function checkTimeForChimes() {
        const now = new Date();
        const hour = now.getHours();
        const minutes = now.getMinutes();

        // Rango: 6 AM a 10 PM (22:00) inclusive
        if (hour >= 6 && hour <= 22) {
            // XX:00 - Campanadas de la hora
            if (minutes === 0 && hour !== lastChimeHour) {
                lastChimeHour = hour;
                const chimes = hour % 12 || 12;
                playChimes(chimes);
            }
            
            // XX:30 - Beep de media hora
            if (minutes === 30 && minutes !== lastBeepMinute) {
                lastBeepMinute = minutes;
                beep.currentTime = 0;
                beep.play().catch(e => console.log("Audio block beep", e));
            }
        }
        
        // Resetear centinelas
        if (minutes !== 0) lastChimeHour = -1;
        if (minutes !== 30) lastBeepMinute = -1;
    }

    // Revisar el tiempo cada 10 segundos
    setInterval(checkTimeForChimes, 10000);
    checkTimeForChimes();

    // POS SYNC
    let lastRenderedJson = "";
    function updateDisplay() {
        const cartState = JSON.parse(localStorage.getItem('pos_cart_state') || '{"cart":[], "globalDiscountPct":0}');
        const lastPayment = JSON.parse(localStorage.getItem('pos_last_payment') || 'null');
        const currentJson = JSON.stringify(cartState) + JSON.stringify(lastPayment);
        if (currentJson === lastRenderedJson) return;
        lastRenderedJson = currentJson;

        const welcome = document.getElementById('welcomeScreen');
        const listContainer = document.getElementById('productList');
        if (cartState.cart.length === 0 && !lastPayment) {
            welcome.classList.remove('hidden');
            return;
        }
        welcome.classList.add('hidden');

        // Diffing
        const existingRows = listContainer.querySelectorAll('.item-row');
        existingRows.forEach(row => {
            if (!cartState.cart.find(item => item.id == row.dataset.id)) {
                row.classList.add('removed-item');
                setTimeout(() => row.remove(), 500);
            }
        });

        let subtotal = 0; let totalQty = 0;
        cartState.cart.forEach(item => {
            const lineTotal = item.price * item.qty * (1 - (item.discountPct || 0) / 100);
            subtotal += lineTotal; totalQty += item.qty;
            let row = listContainer.querySelector(`.item-row[data-id="${item.id}"]`);
            if (row) {
                row.querySelector('.item-qty').innerText = item.qty + 'x';
                row.querySelector('.item-subtotal').innerText = '$' + lineTotal.toFixed(2);
            } else {
                const div = document.createElement('div');
                div.className = 'item-row new-item';
                div.dataset.id = item.id;
                div.innerHTML = `<div class="item-info"><img src="image.php?code=${item.id}" class="product-img-mini" onerror="this.src='assets/img/no-image.png'"><span class="item-qty">${item.qty}x</span><div class="item-details"><span class="item-name">${item.name}</span><span class="item-price">$${parseFloat(item.price).toFixed(2)} c/u</span></div></div><div class="item-subtotal">$${lineTotal.toFixed(2)}</div>`;
                listContainer.appendChild(div);
            }
        });

        const finalTotal = subtotal * (1 - (cartState.globalDiscountPct || 0) / 100);
        document.getElementById('grandTotal').innerText = '$' + finalTotal.toFixed(2);
        document.getElementById('countItems').innerText = totalQty;
        document.getElementById('subtotalVal').innerText = '$' + subtotal.toFixed(2);

        // AUTO-SCROLL AL FINAL
        setTimeout(() => { listContainer.scrollTop = listContainer.scrollHeight; }, 100);

        const changeContainer = document.getElementById('changeContainer');
        if (lastPayment && lastPayment.method === 'Efectivo' && lastPayment.change > 0) {
            changeContainer.style.display = 'block';
            document.getElementById('changeAmount').innerText = '$' + parseFloat(lastPayment.change).toFixed(2);
            if(!window.changeTimer) {
                window.changeTimer = setTimeout(() => { localStorage.removeItem('pos_last_payment'); window.changeTimer = null; updateDisplay(); }, 15000);
            }
        } else { changeContainer.style.display = 'none'; }
    }
    window.addEventListener('storage', updateDisplay);
    setInterval(updateDisplay, 500);
    updateDisplay();
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
