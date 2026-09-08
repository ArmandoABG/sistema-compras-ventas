-- 003_permisos_precio_manual.sql
-- Separa la creación de documentos de la autorización para modificar precios.
-- Para conservar el funcionamiento actual, hereda el permiso a todos los roles
-- que ya tenían el permiso de creación correspondiente.

INSERT INTO permisos (codigo, modulo, nombre, descripcion)
VALUES
    ('ventas.precio_manual', 'ventas', 'Modificar precios en ventas', 'Permite sustituir el precio vigente al crear una venta.'),
    ('cotizaciones.precio_manual', 'ventas', 'Modificar precios en cotizaciones', 'Permite sustituir el precio vigente al crear una cotización.')
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion);

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, nuevo.id
FROM roles_permisos rp
INNER JOIN permisos anterior ON anterior.id = rp.permiso_id
INNER JOIN permisos nuevo ON nuevo.codigo = 'ventas.precio_manual'
WHERE anterior.codigo = 'ventas.crear';

INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, nuevo.id
FROM roles_permisos rp
INNER JOIN permisos anterior ON anterior.id = rp.permiso_id
INNER JOIN permisos nuevo ON nuevo.codigo = 'cotizaciones.precio_manual'
WHERE anterior.codigo = 'cotizaciones.crear';

INSERT INTO schema_migrations (version, descripcion)
VALUES ('003', 'Permisos independientes para modificación manual de precios')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
