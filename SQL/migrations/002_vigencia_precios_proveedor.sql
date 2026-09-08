-- 002_vigencia_precios_proveedor.sql
-- Impide que un precio de proveedor tenga una vigencia final igual o anterior
-- a la fecha en que inicia el precio.

SET @restriccion_existe = (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'historial_precios_proveedor'
      AND constraint_name = 'ck_hpp_vigencia'
);
SET @sql_vigencia = IF(
    @restriccion_existe = 0,
    'ALTER TABLE historial_precios_proveedor ADD CONSTRAINT ck_hpp_vigencia CHECK (vigencia_hasta IS NULL OR vigencia_hasta > fecha_precio)',
    'SELECT 1'
);
PREPARE stmt_vigencia FROM @sql_vigencia;
EXECUTE stmt_vigencia;
DEALLOCATE PREPARE stmt_vigencia;

INSERT INTO schema_migrations (version, descripcion)
VALUES ('002', 'Validación de vigencia para precios de proveedor')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
