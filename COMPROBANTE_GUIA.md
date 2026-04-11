# 📋 Módulo de Comprobantes de Ventas

## 🎉 Descripción

Nuevo módulo profesional para generar y descargar comprobantes de ventas en formato HTML/PDF. Diseño moderno que incluye el logo de la empresa, información de la venta, detalles de productos y totales.

---

## 📂 Archivos Creados

| Archivo | Descripción |
|---------|-------------|
| `comprobante_ventas.php` | Módulo principal de visualización de comprobantes |
| `helpers/comprobante_generator.php` | Clase PHP para generar HTML y PDF de comprobantes |
| `assets/img/logo_comprobante.svg` | Logo de la empresa (SVG escalable) |
| `modulos/comprobante_widget.html` | Widget para integración en POS |
| `COMPROBANTE_GUIA.md` | Esta guía |

---

## 🚀 Cómo Usar

### Opción 1: Desde el Navegador

Accede directamente a un comprobante con la URL:
```
https://tuservidor/comprobante_ventas.php?id=123
```

Reemplaza `123` con el ID de la venta que deseas ver.

### Opción 2: Desde el Código PHP

```php
<?php
require_once 'helpers/comprobante_generator.php';

$generator = new ComprobanteGenerator($pdo, $config);

// Mostrar comprobante en HTML
echo $generator->generarHTML(123);

// O generar PDF directamente
$rutaPDF = $generator->generarPDF(123);
// $rutaPDF contiene la ruta al archivo PDF generado
?>
```

### Opción 3: Widget en POS

El widget está disponible en `/modulos/comprobante_widget.html`. Puedes incluirlo en tu POS:

```html
<?php include_once 'modulos/comprobante_widget.html'; ?>
```

---

## 🎨 Diseño del Comprobante

El comprobante incluye:

1. **Logo de la empresa** (SVG - escalable)
2. **Encabezado profesional**
   - Título "COMPROBANTE"
   - Información de venta (No., Fecha, Hora)
3. **Sección de Cliente**
   - Nombre del cliente
   - Nombre de la empresa
4. **Tabla de Productos**
   - No. (número)
   - Descripción del producto
   - Cantidad
   - Precio unitario
   - Total por línea
5. **Totales**
   - Total general
   - Método de pago
6. **Pie de página**
   - Mensaje de agradecimiento
   - Fecha/hora de generación

---

## 🖨️ Funcionalidades

### Visualización
✅ **HTML Responsive**: Funciona en desktop, tablet y móvil  
✅ **Impresión Profesional**: Optimizado para impresoras  
✅ **Botones de Acción**:
- 🖨️ Imprimir (usa print del navegador)
- 📥 Descargar PDF
- 🏠 Volver al POS

### Generación de PDF
El módulo intenta convertir a PDF usando (en orden de preferencia):
1. **Chromium** (Google Chrome/Chromium Browser)
2. **wkhtmltopdf** (si está instalado)
3. **Fallback**: Impresión desde navegador

Para descargar PDF:
```
https://tuservidor/comprobante_ventas.php?id=123&format=pdf
```

---

## 💾 Datos que Incluye

El comprobante obtiene automáticamente de la BD:

```sql
-- De ventas_cabecera
- ID de venta
- Cliente
- Fecha y hora
- Método de pago
- Total

-- De ventas_detalle
- Descripción del producto
- Cantidad
- Precio
- Total por línea

-- De clientes (si existe)
- Tipo de cliente (Persona/Negocio)
- RUC (si es negocio)
- Contacto principal
```

---

## 🔧 Integración con Otros Módulos

### En POS (`pos.php`)
Agregar botón para generar comprobante después de registrar una venta:

```php
// Después de guardar la venta
$idVenta = $pdo->lastInsertId();
echo "<a href='comprobante_ventas.php?id=$idVenta' target='_blank' class='btn btn-primary'>
        <i class='fas fa-receipt'></i> Ver Comprobante
      </a>";
```

### En Dashboard (`dashboard.php`)
Mostrar widget de acceso rápido:

```php
<?php include_once 'modulos/comprobante_widget.html'; ?>
```

### En Historial de Ventas
Agregar botón de "Ver Comprobante" en cada fila:

```html
<button class='btn btn-sm btn-outline-info' 
        onclick="window.open('comprobante_ventas.php?id=<?php echo $venta['id']; ?>', '_blank')">
    <i class='fas fa-file-pdf'></i> Comprobante
</button>
```

---

## 📝 Personalización

### Cambiar Nombre de Empresa
El nombre de la empresa se obtiene de `config_loader.php`:

```php
$nombreEmpresa = $config['pos_shop_name'] ?? 'Pastelería Renacer';
```

Edita `pos.cfg` para cambiar el nombre:
```json
{
  "pos_shop_name": "Tu Negocio"
}
```

### Cambiar Logo
Reemplaza el archivo `/var/www/assets/img/logo_comprobante.svg` con tu logo.

**Formatos soportados**:
- SVG (recomendado - escalable)
- PNG (hasta 500x500px)
- JPG (hasta 500x500px)

### Cambiar Estilos
Los estilos CSS están en el método `generarHTML()` de la clase `ComprobanteGenerator`. Puedes modificar:

- Colores: `#333` (negro), `#999` (gris)
- Fuentes: `Segoe UI`, `Georgia`
- Espacios y tamaños de letra

---

## 🐛 Solución de Problemas

### "Venta no encontrada"
- Verifica que el ID de la venta sea correcto
- Confirma que la venta existe en la BD

### PDF no descarga
- Asegúrate que Chromium está instalado: `which chromium-browser`
- O instala wkhtmltopdf: `sudo apt-get install wkhtmltopdf`
- O usa "Imprimir a PDF" desde el navegador (Ctrl+P)

### Logo no aparece
- Verifica que el archivo existe: `/var/www/assets/img/logo_comprobante.svg`
- Si no existe, copia tu logo allí o edita la ruta en `comprobante_generator.php`

### Estilos no se aplican correctamente
- Borra caché del navegador (Ctrl+Shift+Del)
- Prueba en incógnito (Ctrl+Shift+N)

---

## 📱 Responsive Design

El comprobante se adapta automáticamente a:
- **Desktop** (800px+): Ancho completo, impresión perfecta
- **Tablet** (600-800px): Ajusta espacios, mantiene legibilidad
- **Móvil** (< 600px): Apto para visualización, impresión a PDF recomendada

---

## 🔒 Seguridad

✅ **Validación de entrada**: El ID de venta se valida como entero  
✅ **Sanitización**: Todos los datos se escapan con `htmlspecialchars()`  
✅ **Permisos**: Heredada de la sesión de POS/Admin  
✅ **Archivos temporales**: Se limpian automáticamente después de generar PDF

---

## 📊 Ejemplo de Uso Completo

```php
<?php
// En un archivo como "generar_comprobante_desde_venta.php"
require_once 'db.php';
require_once 'config_loader.php';
require_once 'helpers/comprobante_generator.php';

session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    die('Acceso denegado');
}

$idVenta = intval($_GET['id']);
$generator = new ComprobanteGenerator($pdo, $config);

// Opción 1: Mostrar HTML
if (isset($_GET['format']) && $_GET['format'] === 'pdf') {
    // Generar PDF
    $rutaPDF = $generator->generarPDF($idVenta);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="comprobante_' . $idVenta . '.pdf"');
    readfile($rutaPDF);
    unlink($rutaPDF);
} else {
    // Mostrar HTML
    echo $generator->generarHTML($idVenta);
}
?>
```

---

## 🎯 Roadmap Futuro

- [ ] Comprobante en **múltiples idiomas**
- [ ] Soporte para **firmas digitales**
- [ ] **Código QR** con datos de la venta
- [ ] Envío por **WhatsApp/Email**
- [ ] **Descuentos y promociones** en el comprobante
- [ ] **Retención de impuestos**
- [ ] **Facturación electrónica**

---

## 📞 Versión y Soporte

**Versión**: 1.0  
**Fecha**: 2026-04-11  
**Compatible con**: PHP 7.4+, MySQL 5.7+  
**Dependencias**: `db.php`, `config_loader.php`  

Para reportar issues o solicitar features, revisa el archivo `CLAUDE.md`.

---

**Estado**: ✅ Producción  
**Mantenimiento**: Activo
