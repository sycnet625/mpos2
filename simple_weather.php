<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Cache-Control: no-cache, max-age=300');

$urls = [
    'https://wttr.in/Havana?format=j1',
    'https://api.open-meteo.com/v1/forecast?latitude=23.1136&longitude=-82.3666&current=temperature_2m,weather_code&daily=temperature_2m_max,temperature_2m_min&timezone=America%2FHavana&forecast_days=1'
];

foreach ($urls as $url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PalWeb/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $resp) {
        $data = json_decode($resp, true);
        if (!$data) continue;

        if (isset($data['current_condition'])) {
            $cur = $data['current_condition'][0] ?? null;
            $weather = $data['weather'][0] ?? null;
            $hourly = $weather['hourly'][0] ?? $cur;
            echo json_encode([
                'source' => 'wttr',
                'city' => 'La Habana',
                'current' => $cur['temp_C'] ?? '--',
                'code' => $cur['weatherCode'] ?? '0',
                'max' => $weather['maxtempC'] ?? '--',
                'min' => $weather['mintempC'] ?? '--'
            ]);
            exit;
        }

        if (isset($data['current'])) {
            echo json_encode([
                'source' => 'open-meteo',
                'city' => 'La Habana',
                'current' => round($data['current']['temperature_2m'] ?? 0),
                'code' => $data['current']['weather_code'] ?? '0',
                'max' => round($data['daily']['temperature_2m_max'][0] ?? 0),
                'min' => round($data['daily']['temperature_2m_min'][0] ?? 0)
            ]);
            exit;
        }
    }
}

echo json_encode(['error' => 'No disponible']);