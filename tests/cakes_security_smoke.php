<?php
declare(strict_types=1);

// Prueba de solo lectura contra una instalación accesible por HTTP(S).
// Uso: CAKES_TEST_BASE_URL=https://localhost php tests/cakes_security_smoke.php
$base = rtrim((string)getenv('CAKES_TEST_BASE_URL'), '/');
if ($base === '') {
    fwrite(STDERR, "Define CAKES_TEST_BASE_URL para ejecutar las pruebas.\n");
    exit(2);
}

function request(string $url, string $method = 'GET', string $body = ''): array
{
    $host = (string)getenv('CAKES_TEST_HOST');
    if ($host !== '' && !preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)) throw new RuntimeException('CAKES_TEST_HOST inválido.');
    $context = stream_context_create(['http'=>[
        'method'=>$method, 'ignore_errors'=>true, 'timeout'=>10,
        'header'=>"Content-Type: application/json\r\n" . ($host !== '' ? "Host: $host\r\n" : ''), 'content'=>$body,
    ], 'ssl'=>$host !== '' ? ['peer_name'=>$host, 'SNI_enabled'=>true] : []]);
    $content = file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    if ($content === false || !preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match)) {
        throw new RuntimeException('No se pudo conectar a la aplicación.');
    }
    return [(int)$match[1], $content];
}

try {
    foreach ([
        ['/cakes_estado.php?token=invalido', 'GET', '', 404],
        ['/reservas.php?cakes=1', 'GET', '', 403],
        ['/cakes_builder_api.php', 'POST', '{"csrf_token":"incorrecto"}', 403],
        ['/reservas.php', 'POST', '{"action":"cake_transition","id":0,"estado":"CONFIRMADO"}', 401],
        ['/reservas.php', 'POST', '{"action":"cake_abono","id":0,"monto":"100"}', 403],
    ] as [$path, $method, $body, $expected]) {
        [$status] = request($base . $path, $method, $body);
        if ($status !== $expected) throw new RuntimeException("$path: HTTP $status; esperado $expected");
        echo "$path: HTTP $status OK\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
