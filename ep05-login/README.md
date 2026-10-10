# Episodio 05 — El inicio de sesión

El primer pedazo del sistema que se ve: **entrar con correo y contraseña**, cambiarla, y la primera cuenta de Super
administrador. Todo en PHP y MariaDB, sin frameworks, y **se instala sin SSH**: con el administrador de archivos,
phpMyAdmin y las tareas cron del panel de tu hosting. Son 8 pasos (del 0 al 7); con calma, unos 30 minutos.

Esta carpeta trae **todo** lo que necesitas para instalarlo desde cero, también las tablas `roles` y `usuarios` del
episodio 04: no necesitas otra carpeta.

## Qué hay en esta carpeta

| Carpeta o archivo | Qué es | ¿Se sube al hosting? |
|---|---|---|
| `sitio/` | El sistema: `app/`, `config/`, `cron/`, `public_html/` y `env.php.ejemplo` | Sí (menos `env.php.ejemplo`) |
| `sql/001_acceso_roles_usuarios.sql` | Las tablas `roles` y `usuarios`, y el rol de Super administrador | No: se pega en phpMyAdmin |
| `sql/002_acceso_bitacora_accesos.sql` | La tabla `accesos`: la bitácora de entradas, fallos y bloqueos | No: se pega en phpMyAdmin |
| `sql/verificar_001_…` y `sql/verificar_002_…` | Sus comprobaciones (23 y 14 revisiones de solo lectura, cada una con su OK o FALLA) | No: se pegan en phpMyAdmin |
| `herramientas/generar-claves.html` | Genera las dos claves de tu `env.php`, en tu computadora | ⛔ Nunca |
| `AVISOS.md` | La licencia de la lista de contraseñas comunes (SecLists, MIT) | No |

## Qué hace

- **Entrar** con correo y contraseña. Un error dice siempre lo mismo («Correo o contraseña incorrectos»), exista o no
  el correo, y tarda lo mismo.
- **Límites, en 15 minutos:** 5 fallos a una misma cuenta desde una misma red, o 20 fallos en total desde esa red, la
  frenan un rato (solo a esa red: nadie te deja fuera desde otra conexión). Con muchos fallos a una misma cuenta desde
  cualquier lado, cada intento espera un poco más, sin bloquear la cuenta.
- **Contraseñas:** al menos 12 caracteres, sin reglas raras (una frase sirve), y ninguna de las 97 745 más usadas. Se
  guardan con Argon2id: la contraseña nunca se guarda, solo su huella.
- **La sesión** se cierra a los 30 minutos sin uso, la cookie no se puede leer desde JavaScript, y cada formulario
  lleva su token contra CSRF.
- **La bitácora** (`accesos`) anota cada entrada, cada fallo y los bloqueos, con la IP. El correo de un fallo se guarda como
  una huella (HMAC), no en claro.
- **Dos tareas de cada noche:** una purga la bitácora vieja y otra vigila que las reglas `CHECK` de la base sigan en
  su lugar.

## Lo que necesitas

- **Un hosting con PHP 8.2 o más y MariaDB 10.5 o más,** que te deje poner carpetas **al lado** de `public_html`
  (no solo dentro). En el video usamos Hostinger (PHP 8.3 y MariaDB 11.8); en otro hosting, los nombres del panel
  cambian, pero los pasos son los mismos.
- **Una base de datos vacía** para el sistema.
- **Tu dominio con HTTPS** (el certificado gratis del panel). Sirve también el dominio temporal que da Hostinger.
- **Un editor de texto** en tu computadora (el Bloc de notas sirve; Visual Studio Code es más cómodo).
- ⚠️ **En XAMPP no corre:** trae MariaDB 10.4, y la tabla `accesos` usa el tipo `INET6` para la IP, que llegó en la
  10.5.

## Cómo instalarlo, paso a paso

En los ejemplos, `tu-dominio.com` es tu dominio y `u123456789` es tu usuario del hosting (en Hostinger es el prefijo
que llevan tus bases de datos). Cámbialos por los tuyos.

### 0. Descarga los archivos

En la página principal del repositorio en GitHub, botón verde **Code → Download ZIP**. Descomprímelo y entra a la
carpeta `ep05-login`: todo lo que sigue sale de ahí.

### 1. La base de datos

1. En el panel, crea una base de datos y un usuario con su contraseña, y dale al usuario permiso sobre la base.
   **Esa contraseña es tuya:** ponla larga y guárdala, la vas a necesitar en el paso 3. ⚠️ **Que no lleve comilla
   simple (`'`) ni diagonal invertida (`\`):** en `env.php` va entre comillas simples y esos dos signos lo rompen.
2. Abre phpMyAdmin y selecciona tu base (en la lista de la izquierda). **Tiene que estar vacía.**
3. Pestaña **SQL**: pega todo `sql/001_acceso_roles_usuarios.sql` y dale **Continuar**.
4. Pestaña **SQL** otra vez: pega todo `sql/002_acceso_bitacora_accesos.sql` y dale **Continuar**. **El orden importa:**
   el 002 apunta a la tabla `usuarios`, y si lo corres antes que el 001, phpMyAdmin responde el error #1005.

   En los dos, si phpMyAdmin marca errores en los `CHECK` antes de correr, ignóralos: lo que vale es el mensaje final
   del servidor, en verde. Si una tabla no aparece en la lista de la izquierda, recarga la página.
5. **Las comprobaciones.** Abre `sql/verificar_001_acceso_roles_usuarios.sql` en tu editor y cambia `inventario` por
   el nombre de tu base **en todo el archivo** (Buscar y reemplazar → Reemplazar todo; en Hostinger el nombre lleva
   delante tu prefijo: `u123456789_inventario`). Son **23** lugares (si tu editor cuenta lo que encuentra, como
   Visual Studio Code, te lo dice al buscar). Pégalo en la pestaña SQL y dale **Continuar**. **Tienen que salir 23
   filas y todas decir OK** (la última columna).
6. Lo mismo con `sql/verificar_002_acceso_bitacora_accesos.sql`: **14** lugares, y **14 filas, todas OK**.

Si en el paso 5 o 6 sale el error #1142 («SELECT command denied…»), te faltó cambiar el nombre de la base en todo el
archivo.

### 2. Las claves

1. Abre `herramientas/generar-claves.html` con doble clic: se abre en tu navegador, **sin internet**, y te da dos
   claves de 64 caracteres al azar: la de arriba, `ACCESO_HMAC_CLAVE`, y la de abajo, `INSTALACION_CLAVE`.
2. **Cópialas a tu `env.php` (paso 3) antes de cerrar o recargar la página:** cada vez que la recargas salen otras.
   Usa el botón **Copiar** de cada una; si tu navegador no deja copiar, la clave queda seleccionada y la copias con
   **Ctrl+C**.
3. ⛔ Esta página no se sube a tu hosting, y las claves no se comparten.

### 3. El archivo `env.php`

1. Haz una copia de `sitio/env.php.ejemplo` y llámala `env.php` (en la misma carpeta `sitio/`). Ábrela en tu editor.
2. Cambia `TU-DOMINIO` por tu dominio, en minúsculas y sin diagonal al final (`https://tu-dominio.com`). **Escríbelo
   tal como vas a abrir el sitio,** con `www` o sin él: el sistema solo acepta esa dirección (ver «Si algo falla»).
3. Cambia cada valor entre `<…>` y **quita también los signos `<` y `>`**:
   - el nombre de tu base y su usuario, tal como salen en el panel (en Hostinger, `u123456789_inventario` y
     `u123456789_…`);
   - la contraseña de ese usuario (la del paso 1);
   - la clave `ACCESO_HMAC_CLAVE` del paso 2 en `ACCESO_HMAC_CLAVE`, y la clave `INSTALACION_CLAVE` en
     `INSTALACION_CLAVE`.

   Cambia solo lo que va entre las comillas: las comillas `'…'` y la coma del final de cada línea se quedan.
4. Guarda. ⚠️ **Con el Bloc de notas:** en «Guardar como», elige **Tipo: Todos los archivos** y **Codificación:
   UTF-8** (⛔ no «UTF-8 con BOM»); si no, se guarda como `env.php.txt` y el sistema no lo encuentra, o no arranca.
5. **Revisa que el nombre quede exactamente `env.php`.** Windows esconde la terminación de los archivos: en el
   Explorador, menú **Ver → Mostrar → Extensiones de nombre de archivo**. Si ves `env.php.txt`, quítale el `.txt`.

⛔ Este archivo trae tu contraseña y tus claves: no lo subas a GitHub ni lo compartas.

### 4. El dominio en `.htaccess`

Abre `sitio/public_html/.htaccess` en tu editor (empieza con punto: si tu computadora lo esconde, en el Explorador
activa **Ver → Mostrar → Elementos ocultos**). Es la regla que manda todo a HTTPS. Busca esta línea:

```
RewriteRule ^ https://TU-DOMINIO%{REQUEST_URI} [R=301,L]
```

y cambia **solo la palabra `TU-DOMINIO`** por tu dominio, en minúsculas y **igual que en `env.php`**. `%{REQUEST_URI}`
se queda pegado al dominio, sin espacio: es lo que lleva a quien entra por `http://` a la misma página en `https://`.
Queda así:

```
RewriteRule ^ https://tu-dominio.com%{REQUEST_URI} [R=301,L]
```

No te saltes este paso aunque el sitio parezca funcionar sin él: en Hostinger el propio hosting ya manda a HTTPS
antes de llegar a esta regla y no se nota, pero en otro hosting la regla con `TU-DOMINIO` manda a una dirección que
no existe.

### 5. Subir los archivos

En el panel, abre el **Administrador de archivos** de tu sitio. Tienes que estar en la carpeta de tu dominio, la que
tiene dentro `public_html` (en Hostinger, `domains/tu-dominio.com/`; al abrir el administrador desde tu sitio, ya
estás ahí). Para subir, arrastra los archivos y las carpetas a la ventana, o usa el botón de subir.

1. Si `public_html` trae un archivo de bienvenida del hosting (`default.php` o `index.html`), quítalo.
2. Sube `sitio/env.php` y ponle permisos **600**: clic derecho sobre el archivo → **Permisos**, y deja marcados solo
   **Lectura** y **Escritura** del **Propietario**. Así nadie más del servidor lo puede leer. Los permisos se ven en
   la última columna de la lista: **600 se ve `-rw-------`**; si dice `-rw-r--r--` (644), todavía no quedó.
   ⚠️ **Si después editas `env.php` en el administrador de archivos, al guardar puede volver a 644:** revisa la
   columna y, si hace falta, ponle 600 otra vez.
3. Sube las carpetas `sitio/app/`, `sitio/config/` y `sitio/cron/`, **al lado** de `public_html`, no dentro.
4. Entra a `public_html` y sube ahí **el contenido** de `sitio/public_html/`: la carpeta `assets/` y el archivo
   `.htaccess`. **`index.php` va al último:** en cuanto lo subes, el sitio queda abierto.

Al terminar, la carpeta de tu dominio se ve así:

```
domains/tu-dominio.com/
├── app/
├── config/
├── cron/
├── env.php          ← permisos 600 (rw-------)
└── public_html/
    ├── .htaccess
    ├── assets/
    └── index.php
```

La carpeta `storage/` (sesiones y registros) la crea el sistema solo, la primera vez. `env.php.ejemplo`, `sql/` y
`herramientas/` se quedan en tu computadora.

### 6. Tu cuenta de Super administrador: `/instalar`

1. Abre `https://tu-dominio.com/instalar`, con la misma dirección que pusiste en `env.php`.
2. Llena el formulario:
   - **Clave de instalación:** la clave `INSTALACION_CLAVE` del paso 2. Se ve como puntos; con el ojo la puedes ver.
   - **Tu nombre** y **Correo**.
   - **Contraseña** y **Escríbela otra vez:** al menos 12 caracteres; abajo te avisa cuando ya los tiene y cuando las
     dos coinciden. **La contraseña la eliges tú aquí:** el sistema no trae ninguna de ejemplo. Guárdala bien: por
     ahora no hay pantalla para recuperarla (ver «Si olvidas tu contraseña»).
3. Dale **Crear la cuenta**. Sale «Listo · Cuenta creada»: dale **Aceptar** y te lleva a la pantalla de entrar.
4. Entra con tu correo y tu contraseña: sale **«Bienvenido, ‹tu nombre›»**.

Con la primera cuenta creada, **`/instalar` se apaga para siempre** (responde «No encontrado»), aunque la clave siga en
tu `env.php`. **No es un archivo que tengas que borrar:** es una dirección que atiende `index.php`, y en cada visita
el sistema revisa que la tabla `usuarios` esté vacía; como ya tiene tu cuenta, ya no abre. Compruébalo abriendo
`https://tu-dominio.com/instalar`. Si quieres, borra después la línea `INSTALACION_CLAVE` de tu `env.php` (y vuelve
a ponerle 600, paso 5).

Si alguien se equivoca de clave 5 veces desde una misma red, el instalador se bloquea un rato para esa red, igual que
el inicio de sesión.

### 7. Las tareas de cada noche (cron)

Un cron es una tarea que el servidor corre solo, a la hora que le digas. Los scripts ya los subiste (la carpeta
`cron/`); lo que falta es decirle al servidor cuándo correrlos, y eso **no se hace en el administrador de archivos
sino en el panel de Hostinger:** **Sitios web → tu sitio → Avanzado → Tareas Cron**. Agrega estas dos, una por una:

1. Deja marcado **PHP**. En **Comando a ejecutar** el panel ya pone `/usr/bin/php /home/u123456789/`; tú escribes el
   resto de la ruta, con tu dominio (la última columna de la tabla de abajo).
2. Elige el **Minuto** y la **Hora**; en **Día**, **Mes** y **Día de la semana**, la opción con `(*)` (cada día, cada
   mes; en el de la semana, Hostinger la llama «Todos los días laborables (*)», pero `*` es todos los días).
3. **Guardar.**

| Qué | Minuto | Hora | Comando a ejecutar (lo que escribes) |
|---|---|---|---|
| La purga de la bitácora | `30` | `9` | `domains/tu-dominio.com/cron/purgar_accesos.php` |
| El vigilante de las reglas `CHECK` | `45` | `9` | `domains/tu-dominio.com/cron/verificar_checks.php` |

En la lista de abajo quedan como `30 9 * * *` y `45 9 * * *`. En otro hosting, o en el modo «Personalizado», el
comando completo es `php /home/u123456789/domains/tu-dominio.com/cron/purgar_accesos.php` (y lo mismo con
`verificar_checks.php`).

- **El panel programa en UTC:** `30 9` son las 3:30 de la mañana en la Ciudad de México.
- **Revisa cada comando letra por letra antes de guardar:** un cron mal escrito no da error, simplemente no corre.
- **Al día siguiente**, en la lista, el botón **Ver resultado** de cada tarea te dice qué pasó. La primera noche, el
  vigilante toma la foto de tus reglas: dice «SELLADO por primera vez · 18 CHECK en 3 tablas». De ahí en adelante dice
  «SANO» mientras ninguna regla desaparezca o cambie.

## Qué debe salir

- Al terminar el paso 6 ya estás dentro («Bienvenido, ‹tu nombre›»). Dale **Salir**: vuelves a la pantalla de entrar,
  con el fondo de estrellas.
- Con un correo inventado: «No se pudo · Correo o contraseña incorrectos».
- En phpMyAdmin, la tabla `accesos` tiene las filas `CUENTA_CREADA`, `ENTRADA` y `SALIDA` con tu id, y una `FALLIDA`
  por el correo inventado. ⚠️ **phpMyAdmin enseña la hora en UTC** (la del servidor): en la Ciudad de México son 6
  horas menos, así que una cuenta creada a las 11:31 de la noche sale con la fecha del día siguiente a las 05:31.
  No está mal: es el mismo momento, contado en otro horario.
- `https://tu-dominio.com/instalar` ya responde «No encontrado».

## Si algo falla

| Lo que ves | Qué pasó | Qué hacer |
|---|---|---|
| Una página en blanco o «Error 500» en todas las páginas, también en `/login` | `env.php` no se puede leer: lleva una comilla simple dentro de un valor o una diagonal invertida al final de uno, se borró una comilla o una coma al editarlo, o se guardó como «UTF-8 con BOM» | Ábrelo y revisa cada línea contra `env.php.ejemplo`; guárdalo en UTF-8 sin BOM (paso 3) |
| «Servicio no disponible.» en todas las páginas | Un valor de `env.php` no tiene la forma esperada o falta | Revisa que se llame exactamente `env.php` (no `env.php.txt`), que el dominio vaya en minúsculas y sin diagonal al final, que no quede ningún `<` o `>`, y que cada clave tenga exactamente 64 caracteres, del 0 al 9 y de la a a la f |
| `/instalar` dice «Servicio no disponible.», o `/login` se ve pero al dar **Entrar** sale «No se pudo · Respuesta inesperada del servidor.» | El sistema no pudo conectarse a la base: el nombre de la base, el usuario o la contraseña de `env.php` no son los del panel | Cópialos otra vez del panel. En `storage/logs/php-error.log` queda el código 1045 (o 1044 si lo que está mal es el nombre de la base) |
| `/instalar` dice «Ocurrió un error. Intenta de nuevo.» | No corriste el `001`: no existen las tablas | Corre el `001` y el `002`, en ese orden (paso 1) |
| Al dar **Crear la cuenta**: «No se pudo · Ocurrió un error. Intenta de nuevo.»; o al dar **Entrar**: «No se pudo entrar en este momento» | No corriste el `002`: falta la tabla `accesos` (la cuenta no se crea a medias) | Corre el `002` (paso 1) y vuelve a intentar. En `storage/logs/php-error.log` sale el código 1146 |
| La página sale sin colores ni logo (fondo blanco) y el botón no hace nada | Si la barra del navegador dice otra dirección que la de `APP_URL` (`www.tu-dominio.com` y `tu-dominio.com` no son la misma): el sistema solo carga sus archivos desde esa dirección. Si es la misma: `assets/` no quedó dentro de `public_html` | Abre el sitio exactamente con la dirección de `APP_URL`; o mueve `assets/` a `public_html` |
| Al abrir el sitio con `http://` te manda a `https://TU-DOMINIO` | Te faltó el paso 4 | Cambia `TU-DOMINIO` en `public_html/.htaccess` y súbelo otra vez |
| «La página caducó» | La página llevaba mucho tiempo abierta y su sesión se cerró | Dale **Aceptar**: la página se recarga; vuelve a intentar |
| `/instalar` dice «No encontrado» y no tienes cuenta | Falta la línea `INSTALACION_CLAVE` en `env.php`, o la tabla `usuarios` ya tiene alguna fila (por ejemplo, si corriste ahí las pruebas del episodio 04) | Revisa `env.php`, o empieza con una base vacía |
| «Demasiados intentos» | Hubo demasiados fallos desde tu red: 5 a una misma cuenta, 20 en total, o 5 claves de instalación equivocadas | Espera 15 minutos |

**Dónde ver los errores:** en el administrador de archivos, en la carpeta `storage/logs/` (al lado de `app/`):
`php-error.log` guarda los errores de la base y del código, y `cron.log` los de las tareas de cada noche; nunca una
contraseña. Los de `env.php` (las dos primeras filas de la tabla) no llegan a anotarse ahí: se resuelven revisando el
archivo.

## Si olvidas tu contraseña

Por ahora no hay pantalla para recuperarla: llega con el módulo de Usuarios. Mientras:

- **Con SSH,** en la carpeta de tu dominio: `php cron/crear_admin.php --restablecer tu@correo.com`. Te pide
  escribir `SI` para confirmar, te da una contraseña temporal y, al entrar con ella, el sistema te pide una nueva.
- **Sin SSH, si era la única cuenta,** la salida es empezar de cero: en phpMyAdmin borra las tres tablas, en este
  orden por sus llaves foráneas (`accesos`, `usuarios` y `roles`), y repite el paso 1 y el paso 6. Se pierde la
  bitácora. Si ya habías borrado la línea `INSTALACION_CLAVE` de tu `env.php`, genera otra clave (paso 2), ponla en
  tu `env.php` y súbelo otra vez antes del paso 6.

## Notas

- Los datos de los ejemplos son ficticios.
- La hora del sistema es la de la Ciudad de México.
- `cron/crear_admin.php` también crea la primera cuenta desde una terminal: es la otra vía, para quien sí tiene SSH.
