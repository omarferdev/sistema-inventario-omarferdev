# Sistema de inventario desde cero — los archivos de la serie

Los archivos de la serie de @omarferdev en TikTok, donde construimos paso a paso un sistema de inventario (ERP/WMS)
en **PHP y MariaDB**, sin frameworks.

Cada video tiene aquí su carpeta, con todo lo que se usó en él, para que lo puedas descargar, leer y probar en tu
computadora.

## Cómo está organizado

- **Una carpeta por episodio** (`ep04-base-de-datos/`, `ep05-…/`). Cada una guarda los archivos **tal como quedaron en
  ese video**: una vez publicada, no se cambia. Lo nuevo llega en la carpeta del episodio siguiente, así puedes seguir
  el paso a paso en orden.
- **Cada carpeta trae su `README.md`**: qué hace, cómo probarlo y qué te debe salir.
- **Una etiqueta por episodio** (`ep04`, `ep05`…), por si quieres descargar el repositorio como estaba en ese video.

## Episodios

| # | Carpeta | Qué se construye |
|---|---|---|
| 04 | [`ep04-base-de-datos/`](ep04-base-de-datos/README.md) | La base de datos del sistema: las tablas `roles` y `usuarios` del inicio de sesión, con sus reglas de seguridad y sus pruebas |

Los videos 01 a 03 (la presentación de la serie, el hosting y phpMyAdmin) no tienen archivos.

## Qué necesitas para probar

- **XAMPP** (gratis), que trae MariaDB y phpMyAdmin: <https://www.apachefriends.org/>. Con eso basta para los primeros
  episodios.
- Cada script es **idempotente**: lo puedes correr dos veces sin dañar nada.

## Licencia

[MIT](LICENSE): puedes usar, copiar y modificar estos archivos, también en tus propios proyectos, citando el origen.
Todos los datos que aparecen (nombres, correos, contraseñas de ejemplo) son ficticios.
