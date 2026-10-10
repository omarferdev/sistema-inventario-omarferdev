-- =====================================================================================================================
-- verificar_002 · La comprobación del 002 (la bitácora) · SOLO LEE        (episodio 05 · paso 1 del README, 4 de 4)
-- =====================================================================================================================
-- ANTES DE PEGARLO: estas consultas buscan la tabla en la base «inventario». En tu editor, con Buscar y reemplazar
--   → Reemplazar todo, cambia ese nombre por el de TU base en todo este archivo (en Hostinger lleva delante tu prefijo,
--   tal como sale en el panel). Son 14 lugares, contando el de este aviso. Si no lo cambias, phpMyAdmin responde el error #1142 («SELECT
--   command denied to user … for table …»): busca la tabla en una base que no es la tuya.
-- CUÁNDO: después de correr el 002, en una ejecución APARTE (phpMyAdmin → tu base → pestaña SQL → pega todo →
--   Continuar). Si lo pegas junto con el 002, phpMyAdmin aborta todo el lote.
-- CÓMO SE LEE: salen 14 filas y la última columna («ok») tiene que decir OK en todas. Una sola FALLA quiere decir que
--   el 002 no quedó como debe: no sigas con el sitio. Para ver la diferencia completa: «Opciones extra» → «Textos
--   completos».
-- QUÉ MIDE: la tabla, sus columnas, sus reglas CHECK, sus índices y sus dos llaves foráneas (con su RESTRICT, que no
--   se ve en SHOW CREATE TABLE), por NOMBRE y no por conteo. La columna «esperado» dice lo que debe salir.
--   · La fila «filas en accesos (recién corrido)» mide la tabla recién creada (0 filas): en cuanto alguien intenta
--     entrar, ya dice FALLA, y está bien. Las demás valen siempre.
--   · La fila «control positivo» es el control: si dice FALLA, la comprobación misma no está leyendo bien y nada de
--     lo demás vale.
-- =====================================================================================================================

-- La comparación es byte a byte (BINARY): con la collation de la conexión, «innodb» y «InnoDB» darían OK.
SELECT verificacion, esperado, obtenido,
       IF(CAST(obtenido AS BINARY) = CAST(esperado AS BINARY), 'OK', 'FALLA') AS ok
FROM (
  SELECT 01 AS n, 'tabla' AS verificacion, 'accesos' AS esperado,
         CAST((SELECT GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos') AS CHAR) AS obtenido
  UNION ALL SELECT 02, 'motor y collation', 'InnoDB utf8mb4_unicode_ci',
         CAST((SELECT CONCAT(ENGINE, ' ', TABLE_COLLATION) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos') AS CHAR)
  UNION ALL SELECT 03, 'columnas', 'id,evento,origen,usuario_id,actor_id,correo_hmac,motivo,ip,agente,creado_en',
         CAST((SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos') AS CHAR)
  UNION ALL SELECT 04, 'CHECK',
         CONCAT('chk_accesos_agente,chk_accesos_correo_hmac,chk_accesos_enum_vacio,chk_accesos_ip_origen,',
                'chk_accesos_motivo,chk_accesos_motivo_evento,chk_accesos_usuario_evento'),
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos') AS CHAR)
  UNION ALL SELECT 05, 'llaves foráneas', 'fk_accesos_actor,fk_accesos_usuario',
         CAST((SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY') AS CHAR)
  UNION ALL SELECT 06, 'FK: tabla padre / al borrar / al actualizar',
         'fk_accesos_actor:usuarios/RESTRICT/RESTRICT,fk_accesos_usuario:usuarios/RESTRICT/RESTRICT',
         CAST((SELECT GROUP_CONCAT(CONCAT(CONSTRAINT_NAME, ':', REFERENCED_TABLE_NAME, '/', DELETE_RULE, '/',
                                          UPDATE_RULE) ORDER BY CONSTRAINT_NAME)
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos') AS CHAR)
  UNION ALL SELECT 07, 'FK: columna -> tabla.columna', 'actor_id -> usuarios.id,usuario_id -> usuarios.id',
         CAST((SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, ' -> ', REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME)
                                   ORDER BY COLUMN_NAME)
                 FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                  AND REFERENCED_TABLE_NAME IS NOT NULL) AS CHAR)
  UNION ALL SELECT 08, 'índices: nombre y columnas en orden',
         CONCAT('PRIMARY:id,ix_accesos_actor:actor_id,ix_accesos_correo_fecha:correo_hmac+creado_en,',
                'ix_accesos_fecha:creado_en,ix_accesos_ip_fecha:ip+creado_en,',
                'ix_accesos_usuario_fecha:usuario_id+creado_en'),
         CAST((SELECT GROUP_CONCAT(CONCAT(INDEX_NAME, ':', cols) ORDER BY CAST(INDEX_NAME AS BINARY))
                 FROM (SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR '+') AS cols
                         FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                        GROUP BY INDEX_NAME) AS i) AS CHAR)
  UNION ALL SELECT 09, 'tipo de accesos.ip', 'inet6',
         CAST((SELECT DATA_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                  AND COLUMN_NAME = 'ip') AS CHAR)
  UNION ALL SELECT 10, 'valores de accesos.evento',
         CONCAT('enum(''ENTRADA'',''FALLIDA'',''BLOQUEADA'',''SALIDA'',''CUENTA_CREADA'',''PASSWORD_CAMBIADA'',',
                '''PASSWORD_RESTABLECIDA'',''SESION_CORTADA'')'),
         CAST((SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                  AND COLUMN_NAME = 'evento') AS CHAR)
  UNION ALL SELECT 11, 'collation de correo_hmac y de motivo', 'ascii_bin,ascii_bin',
         CAST((SELECT GROUP_CONCAT(COLLATION_NAME ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                  AND COLUMN_NAME IN ('correo_hmac', 'motivo')) AS CHAR)
  UNION ALL SELECT 12, 'filas en accesos (recién corrido)', '0',
         CAST((SELECT COUNT(*) FROM inventario.accesos) AS CHAR)
  UNION ALL SELECT 13, 'control positivo: tablas en information_schema > 0', 'sí',
         CAST(IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = 'information_schema') > 0, 'sí', 'no') AS CHAR)
  -- 14: tipo, nulo y valor por defecto de cada columna (menos evento, que mide la 10). Una tabla
  -- accesos VIEJA con los mismos nombres y otros tipos pasaba la 03 (el CREATE … IF NOT EXISTS se la salta callado).
  -- El literal se midió en el laboratorio 11.8.9 (08-oct).
  UNION ALL SELECT 14, 'tipo, nulo y por defecto de cada columna',
         CONCAT('id:bigint(20) unsigned:NO:-,origen:enum(''WEB'',''CLI''):NO:''WEB'',usuario_id:int(10) unsigned:YES:NULL,',
                'actor_id:int(10) unsigned:YES:NULL,correo_hmac:char(64):YES:NULL,motivo:varchar(30):YES:NULL,',
                'ip:inet6:YES:NULL,agente:varchar(255):YES:NULL,creado_en:timestamp:NO:current_timestamp()'),
         CAST((SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE, ':', IFNULL(COLUMN_DEFAULT, '-'))
                                   ORDER BY ORDINAL_POSITION SEPARATOR ',')
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = 'inventario' AND TABLE_NAME = 'accesos'
                  AND COLUMN_NAME <> 'evento') AS CHAR)
) AS v
ORDER BY n;
