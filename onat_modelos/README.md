# Plantillas oficiales ONAT

Este directorio aloja los modelos oficiales `.xlsx` publicados por la ONAT en
https://www.onat.gob.cu/home/modelos-formularios

## Cómo actualizar (acción manual anual)

1. Descargar las versiones del año actual desde el portal ONAT.
2. Renombrar y colocar dentro de `/var/www/onat_modelos/<AÑO>/` con estos nombres:

   - `DJ-08.xlsx` — Declaración Jurada Impuesto sobre Ingresos Personales (anual)
   - `DJ-Utilidades.xlsx` — DJ Utilidades MIPYME / Sociedades Mercantiles (anual)
   - `DJ-09.xlsx` — DJ Utilidades Cooperativas No Agropecuarias (anual)
   - `SC-Trim.xlsx` — Anticipo trimestral de Utilidades
   - `Mensual-Ventas.xlsx` — Declaración mensual Ventas + Territorial
   - `IM-FuerzaTrabajo.xlsx` — Modelo IM Impuesto Fuerza de Trabajo (mensual)
   - `SS-Aporte.xlsx` — Modelo SS Aporte Seguridad Social (mensual)
   - `VectorFiscal.xlsx` — Vector Fiscal del contribuyente

> **Importante:** los nombres del lado izquierdo deben coincidir con la
> constante `MODELO` de cada generador en `/var/www/onat_generators/`. Si
> renombrás archivos, actualizá esa constante.

3. Si la ONAT publica una versión nueva con diferente layout, abrir el `.xlsx`
   y revisar el `CELL_MAP` de cada generador en `/var/www/onat_generators/`.
   Cada constante `CELL_MAP` mapea `clave_lógica → coordenada_excel` (ej. `'B5'`).

## Comportamiento si falta la plantilla

El generador (`onat_generator.php`) detecta la ausencia y produce un Excel
genérico con los mismos datos (cabecera + tabla de partidas). El sistema sigue
funcionando, pero el documento no será idéntico al oficial.

## Archivos generados

Los `.xlsx` y `.pdf` rellenados por empresa/periodo se guardan en
`/var/www/onat_archivos/{id_empresa}/{año}/{periodo}/{MODELO}.{xlsx|pdf}` y
quedan registrados en la tabla `onat_declaraciones`.
