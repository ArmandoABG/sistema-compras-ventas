# Sistema Integral

Sistema web de compras, ventas e inventario ejecutado con PHP y MySQL.

## Entorno recomendado

- Apache de XAMPP.
- PHP 8.2 de `C:\xampp\php\php.exe`. El PHP 7.3 de AppServ no es compatible.
- MySQL 8.0.16 o posterior para aplicar correctamente las restricciones `CHECK`.

## Migraciones

Los cambios nuevos de esquema están en `SQL/migrations` y deben aplicarse en
orden numérico sobre un respaldo verificado. La tabla `schema_migrations`
registra las versiones instaladas. Las migraciones de esta etapa son aditivas y
no eliminan información histórica.

La versión mínima de esquema requerida por este código es **004**.

## Mantenimiento programado

Ejecutar cada cinco minutos con el programador de tareas de Windows:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\sistema_integral\funciones\mantenimiento_operativo_cli.php
```

La tarea libera apartados vencidos, normaliza operaciones QR pendientes, aplica
salidas cuando QR está deshabilitado, vence cotizaciones y cierra sesiones por
inactividad. Las pantallas de consulta no ejecutan estas modificaciones.

## Semántica actual de subtotales

- Compras: `subtotal` de cabecera representa el importe bruto antes del
  descuento. `total = subtotal - descuento_total + impuesto_total`.
- Ventas y cotizaciones: `subtotal` de cabecera representa el importe neto
  después del descuento. `total = subtotal + impuesto_total`.
- Apartados: conserva el subtotal neto heredado o calculado por sus líneas.

Esta diferencia se mantiene para conservar compatibilidad. Una migración futura
debe introducir nombres explícitos (`subtotal_bruto` y `subtotal_neto`), adaptar
reportes e impresiones, reconciliar históricos y retirar los campos ambiguos
solo después de validar todos los módulos.
