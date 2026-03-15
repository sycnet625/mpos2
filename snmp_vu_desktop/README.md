# SNMP VU Desktop

App de escritorio para Windows con 5 medidores analogicos por SNMP.

## Funciones

- 5 gauges con aguja analogica izquierda -> derecha
- lectura local SNMP desde la red del cliente
- LED rojo/verde por ping
- ventana separada de configuracion
- presets para MikroTik y Ubiquiti

## Desarrollo

```bash
cd /var/www/snmp_vu_desktop
npm install
npm start
```

## Empaquetar EXE portable para Windows

```bash
cd /var/www/snmp_vu_desktop
npm install
npm run pack-win
```

## Notas

- La app ejecuta SNMP y ping en el equipo cliente, no en el servidor web.
- Requiere acceso de red local a los dispositivos SNMP.
