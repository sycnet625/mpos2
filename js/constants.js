// ============================================================
// CONSTANTES Y CONFIGURACIÓN - MarcelCraft
// ============================================================

// Variables desde PHP (definidas en el HTML)
const SEED = PHP_SEED;
let MW = PHP_MW;
let MH = PHP_MH;

// Dimensiones de tiles
const TW = 64;  // Ancho del tile
const TH = 32;  // Alto del tile
const WH = 14;  // Altura por nivel

// Tipos de terreno
const T = {
    DEEP: 0,
    WATER: 1,
    SAND: 2,
    GRASS: 3,
    JUNGLE: 4,
    ROCK: 5,
    MOUNTAIN: 6,
    SNOW: 7
};

// Información de los terrenos
const TINFO = {
    [T.DEEP]:     { name: 'Agua Profunda',   emoji: '🌊', icon: '🌊' },
    [T.WATER]:    { name: 'Agua',            emoji: '💧', icon: '💧' },
    [T.SAND]:     { name: 'Arena',           emoji: '🏖️', icon: '🏝️' },
    [T.GRASS]:    { name: 'Césped',          emoji: '🌿', icon: '🌱' },
    [T.JUNGLE]:   { name: 'Jungla',          emoji: '🌴', icon: '🌳' },
    [T.ROCK]:     { name: 'Roca',            emoji: '⛰️', icon: '🪨' },
    [T.MOUNTAIN]: { name: 'Montaña',         emoji: '🏔️', icon: '🗻' },
    [T.SNOW]:     { name: 'Nieve',           emoji: '❄️', icon: '☃️' },
};

// Alturas por defecto según tipo
const DEFAULT_HEIGHT = {
    [T.DEEP]: 0,
    [T.WATER]: 0,
    [T.SAND]: 1,
    [T.GRASS]: 1,
    [T.JUNGLE]: 1,
    [T.ROCK]: 2,
    [T.MOUNTAIN]: 3,
    [T.SNOW]: 4
};

// Decoraciones válidas por tipo
const VALID_DECO = {
    [T.DEEP]: [null],
    [T.WATER]: ['lily', null],
    [T.SAND]: [null],
    [T.GRASS]: ['flower', null],
    [T.JUNGLE]: ['tree', 'palm', null],
    [T.ROCK]: ['boulder', null],
    [T.MOUNTAIN]: ['rock', null],
    [T.SNOW]: ['snowrock', null]
};

// Colores para minimapa
const MINI_C = {
    [T.DEEP]:     '#0e2244',
    [T.WATER]:    '#1a4a8a',
    [T.SAND]:     '#c8a96e',
    [T.GRASS]:    '#5a8a3c',
    [T.JUNGLE]:   '#1a5018',
    [T.ROCK]:     '#6a5a4a',
    [T.MOUNTAIN]: '#9a9080',
    [T.SNOW]:     '#dce8f8',
};

// Título del mapa
let mapTitle = PHP_TITLE;

// ============================================================
// VARIABLES GLOBALES DE CONFIGURACIÓN
// ============================================================
let waterAnimEnabled = true;  // Animación de agua activada por defecto
let dayNightEnabled = true;   // Ciclo día/noche activado por defecto

