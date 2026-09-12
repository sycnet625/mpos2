# Migración de PalWeb POS a otro servidor Linux

El módulo de migración de servidor está formado por:

- `migrar_espejo.sh`: ejecuta la copia y las comprobaciones desde el servidor actual.
- `admin_migracion_api.php`: dashboard gráfico autenticado y API JSON para ejecutarlo desde una interfaz administrativa.
- `admin_migracion.php`: es diferente; administra migraciones de esquema de la base de datos local y no reemplaza al traslado de servidor.

## Qué copia

1. Comprueba que el destino sea Linux, acepte SSH como `root`, tenga los comandos requeridos y disponga de espacio.
2. En modo `new`, verifica Ubuntu 24.04 LTS e instala nginx, Certbot, MySQL, PHP 8.3-FPM, extensiones PHP, Node.js 22, npm, Composer, rsync y dependencias de Chromium/Puppeteer.
3. Copia `/var/www` y `/home/marinero/product_images`, incluyendo POS, APIs, `pos.cfg`, migraciones, assets y el código del puente de WhatsApp. Excluye metadatos de desarrollo, claves del APK y sesiones privadas de Chromium/WhatsApp que el usuario web no puede leer ni debe exponer.
4. Instala dependencias de Node/WhatsApp y Composer si el proyecto las declara.
5. Hace respaldo remoto de las configuraciones existentes y copia nginx, PHP, Apache y MySQL sin borrar carpetas del destino. Los certificados de `/etc/letsencrypt` solo se copian si el proceso tiene permiso de lectura root; desde el dashboard web se omiten por seguridad y se usa Certbot en el destino.
6. Copia unidades systemd, `cron.d` y el crontab de root.
7. Agrega usuarios y grupos con UID/GID desde 1000. No copia contraseñas ni modifica usuarios que ya existan.
8. Por defecto exporta solamente la base de datos indicada en `/var/www/db.php`, con rutinas, triggers y eventos, y la restaura en el destino.
9. Valida configuración y reinicia los servicios instalados/activos.
10. Ejecuta smoke test de PHP, nginx, conexión PDO, archivos críticos y existencia de imágenes.

## Ejecución recomendada

Primero hacer una simulación:

```bash
MIRROR_SSHPASS='CLAVE_SSH' ./migrar_espejo.sh \
  --dest root@IP_DESTINO \
  --mode dry-run \
  --target-mode new
```

Si la simulación es correcta, ejecutar:

```bash
MIRROR_SSHPASS='CLAVE_SSH' ./migrar_espejo.sh \
  --dest root@IP_DESTINO \
  --mode run \
  --target-mode new
```

La variable `MIRROR_SSHPASS` evita incluir la contraseña como argumento visible en `ps`. Es preferible usar una llave SSH y omitir la variable. `--ssh-password` se conserva solamente por compatibilidad con instalaciones antiguas y no debe usarse en producción.

También se puede abrir `admin_migracion_api.php` desde el panel administrativo. Allí se selecciona servidor nuevo o actualización, se ejecuta primero el preflight/simulador y luego se confirma la aplicación real. El panel muestra gauges, estado por cada paso, estado individual de nginx, Certbot, MySQL, PHP-FPM, Node.js, npm, Composer, rsync y servicios, además del error exacto si alguno falla. La ejecución web usa una transmisión NDJSON en tiempo real; las llamadas antiguas sin `stream=1` siguen recibiendo JSON al finalizar.

## Opciones importantes

```text
--db-scope app|all       app protege las demás bases de datos; all migra todas.
--target-mode new|update new instala primero todo el stack; update reutiliza el sistema existente.
--sync-images yes|no     Incluye o excluye /home/marinero/product_images.
--sync-configs yes|no    Incluye o excluye configuraciones de servicios.
--sync-users yes|no      Agrega usuarios/grupos del servidor origen.
--allow-delete yes|no    Solo yes habilita --delete en /var/www.
--only-step 1..10|all    Permite repetir un paso concreto.
```

`--allow-delete no` es el valor seguro: actualiza archivos del destino sin borrar contenido que el destino ya tenga. Si se usa `yes`, conviene revisar antes el respaldo creado en `/root/palweb-migration-backups/`.

## Orden recomendado de puesta en producción

El servidor destino debe tener instalados PHP, PHP-FPM, nginx o Apache, rsync, MySQL/MariaDB, systemd y los módulos PHP usados por PalWeb. El script copia configuraciones y datos, pero no instala paquetes del sistema ni cambia DNS, firewall, certificados DNS o reglas del proveedor.

Después de la migración:

1. Verificar que el DNS o el proxy apunte al destino.
2. Comprobar `nginx -t` y el servicio PHP-FPM de la versión instalada.
3. Abrir login, dashboard, POS, cocina y pantalla del cliente.
4. Confirmar filtros por empresa, sucursal y almacén con un PIN de prueba.
5. Hacer una venta, revisar kardex, stock y reportes.
6. Revisar permisos de `pos.cfg`, `.env`, claves y certificados.

Para emitir el certificado en el servidor nuevo, después de apuntar el DNS:

```bash
sudo certbot --nginx -d www.palweb.net -d shop.palweb.net
```

La migración de la base de datos incluye los datos actuales, por lo que también transporta las correcciones recientes de categorías, productos, sucursales, almacenes y existencias. La pantalla `admin_migracion.php` puede ejecutarse luego si el destino se alimenta desde una base nueva o incompleta, pero no debe ejecutarse a ciegas sobre una restauración completa sin revisar sus migraciones pendientes.

Las sesiones privadas de WhatsApp (`.wwebjs_auth`) no se copian desde el dashboard web. Después de iniciar el bridge en el servidor nuevo, se debe vincular WhatsApp nuevamente mediante el QR. Las claves de `.pos-mobile-secrets` deben trasladarse manualmente por un canal administrativo seguro si son necesarias.
