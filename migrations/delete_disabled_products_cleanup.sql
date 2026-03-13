START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_disabled_products;
CREATE TEMPORARY TABLE tmp_disabled_products AS
SELECT codigo COLLATE utf8mb4_unicode_ci AS codigo
FROM productos
WHERE activo = 0;

DELETE FROM compras_detalle
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM kardex
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM mermas_detalle
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM pedidos_detalle
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM producciones_historial
WHERE id_producto_final IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM recetas_cabecera
WHERE id_producto_final IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM stock_almacen
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM transferencias_detalle
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM ventas_detalle
WHERE id_producto IN (SELECT codigo FROM tmp_disabled_products)
   OR codigo_producto IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM producto_variantes
WHERE producto_codigo IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM resenas_productos
WHERE producto_codigo IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM restock_avisos
WHERE producto_codigo IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM wishlist
WHERE producto_codigo COLLATE utf8mb4_unicode_ci IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM vistas_productos
WHERE codigo_producto COLLATE utf8mb4_unicode_ci IN (SELECT codigo FROM tmp_disabled_products);

DELETE FROM productos
WHERE activo = 0;

COMMIT;
