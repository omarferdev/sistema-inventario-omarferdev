<?php
declare(strict_types=1);

/*
 * index.php — el ÚNICO punto de entrada de OmarFerDev (08-oct-2026). El orden importa:
 * configuración → log → open_basedir → autoloader y helpers → base (perezosa) → nonce y cabeceras → sesión → rutas.
 * Tras tocar este archivo se abre la página en el navegador: una etiqueta de cierre de PHP dentro de un comentario
 * de una línea imprimiría el código, y php -l no lo ve.
 */

define('OFDEV', true);

// Solo el servidor de PHP de desarrollo (laboratorio local): los archivos que existen se sirven tal cual.
if (PHP_SAPI === 'cli-server') {
    $archivo = __DIR__ . rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if ($archivo !== __FILE__ && is_file($archivo)) {
        return false;
    }
}

$raizApp = dirname(__DIR__);
$config = require $raizApp . '/config/app.php';
$GLOBALS['ofdev_config'] = $config;

// El log, siempre, en storage/logs (el hosting lo trae apagado, medido el 08-oct); la pantalla, solo en depuración.
$logs = $config['rutas']['storage'] . '/logs';
if (!is_dir($logs)) {
    @mkdir($logs, 0700, true);
}
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', $logs . '/php-error.log');
ini_set('display_errors', $config['debug'] ? '1' : '0');

// El servidor trae open_basedir vacío (medido el 08-oct); se acota a la carpeta del sitio y a la de temporales.
ini_set('open_basedir', implode(PATH_SEPARATOR, $config['open_basedir']));

spl_autoload_register(static function (string $clase) use ($config): void {
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $archivo = $config['rutas']['app'] . '/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
require $config['rutas']['app'] . '/Core/funciones.php';
\App\Core\Database::configurar($config['db']);

$GLOBALS['ofdev_nonce'] = bin2hex(random_bytes(16));
cabeceras_seguridad();

// Una excepción que nadie atrapó no da un 500 en blanco: va al log (clase, código y lugar, ⛔ nunca
// el mensaje, que puede traer datos) y la respuesta es el error de siempre, con la <meta> de la CSP o en JSON.
set_exception_handler(static function (\Throwable $e): void {
    error_log('[OFDEV] excepción sin atrapar · ' . get_class($e) . ' · código ' . \App\Core\Transaccion::codigo($e)
        . ' · ' . basename($e->getFile()) . ':' . $e->getLine());
    if (esAjax()) {
        responderJson(['exito' => false, 'mensaje' => 'Ocurrió un error. Intenta de nuevo.'], 500);
    }
    \App\Core\Router::error(500, 'Ocurrió un error. Intenta de nuevo.');
});

// La sesión se abre solo para una ruta que existe. Un 404 o un 405 (bots, escaneos) ya no dejan un
// archivo de sesión que vivía 10 h en storage/sessions (los inodos son de la cuenta entera).
$router = new \App\Core\Router(require $config['rutas']['config'] . '/rutas.php');
$metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
// El instalador apagado responde como una ruta que no existe, con cualquier verbo, y ANTES de abrir
// la sesión: ni cookie ni archivo de sesión, y el mismo 404 de siempre (no se distingue de una ruta inexistente).
if (\App\Core\Router::normalizar($uri) === '/instalar' && !\App\Services\AccesoService::instalacionDisponible()) {
    \App\Core\Router::error(404, 'No encontrado.');
}
if ($router->resolver($metodo, $uri)['estado'] === 200) {
    \App\Core\Sesion::iniciar();
}
$router->despachar($metodo, $uri);
