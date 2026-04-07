<?php
/**
 * ARCHIVO: simple_weather.php
 * Provee datos de clima para La Habana, Cuba con múltiples fuentes y caché local.
 * Versión compatible sin dependencia de CURL.
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, max-age=300');

$cacheFile = __DIR__ . '/weather_cache.json';
$cacheTime = 1800; // 30 minutos de caché

// 1. Intentar leer de caché activa
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedData) {
        echo $cachedData;
        exit;
    }
}

// 2. Fuentes de datos
$urls = [
    'wttr' => 'https://wttr.in/Havana?format=j1',
    'meteo' => 'https://api.open-meteo.com/v1/forecast?latitude=23.1136&longitude=-82.3666&current_weather=true&daily=temperature_2m_max,temperature_2m_min&timezone=America%2FHavana&forecast_days=1'
];

$finalResult = null;

foreach ($urls as $source => $url) {
    $opts = [
        'http' => [
            'method' => "GET",
            'header' => "User-Agent: PalWeb-POS/1.0 (compatible; Mozilla/5.0)\r\nAccept: application/json\r\n",
            'timeout' => 8
        ]
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $context);

    if ($resp) {
        $data = json_decode($resp, true);
        if (!$data) continue;

        if ($source === 'wttr' && isset($data['current_condition'])) {
            $cur = $data['current_condition'][0] ?? null;
            $weather = $data['weather'][0] ?? null;
            $finalResult = [
                'source' => 'wttr',
                'city' => 'La Habana',
                'current' => round($cur['temp_C'] ?? 0),
                'code' => $cur['weatherCode'] ?? '0',
                'max' => round($weather['maxtempC'] ?? 0),
                'min' => round($weather['mintempC'] ?? 0),
                'time' => time()
            ];
            break;
        }

        if ($source === 'meteo' && (isset($data['current_weather']) || isset($data['current']))) {
            $current = $data['current_weather'] ?? $data['current'];
            $finalResult = [
                'source' => 'open-meteo',
                'city' => 'La Habana',
                'current' => round($current['temperature'] ?? $current['temperature_2m'] ?? 0),
                'code' => $current['weathercode'] ?? $current['weather_code'] ?? '0',
                'max' => round($data['daily']['temperature_2m_max'][0] ?? 0),
                'min' => round($data['daily']['temperature_2m_min'][0] ?? 0),
                'time' => time()
            ];
            break;
        }
    }
}

// 3. Gestionar resultado y caché
if ($finalResult) {
    $jsonResult = json_encode($finalResult);
    @file_put_contents($cacheFile, $jsonResult);
    echo $jsonResult;
} else {
    if (file_exists($cacheFile)) {
        echo @file_get_contents($cacheFile);
    } else {
        http_response_code(503);
        echo json_encode(['error' => 'Servicio de clima no disponible']);
    }
}
