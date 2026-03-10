# SalesTracker (Android Kotlin)

Aplicacion Android en Kotlin para seguimiento de ventas por texto y metadatos.

## Lo que SI hace
- Lee historial de llamadas del dispositivo (SIM/call log).
- Lee SMS del dispositivo.
- Captura texto de notificaciones de WhatsApp (si habilitas Notification Access).
- Exporta CSV de cada modulo.

## Lo que NO hace
- No graba audio.
- No graba voz de llamadas (SIM o WhatsApp).
- No usa APIs privadas para extraer chats internos de WhatsApp.

## Requisitos
- Android Studio Hedgehog o mas reciente.
- SDK Android 34.
- Dar permisos READ_CALL_LOG y READ_SMS.
- Activar acceso a notificaciones para WhatsApp.

## Build APK debug
```bash
./gradlew assembleDebug
```
APK esperada:
`app/build/outputs/apk/debug/app-debug.apk`
