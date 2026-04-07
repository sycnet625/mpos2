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

    @font-face {
      font-family: "DSEGModern";
      src:
        url("assets/fonts/dseg7-modern-400.woff2") format("woff2"),
        local("DSEG7 Modern"),
        local("DSEG7Modern-Regular");
      font-display: swap;
    }

    @font-face {
      font-family: "DSEGMini";
      src:
        url("assets/fonts/dseg14-classic-mini-400.woff2") format("woff2"),
        local("DSEG14 Classic Mini"),
        local("DSEG14ClassicMini-Regular");
      font-display: swap;
    }

    @font-face {
      font-family: "DSEGModernMini";
      src:
        url("assets/fonts/dseg7-modern-mini-400.woff2") format("woff2"),
        local("DSEG7 Modern Mini"),
        local("DSEG7ModernMini-Regular");
      font-display: swap;
    }

    /* Mejora global de renderizado de fuentes */
    @media screen and (-webkit-min-device-pixel-ratio: 0) {
      .time-text, .h, .m, .ampm, .flip-glyph {
        -webkit-font-smoothing: antialiased;
        text-rendering: geometricPrecision;
      }
    }

    :root {
      --bg: #000000;
      --clock-bg: #0a100a;
      --clock-border: #143314;
      --clock-text: #00e676;
      --clock-glow: rgba(0, 230, 118, 0.50);
      --glow-color: rgba(0, 230, 118, 0.55);
      --line-filter: none;
      --date-text: #87d4a5;
      --weather-text: #b7f3ce;
      --font-main: "Courier New", "Lucida Console", monospace;
      --time-size: clamp(4rem, 16vw, 13rem);
      --meta-size: clamp(0.95rem, 2.2vw, 1.7rem);
      --letter-spacing: 0.14em;
      --panel-bg: rgba(0, 0, 0, 0.90);
      --panel-border: #174117;
      --time-shadow: 2px 4px 6px rgba(0, 0, 0, 0.45);
      --rainbow-gradient: linear-gradient(270deg, #ff004c, #ff7a00, #ffe600, #00d26a, #00b7ff, #6c5cff, #ff00c8);
      --color-fill: var(--rainbow-gradient);
      --color-fill-size: 140vw 100%;
      --color-fill-attachment: fixed;
      --color-flow-animation: rainbow-flow-rtl 7s linear infinite;
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
      /* Color de transicion suave */
      --color-transition-duration: 1.8s;
    }

    * { box-sizing: border-box; }
    html, body { width: 100%; height: 100%; margin: 0; }
    body {
      background: var(--bg);
      color: var(--clock-text);
      font-family: var(--font-main);
      overflow: hidden;
      transition: filter 0.25s ease;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: optimizeLegibility;
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
      font-weight: 400;
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
      filter: var(--line-filter);
      /* Mejora de renderizado de texto */
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: geometricPrecision;
    }
    #clockLine.blink-colon .sep { opacity: 0.2; }
    #clockLine.colorful {
      filter: saturate(1.2) brightness(1.06);
    }
    #clockLine.colorful .time-text {
      background-image: var(--color-fill);
      background-size: var(--color-fill-size);
      background-attachment: var(--color-fill-attachment);
      color: transparent;
      -webkit-background-clip: text;
      background-clip: text;
      text-shadow: var(--time-shadow);
      animation: var(--color-flow-animation);
      /* Transicion de color mas fluida */
      transition: background-image var(--color-transition-duration) ease,
                  text-shadow var(--color-transition-duration) ease;
    }
    #clockLine.colorful .ampm {
      background-image: var(--color-fill);
      color: transparent;
      -webkit-background-clip: text;
      background-clip: text;
      background-size: var(--color-fill-size);
      background-attachment: var(--color-fill-attachment);
      text-shadow: var(--time-shadow);
      animation: var(--color-flow-animation);
      transition: background-image var(--color-transition-duration) ease,
                  text-shadow var(--color-transition-duration) ease;
    }

    @keyframes rainbow-flow-rtl {
      0% { background-position: 100vw 50%; }
      100% { background-position: 0vw 50%; }
    }

    @keyframes fluid-flow {
      0% {
        background-position: 8% 18%, 88% 24%, 18% 82%, 74% 72%, 50% 50%;
      }
      25% {
        background-position: 24% 12%, 76% 18%, 14% 70%, 82% 78%, 56% 42%;
      }
      50% {
        background-position: 16% 36%, 84% 38%, 26% 86%, 70% 62%, 48% 58%;
      }
      75% {
        background-position: 30% 26%, 68% 14%, 20% 72%, 86% 66%, 60% 48%;
      }
      100% {
        background-position: 8% 18%, 88% 24%, 18% 82%, 74% 72%, 50% 50%;
      }
    }

    @keyframes aurora-sweep {
      0% { background-position: 0% 30%; }
      50% { background-position: 100% 70%; }
      100% { background-position: 0% 30%; }
    }

    @keyframes plasma-wave {
      0% { background-position: 0% 50%; filter: hue-rotate(0deg); }
      50% { background-position: 100% 50%; filter: hue-rotate(26deg); }
      100% { background-position: 0% 50%; filter: hue-rotate(0deg); }
    }

    @keyframes prism-glint {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    /* Transicion suave entre cambios de gradiente */
    @keyframes color-fade-in {
      0% { opacity: 0.3; }
      100% { opacity: 1; }
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
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: geometricPrecision;
    }

    .digit-7 .seg {
      position: absolute;
      background: color-mix(in srgb, var(--clock-text) 10%, transparent);
      border-radius: var(--seg-radius);
      transition: background 0.4s ease, opacity 0.4s ease, box-shadow 0.4s ease;
      opacity: 0.12;
    }

    .digit-7 .seg.on {
      background: var(--clock-text);
      opacity: 1;
      box-shadow:
        0 0 8px var(--clock-text),
        0 0 18px var(--clock-glow),
        0 0 32px color-mix(in srgb, var(--clock-text) 40%, transparent);
    }

    #clockLine.colorful .digit-7 .seg.on,
    #clockLine.colorful .colon-7 span {
      background-image: var(--color-fill);
      background-size: var(--color-fill-size);
      background-attachment: var(--color-fill-attachment);
      box-shadow: 0 0 8px rgba(255, 255, 255, 0.28), 0 0 18px rgba(255, 0, 180, 0.24);
      animation: var(--color-flow-animation);
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
      box-shadow:
        0 0 6px var(--clock-text),
        0 0 14px var(--clock-glow),
        0 0 24px color-mix(in srgb, var(--clock-text) 30%, transparent);
      transition: opacity 0.3s ease, box-shadow 0.3s ease;
    }
    .ampm {
      font-size: 0.30em;
      letter-spacing: 0.02em;
      margin-left: 0.18em;
      opacity: 0.9;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: geometricPrecision;
    }

    #clockLine.flip-mode {
      letter-spacing: 0;
      gap: 0.08em;
      align-items: center;
      text-shadow: var(--time-shadow);
    }

    .flip-clock {
      display: inline-flex;
      align-items: center;
      gap: 0.08em;
    }

    .flip-group {
      display: inline-flex;
      gap: 0.06em;
      align-items: center;
    }

    .flip-sep {
      font-size: 0.72em;
      line-height: 1;
      margin: 0 0.02em;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 0.18em;
      text-shadow: var(--time-shadow);
    }

    .flip-digit {
      position: relative;
      width: 0.68em;
      height: 1em;
      perspective: 700px;
      border-radius: 0.14em;
      overflow: hidden;
      background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(0,0,0,0.30));
      border: 1px solid color-mix(in srgb, var(--clock-border) 75%, white 10%);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 0.08em 0.22em rgba(0,0,0,0.45);
    }

    .flip-digit::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      top: 50%;
      height: 1px;
      background: rgba(0,0,0,0.45);
      z-index: 3;
    }

    .flip-card {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 400;
      backface-visibility: hidden;
    }

    .flip-card.current {
      z-index: 1;
    }

    .flip-card.exit {
      z-index: 4;
      animation: flip-card-out 0.62s cubic-bezier(0.22, 0.61, 0.36, 1) forwards;
    }

    .flip-card.enter {
      z-index: 5;
      animation: flip-card-in 0.62s cubic-bezier(0.19, 1, 0.22, 1) forwards;
    }

    .flip-glyph {
      font-size: 0.86em;
      line-height: 1;
      transform: translateY(-0.02em);
      text-shadow: var(--time-shadow);
      color: var(--clock-text);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: geometricPrecision;
    }

    .flip-digit .flip-glyph {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      height: 100%;
    }

    @keyframes flip-card-out {
      0% {
        transform: translateY(0%) scale(1);
        opacity: 1;
      }
      100% {
        transform: translateY(-110%) scale(0.94);
        opacity: 0;
      }
    }

    @keyframes flip-card-in {
      0% {
        transform: translateY(110%) scale(0.94);
        opacity: 0;
      }
      100% {
        transform: translateY(0%) scale(1);
        opacity: 1;
      }
    }

    #clockLine.colorful .flip-glyph,
    #clockLine.colorful .flip-sep {
      background-image: var(--color-fill);
      background-size: var(--color-fill-size);
      background-attachment: var(--color-fill-attachment);
      color: transparent;
      -webkit-background-clip: text;
      background-clip: text;
      text-shadow: var(--time-shadow);
      animation: var(--color-flow-animation);
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
      :root {
        --time-size: clamp(2.5rem, 18vw, 6rem);
        --meta-size: clamp(0.75rem, 2.5vw, 1.1rem);
        --letter-spacing: 0.08em;
      }
      #clockLine { letter-spacing: 0.06em; gap: 0.02em; }
      #clockLine > span { font-size: 0.95em; }
      #customizeBtn, #toolsBtn { font-size: 0.78rem; padding: 6px 10px; top: 8px; }
      #toolsBtn { left: 8px; }
      #customizeBtn { right: 8px; }
      .clock-card { padding: clamp(10px, 3vw, 20px); border-radius: clamp(8px, 2vw, 14px); }
      .row { grid-template-columns: 1fr; gap: 6px; }
      .row label { font-size: 0.85rem; }
      .row input[type="range"] { width: 100%; }
      .row input[type="color"] { width: 40px; height: 30px; }
      .days label { font-size: 0.65rem; padding: 3px 1px; }
      .panel { width: 92vw; max-height: 90dvh; padding: 10px; }
      .timer-panel, .event-panel { width: 90vw; max-height: 88dvh; padding: 12px; }
      .timer-display { font-size: clamp(2rem, 12vw, 3rem); }
      .section { padding: 8px; margin: 8px 0; }
      .section h3 { font-size: 0.8rem; }
      .alarm-history { font-size: 0.68rem; max-width: 160px; bottom: 8px; left: 8px; }
      #secondsLine { font-size: calc(var(--meta-size) * 0.55); }
      #moonPhaseLine, #salesLine, #clientsLine { font-size: calc(var(--meta-size) * 0.5); }
    }

    @media (max-width: 480px) {
      :root {
        --time-size: clamp(2rem, 22vw, 4.5rem);
        --meta-size: clamp(0.65rem, 3vw, 0.9rem);
      }
      #clockLine { flex-wrap: wrap; justify-content: center; }
      .clock-card { padding: 8px; }
      #customizeBtn, #toolsBtn { font-size: 0.7rem; padding: 5px 8px; }
      .panel-actions { grid-template-columns: 1fr; }
      .split { grid-template-columns: 1fr; }
      .timer-sets { flex-wrap: wrap; }
      .switch { width: 48px; height: 26px; }
      .switch-slider::before { width: 20px; height: 20px; }
      .switch input:checked + .switch-slider::before { transform: translateX(22px); }
    }

    @media (max-height: 500px) and (orientation: landscape) {
      .clock-card { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px; }
      #dateLine, #weatherLine, #alarmLine, #secondsLine, #moonPhaseLine, #salesLine, #clientsLine { margin: 2px 0; }
      :root { --time-size: clamp(1.8rem, 12vw, 3rem); }
      .panel { max-height: 95dvh; }
    }

    @keyframes breathe-glow {
      0%, 100% { opacity: 1; filter: brightness(1); }
      50% { opacity: 0.75; filter: brightness(0.82); }
    }
    .time-text.breathe { animation: breathe-glow 3s ease-in-out infinite; }

    @keyframes fade-in-up {
      0% { opacity: 0; transform: translateY(20px); }
      100% { opacity: 1; transform: translateY(0); }
    }
    .clock-card { animation: fade-in-up 0.8s ease-out; }

    #secondsLine {
      margin-top: clamp(3px, 0.8vw, 8px);
      font-size: calc(var(--meta-size) * 0.68);
      color: var(--clock-text);
      opacity: 0.7;
      letter-spacing: 0.12em;
      text-shadow: var(--time-shadow);
    }
    #secondsLine.breathe { animation: breathe-glow 1s ease-in-out infinite; }

    #moonPhaseLine {
      margin-top: clamp(4px, 0.9vw, 10px);
      font-size: calc(var(--meta-size) * 0.6);
      color: var(--date-text);
      opacity: 0.8;
      text-shadow: var(--time-shadow);
    }

    #salesLine, #clientsLine {
      margin-top: clamp(2px, 0.5vw, 6px);
      font-size: calc(var(--meta-size) * 0.55);
      color: var(--weather-text);
      opacity: 0.75;
      text-shadow: var(--time-shadow);
    }

    body.mirror-mode {
      transform: scaleX(-1);
    }

    body.presentation-mode {
      cursor: none;
      user-select: none;
    }
    body.presentation-mode .clock-card { animation: none; }
    .presentation-slides {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: var(--bg);
      display: none;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      z-index: 100;
    }
    .presentation-slides.active { display: flex; }
    .slide {
      position: absolute;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      opacity: 0;
      transition: opacity 1s ease-in-out, transform 0.8s ease;
      transform: scale(0.92);
      pointer-events: none;
    }
    .slide.active { 
      opacity: 1; 
      transform: scale(1);
      pointer-events: auto;
    }
    .slide.prev {
      opacity: 0;
      transform: scale(1.08);
    }
    .slide-clock-time {
      font-size: clamp(4rem, 16vw, 10rem);
      font-family: var(--font-main);
      color: var(--clock-text);
      text-shadow: var(--time-shadow);
      letter-spacing: 0.1em;
    }
    .slide-clock-date {
      font-size: clamp(1.5rem, 5vw, 3rem);
      color: var(--date-text);
      text-shadow: var(--time-shadow);
      margin-top: 0.5em;
    }
    .slide-emoji {
      font-size: clamp(6rem, 20vw, 12rem);
      line-height: 1;
    }
    .slide-weather-text {
      font-size: clamp(2.5rem, 8vw, 6rem);
      color: var(--weather-text);
      text-shadow: var(--time-shadow);
      margin-top: 0.3em;
    }
    .slide-weather-detail {
      font-size: clamp(1.2rem, 4vw, 2.5rem);
      color: var(--date-text);
      text-shadow: var(--time-shadow);
      margin-top: 0.2em;
    }
    .slide-stats-sales {
      font-size: clamp(3rem, 12vw, 8rem);
      color: var(--clock-text);
      text-shadow: var(--time-shadow);
    }
    .slide-stats-detail, .slide-stats-clients {
      font-size: clamp(1.2rem, 4vw, 2.2rem);
      color: var(--date-text);
      text-shadow: var(--time-shadow);
      margin-top: 0.2em;
    }

    .mode-indicator {
      font-size: calc(var(--meta-size) * 0.58);
      color: var(--date-text);
      opacity: 0.65;
      margin-left: 0.15em;
      letter-spacing: 0.04em;
    }

    .timer-panel, .event-panel {
      position: fixed;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      width: min(420px, 92vw);
      max-height: 85dvh;
      overflow: auto;
      background: var(--panel-bg);
      border: 1px solid var(--panel-border);
      border-radius: 14px;
      padding: 16px;
      z-index: 50;
      color: #d5ffd5;
    }
    .timer-tabs {
      display: flex;
      gap: 6px;
      margin-bottom: 14px;
    }
    .timer-tab {
      flex: 1;
      padding: 8px 6px;
      background: #0f2a0f;
      border: 1px solid #2f7b2f;
      border-radius: 8px;
      color: #9bf3b8;
      font-size: 0.82rem;
      cursor: pointer;
      font-family: inherit;
    }
    .timer-tab.active {
      background: #1f4f1f;
      border-color: #4ea84e;
    }
    .timer-content { display: none; }
    .timer-content.active { display: block; }
    .timer-display {
      font-size: clamp(2.8rem, 14vw, 4rem);
      text-align: center;
      font-family: var(--font-main);
      color: var(--clock-text);
      text-shadow: var(--time-shadow);
      margin: 12px 0;
      letter-spacing: 0.08em;
    }
    .timer-controls {
      display: flex;
      gap: 8px;
      justify-content: center;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }
    .timer-controls button {
      padding: 8px 16px;
      background: #0f2a0f;
      border: 1px solid #2f7b2f;
      border-radius: 8px;
      color: #d5ffd5;
      font-family: inherit;
      cursor: pointer;
      font-size: 0.88rem;
    }
    .timer-controls button:hover { background: #1a3a1a; }
    .timer-sets {
      display: flex;
      gap: 6px;
      justify-content: center;
    }
    .pomo-set {
      padding: 6px 12px;
      background: #0d210d;
      border: 1px solid #2a5a2a;
      border-radius: 6px;
      color: #a8e8b8;
      font-family: inherit;
      cursor: pointer;
      font-size: 0.8rem;
    }
    .timer-inputs {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-bottom: 12px;
    }
    .timer-inputs input {
      width: 70px;
      padding: 8px;
      background: #0f2a0f;
      border: 1px solid #2f7b2f;
      border-radius: 8px;
      color: #d5ffd5;
      font-family: inherit;
      text-align: center;
      font-size: 1.1rem;
    }
    .lap-times {
      max-height: 120px;
      overflow: auto;
      font-size: 0.78rem;
      color: #a8e8b8;
      text-align: center;
    }
    .lap-times div { padding: 3px 0; border-bottom: 1px dashed #245024; }

    .event-panel h3 {
      margin: 0 0 12px 0;
      font-size: 1rem;
      color: #aaf7c4;
    }
    .events-list {
      margin-top: 12px;
      max-height: 150px;
      overflow: auto;
    }
    .event-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px;
      background: #0d210d;
      border: 1px solid #245024;
      border-radius: 8px;
      margin-bottom: 6px;
      font-size: 0.82rem;
    }
    .event-item button {
      padding: 4px 8px;
      background: #1a2a1a;
      border: 1px solid #3a3a3a;
      border-radius: 5px;
      color: #c8c8c8;
      cursor: pointer;
      font-size: 0.75rem;
    }

    .alarm-history {
      position: fixed;
      bottom: 12px;
      left: 12px;
      font-size: 0.74rem;
      color: #7ab87a;
      opacity: 0.8;
      max-width: 200px;
    }
    .alarm-history div {
      padding: 3px 0;
    }

    #toolsBtn {
      position: fixed;
      top: 12px;
      left: 12px;
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
    #toolsBtn:hover { background: #152a15; }

    #pwaBanner {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: linear-gradient(0deg, rgba(0,0,0,0.95) 0%, rgba(0,0,0,0.85) 100%);
      border-top: 1px solid #2f7b2f;
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      z-index: 100;
    }
    #pwaBanner.hidden { display: none; }
    #pwaBanner span {
      color: #9bf3b8;
      font-size: 0.85rem;
    }
    #pwaBanner button {
      background: #1f5f1f;
      border: 1px solid #4ea84e;
      color: #fff;
      border-radius: 8px;
      padding: 8px 16px;
      cursor: pointer;
      font-family: inherit;
      font-size: 0.85rem;
    }
    #pwaClose {
      background: transparent;
      border: none;
      color: #7ab87a;
      font-size: 1.2rem;
      cursor: pointer;
      padding: 4px 8px;
    }
  </style>
</head>
<body>
  <div id="pwaBanner">
    <span>Instalar como app para usar offline</span>
    <button id="pwaInstallBtn">Instalar</button>
    <button id="pwaClose">X</button>
  </div>

  <button id="toolsBtn" type="button">Herramientas</button>
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
          <option value="DSEGModern, 'DSEG7 Modern', monospace">DSEG Modern</option>
          <option value="DSEGMini, 'DSEG14 Classic Mini', monospace">DSEG Mini</option>
          <option value="DSEGModernMini, 'DSEG7 Modern Mini', monospace">DSEG Modern Mini</option>
          <option value="'Roboto', Arial, sans-serif">Roboto</option>
          <option value="'Orbitron', 'Roboto', sans-serif">Orbitron</option>
          <option value="'Audiowide', 'Roboto', sans-serif">Audiowide</option>
          <option value="monospace">Monospace</option>
        </select>
      </div>
      <div class="row"><label for="displayStyle">Visualizacion hora</label>
        <select id="displayStyle">
          <option value="classic">LCD clasico</option>
          <option value="flip">Flip cards</option>
        </select>
      </div>
      <div class="switch-row"><label for="colorMode">Modo a color</label>
        <label class="switch"><input id="colorMode" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="row"><label for="colorStyle">Estilo color</label>
        <select id="colorStyle">
          <option value="rainbow">Arcoiris</option>
          <option value="fluid">Fluido</option>
          <option value="mono">Degradado 1 color</option>
          <option value="aurora">Aurora</option>
          <option value="plasma">Plasma neon</option>
          <option value="random">Aleatorio</option>
        </select>
      </div>
      <div class="switch-row"><label for="shadowMode">Sombra en hora</label>
        <label class="switch"><input id="shadowMode" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="row"><label for="shadowColor">Color sombra</label><input id="shadowColor" type="color" value="#00e676"></div>
      <div class="row"><label for="shadowStrength">Grosor sombra</label><input id="shadowStrength" type="range" min="0" max="24" step="1" value="8"></div>
      <div class="switch-row"><label for="glowMode">Glow alrededor de la hora</label>
        <label class="switch"><input id="glowMode" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="row"><label for="glowColor">Color glow</label><input id="glowColor" type="color" value="#00e676"></div>
      <div class="row"><label for="glowStrength">Grosor glow</label><input id="glowStrength" type="range" min="0" max="40" step="1" value="12"></div>
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

    <div class="section">
      <h3>Extras</h3>
      <div class="switch-row"><label for="breatheToggle">Efecto Respiracion</label>
        <label class="switch"><input id="breatheToggle" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="hour24Toggle">Formato 24 horas</label>
        <label class="switch"><input id="hour24Toggle" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="secondsToggle">Mostrar Segundos</label>
        <label class="switch"><input id="secondsToggle" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="moonToggle">Fase Lunar</label>
        <label class="switch"><input id="moonToggle" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="salesToggle">Ventas Diarias</label>
        <label class="switch"><input id="salesToggle" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="clientsToggle">Clientes Diarios</label>
        <label class="switch"><input id="clientsToggle" type="checkbox" checked><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="mirrorToggle">Modo Espejo</label>
        <label class="switch"><input id="mirrorToggle" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="switch-row"><label for="presentToggle">Modo Presentacion</label>
        <label class="switch"><input id="presentToggle" type="checkbox"><span class="switch-slider"></span></label>
      </div>
      <div class="split" style="margin-top:10px;">
        <button id="exportBtn" type="button">Exportar</button>
        <button id="importBtn" type="button">Importar</button>
      </div>
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
      <div id="secondsLine"></div>
      <div id="moonPhaseLine"></div>
      <div id="salesLine"></div>
      <div id="clientsLine"></div>
    </section>
  </main>

  <div id="presentationSlides" class="presentation-slides">
    <div class="slide" data-slide="clock">
      <div class="slide-clock-time" id="slideClockTime">--:--</div>
      <div class="slide-clock-date" id="slideClockDate"></div>
    </div>
    <div class="slide" data-slide="weather">
      <div class="slide-emoji" id="slideWeatherEmoji"></div>
      <div class="slide-weather-text" id="slideWeatherText">--</div>
      <div class="slide-weather-detail" id="slideWeatherDetail"></div>
    </div>
    <div class="slide" data-slide="stats">
      <div class="slide-stats-sales" id="slideSalesTotal">$0.00</div>
      <div class="slide-stats-detail" id="slideSalesDetail">0 transacciones</div>
      <div class="slide-stats-clients" id="slideClientsTotal">0 clientes</div>
    </div>
  </div>

  <div id="timerPanel" class="timer-panel" style="display:none;">
    <div class="timer-tabs">
      <button class="timer-tab active" data-tab="pomodoro">Pomodoro</button>
      <button class="timer-tab" data-tab="stopwatch">Cronometro</button>
      <button class="timer-tab" data-tab="countdown">Countdown</button>
    </div>
    <div id="pomodoroTab" class="timer-content active">
      <div id="pomodoroDisplay" class="timer-display">25:00</div>
      <div class="timer-controls">
        <button id="pomodoroStart" type="button">Iniciar</button>
        <button id="pomodoroReset" type="button">Reset</button>
      </div>
      <div class="timer-sets">
        <button class="pomo-set" data-min="25">25m</button>
        <button class="pomo-set" data-min="5">5m</button>
        <button class="pomo-set" data-min="15">15m</button>
      </div>
    </div>
    <div id="stopwatchTab" class="timer-content">
      <div id="stopwatchDisplay" class="timer-display">00:00:00</div>
      <div class="timer-controls">
        <button id="stopwatchStart" type="button">Iniciar</button>
        <button id="stopwatchLap" type="button">Vuelta</button>
        <button id="stopwatchReset" type="button">Reset</button>
      </div>
      <div id="lapTimes" class="lap-times"></div>
    </div>
    <div id="countdownTab" class="timer-content">
      <div id="countdownDisplay" class="timer-display">00:00:00</div>
      <div class="timer-inputs">
        <input type="number" id="countdownMin" min="0" max="999" placeholder="Min" value="5">
        <input type="number" id="countdownSec" min="0" max="59" placeholder="Seg" value="0">
      </div>
      <div class="timer-controls">
        <button id="countdownStart" type="button">Iniciar</button>
        <button id="countdownReset" type="button">Reset</button>
      </div>
    </div>
  </div>

  <div id="eventPanel" class="event-panel" style="display:none;">
    <h3>Recordatorio de Evento</h3>
    <div class="row"><label for="eventTitle">Titulo</label><input id="eventTitle" type="text" placeholder="Nombre del evento"></div>
    <div class="row"><label for="eventDate">Fecha</label><input id="eventDate" type="datetime-local"></div>
    <div class="row"><label for="eventNotify">Notificar</label>
      <label class="switch"><input id="eventNotify" type="checkbox" checked><span class="switch-slider"></span></label>
    </div>
    <div class="timer-controls">
      <button id="eventSave" type="button">Guardar</button>
      <button id="eventClear" type="button">Borrar</button>
    </div>
    <div id="eventsList" class="events-list"></div>
  </div>

  <div id="alarmHistory" class="alarm-history"></div>

  <script>
    const STORAGE_KEY = "clock_lcd_settings_v6";
    const DEFAULT_DAYS = "1,2,3,4,5,6,0";
    const DEFAULTS = {
      bg: "#000000",
      clockBg: "#0a100a",
      clockBorder: "#143314",
      clockText: "#00e676",
      dateColor: "#87d4a5",
      weatherColor: "#b7f3ce",
      fontFamily: "Courier New, Lucida Console, monospace",
      displayStyle: "classic",
      colorMode: true,
      colorStyle: "rainbow",
      shadowMode: true,
      shadowColor: "#00e676",
      shadowStrength: "8",
      glowMode: false,
      glowColor: "#00e676",
      glowStrength: "12",
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
      alarm2Days: DEFAULT_DAYS,
      breathe: false,
      hour24: false,
      seconds: true
    };

    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);
    const panel = $("#panel");
    const customizeBtn = $("#customizeBtn");
    const alarmLine = $("#alarmLine");
    const secondsLine = $("#secondsLine");
    const timerPanel = $("#timerPanel");
    const eventPanel = $("#eventPanel");
    const alarmHistory = $("#alarmHistory");

    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    let alarmInterval = null;
    let alarmTimeout = null;
    let alarmActiveId = "";
    const lastAlarmMinuteKey = { "1": "", "2": "" };
    let rainbowPatternIndex = 0;
    let rainbowMoveIndex = 0;
    let currentRandomColorStyle = "";
    let lastRandomHourKey = "";
    let lastRenderedClockKey = "";
    let lastAppliedColorSignature = "";
    let breatheEnabled = false;
    let use24Hour = false;
    let lastAlarmHistory = [];

    let pomodoroTime = 25 * 60;
    let pomodoroInterval = null;
    let pomodoroRunning = false;
    let pomodoroSet = 25;

    let stopwatchTime = 0;
    let stopwatchInterval = null;
    let stopwatchRunning = false;
    let lapTimes = [];

    let countdownTime = 5 * 60;
    let countdownInterval = null;
    let countdownRunning = false;

let events = [];

    let presentationMode = false;
    let presentationInterval = null;
    let currentSlide = 0;
    let mirrorMode = false;

    const moonPhases = [
      { name: "Luna Nueva", emoji: "🌑" },
      { name: "Luna Creciente", emoji: "🌒" },
      { name: "Cuarto Creciente", emoji: "🌓" },
      { name: "Gibosa Creciente", emoji: "🌔" },
      { name: "Luna Llena", emoji: "🌕" },
      { name: "Gibosa Menguante", emoji: "🌖" },
      { name: "Cuarto Menguante", emoji: "🌗" },
      { name: "Luna Menguante", emoji: "🌘" }
    ];

    function getMoonPhase(date = new Date()) {
      const year = date.getFullYear();
      const month = date.getMonth() + 1;
      const day = date.getDate();
      let c = 0;
      if (month < 3) {
        c = 365.25 * (year - 1) + 30.6001 * (month + 13) + day;
      } else {
        c = 365.25 * year + 30.6001 * (month + 1) + day;
      }
      const jd = c - 694039.09;
      const phase = jd / 29.5305882;
      const phaseNum = Math.floor((phase - Math.floor(phase)) * 8) % 8;
      return moonPhases[phaseNum];
    }

    function PresentationModeController() {
      this.slideDuration = 8000;
      this.slides = ["clock", "weather", "stats"];
      this.start = function() {
        presentationMode = true;
        document.body.classList.add("presentation-mode");
        $("#presentationSlides").classList.add("active");
        currentSlide = 0;
        this.showSlide(0);
        presentationInterval = setInterval(function() {
          presentation.showNext();
        }.bind(this), this.slideDuration);
      };
      this.stop = function() {
        presentationMode = false;
        document.body.classList.remove("presentation-mode");
        $("#presentationSlides").classList.remove("active");
        if (presentationInterval) clearInterval(presentationInterval);
        presentationInterval = null;
      };
      this.showNext = function() {
        var prev = currentSlide;
        currentSlide = (currentSlide + 1) % this.slides.length;
        this.animateTransition(prev, currentSlide);
      };
      this.animateTransition = function(fromIdx, toIdx) {
        var slideEls = document.querySelectorAll(".slide");
        slideEls[fromIdx].classList.remove("active");
        slideEls[fromIdx].classList.add("prev");
        slideEls[toIdx].classList.add("active");
        setTimeout(function() {
          slideEls[fromIdx].classList.remove("prev");
        }, 800);
        this.updateSlideContent(toIdx);
      };
      this.showSlide = function(idx) {
        var slideEls = document.querySelectorAll(".slide");
        for (var i = 0; i < slideEls.length; i++) {
          slideEls[i].classList.remove("active", "prev");
        }
        slideEls[idx].classList.add("active");
        this.updateSlideContent(idx);
      };
      this.updateSlideContent = function(idx) {
        if (idx === 0) {
          var now = new Date();
          var h = String(now.getHours()).padStart(2, "0");
          var m = String(now.getMinutes()).padStart(2, "0");
          var s = String(now.getSeconds()).padStart(2, "0");
          $("#slideClockTime").textContent = h + ":" + m + ":" + s;
          $("#slideClockDate").textContent = formatDateEs(now);
        } else if (idx === 1) {
          var wt = $("#weatherLine").textContent;
          var code = $("#weatherLine").dataset.code || localStorage.getItem("last_weather_code") || "0";
          var txt = weatherCodeText(code);
          var emoji = txt.match(/[\uD800-\uDBFF][\uDC00-\uDFFF]|\uD83C[\uDDE6-\uDDFF]|[\u2000-\u3300]/g);
          
          $("#slideWeatherText").textContent = wt;
          $("#slideWeatherEmoji").textContent = emoji ? emoji[0] : "☀️";
          $("#slideWeatherDetail").textContent = txt.replace(emoji ? emoji[0] : "", "").trim();
        } else if (idx === 2) {
          var st = $("#salesLine").textContent || "$0.00";
          var ct = $("#clientsLine").textContent || "0";
          $("#slideSalesTotal").textContent = st.replace("Ventas hoy: ", "");
          var parts = ct.match(/\d+/);
          $("#slideClientsTotal").textContent = (parts ? parts[0] : "0") + " clientes";
        }
      };
    }
    var presentation = new PresentationModeController();

    const rainbowPatterns = [
      "linear-gradient(90deg, #ff004c, #ff7a00, #ffe600, #00d26a, #00b7ff, #6c5cff, #ff00c8)",
      "linear-gradient(120deg, #ff3d00, #ffd600, #00e5ff, #2979ff, #d500f9, #ff1744)",
      "linear-gradient(100deg, #f50057, #ff9100, #c6ff00, #00c853, #00b0ff, #651fff)",
      "linear-gradient(135deg, #ff1744, #ffea00, #00e676, #00b8d4, #7c4dff, #f50057)"
    ];
    const auroraPatterns = [
      "linear-gradient(120deg, rgba(0,255,163,1), rgba(0,187,249,1), rgba(118,92,255,1), rgba(255,0,200,1), rgba(0,255,163,1))",
      "linear-gradient(120deg, rgba(84,255,190,1), rgba(0,224,255,1), rgba(112,122,255,1), rgba(255,119,198,1), rgba(84,255,190,1))",
      "linear-gradient(120deg, rgba(0,255,207,1), rgba(0,151,255,1), rgba(150,94,255,1), rgba(255,84,161,1), rgba(0,255,207,1))"
    ];
    const plasmaPatterns = [
      "linear-gradient(90deg, #ff006e, #fb5607, #ffbe0b, #00f5d4, #3a86ff, #8338ec, #ff006e)",
      "linear-gradient(90deg, #ff4d6d, #ff9e00, #f8f32b, #00e5ff, #6a4cff, #ff4d6d)",
      "linear-gradient(90deg, #ff0054, #ff7b00, #ffe600, #00ffc6, #4d96ff, #b517ff, #ff0054)"
    ];
    const driftVectors = [
      { x: 20, y: 0 },
      { x: -20, y: 0 },
      { x: 0, y: 20 },
      { x: 0, y: -20 }
    ];
    let fluidPalette = [];

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
      },
      onTimer: () => {
        Synth.playTone(880, "sine", 0.15, 0.18);
      },
      onTimerEnd: () => {
        Synth.playTone(1047, "sine", 0.3, 0.22);
        setTimeout(() => Synth.playTone(1319, "sine", 0.4, 0.24), 300);
        setTimeout(() => Synth.playTone(1568, "sine", 0.5, 0.2), 600);
      }
    };

    const Pomodoro = {
      formatTime: (secs) => {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
      },
      start: () => {
        if (pomodoroRunning) return;
        pomodoroRunning = true;
        $("#pomodoroStart").textContent = "Pausar";
        pomodoroInterval = setInterval(() => {
          pomodoroTime--;
          $("#pomodoroDisplay").textContent = Pomodoro.formatTime(pomodoroTime);
          if (pomodoroTime <= 0) {
            Pomodoro.stop();
            Synth.onTimerEnd();
            alarmLine.textContent = "Pomodoro completado!";
            setTimeout(() => alarmLine.textContent = "", 3000);
          }
        }, 1000);
      },
      stop: () => {
        pomodoroRunning = false;
        if (pomodoroInterval) clearInterval(pomodoroInterval);
        pomodoroInterval = null;
        $("#pomodoroStart").textContent = "Iniciar";
      },
      reset: () => {
        Pomodoro.stop();
        pomodoroTime = pomodoroSet * 60;
        $("#pomodoroDisplay").textContent = Pomodoro.formatTime(pomodoroTime);
      },
      setTime: (mins) => {
        Pomodoro.stop();
        pomodoroSet = mins;
        pomodoroTime = mins * 60;
        $("#pomodoroDisplay").textContent = Pomodoro.formatTime(pomodoroTime);
      }
    };

    const Stopwatch = {
      formatTime: (secs) => {
        const h = Math.floor(secs / 3600);
        const m = Math.floor((secs % 3600) / 60);
        const s = secs % 60;
        return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
      },
      start: () => {
        if (stopwatchRunning) return;
        stopwatchRunning = true;
        $("#stopwatchStart").textContent = "Pausar";
        stopwatchInterval = setInterval(() => {
          stopwatchTime++;
          $("#stopwatchDisplay").textContent = Stopwatch.formatTime(stopwatchTime);
        }, 1000);
      },
      stop: () => {
        stopwatchRunning = false;
        if (stopwatchInterval) clearInterval(stopwatchInterval);
        stopwatchInterval = null;
        $("#stopwatchStart").textContent = "Iniciar";
      },
      lap: () => {
        lapTimes.push({ time: stopwatchTime, label: lapTimes.length + 1 });
        const html = lapTimes.map(l => `<div>#${l.label}: ${Stopwatch.formatTime(l.time)}</div>`).join("");
        $("#lapTimes").innerHTML = html;
      },
      reset: () => {
        Stopwatch.stop();
        stopwatchTime = 0;
        lapTimes = [];
        $("#stopwatchDisplay").textContent = Stopwatch.formatTime(0);
        $("#lapTimes").innerHTML = "";
      }
    };

    const CountdownTimer = {
      formatTime: (secs) => {
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
      },
      start: () => {
        if (countdownRunning || countdownTime <= 0) return;
        countdownRunning = true;
        $("#countdownStart").textContent = "Pausar";
        if (!$("#countdownMin").value && !$("#countdownSec").value) {
          countdownTime = 5 * 60;
        }
        countdownInterval = setInterval(() => {
          countdownTime--;
          $("#countdownDisplay").textContent = CountdownTimer.formatTime(countdownTime);
          if (countdownTime <= 0) {
            CountdownTimer.stop();
            Synth.onTimerEnd();
            alarmLine.textContent = "Countdown terminado!";
            setTimeout(() => alarmLine.textContent = "", 3000);
          }
        }, 1000);
      },
      stop: () => {
        countdownRunning = false;
        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = null;
        $("#countdownStart").textContent = "Iniciar";
      },
      reset: () => {
        CountdownTimer.stop();
        const m = parseInt($("#countdownMin").value) || 5;
        const s = parseInt($("#countdownSec").value) || 0;
        countdownTime = m * 60 + s;
        $("#countdownDisplay").textContent = CountdownTimer.formatTime(countdownTime);
      }
    };

    const EventManager = {
      load: () => {
        try {
          const data = JSON.parse(localStorage.getItem("clock_events_v1") || "[]");
          events = data;
          EventManager.render();
        } catch (e) {}
      },
      save: () => {
        localStorage.setItem("clock_events_v1", JSON.stringify(events));
      },
      add: () => {
        const title = $("#eventTitle").value.trim();
        const date = $("#eventDate").value;
        if (!title || !date) return;
        events.push({ title, date: new Date(date).toISOString(), notify: $("#eventNotify").checked });
        EventManager.save();
        EventManager.render();
        $("#eventTitle").value = "";
        $("#eventDate").value = "";
      },
      remove: (idx) => {
        events.splice(idx, 1);
        EventManager.save();
        EventManager.render();
      },
      render: () => {
        const now = new Date();
        const html = events.map((e, i) => {
          const d = new Date(e.date);
          const diff = d - now;
          const future = diff > 0;
          const days = Math.floor(Math.abs(diff) / (1000 * 60 * 60 * 24));
          return `<div class="event-item">
            <span>${e.title} (${future ? "+" : "-"}${days}d)</span>
            <button onclick="EventManager.remove(${i})">X</button>
          </div>`;
        }).join("");
        $("#eventsList").innerHTML = html || "<div style='opacity:0.6;font-size:0.8rem;'>Sin eventos</div>";
      },
      check: (now) => {
        events.forEach((e, i) => {
          const eventTime = new Date(e.date).getTime();
          const nowMs = now.getTime();
          if (e.notify && Math.abs(eventTime - nowMs) < 2000) {
            Synth.onTimerEnd();
            alarmLine.textContent = `Evento: ${e.title}`;
          }
        });
      }
    };

    const SettingsIO = {
      export: () => {
        const data = localStorage.getItem(STORAGE_KEY);
        const blob = new Blob([data || "{}"], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "clock_settings.json";
        a.click();
        URL.revokeObjectURL(url);
      },
      import: () => {
        const input = document.createElement("input");
        input.type = "file";
        input.accept = ".json";
        input.onchange = (e) => {
          const file = e.target.files[0];
          const reader = new FileReader();
          reader.onload = (ev) => {
            try {
              localStorage.setItem(STORAGE_KEY, ev.target.result);
              loadSettings();
              alarmLine.textContent = "Settings importados!";
              setTimeout(() => alarmLine.textContent = "", 2000);
            } catch (err) {
              alarmLine.textContent = "Error importando";
              setTimeout(() => alarmLine.textContent = "", 2000);
            }
          };
          reader.readAsText(file);
        };
        input.click();
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
        displayStyle: $("#displayStyle").value,
        colorMode: $("#colorMode").checked,
        colorStyle: $("#colorStyle").value,
        shadowMode: $("#shadowMode").checked,
        shadowColor: $("#shadowColor").value,
        shadowStrength: $("#shadowStrength").value,
        glowMode: $("#glowMode").checked,
        glowColor: $("#glowColor").value,
        glowStrength: $("#glowStrength").value,
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
        alarm2Days: getCheckedDays("2") || DEFAULT_DAYS,
        breathe: !!$("#breatheToggle").checked,
        hour24: !!$("#hour24Toggle").checked,
        seconds: !!$("#secondsToggle").checked,
        mirror: !!$("#mirrorToggle").checked,
        present: !!$("#presentToggle").checked
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
      $("#displayStyle").value = s.displayStyle || DEFAULTS.displayStyle;
      $("#colorMode").checked = !!s.colorMode;
      $("#colorStyle").value = s.colorStyle || DEFAULTS.colorStyle;
      $("#shadowMode").checked = !!s.shadowMode;
      $("#shadowColor").value = s.shadowColor;
      $("#shadowStrength").value = s.shadowStrength;
      $("#glowMode").checked = !!s.glowMode;
      $("#glowColor").value = s.glowColor;
      $("#glowStrength").value = s.glowStrength;
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
      $("#breatheToggle").checked = !!s.breathe;
      $("#hour24Toggle").checked = !!s.hour24;
      $("#secondsToggle").checked = s.seconds !== false;
      $("#mirrorToggle").checked = !!s.mirror;
      $("#presentToggle").checked = !!s.present;
      applySettings(s);
    }

    function mixHex(hex, amount, toward = 255) {
      const raw = String(hex || "").replace("#", "");
      if (!/^[0-9a-fA-F]{6}$/.test(raw)) return hex || "#00e676";
      const parts = [raw.slice(0, 2), raw.slice(2, 4), raw.slice(4, 6)].map((p) => parseInt(p, 16));
      const mixed = parts.map((value) => {
        const next = Math.round(value + (toward - value) * amount);
        return Math.max(0, Math.min(255, next));
      });
      return "#" + mixed.map((v) => v.toString(16).padStart(2, "0")).join("");
    }

    function randomBrightColor() {
      const hue = Math.floor(Math.random() * 360);
      const sat = 70 + Math.floor(Math.random() * 25);
      const light = 50 + Math.floor(Math.random() * 12);
      return `hsl(${hue} ${sat}% ${light}%)`;
    }

    function buildFluidGradient() {
      if (!fluidPalette.length) {
        fluidPalette = new Array(5).fill(0).map(() => randomBrightColor());
      } else {
        fluidPalette = fluidPalette.map(() => randomBrightColor());
      }
      return `radial-gradient(circle at 10% 20%, ${fluidPalette[0]} 0%, transparent 34%),
              radial-gradient(circle at 88% 24%, ${fluidPalette[1]} 0%, transparent 32%),
              radial-gradient(circle at 22% 82%, ${fluidPalette[2]} 0%, transparent 34%),
              radial-gradient(circle at 74% 76%, ${fluidPalette[3]} 0%, transparent 30%),
              radial-gradient(circle at 50% 50%, ${fluidPalette[4]} 0%, rgba(255,255,255,0.08) 18%, transparent 48%)`;
    }

    function resolveColorStyle(now = new Date()) {
      const selected = $("#colorStyle").value || DEFAULTS.colorStyle;
      if (selected !== "random") return selected;
      const hourKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}-${String(now.getHours()).padStart(2, "0")}`;
      if (lastRandomHourKey !== hourKey || !currentRandomColorStyle) {
        const options = ["rainbow", "fluid", "mono", "aurora", "plasma"].filter((style) => style !== currentRandomColorStyle);
        currentRandomColorStyle = options[Math.floor(Math.random() * options.length)];
        lastRandomHourKey = hourKey;
      }
      return currentRandomColorStyle;
    }

    function syncColorStyleIfNeeded(now = new Date(), force = false) {
      const selected = $("#colorStyle").value || DEFAULTS.colorStyle;
      const hourKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}-${String(now.getHours()).padStart(2, "0")}`;
      const resolved = resolveColorStyle(now);
      const signature = `${selected}|${resolved}|${selected === "random" ? hourKey : ""}|${$("#clockText").value}`;
      if (!force && signature === lastAppliedColorSignature) return resolved;
      applyColorStyle(selected, $("#clockText").value, now);
      lastAppliedColorSignature = signature;
      return resolved;
    }

    function applyColorStyle(style, baseColor, now = new Date()) {
      const root = document.documentElement;
      const selected = String(resolveColorStyle(now) || "rainbow");
      let fill = rainbowPatterns[rainbowPatternIndex % rainbowPatterns.length];
      let size = "140vw 100%";
      let animation = "rainbow-flow-rtl 7s linear infinite";
      let attachment = "fixed";

      if (selected === "fluid") {
        fill = buildFluidGradient();
        size = "170% 170%, 170% 170%, 170% 170%, 170% 170%, 180% 180%";
        animation = "fluid-flow 11s ease-in-out infinite";
        attachment = "scroll";
      } else if (selected === "mono") {
        const bright = mixHex(baseColor, 0.62, 255);
        const deep = mixHex(baseColor, 0.38, 0);
        const core = mixHex(baseColor, 0.18, 255);
        fill = `linear-gradient(90deg, ${deep}, ${baseColor}, ${core}, ${bright}, ${baseColor}, ${deep})`;
        size = "220% 100%";
        animation = "prism-glint 8s ease-in-out infinite";
      } else if (selected === "aurora") {
        fill = auroraPatterns[rainbowPatternIndex % auroraPatterns.length];
        size = "230% 230%";
        animation = "aurora-sweep 10s ease-in-out infinite";
      } else if (selected === "plasma") {
        fill = plasmaPatterns[rainbowPatternIndex % plasmaPatterns.length];
        size = "220% 100%";
        animation = "plasma-wave 8s ease-in-out infinite";
      }

      // Transicion suave: fade in del nuevo gradiente
      const timeTextEls = document.querySelectorAll("#clockLine .time-text");
      timeTextEls.forEach(el => {
        el.style.opacity = "0.3";
        el.style.transition = "opacity 0.6s ease";
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            el.style.opacity = "1";
          });
        });
      });

      root.style.setProperty("--color-fill", fill.replace(/\s{2,}/g, " ").trim());
      root.style.setProperty("--color-fill-size", size);
      root.style.setProperty("--color-flow-animation", animation);
      root.style.setProperty("--color-fill-attachment", attachment);
    }

    function applySettings(s) {
      const root = document.documentElement;
      const selectedFont = String(s.fontFamily || "");
      const isDseg =
        selectedFont.includes("DSEGDisplay") ||
        selectedFont.includes("DSEGModern") ||
        selectedFont.includes("DSEGMini") ||
        selectedFont.includes("DSEGModernMini") ||
        selectedFont.includes("DSEG7 Classic") ||
        selectedFont.includes("DSEG14 Classic") ||
        selectedFont.includes("DSEG7 Modern") ||
        selectedFont.includes("DSEG14 Classic Mini") ||
        selectedFont.includes("DSEG7 Modern Mini");
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
      const shadowStrengthValue = Math.max(0, parseFloat(s.shadowStrength || 0));
      const shadowBlur = `${shadowStrengthValue}px`;
      const shadowTextBlur = `${Math.max(1, shadowStrengthValue * 0.72).toFixed(2)}px`;
      const shadowOffsetX = `${Math.max(0, shadowStrengthValue * 0.12).toFixed(2)}px`;
      const shadowOffsetY = `${Math.max(1, shadowStrengthValue * 0.34).toFixed(2)}px`;
      const shadowColor = hexToRgba(s.shadowColor || s.clockText, 0.48);
      const glowBlurValue = Math.max(0, parseFloat(s.glowStrength || 0));
      const glowBlur = `${glowBlurValue}px`;
      const glowColor = hexToRgba(s.glowColor || s.shadowColor || s.clockText, 0.88);
      const lineFilters = [];
      if (Number(s.glowMode) && glowBlurValue > 0) {
        lineFilters.push(`drop-shadow(0 0 ${glowBlur} ${glowColor})`);
        lineFilters.push(`drop-shadow(0 0 ${(glowBlurValue * 1.9).toFixed(2)}px ${hexToRgba(s.glowColor || s.shadowColor || s.clockText, 0.55)})`);
      }
      root.style.setProperty("--shadow-color", shadowColor);
      root.style.setProperty("--shadow-blur", shadowBlur);
      root.style.setProperty("--glow-color", glowColor);
      root.style.setProperty("--clock-offset-x", "0px");
      root.style.setProperty("--clock-offset-y", "0px");

      // Sombra 3D multicapa mas rica
      if (s.shadowMode && shadowStrengthValue > 0) {
        const sc = s.shadowColor || s.clockText;
        const sc2 = mixHex(sc, 0.35, 0);
        const sc3 = mixHex(sc, 0.55, 0);
        const ox = shadowStrengthValue * 0.12;
        const oy = shadowStrengthValue * 0.34;
        const blur1 = shadowStrengthValue * 0.72;
        const blur2 = shadowStrengthValue * 1.1;
        const blur3 = shadowStrengthValue * 1.8;
        const layers = [
          `${ox.toFixed(1)}px ${oy.toFixed(1)}px ${blur1.toFixed(1)}px ${hexToRgba(sc3, 0.55)}`,
          `${(ox * 2).toFixed(1)}px ${(oy * 2.2).toFixed(1)}px ${blur2.toFixed(1)}px ${hexToRgba(sc2, 0.35)}`,
          `${(ox * 3).toFixed(1)}px ${(oy * 3.5).toFixed(1)}px ${blur3.toFixed(1)}px ${hexToRgba(sc, 0.18)}`
        ];
        // Efecto de relieve interior (bevel)
        layers.unshift(`-1px -1px 0px ${hexToRgba(sc, 0.12)}`);
        root.style.setProperty("--time-shadow", layers.join(", "));
        root.style.setProperty("--segment-shadow", `drop-shadow(${ox.toFixed(1)}px ${oy.toFixed(1)}px ${Math.max(1, shadowStrengthValue * 0.82).toFixed(2)}px ${hexToRgba(sc3, 0.5)})`);
      } else {
        root.style.setProperty("--time-shadow", "none");
        root.style.setProperty("--segment-shadow", "none");
      }

      root.style.setProperty("--line-filter", lineFilters.length ? lineFilters.join(" ") : "none");
      applyColorStyle(s.colorStyle || DEFAULTS.colorStyle, s.clockText);
      lastAppliedColorSignature = "";
      syncColorStyleIfNeeded(new Date(), true);
      const line = $("#clockLine");
      line.style.fontFamily = isDseg ? selectedFont : "";
      line.classList.toggle("colorful", !!s.colorMode);
      line.classList.toggle("flip-mode", (s.displayStyle || DEFAULTS.displayStyle) === "flip");
      lastRenderedClockKey = "";

      breatheEnabled = !!s.breathe;
      use24Hour = !!s.hour24;
      secondsLine.style.display = s.seconds !== false ? "" : "none";
      mirrorMode = !!s.mirror;
      document.body.classList.toggle("mirror-mode", mirrorMode);
      if (!!s.present) {
        presentation.start();
      }
    }

    function buildFlipCardMarkup(char) {
      return `<span class="flip-card current"><span class="flip-glyph time-text">${char}</span></span>`;
    }

    function buildFlipClock(hoursText, minutesText, useAmpm, ampm) {
      const chars = [...`${hoursText}${minutesText}`];
      return `<span class="flip-clock">
        <span class="flip-group">
          <span class="flip-digit" data-char="${chars[0]}">${buildFlipCardMarkup(chars[0])}</span>
          <span class="flip-digit" data-char="${chars[1]}">${buildFlipCardMarkup(chars[1])}</span>
        </span>
        <span class="flip-sep time-text sep">:</span>
        <span class="flip-group">
          <span class="flip-digit" data-char="${chars[2]}">${buildFlipCardMarkup(chars[2])}</span>
          <span class="flip-digit" data-char="${chars[3]}">${buildFlipCardMarkup(chars[3])}</span>
        </span>
        <span class="ampm">${ampm}</span>
      </span>`;
    }

    function animateFlipDigit(node, nextChar) {
      const currentChar = node.dataset.char || "";
      if (currentChar === nextChar) return;
      const currentCard = node.querySelector(".flip-card.current");
      if (currentCard) {
        currentCard.classList.remove("current");
        currentCard.classList.add("exit");
      }
      const enter = document.createElement("span");
      enter.className = "flip-card enter";
      enter.innerHTML = `<span class="flip-glyph time-text">${nextChar}</span>`;
      node.appendChild(enter);
      node.dataset.char = nextChar;
      const cleanup = () => {
        node.querySelectorAll(".flip-card").forEach((card) => card.remove());
        node.innerHTML = buildFlipCardMarkup(nextChar);
      };
      enter.addEventListener("animationend", cleanup, { once: true });
    }

    function renderFlipTimeLine(hoursText, minutesText, useAmpm, ampm) {
      const line = $("#clockLine");
      const key = `${hoursText}:${minutesText}:${useAmpm ? ampm : "-"}`;
      const chars = [...`${hoursText}${minutesText}`];
      if (!line.querySelector(".flip-clock")) {
        line.innerHTML = buildFlipClock(hoursText, minutesText, useAmpm, ampm);
      } else {
        const digits = line.querySelectorAll(".flip-digit");
        digits.forEach((digit, idx) => animateFlipDigit(digit, chars[idx] || "0"));
        const ampmNode = line.querySelector(".ampm");
        if (ampmNode) ampmNode.textContent = ampm;
      }
      const ampmNode = line.querySelector(".ampm");
      if (ampmNode) ampmNode.style.display = useAmpm ? "" : "none";
      line.classList.toggle("colorful", $("#colorMode").checked);
      lastRenderedClockKey = key;
    }

    function renderClassicTimeLine(hoursText, minutesText, useAmpm, ampm) {
      const line = $("#clockLine");
      const key = `${hoursText}:${minutesText}:${useAmpm ? ampm : "-"}`;
      if (lastRenderedClockKey !== key || line.querySelector(".flip-clock")) {
        line.innerHTML = `<span class="h time-text">${hoursText}</span><span class="sep time-text">:</span><span class="m time-text">${minutesText}</span><span class="ampm">${ampm}</span>`;
        lastRenderedClockKey = key;
      }
      $("#clockLine .ampm").style.display = useAmpm ? "" : "none";
      line.classList.toggle("colorful", $("#colorMode").checked);
    }

    function renderTimeLine(hoursText, minutesText, useAmpm, ampm) {
      const style = $("#displayStyle").value || DEFAULTS.displayStyle;
      $("#clockLine").classList.toggle("flip-mode", style === "flip");
      if (style === "flip") renderFlipTimeLine(hoursText, minutesText, useAmpm, ampm);
      else renderClassicTimeLine(hoursText, minutesText, useAmpm, ampm);
    }

    function rotateRainbowPattern() {
      if (!$("#colorMode").checked) return;
      const selected = $("#colorStyle").value || DEFAULTS.colorStyle;
      const style = resolveColorStyle(new Date());
      if (selected === "random") return;
      if (style === "fluid") {
        fluidPalette = [];
      } else {
        const pool = style === "aurora" ? auroraPatterns : style === "plasma" ? plasmaPatterns : rainbowPatterns;
        rainbowPatternIndex = (rainbowPatternIndex + 1) % pool.length;
        document.documentElement.style.setProperty("--rainbow-gradient", pool[rainbowPatternIndex]);
      }
      lastAppliedColorSignature = "";
      syncColorStyleIfNeeded(new Date(), true);
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
      const c = Number(code);
      const map = {
        0: "Despejado ☀️", 1: "Mayormente despejado 🌤️", 2: "Parcialmente nublado ⛅", 3: "Nublado ☁️",
        45: "Niebla 🌫️", 48: "Niebla escarchada 🌫️", 51: "Llovizna ligera 🌦️", 53: "Llovizna moderada 🌦️", 55: "Llovizna intensa 🌦️",
        61: "Lluvia ligera 🌧️", 63: "Lluvia moderada 🌧️", 65: "Lluvia fuerte 🌧️", 71: "Nieve ligera 🌨️", 73: "Nieve moderada 🌨️", 75: "Nieve fuerte 🌨️",
        77: "Granizo fino 🌨️", 80: "Chubascos ligeros 🌦️", 81: "Chubascos moderados 🌧️", 82: "Chubascos fuertes 🌧️", 
        85: "Chubascos de nieve 🌨️", 86: "Chubascos de nieve fuertes 🌨️", 95: "Tormenta ⛈️",
        96: "Tormenta con granizo ligero ⛈️", 99: "Tormenta con granizo fuerte ⛈️",
        113: "Despejado ☀️", 116: "Parcialmente nublado ⛅", 119: "Nublado ☁️", 122: "Muy nublado ☁️",
        143: "Niebla ligera 🌫️", 176: "Lluvia dispersa 🌦️", 179: "Nieve dispersa 🌨️", 182: "Aguanieve disperso 🌧️",
        200: "Tormenta cercana ⛈️", 227: "Nieve con viento 🌨️", 230: "Tormenta de nieve ❄️",
        248: "Niebla 🌫️", 260: "Niebla helada ❄️", 263: "Llovizna ligera 🌦️", 266: "Llovizna 🌦️",
        281: "Llovizna helada ❄️", 293: "Lluvia ligera 🌧️", 296: "Lluvia ligera 🌧️", 299: "Lluvia moderada 🌧️",
        302: "Lluvia moderada 🌧️", 305: "Lluvia fuerte 🌧️", 308: "Lluvia muy fuerte 🌧️",
        311: "Lluvia helada ligera ❄️", 314: "Lluvia helada fuerte ❄️", 317: "Aguanieve ligero 🌧️",
        320: "Aguanieve fuerte 🌧️", 323: "Nieve ligera 🌨️", 326: "Nieve ligera 🌨️", 329: "Nieve moderada 🌨️",
        332: "Nieve moderada 🌨️", 335: "Nieve fuerte 🌨️", 338: "Nieve muy fuerte 🌨️",
        350: "Granizo 🌨️", 353: "Chubasco ligero 🌦️", 356: "Chubasco fuerte 🌧️", 359: "Chubasco torrencial 🌧️",
        362: "Aguanieve ligero 🌧️", 365: "Aguanieve fuerte 🌧️", 368: "Nieve ligera 🌨️", 371: "Nieve fuerte 🌨️",
        386: "Lluvia con truenos ⛈️", 389: "Tormenta con lluvia ⛈️", 392: "Nieve con truenos ⛈️", 395: "Tormenta con nieve ⛈️"
      };
      return map[c] || "Condicion variable 🌀";
    }

    async function loadHavanaWeather() {
      const el = $("#weatherLine");
      const CACHE_KEY = "last_weather_cache";
      const CACHE_TIME_KEY = "last_weather_time";
      const CACHE_CODE_KEY = "last_weather_code";
      const CACHE_MAX_AGE = 15 * 60 * 1000; // 15 minutos

      // Intentar cargar desde cache local para mostrar algo inmediato
      try {
        const cached = localStorage.getItem(CACHE_KEY);
        const lastTime = localStorage.getItem(CACHE_TIME_KEY);
        if (cached && lastTime) {
          const age = Date.now() - parseInt(lastTime);
          if (age < CACHE_MAX_AGE) {
            el.textContent = cached;
            if (age < 5 * 60 * 1000) return;
          } else {
            el.textContent = cached + " (actualizando...)";
          }
        }
      } catch (e) {}

      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 8000); 

      try {
        const r = await fetch("simple_weather.php", { 
          cache: "no-store",
          signal: controller.signal 
        });
        clearTimeout(timeoutId);
        
        if (!r.ok) throw new Error("No response");
        const d = await r.json();
        if (d.error) throw new Error(d.error);
        
        const code = String(d.code || "0");
        const weatherText = `${d.city}: ${weatherCodeText(code)} | Ahora ${d.current}°C | Max ${d.max} / Min ${d.min}`;
        
        el.textContent = weatherText;
        el.dataset.code = code; // Guardar codigo para presentacion
        
        localStorage.setItem(CACHE_KEY, weatherText);
        localStorage.setItem(CACHE_TIME_KEY, Date.now().toString());
        localStorage.setItem(CACHE_CODE_KEY, code);
        
      } catch (e) {
        clearTimeout(timeoutId);
        if (!el.textContent.includes(":")) {
          el.textContent = "La Habana, Cuba hoy: clima no disponible";
        }
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
        const useAmpm = use24Hour ? false : $("#ampmToggle").checked;
        const h = useAmpm ? (hours24 % 12 || 12) : hours24;
        const m = String(now.getMinutes()).padStart(2, "0");
        const ampm = hours24 < 12 ? "AM" : "PM";

        if ($("#colorMode").checked) syncColorStyleIfNeeded(now);
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

const sec = String(now.getSeconds()).padStart(2, "0");
        if (secondsLine.textContent !== sec) {
          secondsLine.textContent = sec;
          secondsLine.classList.toggle("breathe", breatheEnabled);
        }

        EventManager.check(now);
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
      fluidPalette = [];
      currentRandomColorStyle = "";
      lastRandomHourKey = "";
      lastRenderedClockKey = "";
      lastAppliedColorSignature = "";
      loadSettings();
    });

    loadSettings();
    bindAlarmDismiss();

    let showMoon = true;
    let showSales = true;
    let showClients = true;

    startClock();
    loadHavanaWeather();
    setInterval(loadHavanaWeather, 30 * 60 * 1000);
    updateMoonPhase();
    loadSalesMetrics();
    setInterval(loadSalesMetrics, 30 * 1000);
    EventManager.load();

    if ("serviceWorker" in navigator) {
      (async () => {
        const swCandidates = ["./service-worker.js", "./sw.js"];
        let lastError = null;

        for (const swPath of swCandidates) {
          try {
            const probe = await fetch(swPath, { method: "HEAD", cache: "no-store" });
            if (!probe.ok && probe.status !== 405) continue;
            await navigator.serviceWorker.register(swPath, { scope: "./" });
            console.log("Service Worker registered:", swPath);
            return;
          } catch (err) {
            lastError = err;
          }
        }

        console.log("Service Worker error:", lastError ? lastError.message : "No Service Worker script available");
      })();
    }

    let deferredPrompt = null;
    let pwaInstalled = false;

    window.addEventListener('beforeinstallprompt', function(e) {
      e.preventDefault();
      deferredPrompt = e;
      if (!pwaInstalled) document.getElementById('pwaBanner').classList.remove('hidden');
    });

    window.addEventListener('appinstalled', function() {
      pwaInstalled = true;
      document.getElementById('pwaBanner').classList.add('hidden');
      localStorage.setItem('pwaInstalled', 'true');
    });

    if (localStorage.getItem('pwaInstalled') === 'true') {
      pwaInstalled = true;
      document.getElementById('pwaBanner').classList.add('hidden');
    }

    document.getElementById('pwaInstallBtn').addEventListener('click', function() {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function(result) {
          if (result.outcome === 'accepted') {
            pwaInstalled = true;
            document.getElementById('pwaBanner').classList.add('hidden');
            localStorage.setItem('pwaInstalled', 'true');
          }
          deferredPrompt = null;
        });
      }
    });

    document.getElementById('pwaClose').addEventListener('click', function() {
      document.getElementById('pwaBanner').classList.add('hidden');
    });

    function updateMoonPhase() {
      const moon = getMoonPhase();
      const el = document.getElementById('moonPhaseLine');
      if (el && showMoon) el.textContent = moon.emoji + ' ' + moon.name;
      else if (el) el.textContent = '';
    }

    async function loadSalesMetrics() {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 6000);

      try {
        const r = await fetch('api_sales.php', { 
          cache: 'no-store',
          signal: controller.signal 
        });
        clearTimeout(timeoutId);
        
        if (!r.ok) throw new Error('http ' + r.status);
        const d = await r.json();
        const salesEl = document.getElementById('salesLine');
        const clientsEl = document.getElementById('clientsLine');
        if (salesEl) salesEl.textContent = showSales ? 'Ventas hoy: $' + d.total + ' (' + d.count + ' ventas)' : '';
        if (clientsEl) clientsEl.textContent = showClients ? 'Clientes hoy: ' + d.clients : '';
      } catch (e) {
        clearTimeout(timeoutId);
        // Silenciar error en consola, no afectar UI si ya hay contenido
      }
    }

    document.getElementById('moonToggle').addEventListener('change', function() {
      showMoon = this.checked;
      updateMoonPhase();
      saveSettings();
    });

    document.getElementById('salesToggle').addEventListener('change', function() {
      showSales = this.checked;
      loadSalesMetrics();
      saveSettings();
    });

    document.getElementById('clientsToggle').addEventListener('change', function() {
      showClients = this.checked;
      loadSalesMetrics();
      saveSettings();
    });

    $("#toolsBtn").addEventListener("click", () => {
      timerPanel.style.display = timerPanel.style.display === "none" ? "block" : "none";
      if (timerPanel.style.display === "block") eventPanel.style.display = "none";
    });

    $$(".timer-tab").forEach(btn => {
      btn.addEventListener("click", () => {
        $$(".timer-tab").forEach(b => b.classList.remove("active"));
        btn.classList.add("active");
        $$(".timer-content").forEach(c => c.classList.remove("active"));
        $(`#${btn.dataset.tab}Tab`).classList.add("active");
      });
    });

    $("#pomodoroStart").addEventListener("click", () => {
      if (pomodoroRunning) Pomodoro.stop();
      else Pomodoro.start();
    });
    $("#pomodoroReset").addEventListener("click", Pomodoro.reset);
    $$(".pomo-set").forEach(btn => {
      btn.addEventListener("click", () => Pomodoro.setTime(parseInt(btn.dataset.min)));
    });

    $("#stopwatchStart").addEventListener("click", () => {
      if (stopwatchRunning) Stopwatch.stop();
      else Stopwatch.start();
    });
    $("#stopwatchLap").addEventListener("click", Stopwatch.lap);
    $("#stopwatchReset").addEventListener("click", Stopwatch.reset);

    $("#countdownStart").addEventListener("click", () => {
      if (countdownRunning) CountdownTimer.stop();
      else CountdownTimer.start();
    });
    $("#countdownReset").addEventListener("click", CountdownTimer.reset);

    $("#eventSave").addEventListener("click", EventManager.add);
    $("#eventClear").addEventListener("click", () => {
      events = [];
      EventManager.save();
      EventManager.render();
    });

    $("#exportBtn").addEventListener("click", SettingsIO.export);
    $("#importBtn").addEventListener("click", SettingsIO.import);

    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        panel.classList.remove("open");
        timerPanel.style.display = "none";
        eventPanel.style.display = "none";
      }
      if (e.key.toLowerCase() === "f") {
        if (!e.isTrusted) return;
        if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {});
        else document.exitFullscreen().catch(() => {});
      }
      if (e.key.toLowerCase() === "m") {
        $("#soundToggle").checked = !$("#soundToggle").checked;
        saveSettings();
      }
      if (e.key.toLowerCase() === "n") {
        $("#nightMode").checked = !$("#nightMode").checked;
        saveSettings();
      }
      if (e.key.toLowerCase() === "c") {
        $("#colorMode").checked = !$("#colorMode").checked;
        saveSettings();
      }
      if (e.key === "1") {
        startAlarm("1");
      }
      if (e.key === "2") {
        startAlarm("2");
      }
      if (e.key.toLowerCase() === "t") {
        timerPanel.style.display = timerPanel.style.display === "none" ? "block" : "none";
        if (timerPanel.style.display === "block") eventPanel.style.display = "none";
      }
      if (e.key.toLowerCase() === "e") {
        eventPanel.style.display = eventPanel.style.display === "none" ? "block" : "none";
        if (eventPanel.style.display === "block") timerPanel.style.display = "none";
      }
      if (e.key.toLowerCase() === "p") {
        if (presentationMode) presentation.stop();
        else presentation.start();
      }
      if (e.key.toLowerCase() === "i") {
        mirrorMode = !mirrorMode;
        document.body.classList.toggle("mirror-mode", mirrorMode);
      }
    });
  </script>
</body>
</html>
