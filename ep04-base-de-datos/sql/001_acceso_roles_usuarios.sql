-- =====================================================================================================================
-- 001 · Acceso: roles y usuarios (sesión de la serie, 07-oct-2026)
-- =====================================================================================================================
-- POR QUÉ
--   El login de OmarFerDev necesita saber quién entra (usuarios) y con qué rol (roles). El Super administrador es un
--   rol de sistema: no se borra ni se desactiva. Diseño: el README.md de este episodio.
-- QUÉ HACE
--   1. Crea la tabla roles.
--   2. Crea la tabla usuarios, con su llave foránea a roles (RESTRICT).
--   3. Siembra el rol SUPERADMIN (es_sistema = 1).
--   NO crea usuarios (el primero lo crea cron/crear_admin.php por SSH) ni toca otra tabla.
-- PRE-VUELO (medido el 07-oct con consultas/medir-servidor.sql)
--   · La base tiene 0 tablas: nadie lee ni escribe estas tablas todavía, y no hay llaves foráneas entrantes.
--   · MariaDB 11.8.9; utf8mb4_unicode_ci; lower_case_table_names 0; explicit_defaults_for_timestamp ON.
--   · phpMyAdmin NO es estricto (sql_mode NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION): el script no depende de
--     valores implícitos, y los CHECK de texto vacío atrapan el NULL que phpMyAdmin convierte en ''.
-- ORDEN FRENTE AL CÓDIGO
--   ANTES del código: el login lee estas tablas. Al revés, la app responde #1146 (la tabla no existe) al entrar.
-- IDEMPOTENTE
--   CREATE TABLE IF NOT EXISTS, y la semilla con INSERT … WHERE NOT EXISTS sobre la clave SUPERADMIN: la segunda
--   corrida no cambia nada. No es INSERT IGNORE a propósito: medido en laboratorio (07-oct), IGNORE convierte en un
--   aviso mudo el choque con un rol que ya se llame «Super administrador» y el script terminaría sin sembrarlo;
--   NOT EXISTS deja ver ese choque como error #1062.
--   ⛔ Este CREATE no se edita una vez corrido: todo cambio posterior es un script nuevo con ALTER.
-- RIESGOS DECLARADOS
--   · «Al menos un Super administrador activo» no lo puede garantizar la base: lo cuida el Service, bajo lock.
--   · Que el rol SUPERADMIN siga siendo de sistema (es_sistema = 1) tampoco: la base garantiza «a lo sumo uno» y que
--     no se desactive; quitarle la marca lo impide el Service de roles en sus cuatro caminos de mutación.
--   · Borrar el rol SUPERADMIN con DELETE desde phpMyAdmin solo lo frena RESTRICT cuando ya tiene usuarios; con 0
--     usuarios, pasa. La base impide desactivarlo (chk_roles_sistema_activo), no borrarlo: lo cuida el Service.
--   · chk_usuarios_hash acepta solo la forma completa de password_hash(): bcrypt $2y$ con costo de 10 a 31 y 53
--     caracteres, o Argon2id v=19. Rechaza una contraseña en texto plano, un prefijo falso y un hash recortado. Si
--     algún día se usa otro algoritmo, primero va un script con el CHECK nuevo y después el código.
--   · Los CHECK de texto también rechazan caracteres de control: en MariaDB el «$» de un REGEXP casa antes de un
--     salto de línea final, y sin esa regla «a@b.co» con un salto al final pasaba como otro correo (medido en
--     laboratorio, 07-oct, tras la revisión del auditor de base de datos).
--   · Los CHECK con nombre no viajan en los respaldos de phpMyAdmin: el detector y su manifiesto llegan con el
--     código PHP (llegan en un episodio posterior).
--   · Probado antes en un laboratorio local con MariaDB 10.4 (dos corridas, los controles que deben fallar y hashes
--     reales de PHP 8.2); en el servidor corre la 11.8.
-- SI LA PRIMERA CORRIDA FALLA A MEDIAS
--   Mientras las dos tablas estén vacías y nada las use, se borran a mano en phpMyAdmin (primero usuarios y después
--   roles, por la llave foránea) y se corre otra vez el 001 corregido. Con datos o con código encima, ya no: va un
--   script nuevo con ALTER. (Este comentario no lleva código SQL a propósito: phpMyAdmin parte el texto por los
--   puntos y coma.)
-- EN PHPMYADMIN
--   Su «análisis estático» marca errores falsos en los CHECK escritos en línea: lo que vale es el mensaje del
--   servidor («Importación finalizada…» o la línea «MySQL ha dicho»).
-- COMPROBACIÓN
--   En un lote APARTE: verificar_001_acceso_roles_usuarios.sql (mezclar DDL con information_schema aborta el lote).
-- Lo corre el USUARIO en phpMyAdmin: base inventario → pestaña Importar (o SQL).
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
