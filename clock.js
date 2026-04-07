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
          $("#slideWeatherText").textContent = wt;
          var moon = getMoonPhase();
          $("#slideWeatherEmoji").textContent = moon.emoji;
          $("#slideWeatherDetail").textContent = moon.name;
        } else if (idx === 2) {
          var st = $("#salesLine").textContent || "$0.00";
          var ct = $("#clientsLine").textContent || "0";
          $("#slideSalesTotal").textContent = st.replace("Ventas: ", "");
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
        0: "Despejado", 1: "Mayormente despejado", 2: "Parcialmente nublado", 3: "Nublado",
        45: "Niebla", 48: "Niebla escarchada", 51: "Llovizna ligera", 53: "Llovizna moderada", 55: "Llovizna intensa",
        61: "Lluvia ligera", 63: "Lluvia moderada", 65: "Lluvia fuerte", 71: "Nieve ligera", 73: "Nieve moderada", 75: "Nieve fuerte",
        80: "Chubascos ligeros", 81: "Chubascos moderados", 82: "Chubascos fuertes", 95: "Tormenta",
        113: "Despejado", 116: "Parcialmente nublado", 119: "Nublado", 122: "Muy nublado",
        143: "Niebla ligera", 176: "Lluvia dispersa", 179: "Nieve dispersa", 182: "Aguanieve disperso",
        200: "Tormenta cercana", 227: "Nieve con viento", 230: "Tormenta de nieve",
        248: "Niebla", 260: "Niebla helada", 263: "Llovizna ligera", 266: "Llovizna",
        281: "Llovizna helada", 293: "Lluvia ligera", 296: "Lluvia ligera", 299: "Lluvia moderada",
        302: "Lluvia moderada", 305: "Lluvia fuerte", 308: "Lluvia muy fuerte",
        311: "Lluvia helada ligera", 314: "Lluvia helada fuerte", 317: "Aguanieve ligero",
        320: "Aguanieve fuerte", 323: "Nieve ligera", 326: "Nieve ligera", 329: "Nieve moderada",
        332: "Nieve moderada", 335: "Nieve fuerte", 338: "Nieve muy fuerte",
        350: "Granizo", 353: "Chubasco ligero", 356: "Chubasco fuerte", 359: "Chubasco torrencial",
        362: "Aguanieve ligero", 365: "Aguanieve fuerte", 368: "Nieve ligera", 371: "Nieve fuerte",
        386: "Lluvia con truenos", 389: "Tormenta con lluvia", 392: "Nieve con truenos", 395: "Tormenta con nieve"
      };
      return map[c] || "Condicion variable";
    }

    async function loadHavanaWeather() {
      const el = $("#weatherLine");
      try {
        const r = await fetch("simple_weather.php", { cache: "no-store" });
        if (!r.ok) throw new Error("No response");
        const d = await r.json();
        if (d.error) throw new Error(d.error);
        const code = String(d.code || "0");
        el.textContent = `${d.city}: ${weatherCodeText(code)} | Ahora ${d.current}°C | Max ${d.max} / Min ${d.min}`;
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
      try {
        const r = await fetch('api_sales.php', { cache: 'no-store' });
        if (!r.ok) throw new Error('http ' + r.status);
        const d = await r.json();
        const salesEl = document.getElementById('salesLine');
        const clientsEl = document.getElementById('clientsLine');
        if (salesEl) salesEl.textContent = showSales ? 'Ventas hoy: $' + d.total + ' (' + d.count + ' ventas)' : '';
        if (clientsEl) clientsEl.textContent = showClients ? 'Clientes hoy: ' + d.clients : '';
      } catch (e) {
        const salesEl = document.getElementById('salesLine');
        const clientsEl = document.getElementById('clientsLine');
        if (salesEl) salesEl.textContent = '';
        if (clientsEl) clientsEl.textContent = '';
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
