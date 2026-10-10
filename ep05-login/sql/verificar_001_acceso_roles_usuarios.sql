-- =====================================================================================================================
-- verificar_001 · La comprobación del 001 (roles y usuarios) · SOLO LEE   (episodio 05 · paso 1 del README, 3 de 4)
-- =====================================================================================================================
-- ANTES DE PEGARLO: estas consultas buscan las tablas en la base «inventario». En tu editor, con Buscar y reemplazar
--   → Reemplazar todo, cambia ese nombre por el de TU base en todo este archivo (en Hostinger lleva delante tu prefijo,
--   tal como sale en el panel). Son 23 lugares, contando el de este aviso. Si no lo cambias, phpMyAdmin responde el error #1142 («SELECT command denied
--   to user … for table …»): busca las tablas en una base que no es la tuya.
-- CUÁNDO: después de correr el 001, en una ejecución APARTE (phpMyAdmin → tu base → pestaña SQL → pega todo →
--   Continuar). Si lo pegas junto con el 001, phpMyAdmin aborta todo el lote.
-- CÓMO SE LEE: salen 23 filas y la última columna («ok») tiene que decir OK en todas. Una sola FALLA quiere decir que
--   el 001 no quedó como debe: no sigas con el sitio. Para ver la diferencia completa: «Opciones extra» → «Textos
--   completos» (phpMyAdmin recorta las celdas largas).
-- QUÉ MIDE: las tablas, sus columnas, sus reglas CHECK, sus UNIQUE y su llave foránea, por NOMBRE y no por conteo (un
--   conteo puede cuadrar con los objetos equivocados). La columna «esperado» dice lo que debe salir.
--   · Cuatro filas miden la base recién creada: «roles en total», «SUPERADMIN: de sistema / activo», «roles de
--     sistema» y «usuarios en total» (1 rol y 0 usuarios). En cuanto creas tu cuenta en /instalar, «usuarios en
--     total» ya dice FALLA, y está bien. Las demás valen siempre.
--   · La fila «control positivo» es el control: si dice FALLA, la comprobación misma no está leyendo bien y nada de
--     lo demás vale.
-- =====================================================================================================================

-- La comparación es byte a byte (BINARY): con la collation de la conexión, «innodb» y «InnoDB» darían OK.
SELECT verificacion, esperado, obtenido,
       IF(CAST(obtenido AS BINARY) = CAST(esperado AS BINARY), 'OK', 'FALLA') AS ok
FROM (
  SELECT 01 AS n, 'tablas' AS verificacion, 'roles,usuarios' AS esperado,
         CAST((SELECT GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME IN ('roles', 'usuarios')) AS CHAR)
         AS obtenido
  UNION ALL SELECT 02, 'motor y collation de las dos tablas',
         'InnoDB utf8mb4_unicode_ci,InnoDB utf8mb4_unicode_ci',
         CAST((SELECT GROUP_CONCAT(CONCAT(ENGINE, ' ', TABLE_COLLATION) ORDER BY TABLE_NAME)
                 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME IN ('roles', 'usuarios')) AS CHAR)
  UNION ALL SELECT 03, 'columnas de roles',
         'id,clave,nombre,descripcion,es_sistema,sistema_lock,activo,creado_en,actualizado_en',
         CAST((SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'roles') AS CHAR)
  UNION ALL SELECT 04, 'columnas de usuarios',
         'id,rol_id,nombre,correo,password_hash,debe_cambiar_password,sesion_version,activo,creado_en,actualizado_en',
         CAST((SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'usuarios') AS CHAR)
  UNION ALL SELECT 05, 'CHECK de roles',
         'chk_roles_activo,chk_roles_clave,chk_roles_es_sistema,chk_roles_nombre,chk_roles_sistema_activo',
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = 'inventario' AND TABLE_NAME = 'roles') AS CHAR)
  UNION ALL SELECT 06, 'CHECK de usuarios',
         CONCAT('chk_usuarios_activo,chk_usuarios_correo,chk_usuarios_correo_min,',
                'chk_usuarios_debe_cambiar,chk_usuarios_hash,chk_usuarios_nombre'),
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = 'inventario' AND TABLE_NAME = 'usuarios') AS CHAR)
  UNION ALL SELECT 07, 'UNIQUE de roles', 'uq_roles_clave,uq_roles_nombre,uq_roles_sistema',
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'roles'
                  AND CONSTRAINT_TYPE = 'UNIQUE') AS CHAR)
  UNION ALL SELECT 08, 'UNIQUE de usuarios', 'uq_usuarios_correo',
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'usuarios'
                  AND CONSTRAINT_TYPE = 'UNIQUE') AS CHAR)
  UNION ALL SELECT 09, 'FK fk_usuarios_rol: tabla padre / al borrar / al actualizar', 'roles / RESTRICT / RESTRICT',
         CAST((SELECT CONCAT(REFERENCED_TABLE_NAME, ' / ', DELETE_RULE, ' / ', UPDATE_RULE)
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = 'inventario' AND CONSTRAINT_NAME = 'fk_usuarios_rol') AS CHAR)
  UNION ALL SELECT 10, 'llaves foráneas de las dos tablas', 'fk_usuarios_rol',
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME IN ('roles', 'usuarios')
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY') AS CHAR)
  UNION ALL SELECT 11, 'roles.sistema_lock es generada y guardada', 'STORED GENERATED',
         CAST((SELECT EXTRA FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'roles'
                  AND COLUMN_NAME = 'sistema_lock') AS CHAR)
  UNION ALL SELECT 12, 'actualizado_en con ON UPDATE en las dos tablas', '2',
         CAST((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME IN ('roles', 'usuarios')
                  AND COLUMN_NAME = 'actualizado_en' AND EXTRA LIKE 'on update%') AS CHAR)
  UNION ALL SELECT 13, 'collation de usuarios.correo', 'ascii_general_ci',
         CAST((SELECT COLLATION_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'usuarios'
                  AND COLUMN_NAME = 'correo') AS CHAR)
  UNION ALL SELECT 14, 'collation de usuarios.password_hash', 'ascii_bin',
         CAST((SELECT COLLATION_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'usuarios'
                  AND COLUMN_NAME = 'password_hash') AS CHAR)
  UNION ALL SELECT 15, 'collation de roles.clave', 'ascii_bin',
         CAST((SELECT COLLATION_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'roles'
                  AND COLUMN_NAME = 'clave') AS CHAR)
  UNION ALL SELECT 16, 'roles en total', '1',
         CAST((SELECT COUNT(*) FROM inventario.roles) AS CHAR)
  UNION ALL SELECT 17, 'SUPERADMIN: de sistema / activo', '1 / 1',
         CAST((SELECT CONCAT(es_sistema, ' / ', activo) FROM inventario.roles WHERE clave = 'SUPERADMIN') AS CHAR)
  UNION ALL SELECT 18, 'roles de sistema', '1',
         CAST((SELECT COUNT(*) FROM inventario.roles WHERE es_sistema = 1) AS CHAR)
  UNION ALL SELECT 19, 'usuarios en total', '0',
         CAST((SELECT COUNT(*) FROM inventario.usuarios) AS CHAR)
  UNION ALL SELECT 20, 'control positivo: tablas en information_schema > 0', 'sí',
         CAST(IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = 'information_schema') > 0, 'sí', 'no') AS CHAR)
  UNION ALL SELECT 21, 'SUPERADMIN: sistema_lock vale 1', '1',
         CAST((SELECT sistema_lock FROM inventario.roles WHERE clave = 'SUPERADMIN') AS CHAR)
  UNION ALL SELECT 22, 'columna de cada UNIQUE',
         'uq_roles_clave:clave,uq_roles_nombre:nombre,uq_roles_sistema:sistema_lock,uq_usuarios_correo:correo',
         CAST((SELECT GROUP_CONCAT(CONCAT(CONSTRAINT_NAME, ':', COLUMN_NAME) ORDER BY CONSTRAINT_NAME)
                 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME IN ('roles', 'usuarios')
                  AND CONSTRAINT_NAME LIKE 'uq%') AS CHAR)
  UNION ALL SELECT 23, 'FK: columna -> tabla.columna', 'rol_id -> roles.id',
         CAST((SELECT CONCAT(COLUMN_NAME, ' -> ', REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME)
                 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = 'inventario' AND CONSTRAINT_NAME = 'fk_usuarios_rol') AS CHAR)
) AS v
ORDER BY n;
