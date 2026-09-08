-- 004_indice_busqueda_productos.sql
-- Evita recorrer todo el catálogo al buscar productos por el inicio del nombre
-- y permite localizar el último precio activo de cada relación sin ordenar su
-- histórico completo.

SET @indice_existe = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'productos'
      AND index_name = 'idx_productos_nombre'
);
SET @sql_indice = IF(
    @indice_existe = 0,
    'ALTER TABLE productos ADD INDEX idx_productos_nombre (nombre)',
    'SELECT 1'
);
PREPARE stmt_indice FROM @sql_indice;
EXECUTE stmt_indice;
DEALLOCATE PREPARE stmt_indice;

SET @indice_precio_existe = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'historial_precios_proveedor'
      AND index_name = 'idx_hpp_rel_activo_fecha'
);
SET @sql_indice_precio = IF(
    @indice_precio_existe = 0,
    'ALTER TABLE historial_precios_proveedor ADD INDEX idx_hpp_rel_activo_fecha (proveedor_producto_id, activo, fecha_precio, id)',
    'SELECT 1'
);
PREPARE stmt_indice_precio FROM @sql_indice_precio;
EXECUTE stmt_indice_precio;
DEALLOCATE PREPARE stmt_indice_precio;

INSERT INTO schema_migrations (version, descripcion)
VALUES ('004', 'Índices de búsqueda de productos y último precio de proveedor')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
