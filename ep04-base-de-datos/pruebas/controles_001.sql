-- Controles del 001 (v3) en el laboratorio. Cada línea dice lo esperado ANTES de correr.
-- Hash real de PHP 8.2 (contraseña de laboratorio, no es secreta), para que cada control falle por SU motivo.
SET @h = '$2y$12$dTwWJtNWZHUJ1BqP6C5ZxO0GxXxfp.ElBXfewA13U/TLP8kyErY.y';
SELECT 'C1 esperado #1062 uq_roles_sistema: un segundo rol de sistema' AS control;
INSERT INTO roles (clave, nombre, es_sistema) VALUES ('OTRO_SISTEMA', 'Otro de sistema', 1);
SELECT 'C2 esperado #4025 chk_roles_sistema_activo: desactivar el rol de sistema' AS control;
UPDATE roles SET activo = 0 WHERE clave = 'SUPERADMIN';
SELECT 'C3 esperado #4025 chk_roles_clave: clave en minúsculas' AS control;
INSERT INTO roles (clave, nombre) VALUES ('almacen', 'Almacén');
SELECT 'C4 esperado #4025 chk_roles_clave: clave con salto de línea final' AS control;
INSERT INTO roles (clave, nombre) VALUES (CONCAT('ALMACEN', CHAR(10)), 'Almacén');
SELECT 'C5 esperado #4025 chk_usuarios_correo: correo sin arroba' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, 'Ana Prueba', 'sin-arroba', @h FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C6 esperado #4025 chk_usuarios_correo: correo con salto de línea final' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, 'Ana Prueba', CONCAT('ana@correo.mx', CHAR(10)), @h FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C7 esperado #4025 chk_usuarios_correo_min: correo con mayúsculas' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, 'Ana Prueba', 'Ana@Correo.mx', @h FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C8 esperado #4025 chk_usuarios_hash: contraseña en texto plano' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, 'Ana Prueba', 'ana@correo.mx', 'MiContrasena123' FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C9 esperado #4025 chk_usuarios_hash: prefijo falso con texto' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, 'Ana Prueba', 'ana@correo.mx', '$2y$secreto' FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C10 esperado #4025 chk_usuarios_nombre: nombre en blanco' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, '   ', 'ana@correo.mx', @h FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C11 esperado #4025 chk_usuarios_nombre: nombre de un tabulador' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, CHAR(9), 'ana@correo.mx', @h FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C12 esperado #1452 fk_usuarios_rol: rol que no existe' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash) VALUES (999999, 'Ana Prueba', 'ana@correo.mx', @h);
SELECT 'C13 esperado OK: un usuario válido (control positivo de los anteriores)' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash, debe_cambiar_password)
SELECT id, 'José Peña', 'ana@correo.mx', @h, 0 FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C14 esperado #1062 uq_usuarios_correo: el mismo correo otra vez (con un Argon2id real)' AS control;
INSERT INTO usuarios (rol_id, nombre, correo, password_hash)
SELECT id, 'Ana Duplicada', 'ana@correo.mx',
       '$argon2id$v=19$m=65536,t=4,p=1$ZzUxTjdsUzJVYjNEc1MwTg$t0fNa5Uv7bYYWbHx+zmRjb3252H+KYTdthaXIcXZGdw'
FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'C15 esperado #1451 fk_usuarios_rol: borrar un rol con usuarios' AS control;
DELETE FROM roles WHERE clave = 'SUPERADMIN';
SELECT 'Estado final esperado: SUPERADMIN 1 1, 1 usuario (ana@correo.mx)' AS control;
SELECT clave, es_sistema, activo, (SELECT COUNT(*) FROM usuarios) AS usuarios,
       (SELECT GROUP_CONCAT(correo) FROM usuarios) AS correos FROM roles;
