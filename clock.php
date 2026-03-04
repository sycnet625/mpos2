<?php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clock LCD</title>
  <style>
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
      --panel-bg: rgba(0, 0, 0, 0.88);
      --panel-border: #174117;
    }

    * { box-sizing: border-box; }
    html, body { width: 100%; height: 100%; margin: 0; }
    body {
      background: var(--bg);
      color: var(--clock-text);
      font-family: var(--font-main);
      overflow: hidden;
    }

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
      width: min(1400px, 100%);
      text-align: center;
      background: var(--clock-bg);
      border: 1px solid var(--clock-border);
      border-radius: clamp(10px, 1.8vw, 18px);
      box-shadow: inset 0 2px 7px rgba(0, 0, 0, 0.7), 0 12px 30px rgba(0, 0, 0, 0.45);
      padding: clamp(16px, 3vw, 36px);
      user-select: none;
    }

    #clockLine {
      font-size: var(--time-size);
      line-height: 0.95;
      font-weight: 700;
      letter-spacing: var(--letter-spacing);
      color: var(--clock-text);
      text-shadow: 0 0 8px var(--clock-text), 0 0 18px var(--clock-glow);
      white-space: nowrap;
      display: flex;
      justify-content: center;
      align-items: baseline;
      gap: 0.04em;
      flex-wrap: nowrap;
    }
    #clockLine.blink-colon .sep { opacity: 0.2; }

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
      width: min(430px, 95vw);
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
    .row select, .row button {
      font-family: inherit;
      background: #0f2a0f;
      color: #d5ffd5;
      border: 1px solid #2f7b2f;
      border-radius: 8px;
      padding: 6px 8px;
    }
    .split { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
    .hint { font-size: 0.8rem; opacity: 0.86; margin-bottom: 10px; }
    .title { margin: 0 0 8px 0; font-size: 1.05rem; }

    @media (max-width: 700px) {
      #clockLine { letter-spacing: 0.08em; }
      #customizeBtn { font-size: 0.84rem; padding: 7px 10px; }
      .row { grid-template-columns: 1fr; }
      .row input[type="range"] { width: 100%; }
    }
  </style>
</head>
<body>
  <button id="customizeBtn" type="button">Personalizacion</button>

  <aside id="panel" aria-label="Panel de personalizacion">
    <h2 class="title">Personalizacion</h2>
    <div class="hint">Ajusta colores, tipografia, tamanos y sonido. Se guarda en este navegador.</div>

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
        <option value="monospace">Monospace</option>
      </select>
    </div>

    <div class="row"><label for="timeSize">Tamano hora</label><input id="timeSize" type="range" min="3" max="18" step="0.1" value="13"></div>
    <div class="row"><label for="metaSize">Tamano fecha/clima</label><input id="metaSize" type="range" min="0.8" max="3.2" step="0.05" value="1.7"></div>
    <div class="row"><label for="spacing">Espaciado letras</label><input id="spacing" type="range" min="0.02" max="0.30" step="0.01" value="0.14"></div>

    <div class="row">
      <label for="ampmToggle">Formato AM/PM</label>
      <select id="ampmToggle">
        <option value="1">Mostrar AM/PM</option>
        <option value="0">Ocultar (24h)</option>
      </select>
    </div>

    <div class="row">
      <label for="soundToggle">Sonido horario</label>
      <select id="soundToggle">
        <option value="1">Activado</option>
        <option value="0">Silenciado</option>
      </select>
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
    </section>
  </main>

  <script>
    const STORAGE_KEY = "clock_lcd_settings_v1";
    const DEFAULTS = {
      bg: "#000000",
      clockBg: "#0a100a",
      clockBorder: "#143314",
      clockText: "#00e676",
      dateColor: "#87d4a5",
      weatherColor: "#b7f3ce",
      fontFamily: "Courier New, Lucida Console, monospace",
      timeSize: "13",
      metaSize: "1.7",
      spacing: "0.14",
      ampm: "1",
      sound: "1"
    };

    const $ = (s) => document.querySelector(s);
    const panel = $("#panel");
    const customizeBtn = $("#customizeBtn");

    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
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
      }
    };

    function saveSettings() {
      const s = {
        bg: $("#bgColor").value,
        clockBg: $("#clockBg").value,
        clockBorder: $("#clockBorder").value,
        clockText: $("#clockText").value,
        dateColor: $("#dateColor").value,
        weatherColor: $("#weatherColor").value,
        fontFamily: $("#fontFamily").value,
        timeSize: $("#timeSize").value,
        metaSize: $("#metaSize").value,
        spacing: $("#spacing").value,
        ampm: $("#ampmToggle").value,
        sound: $("#soundToggle").value
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
      $("#timeSize").value = s.timeSize;
      $("#metaSize").value = s.metaSize;
      $("#spacing").value = s.spacing;
      $("#ampmToggle").value = s.ampm;
      $("#soundToggle").value = s.sound;
      applySettings(s);
    }

    function applySettings(s) {
      const root = document.documentElement;
      root.style.setProperty("--bg", s.bg);
      root.style.setProperty("--clock-bg", s.clockBg);
      root.style.setProperty("--clock-border", s.clockBorder);
      root.style.setProperty("--clock-text", s.clockText);
      root.style.setProperty("--clock-glow", hexToRgba(s.clockText, 0.55));
      root.style.setProperty("--date-text", s.dateColor);
      root.style.setProperty("--weather-text", s.weatherColor);
      root.style.setProperty("--font-main", s.fontFamily);
      root.style.setProperty("--time-size", `clamp(3rem, 16vw, ${parseFloat(s.timeSize)}rem)`);
      root.style.setProperty("--meta-size", `clamp(0.85rem, 2.2vw, ${parseFloat(s.metaSize)}rem)`);
      root.style.setProperty("--letter-spacing", `${parseFloat(s.spacing)}em`);
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
        0: "Despejado",
        1: "Mayormente despejado",
        2: "Parcialmente nublado",
        3: "Nublado",
        45: "Niebla",
        48: "Niebla escarchada",
        51: "Llovizna ligera",
        53: "Llovizna moderada",
        55: "Llovizna intensa",
        61: "Lluvia ligera",
        63: "Lluvia moderada",
        65: "Lluvia fuerte",
        71: "Nieve ligera",
        73: "Nieve moderada",
        75: "Nieve fuerte",
        80: "Chubascos ligeros",
        81: "Chubascos moderados",
        82: "Chubascos fuertes",
        95: "Tormenta"
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

    function startClock() {
      let lastBeepMinute = -1;
      const line = $("#clockLine");
      const hEl = $("#clockLine .h");
      const mEl = $("#clockLine .m");
      const ampmEl = $("#clockLine .ampm");
      const dateEl = $("#dateLine");

      function tick() {
        const now = new Date();
        const hours24 = now.getHours();
        const useAmpm = $("#ampmToggle").value === "1";
        const h = useAmpm ? (hours24 % 12 || 12) : hours24;
        const m = String(now.getMinutes()).padStart(2, "0");
        const ampm = hours24 < 12 ? "AM" : "PM";

        hEl.textContent = String(h).padStart(2, "0");
        mEl.textContent = m;
        ampmEl.textContent = ampm;
        ampmEl.style.display = useAmpm ? "" : "none";
        line.classList.toggle("blink-colon", now.getSeconds() % 2 === 1);
        dateEl.textContent = formatDateEs(now);

        const totalMin = hours24 * 60 + now.getMinutes();
        if (now.getSeconds() === 0 && totalMin !== lastBeepMinute) {
          lastBeepMinute = totalMin;
          if ($("#soundToggle").value === "1") {
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
    }

    customizeBtn.addEventListener("click", () => {
      panel.classList.toggle("open");
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
    startClock();
    loadHavanaWeather();
    setInterval(loadHavanaWeather, 30 * 60 * 1000);
  </script>
</body>
</html>
