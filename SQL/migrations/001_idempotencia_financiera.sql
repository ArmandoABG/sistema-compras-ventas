-- 001_idempotencia_financiera.sql
-- Registra de forma atómica solicitudes financieras para que un reintento
-- devuelva el resultado ya creado sin repetir sus efectos.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(50) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    aplicada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operaciones_idempotentes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operacion VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    clave VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    solicitud_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    estado ENUM('EN_PROCESO', 'COMPLETADA') NOT NULL DEFAULT 'EN_PROCESO',
    recurso_tipo VARCHAR(64) DEFAULT NULL,
    recurso_id BIGINT UNSIGNED DEFAULT NULL,
    mensaje VARCHAR(255) DEFAULT NULL,
    respuesta_json JSON DEFAULT NULL,
    http_status SMALLINT UNSIGNED NOT NULL DEFAULT 201,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operacion_idempotente (operacion, clave),
    KEY idx_operacion_idempotente_recurso (recurso_tipo, recurso_id),
    KEY fk_operacion_idempotente_usuario (created_by),
    CONSTRAINT fk_operacion_idempotente_usuario
        FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, descripcion)
VALUES ('001', 'Idempotencia transaccional para operaciones financieras')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);
