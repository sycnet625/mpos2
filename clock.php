<?php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clock LCD</title>
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&family=Orbitron:wght@500;700&family=Audiowide&display=swap");

    @font-face {
      font-family: "DSEGDisplay";
      src:
        url("assets/fonts/dseg7-classic-400.woff2") format("woff2"),
        url("assets/fonts/dseg14-classic-400.woff2") format("woff2"),
        local("DSEG7 Classic"),
        local("DSEG7Classic-Regular"),
        local("DSEG14 Classic"),
        local("DSEG14Classic-Regular");
      font-display: swap;
    }

    :root {
      --bg: #000000;
      --clock-bg: #0a100a;
      --clock-border: #143314;
      --clock-text: #00e676;
      --clock-glow: rgba(0, 230, 118, 0.50);
      --date-text: #87d4a5;
      --weather-text: #b7f3ce;
      --font-main: "Courier New", "Lucida Console", monospace;
      --time-size: clamp(4rem, 16vw, 13rem);
      --meta-size: clamp(0.95rem, 2.2vw, 1.7rem);
      --letter-spacing: 0.14em;
      --panel-bg: rgba(0, 0, 0, 0.90);
      --panel-border: #174117;
      --time-shadow: 0 0 8px var(--clock-text), 0 0 18px var(--clock-glow);
      --rainbow-gradient: linear-gradient(270deg, #ff004c, #ff7a00, #ffe600, #00d26a, #00b7ff, #6c5cff, #ff00c8);
      --clock-offset-x: 0px;
      --clock-offset-y: 0px;
      --segment-shadow: drop-shadow(0 0 8px var(--clock-glow));
      --shadow-color: rgba(0, 230, 118, 0.55);
      --shadow-blur: 8px;
      --seg-h-left: 14%;
      --seg-h-width: 72%;
      --seg-h-thickness: 10%;
      --seg-v-width: 10%;
      --seg-v-height: 36%;
      --seg-side-offset: 4.5%;
      --seg-v-top: 6%;
      --seg-v-bottom: 6%;
      --seg-radius: 999px;
    }

    * { box-sizing: border-box; }
    html, body { width: 100%; height: 100%; margin: 0; }
    body {
      background: var(--bg);
      color: var(--clock-text);
      font-family: var(--font-main);
      overflow: hidden;
      transition: filter 0.25s ease;
    }
    body.night-dim { filter: brightness(0.38) saturate(0.85); }

    .wrap {
      min-height: 100dvh;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(12px, 2vw, 24px);
      position: relative;
    }

    .clock-card {
      width: min(1450px, 100%);
      text-align: center;
      background: var(--clock-bg);
      border: 1px solid var(--clock-border);
      border-radius: clamp(10px, 1.8vw, 18px);
      box-shadow: inset 0 2px 7px rgba(0, 0, 0, 0.7), 0 12px 30px rgba(0, 0, 0, 0.45);
      padding: clamp(16px, 3vw, 36px);
      user-select: none;
      position: relative;
    }

    #clockLine {
      font-size: var(--time-size);
      line-height: 0.95;
      font-weight: 700;
      letter-spacing: var(--letter-spacing);
      color: var(--clock-text);
      font-family: var(--font-main);
      text-shadow: var(--time-shadow);
      white-space: nowrap;
      display: flex;
      justify-content: center;
      align-items: baseline;
      gap: 0.04em;
      flex-wrap: nowrap;
      transform: translate(var(--clock-offset-x), var(--clock-offset-y));
      transition: transform 1.2s ease, filter 0.6s ease;
    }
    #clockLine.blink-colon .sep { opacity: 0.2; }
    #clockLine.colorful {
      filter: saturate(1.2) brightness(1.06);
    }
    #clockLine.colorful .time-text {
      background-image: var(--rainbow-gradient);
      background-size: 140vw 100%;
      background-attachment: fixed;
      color: transparent;
      -webkit-background-clip: text;
      background-clip: text;
      text-shadow: none;
      animation: rainbow-flow-rtl 7s linear infinite;
    }
    #clockLine.colorful .ampm {
      background-image: var(--rainbow-gradient);
      color: transparent;
      -webkit-background-clip: text;
      background-clip: text;
      background-size: 140vw 100%;
      background-attachment: fixed;
      text-shadow: none;
      animation: rainbow-flow-rtl 7s linear infinite;
    }

    @keyframes rainbow-flow-rtl {
      0% { background-position: 100vw 50%; }
      100% { background-position: 0vw 50%; }
    }

    .time-segment {
      display: inline-flex;
      align-items: center;
      gap: clamp(6px, 0.6vw, 10px);
    }

    .digit-7 {
      position: relative;
      width: 0.66em;
      height: 1em;
      display: inline-block;
      filter: var(--segment-shadow);
    }

    .digit-7 .seg {
      position: absolute;
      background: color-mix(in srgb, var(--clock-text) 10%, transparent);
      border-radius: var(--seg-radius);
      transition: background 0.35s ease, opacity 0.35s ease, box-shadow 0.35s ease;
      opacity: 0.12;
    }

    .digit-7 .seg.on {
      background: var(--clock-text);
      opacity: 1;
      box-shadow: 0 0 10px var(--clock-text), 0 0 24px var(--clock-glow);
    }

    #clockLine.colorful .digit-7 .seg.on,
    #clockLine.colorful .colon-7 span {
      background-image: var(--rainbow-gradient);
      background-size: 140vw 100%;
      background-attachment: fixed;
      box-shadow: 0 0 8px rgba(255, 255, 255, 0.28), 0 0 18px rgba(255, 0, 180, 0.24);
      animation: rainbow-flow-rtl 7s linear infinite;
    }

    .digit-7 .a, .digit-7 .d, .digit-7 .g {
      left: var(--seg-h-left);
      width: var(--seg-h-width);
      height: var(--seg-h-thickness);
    }
    .digit-7 .a { top: 1.5%; }
    .digit-7 .g { top: 45%; }
    .digit-7 .d { bottom: 1.5%; }
    .digit-7 .b, .digit-7 .c, .digit-7 .e, .digit-7 .f {
      width: var(--seg-v-width);
      height: var(--seg-v-height);
    }
    .digit-7 .b { right: var(--seg-side-offset); top: var(--seg-v-top); }
    .digit-7 .c { right: var(--seg-side-offset); bottom: var(--seg-v-bottom); }
    .digit-7 .e { left: var(--seg-side-offset); bottom: var(--seg-v-bottom); }
    .digit-7 .f { left: var(--seg-side-offset); top: var(--seg-v-top); }

    #clockLine[data-segment-style="segments-thin"] {
      --seg-h-left: 18%;
      --seg-h-width: 64%;
      --seg-h-thickness: 7%;
      --seg-v-width: 7%;
      --seg-v-height: 33%;
      --seg-side-offset: 7%;
      --seg-v-top: 8%;
      --seg-v-bottom: 8%;
    }

    #clockLine[data-segment-style="segments-fat"] {
      --seg-h-left: 11%;
      --seg-h-width: 78%;
      --seg-h-thickness: 12%;
      --seg-v-width: 12%;
      --seg-v-height: 38%;
      --seg-side-offset: 2.5%;
      --seg-v-top: 5%;
      --seg-v-bottom: 5%;
    }

    #clockLine[data-segment-style="segments-block"] {
      --seg-h-left: 10%;
      --seg-h-width: 80%;
      --seg-h-thickness: 12%;
      --seg-v-width: 12%;
      --seg-v-height: 38%;
      --seg-side-offset: 2.5%;
      --seg-v-top: 5%;
      --seg-v-bottom: 5%;
      --seg-radius: 2px;
    }

    #clockLine[data-segment-style="segments-rounded"] {
      --seg-h-left: 13%;
      --seg-h-width: 74%;
      --seg-h-thickness: 10%;
      --seg-v-width: 10%;
      --seg-v-height: 36%;
      --seg-side-offset: 4%;
      --seg-v-top: 6%;
      --seg-v-bottom: 6%;
      --seg-radius: 999px;
    }

    #clockLine[data-segment-style="segments-font"] {
      --seg-h-left: 15%;
      --seg-h-width: 70%;
      --seg-h-thickness: 9%;
      --seg-v-width: 9%;
      --seg-v-height: 35%;
      --seg-side-offset: 5.5%;
      --seg-v-top: 6.5%;
      --seg-v-bottom: 6.5%;
      --seg-radius: 3px;
      letter-spacing: 0.05em;
    }

    .colon-7 {
      display: inline-flex;
      flex-direction: column;
      justify-content: center;
      gap: 0.18em;
      margin: 0 0.03em;
    }
    .colon-7 span {
      width: 0.09em;
      height: 0.09em;
      border-radius: 50%;
      background: var(--clock-text);
      box-shadow: 0 0 6px var(--clock-text), 0 0 14px var(--clock-glow);
    }
    .ampm {
      font-size: 0.30em;
      letter-spacing: 0.02em;
      margin-left: 0.18em;
      opacity: 0.9;
    }

    #dateLine {
      margin-top: clamp(8px, 1.5vw, 14px);
      font-size: var(--meta-size);
      color: var(--date-text);
      letter-spacing: 0.04em;
      text-shadow: 0 0 5px rgba(135, 212, 165, 0.35);
    }

    #weatherLine {
      margin-top: clamp(5px, 1vw, 10px);
      font-size: calc(var(--meta-size) * 0.92);
      color: var(--weather-text);
      letter-spacing: 0.03em;
      text-shadow: 0 0 5px rgba(183, 243, 206, 0.25);
      min-height: 1.2em;
    }

    #alarmLine {
      margin-top: clamp(6px, 1vw, 12px);
      min-height: 1.4em;
      font-size: calc(var(--meta-size) * 0.72);
      color: #ffd36a;
      letter-spacing: 0.02em;
      opacity: 0.95;
    }

    #customizeBtn {
      position: fixed;
      top: 12px;
      right: 12px;
      z-index: 30;
      border: 1px solid #2f7b2f;
      background: #0f2a0f;
      color: #9bf3b8;
      border-radius: 9px;
      padding: 8px 12px;
      cursor: pointer;
      font-family: inherit;
      font-size: 0.92rem;
    }

    #panel {
      position: fixed;
      top: 0;
      right: 0;
      width: min(470px, 96vw);
      height: 100dvh;
      background: var(--panel-bg);
      border-left: 1px solid var(--panel-border);
      transform: translateX(102%);
      transition: transform 0.22s ease;
      z-index: 40;
      padding: 14px;
      overflow: auto;
      color: #d5ffd5;
    }
    #panel.open { transform: translateX(0); }

    .row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; margin-bottom: 10px; }
    .row label { font-size: 0.9rem; }
    .row input[type="color"] { width: 48px; height: 34px; padding: 0; border: none; background: transparent; }
    .row input[type="range"] { width: 180px; max-width: 52vw; }
    .row input[type="time"], .row select, .row button {
      font-family: inherit;
      background: #0f2a0f;
      color: #d5ffd5;
      border: 1px solid #2f7b2f;
      border-radius: 8px;
      padding: 6px 8px;
    }
    .switch-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }
    .switch-row label { font-size: 0.9rem; }
    .switch {
      position: relative;
      width: 58px;
      height: 32px;
      display: inline-block;
      flex: 0 0 auto;
    }
    .switch input {
      opacity: 0;
      width: 0;
      height: 0;
      position: absolute;
    }
    .switch-slider {
      position: absolute;
      inset: 0;
      border-radius: 999px;
      background: #173117;
      border: 1px solid #2f7b2f;
      transition: background 0.2s ease, border-color 0.2s ease;
      box-shadow: inset 0 1px 5px rgba(0, 0, 0, 0.45);
    }
    .switch-slider::before {
      content: "";
      position: absolute;
      width: 24px;
      height: 24px;
      left: 3px;
      top: 3px;
      border-radius: 50%;
      background: #d5ffd5;
      transition: transform 0.2s ease;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }
    .switch input:checked + .switch-slider {
      background: linear-gradient(90deg, #0c7f3f, #1fc86b);
      border-color: #57e08f;
    }
    .switch input:checked + .switch-slider::before {
      transform: translateX(26px);
      background: #ffffff;
    }
    .split { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
    .hint { font-size: 0.8rem; opacity: 0.86; margin-bottom: 10px; }
    .title { margin: 0 0 8px 0; font-size: 1.05rem; }
    .panel-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin-bottom: 10px;
    }
    .section {
      margin: 12px 0;
      padding: 10px;
      border: 1px solid #245024;
      border-radius: 10px;
      background: rgba(0, 0, 0, 0.22);
    }
    .section h3 {
      margin: 0 0 8px 0;
      font-size: 0.92rem;
      color: #aaf7c4;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .days {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 4px;
      margin-top: 6px;
    }
    .days label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
      font-size: 0.74rem;
      border: 1px solid #2f7b2f;
      border-radius: 7px;
      padding: 4px 2px;
      background: #0d210d;
    }
    .days input { width: 13px; height: 13px; }

    @media (max-width: 700px) {
      #clockLine { letter-spacing: 0.08em; }
      #customizeBtn { font-size: 0.84rem; padding: 7px 10px; }
      .row { grid-template-columns: 1fr; }
      .row input[type="range"] { width: 100%; }
      .days label { font-size: 0.7rem; }
    }
  </style>
</head>
<body>
  <button id="customizeBtn" type="button">Personalizacion</button>

  <aside id="panel" aria-label="Panel de personalizacion">
    <h2 class="title">Personalizacion</h2>
    <div class="hint">Ajusta colores, tipografia, tamanos, modo nocturno y alarmas. Se guarda en este navegador.</div>
    <div class="panel-actions">
      <button id="panelFullscreenBtn" type="button">Pantalla completa</button>
      <button id="panelCloseBtn" type="button">Cerrar opciones</button>
    </div>

    <div class="section">
      <h3>Apariencia</h3>
      <div class="row"><label for="bgColor">Fondo general</label><input id="bgColor" type="color" value="#000000"></div>
      <div class="row"><label for="clockBg">Fondo reloj</label><input id="clockBg" type="color" value="#0a100a"></div>
      <div class="row"><label for="clockBorder">Borde reloj</label><input id="clockBorder" type="color" value="#143314"></div>
      <div class="row"><label for="clockText">Color hora</label><input id="clockText" type="color" value="#00e676"></div>
      <div class="row"><label for="dateColor">Color fecha</label><input id="dateColor" type="color" value="#87d4a5"></div>
      <div class="row"><label for="weatherColor">Color clima</label><input id="weatherColor" type="color" value="#b7f3ce"></div>

      <div class="row"><label for="fontFamily">Tipografia</label>
        <select id="fontFamily">
          <option value="Courier New, Lucida Console, monospace">LCD Clasico</option>
          <option value="Consolas, Monaco, monospace">Consolas</option>
          <option value="DSEGDisplay, 'DSEG7 Classic', 'DSEG14 Classic', monospace">DSEG 7/14 segmentos</option>
          <option value="'Roboto', Arial, sans-serif">Roboto</option>
          <option value="'Orbitron', 'Roboto', sans-serif">Orbitron</option>
          <option value="'Audiowide', 'Roboto', sans-serif">Audiowide</option>
          <option value="monospace">Monospace</option>
        </select>
      </div>
      <div class="switch-row"><label for="colorMode">Modo a color</label>
        <label class="switch"><input id="colorMode" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="shadowMode">Sombra en hora</label>
        <label class="switch"><input id="shadowMode" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="row"><label for="shadowColor">Color sombra</label><input id="shadowColor" type="color" value="#00e676"></div>
      <div class="row"><label for="shadowStrength">Grosor sombra</label><input id="shadowStrength" type="range" min="0" max="24" step="1" value="8"></div>
      <div class="switch-row"><label for="ampmToggle">Formato AM/PM</label>
        <label class="switch"><input id="ampmToggle" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="soundToggle">Sonido horario</label>
        <label class="switch"><input id="soundToggle" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="nightMode">Modo nocturno (22:00-06:00)</label>
        <label class="switch"><input id="nightMode" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>

      <div class="row"><label for="timeSize">Tamano hora</label><input id="timeSize" type="range" min="3" max="18" step="0.1" value="13"></div>
      <div class="row"><label for="metaSize">Tamano fecha/clima</label><input id="metaSize" type="range" min="0.8" max="3.2" step="0.05" value="1.7"></div>
      <div class="row"><label for="spacing">Espaciado letras</label><input id="spacing" type="range" min="0.02" max="0.30" step="0.01" value="0.14"></div>
    </div>

    <div class="section">
      <h3>Alarmas de Turno</h3>
      <div class="hint">Cada alarma suena 1 minuto y se apaga sola. Se puede silenciar tocando cualquier parte de la pantalla.</div>

      <div style="border-top:1px dashed #2a612a; padding-top:8px; margin-top:4px;">
        <div class="row">
          <label for="alarm1Enabled">Alarma 1</label>
          <label class="switch"><input id="alarm1Enabled" type="checkbox" checked><span class="switch-slider"></span></label>
        </div>
        <div class="row"><label for="alarm1Time">Hora</label><input id="alarm1Time" type="time" value="07:00"></div>
        <div class="days" id="alarm1DaysWrap">
          <label><input type="checkbox" data-alarm="1" value="1">L</label>
          <label><input type="checkbox" data-alarm="1" value="2">M</label>
          <label><input type="checkbox" data-alarm="1" value="3">X</label>
          <label><input type="checkbox" data-alarm="1" value="4">J</label>
          <label><input type="checkbox" data-alarm="1" value="5">V</label>
          <label><input type="checkbox" data-alarm="1" value="6">S</label>
          <label><input type="checkbox" data-alarm="1" value="0">D</label>
        </div>
      </div>

      <div style="border-top:1px dashed #2a612a; padding-top:8px; margin-top:8px;">
        <div class="row">
          <label for="alarm2Enabled">Alarma 2</label>
          <label class="switch"><input id="alarm2Enabled" type="checkbox" checked><span class="switch-slider"></span></label>
        </div>
        <div class="row"><label for="alarm2Time">Hora</label><input id="alarm2Time" type="time" value="20:00"></div>
        <div class="days" id="alarm2DaysWrap">
          <label><input type="checkbox" data-alarm="2" value="1">L</label>
          <label><input type="checkbox" data-alarm="2" value="2">M</label>
          <label><input type="checkbox" data-alarm="2" value="3">X</label>
          <label><input type="checkbox" data-alarm="2" value="4">J</label>
          <label><input type="checkbox" data-alarm="2" value="5">V</label>
          <label><input type="checkbox" data-alarm="2" value="6">S</label>
          <label><input type="checkbox" data-alarm="2" value="0">D</label>
        </div>
      </div>
    </div>

    <div class="split">
      <button id="soundTest" type="button">Probar Sonido</button>
      <button id="resetBtn" type="button">Restablecer</button>
    </div>
  </aside>

  <main class="wrap">
    <section class="clock-card">
      <div id="clockLine">
        <span class="h">12</span><span class="sep">:</span><span class="m">00</span><span class="ampm">AM</span>
      </div>
      <div id="dateLine">--</div>
      <div id="weatherLine">Clima La Habana: cargando...</div>
      <div id="alarmLine"></div>
    </section>
  </main>

  <script>
    const STORAGE_KEY = "clock_lcd_settings_v5";
    const DEFAULT_DAYS = "1,2,3,4,5,6,0";
    const DEFAULTS = {
      bg: "#000000",
      clockBg: "#0a100a",
      clockBorder: "#143314",
      clockText: "#00e676",
      dateColor: "#87d4a5",
      weatherColor: "#b7f3ce",
      fontFamily: "Courier New, Lucida Console, monospace",
      colorMode: true,
      shadowMode: true,
      shadowColor: "#00e676",
      shadowStrength: "8",
      timeSize: "13",
      metaSize: "1.7",
      spacing: "0.14",
      ampm: true,
      sound: true,
      nightMode: true,
      alarm1Enabled: true,
      alarm1Time: "07:00",
      alarm1Days: DEFAULT_DAYS,
      alarm2Enabled: true,
      alarm2Time: "20:00",
      alarm2Days: DEFAULT_DAYS
    };

    const $ = (s) => document.querySelector(s);
    const panel = $("#panel");
    const customizeBtn = $("#customizeBtn");
    const alarmLine = $("#alarmLine");

    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    let alarmInterval = null;
    let alarmTimeout = null;
    let alarmActiveId = "";
    const lastAlarmMinuteKey = { "1": "", "2": "" };
    let rainbowPatternIndex = 0;
    let rainbowMoveIndex = 0;

    const rainbowPatterns = [
      "linear-gradient(90deg, #ff004c, #ff7a00, #ffe600, #00d26a, #00b7ff, #6c5cff, #ff00c8)",
      "linear-gradient(120deg, #ff3d00, #ffd600, #00e5ff, #2979ff, #d500f9, #ff1744)",
      "linear-gradient(100deg, #f50057, #ff9100, #c6ff00, #00c853, #00b0ff, #651fff)",
      "linear-gradient(135deg, #ff1744, #ffea00, #00e676, #00b8d4, #7c4dff, #f50057)"
    ];
    const driftVectors = [
      { x: 20, y: 0 },
      { x: -20, y: 0 },
      { x: 0, y: 20 },
      { x: 0, y: -20 }
    ];

    const Synth = {
      playTone: (freq, type, duration, vol = 0.1) => {
        try {
          if (audioCtx.state === "suspended") audioCtx.resume();
          const osc = audioCtx.createOscillator();
          const gain = audioCtx.createGain();
          osc.type = type;
          osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
          gain.gain.setValueAtTime(vol, audioCtx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
          osc.connect(gain);
          gain.connect(audioCtx.destination);
          osc.start();
          osc.stop(audioCtx.currentTime + duration);
        } catch (e) {}
      },
      onHour: () => {
        Synth.playTone(1047, "sine", 0.55, 0.18);
        setTimeout(() => Synth.playTone(784, "sine", 0.55, 0.24), 480);
      },
      onHalf: () => {
        Synth.playTone(880, "sine", 0.45, 0.16);
      },
      alarmPulse: () => {
        Synth.playTone(1319, "sine", 0.20, 0.22);
        setTimeout(() => Synth.playTone(988, "square", 0.20, 0.20), 240);
        setTimeout(() => Synth.playTone(1319, "sine", 0.22, 0.22), 500);
      }
    };

    function getCheckedDays(alarmId) {
      const vals = [];
      document.querySelectorAll(`input[data-alarm="${alarmId}"]`).forEach((cb) => {
        if (cb.checked) vals.push(cb.value);
      });
      return vals.join(",");
    }

    function setCheckedDays(alarmId, csv) {
      const set = new Set(String(csv || "").split(",").filter(Boolean));
      document.querySelectorAll(`input[data-alarm="${alarmId}"]`).forEach((cb) => {
        cb.checked = set.has(cb.value);
      });
    }

    function saveSettings() {
      const s = {
        bg: $("#bgColor").value,
        clockBg: $("#clockBg").value,
        clockBorder: $("#clockBorder").value,
        clockText: $("#clockText").value,
        dateColor: $("#dateColor").value,
        weatherColor: $("#weatherColor").value,
        fontFamily: $("#fontFamily").value,
        colorMode: $("#colorMode").checked,
        shadowMode: $("#shadowMode").checked,
        shadowColor: $("#shadowColor").value,
        shadowStrength: $("#shadowStrength").value,
        timeSize: $("#timeSize").value,
        metaSize: $("#metaSize").value,
        spacing: $("#spacing").value,
        ampm: $("#ampmToggle").checked,
        sound: $("#soundToggle").checked,
        nightMode: $("#nightMode").checked,
        alarm1Enabled: $("#alarm1Enabled").checked,
        alarm1Time: $("#alarm1Time").value,
        alarm1Days: getCheckedDays("1") || DEFAULT_DAYS,
        alarm2Enabled: $("#alarm2Enabled").checked,
        alarm2Time: $("#alarm2Time").value,
        alarm2Days: getCheckedDays("2") || DEFAULT_DAYS
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
      applySettings(s);
    }

    function loadSettings() {
      let data = null;
      try { data = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null"); } catch (e) {}
      const s = Object.assign({}, DEFAULTS, data || {});
      $("#bgColor").value = s.bg;
      $("#clockBg").value = s.clockBg;
      $("#clockBorder").value = s.clockBorder;
      $("#clockText").value = s.clockText;
      $("#dateColor").value = s.dateColor;
      $("#weatherColor").value = s.weatherColor;
      $("#fontFamily").value = s.fontFamily;
      $("#colorMode").checked = !!s.colorMode;
      $("#shadowMode").checked = !!s.shadowMode;
      $("#shadowColor").value = s.shadowColor;
      $("#shadowStrength").value = s.shadowStrength;
      $("#timeSize").value = s.timeSize;
      $("#metaSize").value = s.metaSize;
      $("#spacing").value = s.spacing;
      $("#ampmToggle").checked = !!s.ampm;
      $("#soundToggle").checked = !!s.sound;
      $("#nightMode").checked = !!s.nightMode;
      $("#alarm1Enabled").checked = !!s.alarm1Enabled;
      $("#alarm1Time").value = s.alarm1Time;
      $("#alarm2Enabled").checked = !!s.alarm2Enabled;
      $("#alarm2Time").value = s.alarm2Time;
      setCheckedDays("1", s.alarm1Days || DEFAULT_DAYS);
      setCheckedDays("2", s.alarm2Days || DEFAULT_DAYS);
      applySettings(s);
    }

    function applySettings(s) {
      const root = document.documentElement;
      const selectedFont = String(s.fontFamily || "");
      const isDseg = selectedFont.includes("DSEGDisplay") || selectedFont.includes("DSEG7 Classic") || selectedFont.includes("DSEG14 Classic");
      root.style.setProperty("--bg", s.bg);
      root.style.setProperty("--clock-bg", s.clockBg);
      root.style.setProperty("--clock-border", s.clockBorder);
      root.style.setProperty("--clock-text", s.clockText);
      root.style.setProperty("--clock-glow", hexToRgba(s.clockText, 0.55));
      root.style.setProperty("--date-text", s.dateColor);
      root.style.setProperty("--weather-text", s.weatherColor);
      root.style.setProperty("--font-main", isDseg ? `"Courier New", "Lucida Console", monospace` : s.fontFamily);
      root.style.setProperty("--time-size", `clamp(3rem, 16vw, ${parseFloat(s.timeSize)}rem)`);
      root.style.setProperty("--meta-size", `clamp(0.85rem, 2.2vw, ${parseFloat(s.metaSize)}rem)`);
      root.style.setProperty("--letter-spacing", `${parseFloat(s.spacing)}em`);
      const shadowBlur = `${parseFloat(s.shadowStrength || 0)}px`;
      const shadowColor = hexToRgba(s.shadowColor || s.clockText, 0.82);
      root.style.setProperty("--shadow-color", shadowColor);
      root.style.setProperty("--shadow-blur", shadowBlur);
      root.style.setProperty("--time-shadow", s.shadowMode ? `0 0 ${shadowBlur} ${shadowColor}, 0 0 calc(${shadowBlur} * 2) ${shadowColor}` : "none");
      root.style.setProperty("--segment-shadow", s.shadowMode ? `drop-shadow(0 0 ${shadowBlur} ${shadowColor})` : "none");
      root.style.setProperty("--clock-offset-x", "0px");
      root.style.setProperty("--clock-offset-y", "0px");
      const line = $("#clockLine");
      line.style.fontFamily = isDseg ? selectedFont : "";
      line.classList.toggle("colorful", !!s.colorMode);
    }

    function renderTimeLine(hoursText, minutesText, useAmpm, ampm) {
      const line = $("#clockLine");
      line.innerHTML = `<span class="h time-text">${hoursText}</span><span class="sep time-text">:</span><span class="m time-text">${minutesText}</span><span class="ampm">${ampm}</span>`;
      $("#clockLine .ampm").style.display = useAmpm ? "" : "none";
      line.classList.toggle("colorful", $("#colorMode").checked);
    }

    function rotateRainbowPattern() {
      if (!$("#colorMode").checked) return;
      rainbowPatternIndex = (rainbowPatternIndex + 1) % rainbowPatterns.length;
      document.documentElement.style.setProperty("--rainbow-gradient", rainbowPatterns[rainbowPatternIndex]);
    }

    function driftClockIfColorMode() {
      const root = document.documentElement;
      if (!$("#colorMode").checked) {
        root.style.setProperty("--clock-offset-x", "0px");
        root.style.setProperty("--clock-offset-y", "0px");
        return;
      }
      rainbowMoveIndex = Math.floor(Math.random() * driftVectors.length);
      const drift = driftVectors[rainbowMoveIndex];
      root.style.setProperty("--clock-offset-x", `${drift.x}px`);
      root.style.setProperty("--clock-offset-y", `${drift.y}px`);
    }

    function hexToRgba(hex, a) {
      const raw = (hex || "").replace("#", "");
      if (!/^[0-9a-fA-F]{6}$/.test(raw)) return `rgba(0,230,118,${a})`;
      const r = parseInt(raw.slice(0, 2), 16);
      const g = parseInt(raw.slice(2, 4), 16);
      const b = parseInt(raw.slice(4, 6), 16);
      return `rgba(${r}, ${g}, ${b}, ${a})`;
    }

    function formatDateEs(d) {
      const f = new Intl.DateTimeFormat("es-CU", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric"
      });
      const t = f.format(d);
      return t.charAt(0).toUpperCase() + t.slice(1);
    }

    function weatherCodeText(code) {
      const map = {
        0: "Despejado", 1: "Mayormente despejado", 2: "Parcialmente nublado", 3: "Nublado",
        45: "Niebla", 48: "Niebla escarchada", 51: "Llovizna ligera", 53: "Llovizna moderada", 55: "Llovizna intensa",
        61: "Lluvia ligera", 63: "Lluvia moderada", 65: "Lluvia fuerte", 71: "Nieve ligera", 73: "Nieve moderada", 75: "Nieve fuerte",
        80: "Chubascos ligeros", 81: "Chubascos moderados", 82: "Chubascos fuertes", 95: "Tormenta"
      };
      return map[Number(code)] || "Condicion variable";
    }

    async function loadHavanaWeather() {
      const el = $("#weatherLine");
      const url = "https://api.open-meteo.com/v1/forecast?latitude=23.1136&longitude=-82.3666&current=temperature_2m,weather_code&daily=temperature_2m_max,temperature_2m_min&timezone=America%2FHavana&forecast_days=1";
      try {
        const r = await fetch(url, { cache: "no-store" });
        if (!r.ok) throw new Error("http " + r.status);
        const d = await r.json();
        const cur = d.current || {};
        const dayMax = d.daily?.temperature_2m_max?.[0];
        const dayMin = d.daily?.temperature_2m_min?.[0];
        const tNow = Number(cur.temperature_2m);
        const text = weatherCodeText(cur.weather_code);
        const nowTxt = Number.isFinite(tNow) ? `${Math.round(tNow)}°C` : "--";
        const maxTxt = Number.isFinite(dayMax) ? `${Math.round(dayMax)}°C` : "--";
        const minTxt = Number.isFinite(dayMin) ? `${Math.round(dayMin)}°C` : "--";
        el.textContent = `La Habana, Cuba hoy: ${text} | Ahora ${nowTxt} | Max ${maxTxt} / Min ${minTxt}`;
      } catch (e) {
        el.textContent = "La Habana, Cuba hoy: clima no disponible";
      }
    }

    function isNightModeActive(now) {
      if (!$("#nightMode").checked) return false;
      const h = now.getHours();
      return (h >= 22 || h < 6);
    }

    function canPlaySound(now) {
      if (!$("#soundToggle").checked) return false;
      if (isNightModeActive(now)) return false;
      return true;
    }

    function applyNightVisual(now) {
      document.body.classList.toggle("night-dim", isNightModeActive(now));
    }

    function stopAlarm(reason = "manual") {
      if (!alarmInterval && !alarmTimeout) return;
      if (alarmInterval) clearInterval(alarmInterval);
      if (alarmTimeout) clearTimeout(alarmTimeout);
      alarmInterval = null;
      alarmTimeout = null;
      alarmActiveId = "";
      alarmLine.textContent = reason === "auto" ? "Alarma detenida automaticamente." : "Alarma silenciada.";
      setTimeout(() => { if (!alarmActiveId) alarmLine.textContent = ""; }, 1500);
    }

    function startAlarm(alarmId) {
      if (alarmActiveId) stopAlarm("replace");
      alarmActiveId = alarmId;
      alarmLine.textContent = `Alarma de turno ${alarmId} activa. Toca la pantalla para silenciar.`;

      if (!canPlaySound(new Date())) {
        alarmLine.textContent = "Alarma detectada, pero sonido desactivado (modo nocturno o silencio).";
        alarmTimeout = setTimeout(() => stopAlarm("auto"), 60000);
        return;
      }

      Synth.alarmPulse();
      alarmInterval = setInterval(() => {
        if (!canPlaySound(new Date())) return;
        Synth.alarmPulse();
      }, 900);
      alarmTimeout = setTimeout(() => stopAlarm("auto"), 60000);
    }

    function alarmMatch(alarmId, now) {
      const enabled = $(`#alarm${alarmId}Enabled`).checked;
      if (!enabled) return false;

      const time = $(`#alarm${alarmId}Time`).value || "";
      if (!/^\d{2}:\d{2}$/.test(time)) return false;

      const currentHM = `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`;
      if (time !== currentHM) return false;

      const daysCsv = getCheckedDays(String(alarmId)) || DEFAULT_DAYS;
      const daySet = new Set(daysCsv.split(",").filter(Boolean));
      if (!daySet.has(String(now.getDay()))) return false;

      const key = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,"0")}-${String(now.getDate()).padStart(2,"0")}-${currentHM}`;
      if (lastAlarmMinuteKey[String(alarmId)] === key) return false;
      lastAlarmMinuteKey[String(alarmId)] = key;
      return true;
    }

    function startClock() {
      let lastBeepMinute = -1;
      const line = $("#clockLine");
      const dateEl = $("#dateLine");

      function tick() {
        const now = new Date();
        applyNightVisual(now);

        const hours24 = now.getHours();
        const useAmpm = $("#ampmToggle").checked;
        const h = useAmpm ? (hours24 % 12 || 12) : hours24;
        const m = String(now.getMinutes()).padStart(2, "0");
        const ampm = hours24 < 12 ? "AM" : "PM";

        renderTimeLine(String(h).padStart(2, "0"), m, useAmpm, ampm);
        line.classList.toggle("blink-colon", now.getSeconds() % 2 === 1);
        dateEl.textContent = formatDateEs(now);

        const totalMin = hours24 * 60 + now.getMinutes();
        if (now.getSeconds() === 0 && totalMin !== lastBeepMinute) {
          lastBeepMinute = totalMin;

          if (alarmMatch(1, now)) startAlarm("1");
          if (alarmMatch(2, now)) startAlarm("2");

          if (canPlaySound(now)) {
            if (now.getMinutes() === 0) {
              Synth.onHour();
            } else if (now.getMinutes() === 30) {
              Synth.onHalf();
            }
          }
        }
      }

      tick();
      setInterval(tick, 1000);
      setInterval(rotateRainbowPattern, 5000);
      setInterval(driftClockIfColorMode, 10000);
    }

    function bindAlarmDismiss() {
      const stopIfActive = () => {
        if (alarmActiveId) stopAlarm("manual");
      };
      document.addEventListener("pointerdown", stopIfActive);
      document.addEventListener("touchstart", stopIfActive, { passive: true });
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" || e.key === "Enter" || e.key === " ") stopIfActive();
      });
    }

    customizeBtn.addEventListener("click", () => {
      panel.classList.toggle("open");
    });

    $("#panelCloseBtn").addEventListener("click", () => {
      panel.classList.remove("open");
    });

    $("#panelFullscreenBtn").addEventListener("click", async () => {
      try {
        if (!document.fullscreenElement) await document.documentElement.requestFullscreen();
        else await document.exitFullscreen();
      } catch (_) {}
    });

    document.querySelectorAll("#panel input, #panel select").forEach((el) => {
      el.addEventListener("input", saveSettings);
      el.addEventListener("change", saveSettings);
    });

    $("#soundTest").addEventListener("click", () => {
      Synth.onHour();
      setTimeout(() => Synth.onHalf(), 1200);
    });

    $("#resetBtn").addEventListener("click", () => {
      localStorage.removeItem(STORAGE_KEY);
      stopAlarm("reset");
      loadSettings();
    });

    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") panel.classList.remove("open");
      if (e.key.toLowerCase() === "f") {
        if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {});
        else document.exitFullscreen().catch(() => {});
      }
    });

    loadSettings();
    bindAlarmDismiss();
    startClock();
    loadHavanaWeather();
    setInterval(loadHavanaWeather, 30 * 60 * 1000);
  </script>
</body>
</html>
