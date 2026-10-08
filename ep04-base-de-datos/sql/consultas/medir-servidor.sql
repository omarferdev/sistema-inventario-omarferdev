-- =====================================================================================================================
-- medir-servidor.sql · consulta de SOLO LECTURA (no cambia nada) · v2
-- ---------------------------------------------------------------------------------------------------------------------
-- Por qué:   antes de diseñar la primera tabla se miden los valores del servidor que se tratan como
--            «por medir»: nunca se supone un valor del servidor. Un valor
--            supuesto (el nivel de aislamiento) tumbó una investigación entera.
-- Cuándo:    en el paso 0, y otra vez cuando el hosting actualice MariaDB (lo hace sin avisar).
-- Cómo:      phpMyAdmin → base inventario → pestaña SQL. Un lote a la vez: se copia, se ejecuta y se
--            guarda el resultado antes de pasar al siguiente.
-- No lleva:  número de migración (SQL/NNN): no toca el esquema.
-- Seguridad: ⛔ no se usa SHOW GRANTS. En MariaDB imprime el hash de la contraseña de la cuenta
--            («IDENTIFIED BY PASSWORD '*…'»), y ese hash no debe salir ni en el chat ni en una captura. Los permisos
--            se leen de information_schema, que no lo trae.
-- Historia:  v1 (07-oct) con SHOW GRANTS y solo los valores de la sesión; v2 (07-oct), tras revisarla contra las
--            buenas prácticas: sin el hash, con los valores globales (los de la app) junto a los de la sesión de phpMyAdmin, y
--            con el usuario autenticado, los límites de conexiones y explicit_defaults_for_timestamp.
--
-- ESPERADO, publicado antes de medir:
--   Lote 1 · 22 filas (variable, valor). MariaDB 11.x. usuario_autenticado = tu_usuario@<host>: si es otra
--            cuenta, los permisos del lote 3 no son los de la app. Los valores «_global» son los que hereda la app;
--            los «_sesion», los de phpMyAdmin, y pueden diferir. El aislamiento NO se supone (allá era
--            READ-COMMITTED, que no es el de fábrica).
--            Si sale #1193 (variable desconocida), la versión es anterior a la 11.1: cambiar las dos
--            transaction_isolation por tx_isolation y volver a correrlo.
--   Lote 2 · una fila, tablas = 0 y control_positivo > 0. Si no regresa ninguna fila, el instrumento está mal
--            (el nombre de la base no casa) y el «0» no prueba nada.
--   Lote 3 · al menos una fila «global» (USAGE) y las de «esquema» sobre inventario; la columna total
--            repite en cada fila cuántas hay (phpMyAdmin pagina a 25 sin avisar). 0 filas = instrumento roto, no
--            «sin permisos». Lleva el número de cuenta: ⛔ no sale en un video.
-- =====================================================================================================================


-- Lote 1 · el servidor y quién se conecta (sesión de phpMyAdmin y valores globales, que son los de la app)
SELECT 'version' AS variable, CAST(VERSION() AS CHAR) AS valor
UNION ALL SELECT 'version_comment',                 CAST(@@version_comment AS CHAR)
UNION ALL SELECT 'usuario_autenticado',             CAST(CURRENT_USER() AS CHAR)
UNION ALL SELECT 'usuario_conexion',                CAST(USER() AS CHAR)
UNION ALL SELECT 'aislamiento_global',              CAST(@@global.transaction_isolation AS CHAR)
UNION ALL SELECT 'aislamiento_sesion',              CAST(@@transaction_isolation AS CHAR)
UNION ALL SELECT 'sql_mode_global',                 CAST(@@global.sql_mode AS CHAR)
UNION ALL SELECT 'sql_mode_sesion',                 CAST(@@sql_mode AS CHAR)
UNION ALL SELECT 'zona_global',                     CAST(@@global.time_zone AS CHAR)
UNION ALL SELECT 'zona_sesion',                     CAST(@@time_zone AS CHAR)
UNION ALL SELECT 'zona_sistema',                    CAST(@@system_time_zone AS CHAR)
UNION ALL SELECT 'wait_timeout_global',             CAST(@@global.wait_timeout AS CHAR)
UNION ALL SELECT 'max_statement_time_global',       CAST(@@global.max_statement_time AS CHAR)
UNION ALL SELECT 'max_statement_time_sesion',       CAST(@@max_statement_time AS CHAR)
UNION ALL SELECT 'innodb_lock_wait_timeout',        CAST(@@innodb_lock_wait_timeout AS CHAR)
UNION ALL SELECT 'max_connections',                 CAST(@@max_connections AS CHAR)
UNION ALL SELECT 'max_user_connections',            CAST(@@max_user_connections AS CHAR)
UNION ALL SELECT 'charset_servidor',                CAST(@@character_set_server AS CHAR)
UNION ALL SELECT 'collation_servidor',              CAST(@@collation_server AS CHAR)
UNION ALL SELECT 'motor_por_omision',               CAST(@@default_storage_engine AS CHAR)
UNION ALL SELECT 'lower_case_table_names',          CAST(@@lower_case_table_names AS CHAR)
UNION ALL SELECT 'explicit_defaults_for_timestamp', CAST(@@explicit_defaults_for_timestamp AS CHAR);


-- Lote 2 · la base (con su nombre literal, nunca DATABASE()) y su control positivo
SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME,
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'inventario') AS tablas,
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema') AS control_positivo
FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'inventario';


-- Lote 3 · los permisos de la cuenta, sin el hash de su contraseña (en lugar de SHOW GRANTS)
SELECT nivel, GRANTEE, esquema, PRIVILEGE_TYPE, COUNT(*) OVER () AS total
FROM (
    SELECT 1 AS orden, 'global' AS nivel, GRANTEE, '*' AS esquema, PRIVILEGE_TYPE
      FROM information_schema.USER_PRIVILEGES
    UNION ALL
    SELECT 2, 'esquema', GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE
      FROM information_schema.SCHEMA_PRIVILEGES
) AS p
ORDER BY orden, esquema, PRIVILEGE_TYPE;
