# WhatsApp Web Bridge (POS BOT)

Este servicio conecta WhatsApp Web con `pos_bot_api.php` para responder mensajes automaticamente.

## 1) Instalar dependencias

```bash
cd /var/www/marinero/wa_web_bridge
npm install
```

## 2) Configurar variables

```bash
cp .env.example .env
```

Ajusta `POS_BOT_VERIFY_TOKEN` al mismo valor guardado en `pos_bot.php`.

## 3) Ejecutar

```bash
cd /var/www/marinero/wa_web_bridge
export $(grep -v '^#' .env | xargs)
npm start
```

En la primera ejecucion se mostrara un QR en terminal.
Escanealo en WhatsApp del telefono: `Configuracion > Dispositivos vinculados > Vincular un dispositivo`.

## 4) Operacion

- La sesion queda guardada en `.wwebjs_auth`.
- Cuando llegue un mensaje, el bridge llama a:
  - `pos_bot_api.php?action=web_incoming`
- Las respuestas que devuelve el bot se envian al chat por WhatsApp Web.

## 5) Notas

- Para que responda, en `pos_bot.php` el bot debe estar habilitado.
- En modo `web`, el bot ignora credenciales de Meta Cloud API.
