-- =====================================================================================================================
-- 002 · La tabla accesos: la bitácora del inicio de sesión                (episodio 05 · paso 1 del README, 2 de 4)
-- =====================================================================================================================
-- QUÉ HACE
--   Crea la tabla accesos, con dos llaves foráneas a usuarios (RESTRICT). Ahí queda cada entrada, cada fallo, cada
--   salida y los bloqueos, y también los cambios de cuenta. De esta tabla sale el límite de intentos. No toca roles ni
--   usuarios, y no agrega filas.
-- ORDEN
--   Va DESPUÉS del 001: apunta a la tabla usuarios y, sin ella, phpMyAdmin responde el error #1005. Y va antes de
--   subir el sitio: el inicio de sesión escribe aquí en cada intento y, sin la tabla, nadie puede entrar.
-- CÓMO SE CORRE
--   phpMyAdmin → tu base en la lista de la izquierda → pestaña SQL → pega TODO este archivo → Continuar.
--   Si phpMyAdmin marca errores en los CHECK antes de correrlo, ignóralos: lo que vale es el mensaje final del
--   servidor, en verde. Si la tabla no aparece en la lista de la izquierda, recarga la página.
--   Pide MariaDB 10.5 o más: la IP se guarda con el tipo INET6 (XAMPP trae la 10.4 y aquí falla).
-- SE PUEDE CORRER DOS VECES
--   CREATE TABLE IF NOT EXISTS: la segunda corrida no cambia nada.
-- LO QUE GUARDA Y LO QUE NO
--   · El correo tecleado NUNCA va en claro: va su huella HMAC-SHA256 (64 caracteres), calculada por el sistema con la
--     clave ACCESO_HMAC_CLAVE de tu env.php. Así, una contraseña tecleada por error en el campo del correo no queda
--     escrita, y los intentos se cuentan por correo aunque la cuenta no exista.
--   · Nunca la contraseña, ni completa ni en parte.
--   · motivo es interno (PASSWORD, NO_EXISTE, INACTIVO…): el sistema nunca se lo enseña a quien intenta entrar.
-- LO QUE CUIDA LA BASE (7 reglas CHECK)
--   Que cada evento traiga lo que le toca (un fallo, su motivo; una entrada, su usuario), que la huella del correo
--   tenga su forma, que una entrada web traiga su IP y que ningún texto traiga caracteres de control.
-- BUENO SABER
--   · La bitácora solo crece: la tarea cron/purgar_accesos.php (paso 7 del README) borra cada noche lo viejo, en lotes:
--     los intentos y sesiones de más de 90 días y los cambios de cuenta de más de un año.
--   · Un usuario que ya aparece aquí no se puede borrar (RESTRICT). Está bien: los usuarios no se borran, se desactivan.
--   · En phpMyAdmin, una IP se busca como texto: WHERE ip = '203.0.113.7'.
--   · Los CHECK no viajan en los respaldos que exporta phpMyAdmin: por eso la tarea cron/verificar_checks.php vigila
--     cada noche que sigan en su lugar.
-- SI FALLA A MEDIAS
--   Mientras la tabla esté vacía y nada la use, bórrala en phpMyAdmin y córrelo otra vez.
-- COMPROBACIÓN
--   Después, en una ejecución aparte: verificar_002_acceso_bitacora_accesos.sql (14 filas, todas OK).
-- =====================================================================================================================


CREATE TABLE IF NOT EXISTS accesos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento      ENUM('ENTRADA', 'FALLIDA', 'BLOQUEADA', 'SALIDA',
                   'CUENTA_CREADA', 'PASSWORD_CAMBIADA', 'PASSWORD_RESTABLECIDA', 'SESION_CORTADA') NOT NULL
              COMMENT 'Se amplía al final y antes que el código; nunca se reordena',
  origen      ENUM('WEB', 'CLI') NOT NULL DEFAULT 'WEB'
              COMMENT 'CLI = un script por SSH (crear_admin.php), sin IP',
  usuario_id  INT UNSIGNED NULL COMMENT 'A quién le pasó; NULL si el correo no existe',
  actor_id    INT UNSIGNED NULL COMMENT 'Quién lo hizo, si no fue el mismo usuario (un administrador que restablece)',
  correo_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
              COMMENT 'HMAC-SHA256 en hexadecimal del correo tecleado, normalizado; nunca el correo en claro',
  motivo      VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL
              COMMENT 'Interno, en MAYÚSCULAS: por qué falló, se bloqueó o se cortó. Nunca se enseña al usuario',
  ip          INET6 NULL COMMENT 'REMOTE_ADDR, normalizada por la app; vacía solo si origen = CLI',
  agente      VARCHAR(255) NULL COMMENT 'El navegador, recortado y sin caracteres de control',
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Sin actualizado_en: la bitácora no se edita',
  PRIMARY KEY (id),
  KEY ix_accesos_ip_fecha (ip, creado_en),
  KEY ix_accesos_correo_fecha (correo_hmac, creado_en),
  KEY ix_accesos_usuario_fecha (usuario_id, creado_en),
  KEY ix_accesos_actor (actor_id),
  KEY ix_accesos_fecha (creado_en),
  CONSTRAINT fk_accesos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
             ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_accesos_actor   FOREIGN KEY (actor_id) REFERENCES usuarios (id)
             ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_accesos_enum_vacio   CHECK (evento <> '' AND origen <> ''),
  CONSTRAINT chk_accesos_correo_hmac  CHECK (correo_hmac IS NULL
                                             OR (CHAR_LENGTH(correo_hmac) = 64
                                                 AND correo_hmac REGEXP '^[0-9a-f]{64}$'
                                                 AND correo_hmac NOT REGEXP '[[:cntrl:]]')),
  CONSTRAINT chk_accesos_motivo       CHECK (motivo IS NULL
                                             OR (motivo REGEXP '^[A-Z][A-Z_]{1,29}$'
                                                 AND motivo NOT REGEXP '[[:cntrl:]]')),
  CONSTRAINT chk_accesos_motivo_evento CHECK ((evento IN ('FALLIDA', 'BLOQUEADA', 'SESION_CORTADA'))
                                              = (motivo IS NOT NULL)),
  CONSTRAINT chk_accesos_ip_origen    CHECK (ip IS NOT NULL OR origen = 'CLI'),
  CONSTRAINT chk_accesos_usuario_evento CHECK (evento IN ('FALLIDA', 'BLOQUEADA') OR usuario_id IS NOT NULL),
  CONSTRAINT chk_accesos_agente       CHECK (agente IS NULL OR agente NOT REGEXP '[[:cntrl:]]')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora de accesos y cambios de cuenta. Escriben: Service de acceso y crear_admin.php. Solo crece.';
