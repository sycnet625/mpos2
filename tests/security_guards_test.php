<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
function runSecurityCode(string $code): string {
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=/tmp', '-r', $code], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc) !== 0) throw new RuntimeException($err);
    return $out;
}
$shutdown = 'register_shutdown_function(function(){echo "|HTTP:", http_response_code();});';
foreach (['pos_admin.php', 'pos_expenses.php', 'pos_expenses_api.php', 'terminal_api.php'] as $file) {
    $out = runSecurityCode($shutdown . '$_SERVER["REQUEST_METHOD"]="POST"; require ' . var_export($root . '/' . $file, true) . ';');
    if (!str_contains($out, '|HTTP:403')) throw new RuntimeException('No se bloqueó ' . $file);
}
$security = 'require ' . var_export($root . '/pos_security.php', true) . '; $_SESSION=["pos_csrf_token"=>"token-real"];';
$out = runSecurityCode($shutdown . $security . 'pos_security_require_csrf(["csrf_token"=>"falso"]);');
if (!str_contains($out, '|HTTP:403')) throw new RuntimeException('CSRF inválido aceptado');
$out = runSecurityCode($security . '$_SERVER["HTTP_X_CSRF_TOKEN"]="token-real"; pos_security_require_csrf(); echo "OK";');
if ($out !== 'OK') throw new RuntimeException('CSRF válido rechazado');
$bucket = 'regression_' . bin2hex(random_bytes(8));
$rateCode = '$_SERVER["REMOTE_ADDR"]="192.0.2.123"; require ' . var_export($root . '/auth_rate_limit.php', true) . '; auth_rate_limit(' . var_export($bucket, true) . ', 2, 600); echo "OK";';
for ($i = 0; $i < 2; $i++) if (runSecurityCode($rateCode) !== 'OK') throw new RuntimeException('Límite prematuro');
$out = runSecurityCode($shutdown . $rateCode);
if (!str_contains($out, '|HTTP:429')) throw new RuntimeException('No se limitó el tercer intento en otro proceso');
unlink(sys_get_temp_dir() . '/palweb_auth_v2_' . hash('sha256', $bucket . '|192.0.2.123'));
echo "9 comprobaciones de acceso, CSRF y límites correctas.\n";
