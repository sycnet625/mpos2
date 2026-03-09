# Reservas Offline (Android)

App Android nueva para replicar `reservas.php` en modo offline con cola de sincronizacion.

## Funciones implementadas

- Descarga inicial completa de:
  - Productos (stock, reservable, servicio)
  - Clientes
  - Reservas + detalles
- Trabajo totalmente offline:
  - Crear/editar reservas
  - Cambiar estado (pendiente, en preparacion, en camino, entregado, cancelado)
  - Marcar entregar/cancelar
  - Crear clientes
- Cola local de pendientes (`sync_queue`) en Room.
- Al volver internet, muestra estado online y permite subir pendientes.
- Re-sync de catalogo al terminar sincronizacion de cola.
- UI inspirada en `reservas.php`:
  - KPIs
  - filtros estado/fecha/busqueda
  - vista lista y vista almanaque
  - formulario de reserva con productos y total

## Endpoint backend

Se agrego endpoint:

- `api/reservas_offline.php`

Acciones:

- `GET ?action=bootstrap`
- `POST ?action=sync`

Si defines `offline_api_key` en `pos.cfg`, la app debe enviarla en `X-API-KEY`.

## Configuracion en app

En boton de engrane:

- `Base URL` ejemplo: `http://192.168.1.50`
- `API KEY` (si aplica)

La ruta del endpoint es fija: `/api/reservas_offline.php`.

## Build

Proyecto en:

- `apk/ReservasOffline`

Comando sugerido:

```bash
cd /var/www/apk/ReservasOffline
ANDROID_SDK_ROOT=/tmp/android-sdk GRADLE_USER_HOME=/tmp/.gradle ./gradlew assembleDebug
```

En este entorno de ejecucion puede fallar por red/hostname del sandbox, pero el proyecto queda listo para compilar en Android Studio o CI normal.
