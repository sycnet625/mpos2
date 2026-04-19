<?php
date_default_timezone_set('America/Havana');
require_once 'config_loader.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Almanaque Luxury Pro - <?= date('F Y', strtotime('now')) ?></title>
  <style>
    :root {
      /* Skins Variables */
      --bg-gradient: linear-gradient(135deg, #0f1923 0%, #1a2a3a 50%, #0f1923 100%);
      --clock-bezel: conic-gradient(from 20deg, #b8b8bc, #8a8a8e, #d0d0d4, #7a7a7e, #c8c8cc, #6a6a6e, #b0b0b4, #8a8a8e, #d8d8dc, #7a7a7e, #a0a0a4, #8a8a8e, #b8b8bc);
      --dial-bg: radial-gradient(circle at 42% 38%, #243040 0%, #1a2636 25%, #122030 50%, #0c1828 75%, #081020 100%);
      --marker-color: linear-gradient(180deg, #e8eef4 0%, #b8c8d8 30%, #a0b0c0 70%, #b8c8d8 100%);
      --hand-color: linear-gradient(90deg, #7a8a9a 0%, #a8b8c8 15%, #d0dce8 35%, #e8f0f8 50%, #d0dce8 65%, #a8b8c8 85%, #7a8a9a 100%);
      --accent-color: #4a9eff;
      --text-color: #e0e8f0;
      --panel-bg: rgba(18, 30, 44, 0.95);
      --calendar-card-bg: rgba(18, 30, 44, 0.85);
    }

    /* Skin Golden Skeleton */
    [data-skin="golden"] {
      --bg-gradient: linear-gradient(135deg, #1a120b 0%, #3c2a21 50%, #1a120b 100%);
      --clock-bezel: conic-gradient(from 20deg, #d4af37, #f9e27d, #b8860b, #fcf6ba, #d4af37, #aa8800, #d4af37);
      --dial-bg: radial-gradient(circle at 50% 50%, #2c1e12 0%, #1a0f08 100%);
      --marker-color: linear-gradient(180deg, #f9e27d, #d4af37, #b8860b);
      --hand-color: linear-gradient(90deg, #b8860b, #d4af37, #f9e27d, #d4af37, #b8860b);
      --accent-color: #d4af37;
      --text-color: #f9e27d;
      --panel-bg: rgba(30, 20, 10, 0.95);
      --calendar-card-bg: rgba(40, 30, 20, 0.85);
    }

    /* Skin Midnight Blue */
    [data-skin="midnight"] {
      --bg-gradient: linear-gradient(135deg, #050a14 0%, #0a1a35 100%);
      --clock-bezel: conic-gradient(from 0deg, #ccc, #eee, #999, #bbb, #ccc);
      --dial-bg: radial-gradient(circle at 50% 50%, #0a244d 0%, #020814 100%);
      --marker-color: #fff;
      --hand-color: linear-gradient(90deg, #ddd, #fff, #ddd);
      --accent-color: #00b7ff;
      --text-color: #fff;
      --calendar-card-bg: rgba(10, 20, 40, 0.9);
    }

    /* Skin Rose Gold Luxury */
    [data-skin="rosegold"] {
      --bg-gradient: linear-gradient(135deg, #2d1b1b 0%, #1a0f0f 100%);
      --clock-bezel: conic-gradient(from 45deg, #e7a08d, #f9c8b8, #b36b5a, #f9c8b8, #e7a08d);
      --dial-bg: #fff;
      --marker-color: #b36b5a;
      --hand-color: linear-gradient(90deg, #b36b5a, #e7a08d, #b36b5a);
      --accent-color: #b36b5a;
      --text-color: #b36b5a;
      --calendar-card-bg: rgba(255, 255, 255, 0.95);
    }

    /* Skin Tactical Neon */
    [data-skin="tactical"] {
      --bg-gradient: radial-gradient(circle at 50% 50%, #121212 0%, #000 100%);
      --clock-bezel: conic-gradient(from 0deg, #333, #111, #444, #222, #333);
      --dial-bg: #0a0a0a;
      --marker-color: #00ff41;
      --hand-color: #eee;
      --accent-color: #00ff41;
      --text-color: #00ff41;
      --panel-bg: rgba(0, 0, 0, 0.95);
      --calendar-card-bg: rgba(10, 10, 10, 0.9);
    }

    /* Skin Emerald Carbon */
    [data-skin="emerald"] {
      --bg-gradient: linear-gradient(135deg, #061a12 0%, #000 100%);
      --clock-bezel: #111;
      --dial-bg: repeating-linear-gradient(45deg, #0a2b1f 0%, #051510 5%, #0a2b1f 10%);
      --marker-color: #50fa7b;
      --hand-color: #fff;
      --accent-color: #50fa7b;
      --text-color: #50fa7b;
      --calendar-card-bg: rgba(5, 20, 15, 0.9);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100dvh;
      background: var(--bg-gradient);
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      color: var(--text-color);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      transition: all 0.5s ease;
    }

    /* Controls */
    .top-controls {
      position: fixed;
      top: 20px;
      right: 20px;
      display: flex;
      gap: 10px;
      z-index: 100;
    }
    .ctrl-btn {
      background: var(--panel-bg);
      border: 1px solid var(--accent-color);
      color: var(--text-color);
      padding: 10px 15px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: bold;
      box-shadow: 0 4px 15px rgba(0,0,0,0.3);
      transition: all 0.2s;
    }
    .ctrl-btn:hover { background: var(--accent-color); color: #000; }

    /* Side Panel */
    #optionsPanel {
      position: fixed;
      top: 0;
      right: -320px;
      width: 300px;
      height: 100%;
      background: var(--panel-bg);
      border-left: 1px solid var(--accent-color);
      z-index: 101;
      padding: 30px 20px;
      transition: right 0.3s ease;
      box-shadow: -5px 0 25px rgba(0,0,0,0.5);
      backdrop-filter: blur(10px);
      overflow-y: auto;
    }
    #optionsPanel.open { right: 0; }

    .option-group { margin-bottom: 25px; }
    .option-group h3 { 
      font-size: 14px; 
      text-transform: uppercase; 
      margin-bottom: 15px; 
      color: var(--accent-color);
      letter-spacing: 1px;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      padding-bottom: 5px;
    }
    .skin-grid { display: grid; grid-template-columns: 1fr; gap: 8px; }
    .skin-opt {
      padding: 12px;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
      background: rgba(255,255,255,0.05);
      transition: all 0.2s;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .skin-opt::before {
      content: '';
      width: 12px;
      height: 12px;
      border-radius: 50%;
      border: 1px solid #fff;
    }
    .skin-opt.active { background: rgba(255,255,255,0.1); border-color: var(--accent-color); }
    .skin-opt.active::before { background: var(--accent-color); }

    .container {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: clamp(20px, 4vw, 50px);
      padding: clamp(12px, 2vw, 24px);
      max-width: 1250px;
      width: 100%;
    }

    /* --- Analog Clock --- */
    .clock-wrap { position: relative; flex-shrink: 0; }

    .analog-clock {
      width: clamp(280px, 35vw, 420px);
      height: clamp(280px, 35vw, 420px);
      border-radius: 50%;
      position: relative;
      background: radial-gradient(circle at 50% 50%, #1a1a1e 0%, #1a1a1e 61%, transparent 61.5%), var(--clock-bezel);
      box-shadow:
        0 0 0 1px rgba(0,0,0,0.5),
        0 0 0 4px rgba(255,255,255,0.05),
        0 20px 50px rgba(0,0,0,0.6),
        inset 0 2px 15px rgba(255,255,255,0.1);
    }

    .clock-dial {
      position: absolute;
      inset: 6.5%;
      border-radius: 50%;
      background: var(--dial-bg);
      box-shadow: inset 0 5px 25px rgba(0,0,0,0.8);
      overflow: hidden;
    }

    /* Sunburst Texture */
    .sunburst {
      position: absolute; inset: 0;
      background: repeating-conic-gradient(from 0deg, rgba(255,255,255,0.03) 0deg, transparent 5deg, rgba(0,0,0,0.03) 10deg);
      pointer-events: none;
    }

    /* Complications */
    .complication {
      position: absolute;
      width: 22%;
      height: 22%;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 50%;
      background: rgba(0,0,0,0.2);
      box-shadow: inset 0 2px 5px rgba(0,0,0,0.4);
      transition: opacity 0.3s ease, transform 0.3s ease;
    }
    [data-comps="off"] .complication {
      opacity: 0;
      pointer-events: none;
      transform: scale(0.8);
    }

    /* Moon Phase (10 o'clock) */
    .comp-moon {
      top: 25%; left: 20%;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .moon-disk {
      width: 100%; height: 100%;
      background: #000;
      position: relative;
      border-radius: 50%;
    }
    .moon-actual {
      position: absolute; width: 60%; height: 60%;
      top: 20%; left: 20%;
      background: #fff;
      border-radius: 50%;
      box-shadow: 0 0 10px #fff;
    }
    .moon-shadow {
      position: absolute; width: 60%; height: 60%;
      top: 20%; left: 20%;
      background: #000;
      border-radius: 50%;
      transition: transform 0.5s;
    }

    /* Day of Week (2 o'clock) */
    .comp-day {
      top: 25%; right: 20%;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: center;
      font-size: 10px;
      font-weight: bold;
      color: var(--accent-color);
    }
    .comp-day .hand-sub {
      position: absolute; top: 50%; left: 50%;
      width: 2px; height: 40%; background: var(--accent-color);
      transform-origin: bottom center;
    }

    /* Seconds Sub-dial (6 o'clock) */
    .comp-seconds {
      bottom: 15%; left: 50%;
      transform: translateX(-50%);
      width: 24%; height: 24%;
    }
    .comp-seconds .hand-sec-sub {
      position: absolute; top: 10%; left: 50%;
      width: 1.5px; height: 40%; background: #ff2828;
      transform-origin: bottom center;
    }

    /* Skeleton Decorations */
    .skeleton-elements {
      position: absolute; inset: 0; opacity: 0; transition: opacity 0.5s;
    }
    [data-skin="golden"] .skeleton-elements { opacity: 0.6; }

    .gear {
      position: absolute;
      background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path fill="%23d4af37" d="M100 50c0-3.3-2.3-6.1-5.4-6.8l-1.6-4.9 4-4c2.3-2.3 2.3-6.1 0-8.5l-2.8-2.8c-2.3-2.3-6.1-2.3-8.5 0l-4 4-4.9-1.6c-.7-3.1-3.5-5.4-6.8-5.4h-4c-3.3 0-6.1 2.3-6.8 5.4l-4.9 1.6-4-4c-2.3-2.3-6.1-2.3-8.5 0l-2.8 2.8c-2.3 2.3-2.3 6.1 0 8.5l4 4-1.6 4.9c-3.1.7-5.4 3.5-5.4 6.8v4c0 3.3 2.3 6.1 5.4 6.8l1.6 4.9-4 4c-2.3 2.3-2.3 6.1 0 8.5l2.8 2.8c2.3 2.3 6.1 2.3 8.5 0l4-4 4.9 1.6c.7 3.1 3.5 5.4 6.8 5.4h4c3.3 0 6.1-2.3 6.8-5.4l4.9-1.6 4 4c2.3 2.3 6.1 2.3 8.5 0l2.8-2.8c2.3-2.3 2.3-6.1 0-8.5l-4-4 1.6-4.9c3.1-.7 5.4-3.5 5.4-6.8v-4zm-50 15c-8.3 0-15-6.7-15-15s6.7-15 15-15 15 6.7 15 15-6.7 15-15 15z"/></svg>');
      background-size: contain;
      animation: rotate-gear 20s linear infinite;
    }
    @keyframes rotate-gear { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .gear-1 { width: 140px; height: 140px; top: 5%; left: 5%; animation-duration: 40s; opacity: 0.3; }
    .gear-2 { width: 90px; height: 90px; bottom: 10%; right: 10%; animation-duration: 25s; animation-direction: reverse; opacity: 0.4; }

    .tourbillon {
      position: absolute;
      width: 70px; height: 70px;
      bottom: 18%; left: 50%;
      transform: translateX(-50%);
      border: 2px solid rgba(212, 175, 55, 0.4);
      border-radius: 50%;
      background: radial-gradient(circle, rgba(212,175,55,0.15) 0%, transparent 80%);
      animation: rotate-gear 3s linear infinite;
      z-index: 1;
    }

    /* Numerals */
    .num-wrap { position: absolute; inset: 0; pointer-events: none; }
    .roman-num {
      position: absolute; top: 50%; left: 50%; transform-origin: center;
      font-family: 'Times New Roman', serif; font-weight: bold;
      color: var(--accent-color); font-size: 22px;
      text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
    }
    [data-skin="tactical"] .roman-num { display: none; }

    .clock-brand {
      position: absolute; top: 38%; left: 50%;
      transform: translateX(-50%); text-align: center; z-index: 5;
    }
    .brand-name { font-size: 11px; font-weight: bold; letter-spacing: 4px; text-transform: uppercase; color: var(--accent-color); }
    .brand-sub { font-size: 7px; letter-spacing: 2px; text-transform: uppercase; color: rgba(255,255,255,0.3); margin-top: 2px; }

    .date-window {
      position: absolute; right: 12%; top: 50%; transform: translateY(-50%);
      background: #fff; color: #000; font-size: 13px; font-weight: bold;
      padding: 2px 6px; border-radius: 2px; z-index: 6; border: 1px solid #999;
    }
    [data-skin="tactical"] .date-window { background: #00ff41; color: #000; border: none; }

    /* Hands */
    .hand { position: absolute; bottom: 50%; left: 50%; transform-origin: bottom center; z-index: 10; }
    .hand-hour { width: 9px; height: 22%; margin-left: -4.5px; background: var(--hand-color); border-radius: 4px; }
    .hand-minute { width: 7px; height: 32%; margin-left: -3.5px; background: var(--hand-color); border-radius: 3px; }
    .hand-second { width: 2px; height: 40%; margin-left: -1px; background: #ff2828; z-index: 12; }
    [data-skin="golden"] .hand-second { background: #d4af37; }
    [data-skin="rosegold"] .hand-second { background: #b36b5a; }

    .clock-center {
      position: absolute; width: 16px; height: 16px; top: 50%; left: 50%; transform: translate(-50%, -50%);
      border-radius: 50%; background: radial-gradient(circle at 30% 30%, #eee, #333);
      z-index: 15; box-shadow: 0 2px 5px rgba(0,0,0,0.5);
    }

    .crystal-glass {
      position: absolute; inset: 0; border-radius: 50%; z-index: 20; pointer-events: none;
      background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 50%, rgba(255,255,255,0.05) 100%);
    }

    .digital-time {
      text-align: center; margin-top: 15px; font-size: 18px; font-family: monospace;
      color: var(--accent-color); letter-spacing: 2px;
    }

    /* Calendar */
    .calendar-card {
      background: var(--calendar-card-bg); border: 1px solid rgba(255,255,255,0.1);
      border-radius: 16px; padding: clamp(16px, 2.5vw, 30px); max-width: 600px; width: 100%;
      backdrop-filter: blur(10px); transition: all 0.5s ease;
    }
    .calendar-header h1 { font-size: 28px; text-transform: capitalize; color: #fff; }
    .calendar-nav { display: flex; justify-content: center; gap: 10px; margin: 15px 0; }
    .calendar-nav button {
      background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
      color: #fff; border-radius: 8px; padding: 6px 15px; cursor: pointer;
    }
    .calendar-nav button:hover { background: var(--accent-color); color: #000; }
    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
    .day-header { text-align: center; font-size: 12px; font-weight: bold; color: var(--accent-color); padding: 8px 0; }
    .day-cell {
      aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
      border-radius: 8px; font-size: 16px; color: #ccc;
    }
    .day-cell.today { background: var(--accent-color); color: #000; font-weight: bold; }
    .day-cell.sunday { color: #ff5555; }

    @media (max-width: 900px) {
      .container { flex-direction: column; }
      .analog-clock { width: 320px; height: 320px; }
    }
  </style>
</head>
<body data-skin="default">
  <div class="top-controls">
    <button class="ctrl-btn" id="fullBtn">⛶</button>
    <button class="ctrl-btn" id="menuBtn">Configuración</button>
  </div>

  <aside id="optionsPanel">
    <div class="option-group">
      <h3>Colección Luxury</h3>
      <div class="skin-grid">
        <div class="skin-opt active" data-skin-val="default">Acero Clásico</div>
        <div class="skin-opt" data-skin-val="golden">Oro Esqueleto</div>
        <div class="skin-opt" data-skin-val="midnight">Midnight Blue</div>
        <div class="skin-opt" data-skin-val="rosegold">Rose Gold</div>
        <div class="skin-opt" data-skin-val="emerald">Emerald Carbon</div>
        <div class="skin-opt" data-skin-val="tactical">Táctico Neón</div>
      </div>
    </div>
    <div class="option-group">
      <h3>Complicaciones</h3>
      <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px;">
        <input type="checkbox" id="toggleComps" checked style="width: 18px; height: 18px; accent-color: var(--accent-color);">
        Mostrar Tri-Compás
      </label>
    </div>
    <div class="option-group">
      <button class="ctrl-btn" id="closeMenuBtn" style="width:100%">Cerrar</button>
    </div>
  </aside>

  <div class="container">
    <div class="clock-wrap">
      <div class="analog-clock" id="analogClock">
        <div class="clock-dial">
          <div class="sunburst"></div>
          
          <!-- Complicaciones -->
          <div class="complication comp-moon" title="Fase Lunar">
            <div class="moon-disk">
              <div class="moon-actual"></div>
              <div class="moon-shadow" id="moonShadow"></div>
            </div>
          </div>
          <div class="complication comp-day" title="Día de la semana">
            <div id="dayLabel">LUN</div>
            <div class="hand-sub" id="dayHand"></div>
          </div>
          <div class="complication comp-seconds" title="Segundero pequeño">
            <div class="hand-sec-sub" id="smallSecHand"></div>
          </div>

          <div class="skeleton-elements">
            <div class="gear gear-1"></div>
            <div class="gear gear-2"></div>
            <div class="tourbillon"></div>
          </div>
          
          <div class="num-wrap" id="romanNumbers"></div>
          
          <div class="clock-brand">
            <div class="brand-name"><?= htmlspecialchars(config_loader_system_name()) ?></div>
            <div class="brand-sub">Luxury Complication</div>
          </div>
          <div class="date-window" id="dateWindow">1</div>
        </div>
        
        <div class="hand hand-hour" id="hourHand"></div>
        <div class="hand hand-minute" id="minuteHand"></div>
        <!-- Segundero central (opcional segun estilo) -->
        <div class="hand hand-second" id="secondHand"></div>
        
        <div class="clock-center"></div>
        <div class="crystal-glass"></div>
      </div>
      <div class="digital-time" id="digitalTime">00:00:00</div>
    </div>

    <div class="calendar-card">
      <div class="calendar-header">
        <h1 id="monthYear">Abril 2026</h1>
        <div class="year" id="weekInfo">Semana 15</div>
        <div class="calendar-nav">
          <button id="prevMonth">Anterior</button>
          <button id="todayBtn">Hoy</button>
          <button id="nextMonth">Siguiente</button>
        </div>
      </div>
      <div class="calendar-grid" id="calendarGrid"></div>
    </div>
  </div>

  <script>
    const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    const DIAS_L = ['DOM','LUN','MAR','MIE','JUE','VIE','SAB'];
    const ROMANOS = ['XII','I','II','III','IV','V','VI','VII','VIII','IX','X','XI'];

    let viewDate = new Date();
    let viewMonth = viewDate.getMonth();
    let viewYear = viewDate.getFullYear();

    function buildClockFace() {
      const romanWrap = document.getElementById('romanNumbers');
      romanWrap.innerHTML = '';
      for (let i = 0; i < 12; i++) {
        const rNum = document.createElement('div');
        rNum.className = 'roman-num';
        rNum.textContent = ROMANOS[i];
        const angle = (i * 30) * (Math.PI / 180);
        const radius = 135; 
        const x = Math.sin(angle) * radius;
        const y = -Math.cos(angle) * radius;
        rNum.style.transform = `translate(calc(-50% + ${x}px), calc(-50% + ${y}px)) rotate(${i*30}deg)`;
        romanWrap.appendChild(rNum);
      }
    }

    function getMoonPhase(date) {
      let year = date.getFullYear(), month = date.getMonth() + 1, day = date.getDate();
      if (month < 3) { year--; month += 12; }
      let c = 365.25 * year, e = 30.6 * month, jd = c + e + day - 694039.09;
      jd /= 29.5305882;
      let b = parseInt(jd);
      jd -= b;
      return jd; // 0 a 1
    }

    function updateClock() {
      const now = new Date();
      const h = now.getHours() % 12, m = now.getMinutes(), s = now.getSeconds(), ms = now.getMilliseconds();

      document.getElementById('hourHand').style.transform = `rotate(${(h + m/60) * 30}deg)`;
      document.getElementById('minuteHand').style.transform = `rotate(${(m + s/60) * 6}deg)`;
      document.getElementById('secondHand').style.transform = `rotate(${(s + ms/1000) * 6}deg)`;
      document.getElementById('smallSecHand').style.transform = `rotate(${(s + ms/1000) * 6}deg)`;
      
      document.getElementById('dateWindow').textContent = now.getDate();
      document.getElementById('digitalTime').textContent = now.toTimeString().split(' ')[0];
      
      // Complicacion Dia de la Semana
      const day = now.getDay();
      document.getElementById('dayLabel').textContent = DIAS_L[day];
      document.getElementById('dayHand').style.transform = `rotate(${day * (360/7)}deg)`;

      // Fase Lunar
      const phase = getMoonPhase(now);
      const moonShadow = document.getElementById('moonShadow');
      // Desplazamiento visual simplificado de la sombra lunar
      const move = (phase < 0.5) ? (phase * 200) : (200 - (phase * 200));
      moonShadow.style.transform = `translateX(${move - 10}%)`;

      requestAnimationFrame(updateClock);
    }

    function renderCalendar(month, year) {
      const grid = document.getElementById('calendarGrid');
      const today = new Date();
      document.getElementById('monthYear').textContent = `${MESES[month]} ${year}`;
      const firstDay = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();
      let html = '';
      DIAS_L.forEach(d => html += `<div class="day-header">${d}</div>`);
      for (let i = 0; i < firstDay; i++) html += '<div class="day-cell empty"></div>';
      for (let d = 1; d <= daysInMonth; d++) {
        const isToday = d === today.getDate() && month === today.getMonth() && year === today.getFullYear();
        html += `<div class="day-cell ${isToday?'today':''}">${d}</div>`;
      }
      grid.innerHTML = html;
    }

    // Interaction
    const menuBtn = document.getElementById('menuBtn'), 
          closeBtn = document.getElementById('closeMenuBtn'), 
          panel = document.getElementById('optionsPanel'), 
          skins = document.querySelectorAll('.skin-opt'),
          toggleComps = document.getElementById('toggleComps');

    menuBtn.onclick = () => panel.classList.add('open');
    closeBtn.onclick = () => panel.classList.remove('open');

    toggleComps.onchange = () => {
      const state = toggleComps.checked ? 'on' : 'off';
      document.body.setAttribute('data-comps', state);
      localStorage.setItem('almanaque_comps', state);
    };
    skins.forEach(opt => {
      opt.onclick = () => {
        const skin = opt.dataset.skinVal;
        document.body.setAttribute('data-skin', skin);
        skins.forEach(s => s.classList.remove('active'));
        opt.classList.add('active');
        localStorage.setItem('almanaque_skin_v2', skin);
      };
    });
    document.getElementById('fullBtn').onclick = () => { if (!document.fullscreenElement) document.documentElement.requestFullscreen(); else document.exitFullscreen(); };

    // Init
    const savedSkin = localStorage.getItem('almanaque_skin_v2') || 'default';
    document.body.setAttribute('data-skin', savedSkin);
    const activeOpt = document.querySelector(`[data-skin-val="${savedSkin}"]`);
    if(activeOpt) activeOpt.classList.add('active');

    const savedComps = localStorage.getItem('almanaque_comps') || 'on';
    document.body.setAttribute('data-comps', savedComps);
    if(toggleComps) toggleComps.checked = (savedComps === 'on');

    buildClockFace();
    updateClock();
    renderCalendar(viewMonth, viewYear);

    // Nav
    document.getElementById('prevMonth').onclick = () => { viewMonth--; if(viewMonth<0){viewMonth=11; viewYear--;} renderCalendar(viewMonth, viewYear); };
    document.getElementById('nextMonth').onclick = () => { viewMonth++; if(viewMonth>11){viewMonth=0; viewYear++;} renderCalendar(viewMonth, viewYear); };
    document.getElementById('todayBtn').onclick = () => { const n=new Date(); viewMonth=n.getMonth(); viewYear=n.getFullYear(); renderCalendar(viewMonth, viewYear); };
  </script>
</body>
</html>
