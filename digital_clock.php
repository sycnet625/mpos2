<?php
date_default_timezone_set('America/Havana');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Digital Neon Clock</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=Rajdhani:wght@500;700&display=swap');

    :root {
      --bg-color: #050505;
      --panel-bg: rgba(20, 20, 20, 0.8);
      --accent-color: #00f2ff;
      --glow-color: rgba(0, 242, 255, 0.5);
      --text-main: #fff;
      --digit-gradient: linear-gradient(180deg, #fff 0%, var(--accent-color) 100%);
    }

    [data-theme="aurora"] {
      --accent-color: #70ff00;
      --glow-color: rgba(112, 255, 0, 0.4);
      --digit-gradient: linear-gradient(180deg, #fff 0%, #70ff00 100%);
    }

    [data-theme="lava"] {
      --accent-color: #ff3c00;
      --glow-color: rgba(255, 60, 0, 0.4);
      --digit-gradient: linear-gradient(180deg, #fff 0%, #ff3c00 100%);
    }

    [data-theme="cyber"] {
      --accent-color: #ff00ea;
      --glow-color: rgba(255, 0, 234, 0.4);
      --digit-gradient: linear-gradient(180deg, #fff 0%, #ff00ea 100%);
    }

    [data-theme="rgb"] {
      --accent-color: #00f2ff;
      --glow-color: rgba(0, 242, 255, 0.5);
      --digit-gradient: linear-gradient(180deg, #fff 0%, var(--accent-color) 100%);
      animation: rgb-cycle 8s linear infinite;
    }

    @keyframes rgb-cycle {
      0% { filter: hue-rotate(0deg); }
      100% { filter: hue-rotate(360deg); }
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      background-color: var(--bg-color);
      color: var(--text-main);
      font-family: 'Rajdhani', sans-serif;
      height: 100dvh;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      transition: all 0.5s ease;
    }

    .background-glow {
      position: fixed;
      width: 100vw;
      height: 100vh;
      background: radial-gradient(circle at center, var(--glow-color) 0%, transparent 70%);
      opacity: 0.15;
      pointer-events: none;
      z-index: -1;
    }

    .clock-container {
      position: relative;
      text-align: center;
      padding: 40px;
      background: var(--panel-bg);
      border-radius: 30px;
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 25px 50px rgba(0,0,0,0.5), inset 0 0 20px rgba(255,255,255,0.02);
      backdrop-filter: blur(15px);
      width: min(900px, 95vw);
    }

    /* Time Section */
    .time-row {
      display: flex;
      align-items: baseline;
      justify-content: center;
      gap: 10px;
      margin-bottom: 10px;
    }

    .digits {
      font-family: 'Orbitron', sans-serif;
      font-size: clamp(5rem, 18vw, 12rem);
      font-weight: 700;
      line-height: 1;
      background: var(--digit-gradient);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      filter: drop-shadow(0 0 15px var(--glow-color));
      letter-spacing: -2px;
    }

    .seconds-col {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 5px;
    }

    .seconds {
      font-size: clamp(1.5rem, 5vw, 3rem);
      color: var(--accent-color);
      opacity: 0.8;
      font-weight: bold;
    }

    .ampm {
      font-size: 1.2rem;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: var(--text-main);
      opacity: 0.5;
    }

    /* Info Section */
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid rgba(255,255,255,0.1);
    }

    .date-box, .weather-box {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .label {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: var(--accent-color);
      font-weight: bold;
    }

    .value {
      font-size: clamp(1.2rem, 3vw, 1.8rem);
      font-weight: 600;
    }

    .day-name {
      font-size: 1.2rem;
      color: var(--text-main);
      opacity: 0.7;
    }

    /* Menu Buttons */
    .controls {
      position: fixed;
      top: 20px;
      right: 20px;
      display: flex;
      gap: 10px;
    }

    .btn {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      color: #fff;
      padding: 10px 15px;
      border-radius: 10px;
      cursor: pointer;
      backdrop-filter: blur(5px);
      transition: all 0.2s;
    }
    .btn:hover { background: var(--accent-color); color: #000; }

    #settingsPanel {
      position: fixed;
      top: 0;
      right: -320px;
      width: 300px;
      height: 100%;
      background: rgba(10, 10, 10, 0.95);
      border-left: 1px solid var(--accent-color);
      padding: 30px 20px;
      transition: right 0.3s ease;
      z-index: 100;
    }
    #settingsPanel.open { right: 0; }

    .theme-opt {
      padding: 15px;
      margin-bottom: 10px;
      border-radius: 10px;
      cursor: pointer;
      border: 1px solid rgba(255,255,255,0.1);
      transition: all 0.2s;
    }
    .theme-opt:hover { border-color: var(--accent-color); }
    .theme-opt.active { background: var(--accent-color); color: #000; font-weight: bold; }

    @media (max-width: 600px) {
      .clock-container { padding: 20px; border-radius: 20px; }
      .info-row { flex-direction: column; gap: 20px; }
    }
  </style>
</head>
<body data-theme="default">
  <div class="background-glow"></div>

  <div class="controls">
    <button class="btn" id="fullBtn">⛶</button>
    <button class="btn" id="menuBtn">Estilo</button>
  </div>

  <aside id="settingsPanel">
    <h2 style="margin-bottom: 20px; font-size: 1.5rem; color: var(--accent-color);">Personalizar</h2>
    <div class="theme-opt active" data-val="default">Ice Blue</div>
    <div class="theme-opt" data-val="aurora">Aurora Green</div>
    <div class="theme-opt" data-val="lava">Lava Red</div>
    <div class="theme-opt" data-val="cyber">Cyber Pink</div>
    <div class="theme-opt" data-val="rgb">RGB Dinámico 🌈</div>
    <div class="theme-opt" id="toggle24">Formato 24H: Desactivado</div>
    
    <button class="btn" id="closeBtn" style="width: 100%; margin-top: 20px;">Cerrar</button>
  </aside>

  <main class="clock-container">
    <div class="time-row">
      <div class="digits" id="timeDigits">00:00</div>
      <div class="seconds-col">
        <div class="seconds" id="secDigits">00</div>
        <div class="ampm" id="ampmLabel">PM</div>
      </div>
    </div>

    <div class="info-row">
      <div class="date-box">
        <span class="label">Fecha</span>
        <span class="value" id="dateLabel">07 ABRIL</span>
        <span class="day-name" id="dayLabel">MARTES</span>
      </div>
      <div class="weather-box">
        <span class="label">La Habana</span>
        <span class="value" id="tempLabel">--°C</span>
        <span class="day-name" id="weatherDesc">Cargando...</span>
      </div>
    </div>
  </main>

  <script>
    let is24h = localStorage.getItem('digi_24h') === 'true';
    const themes = document.querySelectorAll('.theme-opt[data-val]');
    const settings = document.getElementById('settingsPanel');

    function updateTime() {
      const now = new Date();
      let h = now.getHours();
      const m = String(now.getMinutes()).padStart(2, '0');
      const s = String(now.getSeconds()).padStart(2, '0');
      const ampm = h >= 12 ? 'PM' : 'AM';

      if (!is24h) {
        h = h % 12 || 12;
      }
      h = String(h).padStart(2, '0');

      document.getElementById('timeDigits').textContent = `${h}:${m}`;
      document.getElementById('secDigits').textContent = s;
      document.getElementById('ampmLabel').textContent = is24h ? '' : ampm;

      // Date
      const options = { day: 'numeric', month: 'long' };
      document.getElementById('dateLabel').textContent = now.toLocaleDateString('es-ES', options).toUpperCase();
      document.getElementById('dayLabel').textContent = now.toLocaleDateString('es-ES', { weekday: 'long' }).toUpperCase();
    }

    function weatherEmoji(code) {
      const c = Number(code);
      const map = {
        0: "☀️", 1: "🌤️", 2: "⛅", 3: "☁️",
        45: "🌫️", 48: "🌫️", 51: "🌦️", 53: "🌦️", 55: "🌦️",
        61: "🌧️", 63: "🌧️", 65: "🌧️", 71: "🌨️", 73: "🌨️", 75: "🌨️",
        77: "🌨️", 80: "🌦️", 81: "🌧️", 82: "🌧️", 85: "🌨️", 86: "🌨️",
        95: "⛈️", 96: "⛈️", 99: "⛈️",
        113: "☀️", 116: "⛅", 119: "☁️", 122: "☁️",
        143: "🌫️", 176: "🌦️", 179: "🌨️", 182: "🌧️", 200: "⛈️",
        227: "🌨️", 230: "❄️", 248: "🌫️", 260: "❄️", 263: "🌦️", 266: "🌦️",
        281: "❄️", 293: "🌧️", 296: "🌧️", 299: "🌧️", 302: "🌧️", 305: "🌧️",
        308: "🌧️", 311: "❄️", 314: "❄️", 317: "🌧️", 320: "🌧️", 323: "🌨️",
        326: "🌨️", 329: "🌨️", 332: "🌨️", 335: "🌨️", 338: "🌨️", 350: "🌨️",
        353: "🌦️", 356: "🌧️", 359: "🌧️", 362: "🌧️", 365: "🌧️", 368: "🌨️",
        371: "🌨️", 386: "⛈️", 389: "⛈️", 392: "⛈️", 395: "⛈️"
      };
      return map[c] || "🌀";
    }

    function weatherText(code) {
      const c = Number(code);
      const map = {
        0: "Despejado", 1: "Mayormente despejado", 2: "Parcialmente nublado", 3: "Nublado",
        45: "Niebla", 48: "Niebla escarchada", 51: "Llovizna ligera", 53: "Llovizna moderada", 55: "Llovizna intensa",
        61: "Lluvia ligera", 63: "Lluvia moderada", 65: "Lluvia fuerte", 71: "Nieve ligera", 73: "Nieve moderada", 75: "Nieve fuerte",
        77: "Granizo fino", 80: "Chubascos ligeros", 81: "Chubascos moderados", 82: "Chubascos fuertes", 95: "Tormenta",
        96: "Tormenta con granizo", 99: "Tormenta fuerte", 113: "Despejado", 116: "Parcialmente nublado", 119: "Nublado",
        122: "Muy nublado", 143: "Niebla ligera", 176: "Lluvia dispersa", 179: "Nieve dispersa", 182: "Aguanieve",
        200: "Tormenta cercana", 227: "Nieve con viento", 230: "Tormenta de nieve", 248: "Niebla", 260: "Niebla helada",
        263: "Llovizna ligera", 266: "Llovizna", 281: "Llovizna helada", 293: "Lluvia ligera", 296: "Lluvia ligera",
        299: "Lluvia moderada", 302: "Lluvia moderada", 305: "Lluvia fuerte", 308: "Lluvia muy fuerte",
        311: "Lluvia helada", 314: "Lluvia helada fuerte", 317: "Aguanieve ligero", 320: "Aguanieve fuerte",
        323: "Nieve ligera", 326: "Nieve ligera", 329: "Nieve moderada", 332: "Nieve moderada", 335: "Nieve fuerte",
        338: "Nieve muy fuerte", 350: "Granizo", 353: "Chubasco ligero", 356: "Chubasco fuerte", 359: "Chubasco torrencial",
        362: "Aguanieve ligero", 365: "Aguanieve fuerte", 368: "Nieve ligera", 371: "Nieve fuerte",
        386: "Lluvia con truenos", 389: "Tormenta con lluvia", 392: "Nieve con truenos", 395: "Tormenta con nieve"
      };
      return map[c] || "Condición variable";
    }

    async function loadWeather() {
      const tempEl = document.getElementById('tempLabel');
      const descEl = document.getElementById('weatherDesc');
      
      // Intentar cargar desde cache local (resiliencia)
      const cached = localStorage.getItem('last_weather_json');
      if (cached) {
        try {
          const d = JSON.parse(cached);
          tempEl.textContent = `${weatherEmoji(d.code)} ${d.current}°C`;
          descEl.textContent = `${weatherText(d.code)} (MÁX ${d.max}°)`;
        } catch(e) {}
      }

      try {
        const r = await fetch('simple_weather.php?t=' + Date.now());
        if (!r.ok) throw new Error();
        const d = await r.json();
        
        if (d.current !== undefined) {
          const emoji = weatherEmoji(d.code);
          const desc = weatherText(d.code);
          tempEl.textContent = `${emoji} ${d.current}°C`;
          descEl.textContent = `${desc} (MÁX ${d.max}°)`;
          // Guardar para uso offline/lento
          localStorage.setItem('last_weather_json', JSON.stringify(d));
        }
      } catch (e) {
        if (!tempEl.textContent.includes('°')) {
          tempEl.textContent = '☀️ --°C';
          descEl.textContent = 'Clima no disponible';
        }
      }
    }

    // Interaction
    document.getElementById('menuBtn').onclick = () => settings.classList.add('open');
    document.getElementById('closeBtn').onclick = () => settings.classList.remove('open');
    
    document.getElementById('fullBtn').onclick = () => {
      if (!document.fullscreenElement) document.documentElement.requestFullscreen();
      else document.exitFullscreen();
    };

    document.getElementById('toggle24').onclick = function() {
      is24h = !is24h;
      localStorage.setItem('digi_24h', is24h);
      this.textContent = `Formato 24H: ${is24h ? 'Activado' : 'Desactivado'}`;
      updateTime();
    };

    themes.forEach(opt => {
      opt.onclick = () => {
        const theme = opt.dataset.val;
        document.body.setAttribute('data-theme', theme);
        themes.forEach(t => t.classList.remove('active'));
        opt.classList.add('active');
        localStorage.setItem('digi_theme', theme);
      };
    });

    // Init
    const savedTheme = localStorage.getItem('digi_theme') || 'default';
    document.body.setAttribute('data-theme', savedTheme);
    document.querySelector(`[data-val="${savedTheme}"]`)?.classList.add('active');
    document.getElementById('toggle24').textContent = `Formato 24H: ${is24h ? 'Activado' : 'Desactivado'}`;

    setInterval(updateTime, 1000);
    setInterval(loadWeather, 600000); // 10 min
    updateTime();
    loadWeather();
  </script>
</body>
</html>
