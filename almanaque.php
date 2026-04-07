<?php
date_default_timezone_set('America/Havana');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Almanaque - <?= date('F Y', strtotime('now')) ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100dvh;
      background: linear-gradient(135deg, #0f1923 0%, #1a2a3a 50%, #0f1923 100%);
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      color: #e0e8f0;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .container {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: clamp(20px, 4vw, 50px);
      padding: clamp(12px, 2vw, 24px);
      max-width: 1100px;
      width: 100%;
    }

    /* --- Reloj analogico --- */
    .clock-wrap {
      position: relative;
      flex-shrink: 0;
    }

    .analog-clock {
      width: clamp(240px, 30vw, 340px);
      height: clamp(240px, 30vw, 340px);
      border-radius: 50%;
      position: relative;
      /* Bisel exterior metalico pulido */
      background:
        radial-gradient(circle at 50% 50%, #1a1a1e 0%, #1a1a1e 61%, transparent 61.5%),
        conic-gradient(from 20deg,
          #b8b8bc, #8a8a8e, #d0d0d4, #7a7a7e,
          #c8c8cc, #6a6a6e, #b0b0b4, #8a8a8e,
          #d8d8dc, #7a7a7e, #a0a0a4, #8a8a8e,
          #b8b8bc
        );
      box-shadow:
        0 0 0 1px #2a2a2e,
        0 0 0 3px #4a4a4e,
        0 0 0 4px #2a2a2e,
        0 6px 16px rgba(0,0,0,0.6),
        0 16px 48px rgba(0,0,0,0.5),
        inset 0 2px 6px rgba(255,255,255,0.2),
        inset 0 -2px 4px rgba(0,0,0,0.3);
    }

    /* Esfera con patron sunburst */
    .clock-dial {
      position: absolute;
      inset: 6.5%;
      border-radius: 50%;
      background:
        radial-gradient(circle at 42% 38%,
          #243040 0%,
          #1a2636 25%,
          #122030 50%,
          #0c1828 75%,
          #081020 100%
        );
      box-shadow:
        inset 0 3px 10px rgba(0,0,0,0.8),
        inset 0 -1px 4px rgba(255,255,255,0.04);
      overflow: hidden;
    }

    /* Textura sunburst sutil (rayos solares) */
    .clock-dial::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: 50%;
      background: repeating-conic-gradient(
        from 0deg at 50% 50%,
        rgba(255,255,255,0.012) 0deg,
        transparent 0.3deg,
        rgba(0,0,0,0.012) 0.6deg,
        transparent 0.9deg
      );
      pointer-events: none;
    }

    /* Anillo interior (chapter ring) */
    .chapter-ring {
      position: absolute;
      inset: 2.5%;
      border-radius: 50%;
      border: 1px solid rgba(100, 140, 180, 0.1);
      box-shadow: inset 0 0 8px rgba(0,0,0,0.3);
      pointer-events: none;
    }

    /* Ventana de fecha */
    .date-window {
      position: absolute;
      right: 14%;
      top: 50%;
      transform: translateY(-50%);
      background: linear-gradient(180deg, #f8f8f8, #e8e8e8);
      color: #1a1a2e;
      font-size: clamp(10px, 1.2vw, 13px);
      font-weight: 700;
      font-family: 'Arial', sans-serif;
      padding: 3px 6px;
      border-radius: 3px;
      box-shadow:
        inset 0 1px 3px rgba(0,0,0,0.25),
        inset 0 -1px 1px rgba(255,255,255,0.5),
        0 1px 2px rgba(0,0,0,0.4);
      z-index: 6;
      line-height: 1.2;
      min-width: 22px;
      text-align: center;
      border: 1px solid #ccc;
    }

    /* Lupa ciclope sobre la fecha */
    .date-window::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: 3px;
      background: radial-gradient(ellipse at 40% 30%,
        rgba(255,255,255,0.3) 0%,
        transparent 60%
      );
      pointer-events: none;
    }

    /* Marca del reloj */
    .clock-brand {
      position: absolute;
      top: 24%;
      left: 50%;
      transform: translateX(-50%);
      text-align: center;
      z-index: 2;
      pointer-events: none;
    }

    .clock-brand .brand-name {
      font-size: clamp(8px, 1vw, 11px);
      font-weight: 700;
      letter-spacing: 3.5px;
      text-transform: uppercase;
      color: rgba(180, 210, 240, 0.75);
      font-family: 'Georgia', 'Times New Roman', serif;
      text-shadow: 0 1px 2px rgba(0,0,0,0.5);
    }

    .clock-brand .brand-sub {
      font-size: clamp(5px, 0.65vw, 7px);
      letter-spacing: 2px;
      text-transform: uppercase;
      color: rgba(140, 175, 210, 0.45);
      margin-top: 2px;
    }

    /* Marcadores de hora aplicados (indices) */
    .hour-marker {
      position: absolute;
      top: 50%;
      left: 50%;
      margin-left: -2px;
      transform-origin: 50% 50%;
      z-index: 1;
    }

    .hour-marker .marker-bar {
      width: clamp(3px, 0.4vw, 4px);
      height: clamp(14px, 1.8vw, 20px);
      margin-left: calc(clamp(3px, 0.4vw, 4px) / -2);
      background: linear-gradient(180deg,
        #e8eef4 0%,
        #b8c8d8 30%,
        #a0b0c0 70%,
        #b8c8d8 100%
      );
      border-radius: 1px;
      box-shadow:
        0 1px 3px rgba(0,0,0,0.5),
        inset 0 1px 0 rgba(255,255,255,0.4),
        inset 0 -1px 0 rgba(0,0,0,0.1);
    }

    .hour-marker.major .marker-bar {
      width: clamp(5px, 0.6vw, 6px);
      height: clamp(18px, 2.2vw, 24px);
      margin-left: calc(clamp(5px, 0.6vw, 6px) / -2);
      background: linear-gradient(180deg,
        #f0f4f8 0%,
        #c8d8e8 30%,
        #b0c0d0 70%,
        #c8d8e8 100%
      );
      border-radius: 1px;
      box-shadow:
        0 2px 4px rgba(0,0,0,0.6),
        inset 0 1px 0 rgba(255,255,255,0.5),
        inset 0 -1px 0 rgba(0,0,0,0.15);
    }

    /* Punto de lume en marcadores */
    .hour-marker .lume-dot {
      position: absolute;
      bottom: -7px;
      left: 50%;
      transform: translateX(-50%);
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: radial-gradient(circle at 35% 35%,
        #d8f0d0,
        #a0c898 60%,
        #80a878 100%
      );
      box-shadow:
        0 0 4px rgba(160, 200, 150, 0.5),
        inset 0 1px 1px rgba(255,255,255,0.3);
    }

    .hour-marker.major .lume-dot {
      width: 6px;
      height: 6px;
      bottom: -8px;
    }

    /* Manecillas 3D */
    .hand {
      position: absolute;
      bottom: 50%;
      left: 50%;
      transform-origin: bottom center;
      z-index: 8;
    }

    /* Manecilla de hora - estilo espada con 3D */
    .hand-hour {
      width: clamp(7px, 0.85vw, 9px);
      height: 22%;
      margin-left: calc(clamp(7px, 0.85vw, 9px) / -2);
      background: linear-gradient(90deg,
        #7a8a9a 0%,
        #a8b8c8 15%,
        #d0dce8 35%,
        #e8f0f8 50%,
        #d0dce8 65%,
        #a8b8c8 85%,
        #7a8a9a 100%
      );
      border-radius: 2px 2px 1px 1px;
      box-shadow:
        3px 3px 6px rgba(0,0,0,0.5),
        -1px -1px 2px rgba(255,255,255,0.06),
        inset 0 0 2px rgba(0,0,0,0.15);
      z-index: 8;
    }

    /* Punta de la manecilla de hora */
    .hand-hour::before {
      content: '';
      position: absolute;
      top: -10px;
      left: 50%;
      transform: translateX(-50%);
      width: 0;
      height: 0;
      border-left: calc(clamp(7px, 0.85vw, 9px) / 2 + 1px) solid transparent;
      border-right: calc(clamp(7px, 0.85vw, 9px) / 2 + 1px) solid transparent;
      border-bottom: 12px solid #c8d8e8;
      filter: drop-shadow(2px 2px 3px rgba(0,0,0,0.5));
    }

    /* Manecilla de minutos - estilo espada con 3D */
    .hand-minute {
      width: clamp(5px, 0.55vw, 6px);
      height: 32%;
      margin-left: calc(clamp(5px, 0.55vw, 6px) / -2);
      background: linear-gradient(90deg,
        #7a8a9a 0%,
        #a8b8c8 15%,
        #d0dce8 35%,
        #e8f0f8 50%,
        #d0dce8 65%,
        #a8b8c8 85%,
        #7a8a9a 100%
      );
      border-radius: 2px 2px 1px 1px;
      box-shadow:
        3px 3px 7px rgba(0,0,0,0.5),
        -1px -1px 2px rgba(255,255,255,0.06),
        inset 0 0 2px rgba(0,0,0,0.15);
      z-index: 9;
    }

    .hand-minute::before {
      content: '';
      position: absolute;
      top: -12px;
      left: 50%;
      transform: translateX(-50%);
      width: 0;
      height: 0;
      border-left: calc(clamp(5px, 0.55vw, 6px) / 2 + 1px) solid transparent;
      border-right: calc(clamp(5px, 0.55vw, 6px) / 2 + 1px) solid transparent;
      border-bottom: 14px solid #c8d8e8;
      filter: drop-shadow(2px 2px 3px rgba(0,0,0,0.5));
    }

    /* Contrapeso de las manecillas */
    .hand-hour::after,
    .hand-minute::after {
      content: '';
      position: absolute;
      bottom: -18%;
      left: 50%;
      transform: translateX(-50%);
      width: 120%;
      height: 18%;
      background: linear-gradient(90deg, #6a7a8a, #a0b0c0, #6a7a8a);
      border-radius: 1px;
      box-shadow: 0 1px 2px rgba(0,0,0,0.3);
    }

    /* Manecilla de segundos */
    .hand-second {
      width: 1.5px;
      height: 38%;
      margin-left: -0.75px;
      background: linear-gradient(180deg, #ff2828, #cc0000 60%, #aa0000);
      z-index: 10;
      box-shadow: 0 0 5px rgba(255, 40, 40, 0.4);
    }

    /* Contrapeso del segundero */
    .hand-second::after {
      content: '';
      position: absolute;
      bottom: -22%;
      left: 50%;
      transform: translateX(-50%);
      width: 5px;
      height: 22%;
      background: linear-gradient(180deg, #cc0000, #990000);
      border-radius: 2px;
      box-shadow: 0 1px 2px rgba(0,0,0,0.3);
    }

    /* Centro del reloj (capuchon 3D) */
    .clock-center {
      position: absolute;
      width: clamp(12px, 1.4vw, 16px);
      height: clamp(12px, 1.4vw, 16px);
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      border-radius: 50%;
      background: radial-gradient(circle at 32% 32%,
        #f0f0f4,
        #b0b0b4 40%,
        #707074 70%,
        #505054 100%
      );
      box-shadow:
        0 2px 5px rgba(0,0,0,0.6),
        inset 0 1px 2px rgba(255,255,255,0.35),
        inset 0 -1px 2px rgba(0,0,0,0.2);
      z-index: 15;
    }

    /* Capuchon del segundero (mas pequeno, encima) */
    .second-cap {
      position: absolute;
      width: clamp(6px, 0.7vw, 8px);
      height: clamp(6px, 0.7vw, 8px);
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      border-radius: 50%;
      background: radial-gradient(circle at 32% 32%, #ff5050, #cc0000 60%, #990000);
      box-shadow:
        0 1px 3px rgba(0,0,0,0.5),
        inset 0 1px 1px rgba(255,255,255,0.25);
      z-index: 16;
    }

    /* Efecto de cristal curvo (zafiro) */
    .crystal-glass {
      position: absolute;
      inset: 0;
      border-radius: 50%;
      z-index: 20;
      pointer-events: none;
      background:
        radial-gradient(ellipse at 28% 18%,
          rgba(255,255,255,0.22) 0%,
          rgba(255,255,255,0.08) 20%,
          rgba(255,255,255,0.02) 40%,
          transparent 55%
        ),
        radial-gradient(ellipse at 72% 82%,
          rgba(255,255,255,0.05) 0%,
          transparent 35%
        ),
        radial-gradient(ellipse at 50% 50%,
          rgba(180, 200, 220, 0.03) 0%,
          transparent 70%
        );
      border: 1px solid rgba(255,255,255,0.08);
    }

    /* Reflejo principal del cristal (brillo superior curvo) */
    .crystal-glass::before {
      content: '';
      position: absolute;
      top: 3%;
      left: 10%;
      width: 58%;
      height: 32%;
      border-radius: 50%;
      background: linear-gradient(180deg,
        rgba(255,255,255,0.15) 0%,
        rgba(255,255,255,0.06) 35%,
        transparent 100%
      );
      transform: rotate(-12deg);
      filter: blur(1px);
    }

    /* Reflejo secundario (brillo inferior sutil) */
    .crystal-glass::after {
      content: '';
      position: absolute;
      bottom: 6%;
      right: 14%;
      width: 35%;
      height: 18%;
      border-radius: 50%;
      background: linear-gradient(180deg,
        transparent 0%,
        rgba(255,255,255,0.03) 50%,
        rgba(255,255,255,0.06) 100%
      );
      transform: rotate(8deg);
    }

    .digital-time {
      text-align: center;
      margin-top: 14px;
      font-size: clamp(13px, 1.6vw, 17px);
      font-family: 'Consolas', 'Courier New', monospace;
      color: #4a9eff;
      letter-spacing: 2px;
      text-shadow: 0 0 8px rgba(74, 158, 255, 0.4);
    }

    /* --- Almanaque --- */
    .calendar-card {
      background: rgba(18, 30, 44, 0.85);
      border: 1px solid #2a4a6a;
      border-radius: 16px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
      padding: clamp(16px, 2.5vw, 30px);
      min-width: 0;
      max-width: 600px;
      width: 100%;
      backdrop-filter: blur(8px);
    }

    .calendar-header {
      text-align: center;
      margin-bottom: 16px;
    }

    .calendar-header h1 {
      font-size: clamp(20px, 3vw, 30px);
      font-weight: 700;
      color: #e0e8f0;
      text-transform: capitalize;
      letter-spacing: 1px;
    }

    .calendar-header .year {
      font-size: clamp(14px, 1.8vw, 18px);
      color: #5a8aaa;
      margin-top: 4px;
    }

    .calendar-nav {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: 10px;
    }

    .calendar-nav button {
      background: rgba(42, 74, 106, 0.5);
      border: 1px solid #2a4a6a;
      color: #8ab4d8;
      border-radius: 8px;
      padding: 4px 14px;
      cursor: pointer;
      font-size: 14px;
      transition: all 0.2s;
    }

    .calendar-nav button:hover {
      background: #2a4a6a;
      color: #c0d8f0;
    }

    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 4px;
    }

    .day-header {
      text-align: center;
      font-size: clamp(10px, 1.2vw, 13px);
      font-weight: 600;
      color: #5a8aaa;
      padding: 6px 0;
      text-transform: uppercase;
    }

    .day-cell {
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      font-size: clamp(12px, 1.4vw, 16px);
      font-weight: 500;
      color: #b0c8e0;
      transition: all 0.2s;
      cursor: default;
    }

    .day-cell.empty {
      color: transparent;
    }

    .day-cell.today {
      background: linear-gradient(135deg, #4a9eff, #2a6acc);
      color: #fff;
      font-weight: 700;
      box-shadow: 0 0 12px rgba(74, 158, 255, 0.5);
    }

    .day-cell.sunday {
      color: #ff6b6b;
    }

    .day-cell.saturday {
      color: #ffd93d;
    }

    .day-cell:not(.empty):not(.today):hover {
      background: rgba(42, 74, 106, 0.4);
    }

    .calendar-footer {
      margin-top: 14px;
      text-align: center;
      font-size: clamp(11px, 1.2vw, 14px);
      color: #5a8aaa;
    }

    /* --- Responsive --- */
    @media (max-width: 700px) {
      .container {
        flex-direction: column;
        gap: 20px;
      }

      .analog-clock {
        width: clamp(180px, 45vw, 240px);
        height: clamp(180px, 45vw, 240px);
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- Reloj analogico -->
    <div class="clock-wrap">
      <div class="analog-clock" id="analogClock">
        <div class="clock-dial">
          <div class="chapter-ring"></div>
          <div class="clock-brand">
            <div class="brand-name">PalWeb</div>
            <div class="brand-sub">Automatic</div>
          </div>
          <div class="date-window" id="dateWindow">1</div>
        </div>
        <div class="hand hand-hour" id="hourHand"></div>
        <div class="hand hand-minute" id="minuteHand"></div>
        <div class="hand hand-second" id="secondHand"></div>
        <div class="clock-center"></div>
        <div class="second-cap"></div>
        <div class="crystal-glass"></div>
      </div>
      <div class="digital-time" id="digitalTime">--:--:--</div>
    </div>

    <!-- Almanaque -->
    <div class="calendar-card">
      <div class="calendar-header">
        <h1 id="monthYear"></h1>
        <div class="year" id="weekInfo"></div>
        <div class="calendar-nav">
          <button id="prevMonth">&#9664; Anterior</button>
          <button id="todayBtn">Hoy</button>
          <button id="nextMonth">Siguiente &#9654;</button>
        </div>
      </div>
      <div class="calendar-grid" id="calendarGrid"></div>
      <div class="calendar-footer" id="calendarFooter"></div>
    </div>
  </div>

  <script>
    const MESES = [
      'Enero','Febrero','Marzo','Abril','Mayo','Junio',
      'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'
    ];
    const DIAS = ['Dom','Lun','Mar','Mie','Jue','Vie','Sab'];

    let viewDate = new Date();
    let viewMonth = viewDate.getMonth();
    let viewYear = viewDate.getFullYear();

    // --- Construir marcadores del reloj ---
    function buildClockFace() {
      const dial = document.querySelector('.clock-dial');
      if (!dial.offsetWidth) {
        setTimeout(buildClockFace, 50);
        return;
      }
      const dialSize = dial.offsetWidth;
      const radius = dialSize * 0.42;
      const minRadius = dialSize * 0.40;

      // Marcadores de hora (12 posiciones con barras)
      for (let i = 0; i < 12; i++) {
        const marker = document.createElement('div');
        const isMajor = i % 3 === 0;
        marker.className = 'hour-marker' + (isMajor ? ' major' : '');

        const bar = document.createElement('div');
        bar.className = 'marker-bar';
        marker.appendChild(bar);

        const lume = document.createElement('div');
        lume.className = 'lume-dot';
        marker.appendChild(lume);

        marker.style.transform = `rotate(${i * 30}deg) translateY(-${radius}px)`;

        dial.appendChild(marker);
      }

      // Minuto ticks (60 posiciones)
      for (let i = 0; i < 60; i++) {
        if (i % 5 === 0) continue;
        const dot = document.createElement('div');
        dot.style.cssText = `
          position: absolute;
          width: 2px;
          height: 2px;
          background: rgba(140, 180, 220, 0.25);
          border-radius: 50%;
          top: 50%;
          left: 50%;
          transform: rotate(${i * 6}deg) translateY(-${minRadius}px);
        `;
        dial.appendChild(dot);
      }
    }
    buildClockFace();

    // --- Actualizar reloj ---
    function updateClock() {
      const now = new Date();
      const h = now.getHours() % 12;
      const m = now.getMinutes();
      const s = now.getSeconds();
      const ms = now.getMilliseconds();

      const secDeg = (s + ms / 1000) * 6;
      const minDeg = (m + s / 60) * 6;
      const hourDeg = (h + m / 60) * 30;

      document.getElementById('secondHand').style.transform = `rotate(${secDeg}deg)`;
      document.getElementById('minuteHand').style.transform = `rotate(${minDeg}deg)`;
      document.getElementById('hourHand').style.transform = `rotate(${hourDeg}deg)`;

      // Fecha en ventana
      document.getElementById('dateWindow').textContent = now.getDate();

      const hh = String(now.getHours()).padStart(2, '0');
      const mm = String(m).padStart(2, '0');
      const ss = String(s).padStart(2, '0');
      document.getElementById('digitalTime').textContent = `${hh}:${mm}:${ss}`;

      requestAnimationFrame(updateClock);
    }

    // --- Renderizar almanaque ---
    function renderCalendar(month, year) {
      const grid = document.getElementById('calendarGrid');
      const today = new Date();
      const todayDate = today.getDate();
      const todayMonth = today.getMonth();
      const todayYear = today.getFullYear();

      document.getElementById('monthYear').textContent = `${MESES[month]} ${year}`;

      const firstDay = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();

      // Info de semana
      const weekNum = getWeekNumber(today);
      document.getElementById('weekInfo').textContent = `Semana ${weekNum} del ano`;

      let html = '';
      DIAS.forEach(d => {
        html += `<div class="day-header">${d}</div>`;
      });

      for (let i = 0; i < firstDay; i++) {
        html += `<div class="day-cell empty">.</div>`;
      }

      for (let d = 1; d <= daysInMonth; d++) {
        const dayOfWeek = new Date(year, month, d).getDay();
        let cls = 'day-cell';
        if (d === todayDate && month === todayMonth && year === todayYear) {
          cls += ' today';
        } else if (dayOfWeek === 0) {
          cls += ' sunday';
        } else if (dayOfWeek === 6) {
          cls += ' saturday';
        }
        html += `<div class="${cls}">${d}</div>`;
      }

      const totalCells = firstDay + daysInMonth;
      const remaining = (7 - (totalCells % 7)) % 7;
      for (let i = 0; i < remaining; i++) {
        html += `<div class="day-cell empty">.</div>`;
      }

      grid.innerHTML = html;

      // Footer
      const daysLeft = daysInMonth - todayDate;
      if (month === todayMonth && year === todayYear) {
        document.getElementById('calendarFooter').textContent =
          `Hoy es ${todayDate} de ${MESES[month]}. Faltan ${daysLeft} dias para que termine el mes.`;
      } else {
        document.getElementById('calendarFooter').textContent =
          `${daysInMonth} dias en este mes`;
      }
    }

    function getWeekNumber(d) {
      const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
      date.setUTCDate(date.getUTCDate() + 4 - (date.getUTCDay() || 7));
      const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
      return Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
    }

    // --- Navegacion ---
    document.getElementById('prevMonth').addEventListener('click', () => {
      viewMonth--;
      if (viewMonth < 0) { viewMonth = 11; viewYear--; }
      renderCalendar(viewMonth, viewYear);
    });

    document.getElementById('nextMonth').addEventListener('click', () => {
      viewMonth++;
      if (viewMonth > 11) { viewMonth = 0; viewYear++; }
      renderCalendar(viewMonth, viewYear);
    });

    document.getElementById('todayBtn').addEventListener('click', () => {
      const now = new Date();
      viewMonth = now.getMonth();
      viewYear = now.getFullYear();
      renderCalendar(viewMonth, viewYear);
    });

    // --- Init ---
    renderCalendar(viewMonth, viewYear);
    updateClock();
  </script>
</body>
</html>
