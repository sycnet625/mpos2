<?php
require_once __DIR__ . '/shop_pricing.php';

// Validar el pedido antes de escribir: ningún importe ni ubicación proviene del cliente.
function shop_validate_checkout(PDO $pdo, array $config, array $input): array
{
    $empresa = (int)$config['id_empresa'];
    $sucursal = (int)$config['id_sucursal'];
    $almacen = (int)$config['id_almacen'];
    if ($empresa <= 0 || $sucursal <= 0 || $almacen <= 0) throw new InvalidArgumentException('Tienda sin configurar.');
    foreach (['id_empresa' => $empresa, 'id_sucursal' => $sucursal, 'id_almacen' => $almacen] as $key => $value) {
        if (isset($input[$key]) && (int)$input[$key] !== $value) throw new InvalidArgumentException('Ubicación del pedido inválida.');
    }
    $service = (string)($input['tipo_servicio'] ?? 'recogida');
    if (!in_array($service, ['reserva', 'domicilio', 'recogida', 'delivery', 'recoger', 'mostrador'], true)) {
        throw new InvalidArgumentException('Tipo de servicio inválido.');
    }
    $items = $input['items'] ?? null;
    if (!is_array($items) || count($items) < 1 || count($items) > 200) throw new InvalidArgumentException('Carrito inválido.');
    $stmt = $pdo->prepare("SELECT p.codigo, p.nombre,
        COALESCE(ps.precio_venta, p.precio) AS precio_base,
        COALESCE(ps.precio_mayorista, p.precio_mayorista, ps.precio_venta, p.precio) AS precio_mayorista_base,
        COALESCE(p.precio_oferta, 0) AS precio_oferta_base
        FROM productos p LEFT JOIN productos_precios_sucursal ps
          ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
        WHERE p.codigo = ? AND p.id_empresa = ? AND p.activo = 1 AND p.es_web = 1
          AND (p.sucursales_web = '' OR p.sucursales_web IS NULL OR FIND_IN_SET(?, p.sucursales_web) > 0)
        LIMIT 1");
    $validated = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        if (!is_array($item)) throw new InvalidArgumentException('Producto inválido.');
        $sku = (string)($item['id'] ?? $item['codigo'] ?? '');
        $qty = $item['qty'] ?? null;
        if ($sku === '' || strlen($sku) > 50 || !is_numeric($qty) || !is_finite((float)$qty)
            || (float)$qty <= 0 || (float)$qty > 1000000) throw new InvalidArgumentException('Cantidad o producto inválido.');
        $stmt->execute([$sucursal, $sku, $empresa, $sucursal]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new InvalidArgumentException('Producto no disponible en esta tienda.');
        shop_apply_price_config_to_row($product, $config);
        $price = (float)($product['precio_oferta'] > 0 ? $product['precio_oferta'] : $product['precio']);
        $name = (string)$product['nombre'];
        if (!empty($item['variant'])) {
            if (!is_array($item['variant'])) throw new InvalidArgumentException('Variante inválida.');
            $variant = $pdo->prepare('SELECT nombre, precio_extra FROM producto_variantes WHERE producto_codigo = ? AND activo = 1 AND nombre = ? LIMIT 1');
            $variant->execute([$sku, (string)($item['variant']['nombre'] ?? '')]);
            $row = $variant->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new InvalidArgumentException('Variante no disponible.');
            $price += (float)$row['precio_extra'];
            $name .= ' (' . $row['nombre'] . ')';
        }
        $price = round($price, 2);
        if (!is_finite($price) || $price < 0) throw new InvalidArgumentException('Precio de catálogo inválido.');
        if (!isset($item['price']) || !is_numeric($item['price']) || !is_finite((float)$item['price'])
            || abs((float)$item['price'] - $price) > 0.009) {
            throw new InvalidArgumentException('El precio cambió. Actualice el carrito antes de confirmar.');
        }
        $validated[] = ['id' => $sku, 'qty' => (float)$qty, 'price' => $price, 'name' => substr($name, 0, 150),
            'note' => substr((string)($item['note'] ?? ''), 0, 100)];
        $subtotal += $price * (float)$qty;
    }
    $shipping = 0.0;
    $delivery = in_array($service, ['domicilio', 'delivery'], true) || !empty($input['delivery']);
    // Compatibilidad con carritos abiertos antes de introducir campos de envío explícitos.
    $address = (string)($input['cliente_direccion'] ?? '');
    $municipio = (string)($input['municipio'] ?? '');
    $barrio = (string)($input['barrio'] ?? '');
    if (preg_match('/^\[MENSAJERÍA: ([^\]]+) - ([^\]]+)\]/u', $address, $match)) {
        $delivery = true;
        if ($municipio === '') $municipio = $match[1];
        if ($barrio === '') $barrio = $match[2];
    }
    if ($delivery) {
        $locations = shop_delivery_locations();
        if (!isset($locations[$municipio][$barrio])) throw new InvalidArgumentException('Destino de entrega inválido.');
        $shipping = round(100 + $locations[$municipio][$barrio] * (float)($config['mensajeria_tarifa_km'] ?? 150), 2);
    }
    $total = round($subtotal + $shipping, 2);
    if (!is_finite($total) || $total <= 0 || $total > 9999999999.99) throw new InvalidArgumentException('Total inválido.');
    if (!isset($input['total']) || !is_numeric($input['total']) || !is_finite((float)$input['total'])
        || abs((float)$input['total'] - $total) > 0.009) throw new InvalidArgumentException('El total cambió. Actualice el carrito.');

    $method = (string)($input['metodo_pago'] ?? '');
    $state = 'pendiente';
    if ($service === 'reserva') {
        $method = 'PENDIENTE_ENTREGA';
    } else {
        $selected = null;
        foreach (($config['metodos_pago'] ?? []) as $candidate) {
            if (!empty($candidate['activo']) && !empty($candidate['aplica_shop']) && $candidate['id'] === $method) $selected = $candidate;
        }
        if (!$selected) throw new InvalidArgumentException('Método de pago no disponible.');
        if (!empty($selected['requiere_codigo']) || !empty($selected['es_transferencia'])) {
            if (trim((string)($input['codigo_pago'] ?? '')) === '') throw new InvalidArgumentException('Falta el código de pago.');
        }
        if (!empty($selected['es_transferencia'])) $state = 'verificando';
    }
    $currency = (string)($input['moneda'] ?? 'CUP');
    $rates = ['CUP' => 1.0, 'USD' => (float)($config['tipo_cambio_usd'] ?? 385), 'MLC' => (float)($config['tipo_cambio_mlc'] ?? 310)];
    if (!isset($rates[$currency]) || !is_finite($rates[$currency]) || $rates[$currency] <= 0) throw new InvalidArgumentException('Moneda inválida.');
    return ['items' => $validated, 'total' => $total, 'metodo_pago' => $method, 'estado_pago' => $state,
        'moneda' => $currency, 'tipo_cambio' => $rates[$currency], 'monto_moneda_original' => round($total / $rates[$currency], 2)];
}
