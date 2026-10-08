# Episodio 04 — Creamos la base de datos del sistema

Las dos primeras tablas del sistema, las del inicio de sesión: **`roles`** (qué puede hacer cada persona) y
**`usuarios`** (quién entra). En el video se corrieron en el servidor (MariaDB 11.8) y la comprobación dio 23 de 23 en
OK; también se probaron en XAMPP (MariaDB 10.4).

## Qué hay en esta carpeta

| Archivo | Qué es |
|---|---|
| `sql/001_acceso_roles_usuarios.sql` | El script que crea las dos tablas y el rol de sistema `SUPERADMIN` |
| `sql/verificar_001_acceso_roles_usuarios.sql` | La comprobación: 23 revisiones de solo lectura, cada una con su OK o FALLA |
| `sql/consultas/medir-servidor.sql` | Lo que medimos del servidor antes de crear nada (solo lectura) |
| `pruebas/controles_001.sql` | 15 pruebas: 14 que la base **debe rechazar** y 1 que debe aceptar |

## Las tablas

**`roles`** — `id`, `clave` (en MAYÚSCULAS, como `SUPERADMIN`), `nombre`, `descripcion`, `es_sistema`, `activo`,
`creado_en` y `actualizado_en`.

- Solo puede haber **un rol de sistema** (el Super administrador): lo garantiza un índice único sobre una columna
  generada, `sistema_lock`.
- El rol de sistema **no se puede desactivar** (una regla `CHECK`).

**`usuarios`** — `id`, `rol_id`, `nombre`, `correo`, `password_hash`, `debe_cambiar_password`, `sesion_version`,
`activo`, `creado_en` y `actualizado_en`.

- **Con el correo se entra**, y es único sin importar mayúsculas.
- **La contraseña nunca se guarda**: se guarda su huella (el resultado de `password_hash()` de PHP, con bcrypt o
  Argon2id). La base rechaza cualquier cosa que no tenga la forma completa de una huella.
- `sesion_version` deja listo cortar las sesiones abiertas cuando alguien cambia su contraseña.
- Las dos tablas van unidas por una **llave foránea** con `RESTRICT`: no se puede borrar un rol que tiene usuarios.

## Las reglas que pone la base (`CHECK`)

- La clave del rol, en MAYÚSCULAS.
- El nombre, sin quedar en blanco.
- El correo, con forma de correo y en minúsculas.
- La contraseña, solo como huella completa (bcrypt con costo de 10 a 31, o Argon2id).
- Los valores 0 o 1 en las banderas.
- Sin caracteres de control. En MariaDB, el `$` de una expresión regular acepta también un salto de línea al final, y
  sin esta regla `a@b.co` más un salto de línea pasaba como un correo distinto.

## Cómo probarlo en tu computadora (XAMPP)

1. Instala XAMPP y, en su panel, arranca **Apache** y **MySQL** (es MariaDB).
2. Abre <http://localhost/phpmyadmin>.
3. Crea una base llamada **`inventario`** con el cotejamiento `utf8mb4_unicode_ci`. Usa ese nombre: la comprobación lo
   busca así.
4. Selecciona la base `inventario`, entra a la pestaña **Importar**, elige `sql/001_acceso_roles_usuarios.sql` y dale
   **Continuar**. Si phpMyAdmin marca errores en los `CHECK` antes de correr, ignóralos: lo que vale es el mensaje final
   del servidor.
5. En la pestaña **SQL**, pega `sql/verificar_001_acceso_roles_usuarios.sql` y dale **Continuar**: tienen que salir
   **23 filas con OK**.
6. **Las pruebas:** en la pestaña SQL, corre los controles de `pruebas/controles_001.sql` **uno por uno** (phpMyAdmin se
   detiene en el primer error), y pega cada uno **junto con la primera línea del archivo** (`SET @h = …`): phpMyAdmin
   no guarda esa variable de una consulta a otra. Cada control dice qué error debe salir: por ejemplo, el `#4025`
   cuando una regla `CHECK` rechaza el dato, o el `#1062` cuando el correo ya existe.
7. Después de las pruebas queda un usuario de ejemplo, así que la comprobación marcará FALLA en «usuarios en total».
   Para empezar de cero, borra las tablas (primero `usuarios` y después `roles`) y vuelve al paso 4.

Si corres el `001` dos veces no pasa nada: es **idempotente**.

**La consulta de medición** (`sql/consultas/medir-servidor.sql`) es opcional y solo lee. En XAMPP, que trae MariaDB
10.4, su primer lote da el error `#1193`, porque la variable `transaction_isolation` llegó en la 11.1: cambia las dos
apariciones por `tx_isolation` y vuelve a correrlo (la cabecera del archivo lo explica).

## Notas

- Todos los datos son ficticios. La huella de las pruebas es de una contraseña de laboratorio, no de una cuenta real.
- El primer usuario administrador no se crea aquí: llega con el código PHP del inicio de sesión, en un episodio
  posterior.
