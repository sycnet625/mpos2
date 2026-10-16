<?php
// Pruebas sin conexión a BD ni escrituras de negocio.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/shop_checkout.php';

class CheckoutFixtureStatement extends PDOStatement {
    private array $params = [];
    public function __construct(private string $sql) {}
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (str_contains($this->sql, 'producto_variantes')) {
            return $this->params === ['SKU-1', 'Grande'] ? ['nombre' => 'Grande', 'precio_extra' => 20] : false;
        }
        return $this->params === [2, 'SKU-1', 1, 2]
            ? ['codigo' => 'SKU-1', 'nombre' => 'Producto', 'precio_base' => 100, 'precio_mayorista_base' => 80, 'precio_oferta_base' => 90]
            : false;
    }
}
class CheckoutFixturePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new CheckoutFixtureStatement($query); }
}
$pdo = new CheckoutFixturePDO();
$config = ['id_empresa' => 1, 'id_sucursal' => 2, 'id_almacen' => 3, 'tipo_cambio_usd' => 400,
    'metodos_pago' => [
        ['id' => 'Efectivo', 'activo' => true, 'aplica_shop' => true],
        ['id' => 'Transferencia', 'activo' => true, 'aplica_shop' => true, 'es_transferencia' => true],
    ]];
$base = ['tipo_servicio' => 'recogida', 'metodo_pago' => 'Efectivo', 'total' => 180,
    'items' => [['id' => 'SKU-1', 'qty' => 2, 'price' => 90]]];
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
function rejected(array $input, string $label): void {
    global $pdo, $config;
    try { shop_validate_checkout($pdo, $config, $input); }
    catch (InvalidArgumentException $e) { check(true, $label); return; }
    throw new RuntimeException('No se rechazó: ' . $label);
}
$result = shop_validate_checkout($pdo, $config, $base + ['estado_pago' => 'confirmado', 'tipo_cambio' => 0.01, 'moneda' => 'USD']);
check($result['estado_pago'] === 'pendiente', 'No aceptar pagos confirmados por el navegador');
check($result['tipo_cambio'] === 400.0 && $result['monto_moneda_original'] === 0.45, 'Tipo de cambio del servidor');
check($result['items'][0]['name'] === 'Producto' && $result['total'] === 180.0, 'Oferta y nombre del catálogo');
rejected(array_replace($base, ['total' => 1]), 'Total alterado');
rejected(array_replace($base, ['items' => [['id' => 'SKU-1', 'qty' => 2, 'price' => 1]], 'total' => 2]), 'Precio alterado');
foreach ([0, -1, INF, NAN, 'texto'] as $qty) {
    rejected(array_replace($base, ['items' => [['id' => 'SKU-1', 'qty' => $qty, 'price' => 90]]]), 'Cantidad inválida');
}
rejected($base + ['id_sucursal' => 99], 'Sucursal ajena');
rejected($base + ['id_almacen' => 99], 'Almacén ajeno');
rejected(array_replace($base, ['items' => [['id' => 'PRIVADO', 'qty' => 2, 'price' => 90]]]), 'Producto no publicado');
rejected(array_replace($base, ['metodo_pago' => 'INVENTADO']), 'Método no configurado');
rejected(array_replace($base, ['metodo_pago' => 'Transferencia']), 'Transferencia sin código');
$transfer = shop_validate_checkout($pdo, $config, array_replace($base, ['metodo_pago' => 'Transferencia', 'codigo_pago' => 'prueba', 'estado_pago' => 'confirmado']));
check($transfer['estado_pago'] === 'verificando', 'Transferencia requiere confirmación');
$delivery = array_replace($base, ['tipo_servicio' => 'domicilio', 'municipio' => 'Cerro', 'barrio' => 'El Canal', 'total' => 355]);
check(shop_validate_checkout($pdo, $config, $delivery)['total'] === 355.0, 'Tarifa de envío');
rejected(array_replace($delivery, ['total' => 180]), 'Envío omitido');
rejected(array_replace($delivery, ['barrio' => 'INVENTADO']), 'Destino no configurado');
$variant = array_replace($base, ['total' => 220, 'items' => [['id' => 'SKU-1', 'qty' => 2, 'price' => 110, 'variant' => ['nombre' => 'Grande', 'precio_extra' => -999]]]]);
check(shop_validate_checkout($pdo, $config, $variant)['total'] === 220.0, 'Variante calculada en servidor');
check(shop_apply_price_rule(101, ['shop_price_mode' => 'custom', 'shop_price_custom_pct' => 10, 'shop_price_round_to_5' => true]) === 115.0, 'Regla personalizada y redondeo');
check(shop_apply_price_rule(100, ['shop_price_mode' => 'mayorista'], 80) === 80.0, 'Precio mayorista');
echo "$checks comprobaciones de checkout correctas.\n";
