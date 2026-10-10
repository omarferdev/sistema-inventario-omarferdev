-- =====================================================================================================================
-- 001 · Las tablas roles y usuarios                                        (episodio 05 · paso 1 del README, 1 de 4)
-- =====================================================================================================================
-- QUÉ HACE
--   1. Crea la tabla roles.
--   2. Crea la tabla usuarios, con su llave foránea a roles (RESTRICT: un rol que tiene usuarios no se puede borrar).
--   3. Crea el rol de Super administrador (SUPERADMIN). Es el rol de sistema: no se desactiva, y el sistema no lo borra.
--   No crea ninguna cuenta: la primera la creas tú en https://tu-dominio.com/instalar (paso 6 del README).
-- ORDEN
--   Va PRIMERO, con la base vacía. El 002 (la bitácora) apunta a la tabla usuarios: si corres el 002 antes que este,
--   phpMyAdmin responde el error #1005. Los dos van antes de subir el sitio, porque el inicio de sesión los lee.
-- CÓMO SE CORRE
--   phpMyAdmin → tu base en la lista de la izquierda → pestaña SQL → pega TODO este archivo → Continuar.
--   Si phpMyAdmin marca errores en los CHECK antes de correrlo, ignóralos: su revisión previa se equivoca con ellos.
--   Lo que vale es el mensaje final del servidor, en verde.
-- SE PUEDE CORRER DOS VECES
--   Las tablas van con CREATE TABLE IF NOT EXISTS y el rol con INSERT … WHERE NOT EXISTS: la segunda corrida no cambia
--   nada.
-- LO QUE CUIDA LA BASE (11 reglas CHECK y las llaves UNIQUE)
--   · La contraseña nunca se guarda: solo su huella, con la forma que da password_hash() de PHP (Argon2id o bcrypt).
--     Un texto en claro o una huella con otro prefijo se rechazan.
--   · El correo, con forma de correo, en minúsculas y sin repetirse; la clave y el nombre del rol, sin repetirse; los
--     nombres, sin quedar vacíos; y el correo, los nombres y la clave del rol, sin caracteres de control (por ejemplo,
--     un salto de línea pegado al final).
--   · A lo sumo un rol de sistema, y siempre activo.
-- LO QUE LA BASE SOLA NO PUEDE CUIDAR (lo cuidará el módulo de Usuarios, en un episodio siguiente)
--   · Que siempre quede al menos un Super administrador activo.
--   · Que nadie borre el rol SUPERADMIN mientras no tenga usuarios (con usuarios, ya lo impide la llave foránea).
--   En este episodio ninguna pantalla borra ni desactiva roles o usuarios.
-- SI FALLA A MEDIAS
--   Mientras las dos tablas estén vacías, bórralas en phpMyAdmin (primero usuarios y después roles, por la llave
--   foránea) y córrelo otra vez.
-- COMPROBACIÓN
--   Después, en una ejecución aparte: verificar_001_acceso_roles_usuarios.sql (23 filas, todas OK).
-- =====================================================================================================================


-- PASO 1 · roles
CREATE TABLE IF NOT EXISTS roles (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                 COMMENT 'Clave natural en MAYÚSCULAS (SUPERADMIN, ALMACEN…); no cambia',
  nombre         VARCHAR(60) NOT NULL COMMENT 'Nombre visible, en capitalización natural; único',
  descripcion    VARCHAR(255) NULL,
  es_sistema     TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT '1 = Super administrador: acceso total; no se borra ni se desactiva; a lo sumo uno',
  sistema_lock   TINYINT AS (NULLIF(es_sistema, 0)) PERSISTENT
                 COMMENT 'Generada: 1 si es de sistema, NULL si no; su UNIQUE deja a lo sumo un rol de sistema',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                 COMMENT 'Bloqueo optimista: no se quita',
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_clave (clave),
  UNIQUE KEY uq_roles_nombre (nombre),
  UNIQUE KEY uq_roles_sistema (sistema_lock),
  CONSTRAINT chk_roles_clave          CHECK (clave REGEXP '^[A-Z][A-Z0-9_]{1,29}$'
                                             AND clave NOT REGEXP '[[:cntrl:]]'),
  CONSTRAINT chk_roles_nombre         CHECK (nombre NOT REGEXP '^[[:space:]]*$' AND nombre NOT REGEXP '[[:cntrl:]]'),
  CONSTRAINT chk_roles_es_sistema     CHECK (es_sistema IN (0, 1)),
  CONSTRAINT chk_roles_activo         CHECK (activo IN (0, 1)),
  CONSTRAINT chk_roles_sistema_activo CHECK (es_sistema = 0 OR activo = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles del personal. Escribe: el Service de roles. El de sistema no se borra ni se desactiva.';


-- PASO 2 · usuarios
CREATE TABLE IF NOT EXISTS usuarios (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rol_id                INT UNSIGNED NOT NULL COMMENT 'Su rol; un rol con usuarios no se borra (RESTRICT)',
  nombre                VARCHAR(100) NOT NULL COMMENT 'Nombre completo, como se teclea',
  correo                VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
                        COMMENT 'Con él se entra: en minúsculas, solo ASCII y único en todo el sistema',
  password_hash         VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
                        COMMENT 'Lo que devuelve password_hash() de PHP. Nunca la contraseña',
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 1
                        COMMENT '1 = al entrar, la cambia antes de usar el sistema (inicio de sesión parcial)',
  sesion_version        INT UNSIGNED NOT NULL DEFAULT 1
                        COMMENT 'Sube al cambiar o restablecer la contraseña: corta las sesiones abiertas',
  activo                TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Los usuarios no se borran: se desactivan',
  creado_en             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        COMMENT 'Bloqueo optimista: no se quita',
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_correo (correo),
  KEY ix_usuarios_rol (rol_id),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_usuarios_nombre      CHECK (nombre NOT REGEXP '^[[:space:]]*$' AND nombre NOT REGEXP '[[:cntrl:]]'),
  CONSTRAINT chk_usuarios_correo      CHECK (correo REGEXP '^[a-z0-9._%+-]+@[a-z0-9-]+([.][a-z0-9-]+)+$'
                                             AND correo NOT REGEXP '[[:cntrl:]]'),
  CONSTRAINT chk_usuarios_correo_min  CHECK (CAST(correo AS BINARY) = CAST(LOWER(correo) AS BINARY)),
  CONSTRAINT chk_usuarios_hash        CHECK (password_hash REGEXP CONCAT(
                                               '^([$]2y[$](1[0-9]|2[0-9]|3[01])[$][./A-Za-z0-9]{53}',
                                               '|[$]argon2id[$]v=19[$]m=[0-9]+,t=[0-9]+,p=[0-9]+',
                                               '[$][A-Za-z0-9+/]+[$][A-Za-z0-9+/]+)$')
                                             AND password_hash NOT REGEXP '[[:cntrl:]]'),
  CONSTRAINT chk_usuarios_debe_cambiar CHECK (debe_cambiar_password IN (0, 1)),
  CONSTRAINT chk_usuarios_activo      CHECK (activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Personal con acceso. Escribe: el Service de acceso y cron/crear_admin.php. Sin datos de empleado.';


-- PASO 3 · el rol de sistema (semilla contra su clave natural, nunca contra el id)
INSERT INTO roles (clave, nombre, descripcion, es_sistema, activo)
SELECT 'SUPERADMIN', 'Super administrador', 'Rol de sistema: acceso total. No se borra ni se desactiva.', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE clave = 'SUPERADMIN');
