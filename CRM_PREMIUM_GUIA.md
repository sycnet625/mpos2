# CRM Premium - Guía de Uso

## 🎉 Resumen de Cambios

El módulo CRM ha sido actualizado a nivel **Premium** con las siguientes características:

### Nuevas Funcionalidades

#### 1. **Múltiples Teléfonos por Cliente**
- Cada cliente puede tener varios números de teléfono
- Tipos: Celular, Fijo, WhatsApp, Comercial
- Marcar uno como "Principal" para reportes y envíos
- Acceso rápido desde la tabla principal

#### 2. **Múltiples Direcciones por Cliente**
- Cada cliente puede tener varias direcciones
- Tipos: Entrega, Facturación, Comercial, Almacén
- Dirección completa generada automáticamente (Calle, Nº, Apto, Reparto, Ciudad)
- Instrucciones especiales de entrega (ej: "Ring 3 veces")
- Una marcada como "Principal" para entregas por defecto

#### 3. **Distinción entre Personas y Negocios**
- Tipo Cliente: **Persona** o **Negocio**
- Para Negocios, campos adicionales:
  - **RUC**: Número de identificación comercial
  - **Contacto Principal**: Nombre de la persona responsable
  - **Giro del Negocio**: Descripción del tipo de negocio (Restaurante, Farmacia, etc.)

#### 4. **Mejoras en la UI**
- Tablas dinámicas para agregar/quitar teléfonos y direcciones
- Indicadores visuales en la lista principal (badges con cantidad)
- Clasificación por tipo: Persona (verde) / Negocio (azul)
- Modal scrollable para mejora en dispositivos móviles

---

## 📊 Estructura de Base de Datos

### Tabla: `clientes_telefonos`
```sql
CREATE TABLE clientes_telefonos (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_cliente INT FOREIGN KEY REFERENCES clientes(id) ON DELETE CASCADE,
  tipo ENUM('Celular', 'Fijo', 'WhatsApp', 'Comercial'),
  numero VARCHAR(50) NOT NULL,
  es_principal TINYINT(1),
  fecha_agregado DATETIME DEFAULT CURRENT_TIMESTAMP,
  activo TINYINT(1) DEFAULT 1
);
```

### Tabla: `clientes_direcciones`
```sql
CREATE TABLE clientes_direcciones (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_cliente INT FOREIGN KEY REFERENCES clientes(id) ON DELETE CASCADE,
  tipo ENUM('Entrega', 'Facturación', 'Comercial', 'Almacén'),
  calle VARCHAR(100) NOT NULL,
  numero VARCHAR(20),
  apartamento VARCHAR(50),
  reparto VARCHAR(100),
  ciudad VARCHAR(100),
  codigo_postal VARCHAR(20),
  direccion_completa VARCHAR(255) GENERATED STORED,
  es_principal TINYINT(1),
  instrucciones TEXT,
  fecha_agregada DATETIME DEFAULT CURRENT_TIMESTAMP,
  activo TINYINT(1) DEFAULT 1
);
```

### Columnas Nuevas en `clientes`
```sql
ALTER TABLE clientes ADD (
  tipo_cliente ENUM('Persona', 'Negocio') DEFAULT 'Persona',
  ruc VARCHAR(50),
  contacto_principal VARCHAR(150),
  giro_negocio VARCHAR(150),
  telefono_principal VARCHAR(50),      -- Compatibilidad
  direccion_principal VARCHAR(255)      -- Compatibilidad
);
```

---

## 🚀 Cómo Usar

### Crear un Cliente (Persona)

1. Haz clic en **"Nuevo Cliente"**
2. Completa **Datos Básicos**:
   - Nombre
   - Email
   - Carnet/NIT
3. Tipo Cliente: **Persona** (por defecto)
4. En **Teléfonos**: Haz clic en **"Agregar"**
   - Selecciona tipo (Celular, Fijo, WhatsApp, etc.)
   - Ingresa el número
   - Marca como principal (al menos uno)
5. En **Direcciones**: Haz clic en **"Agregar"**
   - Selecciona tipo (Entrega, Facturación, etc.)
   - Ingresa Calle, Nº, Apto, Reparto, Ciudad, CP
   - Instrucciones especiales (opcional)
   - Marca como principal
6. **Datos Adicionales** (opcional):
   - Categoría (Regular, VIP, Corporativo)
   - Origen (cómo se enteró)
   - Cumpleaños
   - Si es mensajero
7. Haz clic en **"Guardar Cliente"**

### Crear un Cliente (Negocio)

1. Haz clic en **"Nuevo Cliente"**
2. Completa **Datos Básicos**
3. Tipo Cliente: **Negocio** ← Esto activa campos adicionales
4. Se muestran campos obligatorios:
   - **RUC**: Número de identificación del negocio
   - **Contacto Principal**: Nombre de la persona responsable
   - **Giro del Negocio**: Descripción (ej: "Restaurante de comida rápida")
5. Agregar teléfonos y direcciones (igual que personas)
6. Guardar

### Editar un Cliente

1. En la tabla, busca el cliente
2. Haz clic en el botón **Editar** (lápiz)
3. Se cargarán todos los datos:
   - Teléfonos en una tabla
   - Direcciones en una tabla
   - Todos los campos
4. Puedes:
   - **Agregar** nuevos teléfonos/direcciones
   - **Eliminar** teléfonos/direcciones (botón 🗑️)
   - **Cambiar el principal** (radio button)
   - **Editar valores** directamente en los inputs
5. Haz clic en **"Guardar Cliente"**

### Eliminar un Cliente

1. En la tabla, busca el cliente
2. Haz clic en el botón **Eliminar** (papelera roja)
3. Confirma la eliminación
4. ⚠️ **Nota**: Se eliminarán los datos de contacto, pero el historial de ventas se mantiene

### Búsqueda

El campo de búsqueda busca por:
- Nombre del cliente
- Número de teléfono
- Carnet/NIT
- RUC (si es negocio)

---

## 📈 KPIs y Estadísticas

El dashboard muestra:
- **Total Clientes**: Cantidad de clientes registrados
- **Negocios**: Cantidad de clientes tipo "Negocio"
- **Nuevos (Mes)**: Clientes creados este mes
- **VIPs**: Clientes con categoría VIP

Además, para cada cliente:
- **LTV** (Lifetime Value): Total gastado en todas las compras
- **Compras**: Cantidad de transacciones
- **Última Visita**: Fecha de la última compra
- **Estado**: 🔥 Activo (0-30 días), ⚠️ Riesgo (30-90 días), 💤 Dormido (>90 días)

---

## 🔄 Compatibilidad con Otros Módulos

### POS y Shop
- Siguen funcionando sin cambios
- Los módulos usan `cliente_nombre` (texto)
- No dependen de las tablas nuevas

### API y Integraciones
- Si necesitas el **teléfono principal**: usa `clientes.telefono_principal`
- Si necesitas la **dirección principal**: usa `clientes.direccion_principal`
- Para todas las opciones, haz JOIN con `clientes_telefonos` / `clientes_direcciones`

### Ejemplo de Query
```php
// Obtener todos los teléfonos de un cliente
$stmt = $pdo->prepare("
  SELECT numero, tipo, es_principal 
  FROM clientes_telefonos 
  WHERE id_cliente = ? AND activo = 1
  ORDER BY es_principal DESC
");
$stmt->execute([$cliente_id]);
$telefonos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener dirección principal para entrega
$stmt = $pdo->prepare("
  SELECT calle, numero, apartamento, reparto, ciudad, codigo_postal, instrucciones
  FROM clientes_direcciones 
  WHERE id_cliente = ? AND es_principal = 1 AND activo = 1
  LIMIT 1
");
$stmt->execute([$cliente_id]);
$direccion = $stmt->fetch(PDO::FETCH_ASSOC);
```

---

## 🛠️ Migraciones Realizadas

### Script: `/var/www/migrations/upgrade_crm_premium.php`

Se ejecutó automáticamente y realizó:
1. ✅ Creó tabla `clientes_telefonos` con FK CASCADE
2. ✅ Creó tabla `clientes_direcciones` con FK CASCADE
3. ✅ Agregó columnas a `clientes`
4. ✅ Migró datos existentes:
   - Teléfono único → primer teléfono en `clientes_telefonos`
   - Dirección única → primera dirección en `clientes_direcciones`
5. ✅ Actualizó referencias cruzadas

**Si necesitas re-ejecutar la migración:**
```bash
php migrations/upgrade_crm_premium.php
```

---

## ✨ Features Premium

### Validaciones
- ✅ Nombre obligatorio
- ✅ Para negocios: RUC, Contacto Principal, Giro obligatorios
- ✅ Al menos un teléfono o dirección
- ✅ Al menos uno marcado como principal

### Seguridad
- ✅ FK con CASCADE: eliminar cliente elimina teléfonos y direcciones automáticamente
- ✅ Transacciones: si algo falla, se revierte todo
- ✅ Validación de entrada (JSON decode verificado)
- ✅ SQL prepared statements en todos lados

### Performance
- ✅ Índices en `id_cliente` para búsquedas rápidas
- ✅ Índice en `numero` de teléfono para búsqueda
- ✅ Dirección completa generada con GENERATED COLUMN

---

## 📝 Ejemplo: Integración con POS

Para usar en `pos.php` o `shop.php`:

```php
// Obtener cliente
$stmtCli = $pdo->prepare("SELECT * FROM clientes WHERE nombre = ? LIMIT 1");
$stmtCli->execute([$cliente_nombre]);
$cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);

if ($cliente) {
    // Obtener teléfono principal para envío de comprobante
    $stmtTel = $pdo->prepare("
        SELECT numero FROM clientes_telefonos 
        WHERE id_cliente = ? AND es_principal = 1 AND activo = 1 
        LIMIT 1
    ");
    $stmtTel->execute([$cliente['id']]);
    $telPrincipal = $stmtTel->fetchColumn();
    
    // Obtener dirección principal para entrega
    $stmtDir = $pdo->prepare("
        SELECT instrucciones FROM clientes_direcciones 
        WHERE id_cliente = ? AND es_principal = 1 AND activo = 1 
        LIMIT 1
    ");
    $stmtDir->execute([$cliente['id']]);
    $instrucciones = $stmtDir->fetchColumn();
}
```

---

## 🐛 Troubleshooting

### El modal no carga datos al editar
- Verifica que el cliente tenga teléfonos/direcciones en BD
- Abre la consola de navegador (F12) para ver errores de JavaScript

### Los teléfonos/direcciones no se guardan
- Verifica que en el formulario haya al menos uno agregado
- Revisa que los botones "Agregar" se hayan presionado
- Mira la consola del navegador para errores

### Error al eliminar cliente
- Verifica que no haya ventas asociadas activas (se mantienen aunque se elimine cliente)
- Chequea que tenga permisos de admin

---

## 📞 Soporte

Cualquier duda o issue sobre el CRM Premium, revisa:
- CLAUDE.md (instrucciones del proyecto)
- Los comentarios en `crm_clients.php`
- Las queries en `migrations/upgrade_crm_premium.php`

---

**Versión**: CRM Premium v1.0  
**Fecha**: 2026-04-11  
**Estado**: ✅ Producción
