-- 005_tema_preferido_usuario.sql
-- Preferencia visual no sensible, asociada exclusivamente a cada usuario.
-- DARK conserva el comportamiento histórico para todas las cuentas existentes.

SET @tema_preferido_existe = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'usuarios'
      AND column_name = 'tema_preferido'
);

SET @sql_tema_preferido = IF(
    @tema_preferido_existe = 0,
    "ALTER TABLE usuarios ADD COLUMN tema_preferido ENUM('dark','light') NOT NULL DEFAULT 'dark' COMMENT 'Preferencia visual del usuario' AFTER telefono",
    'SELECT 1'
);

PREPARE stmt_tema_preferido FROM @sql_tema_preferido;
EXECUTE stmt_tema_preferido;
DEALLOCATE PREPARE stmt_tema_preferido;

SET @tema_preferido_check_existe = (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'usuarios'
      AND constraint_name = 'ck_usuarios_tema_preferido'
      AND constraint_type = 'CHECK'
);

SET @sql_tema_preferido_check = IF(
    @tema_preferido_check_existe = 0,
    "ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_tema_preferido CHECK (tema_preferido IN ('dark','light'))",
    'SELECT 1'
);

PREPARE stmt_tema_preferido_check FROM @sql_tema_preferido_check;
EXECUTE stmt_tema_preferido_check;
DEALLOCATE PREPARE stmt_tema_preferido_check;

UPDATE usuarios
SET tema_preferido = 'dark'
WHERE tema_preferido IS NULL
   OR tema_preferido NOT IN ('dark', 'light');

INSERT INTO schema_migrations (version, descripcion)
VALUES ('005', 'Preferencia de tema visual por usuario')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
