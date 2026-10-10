<?php
declare(strict_types=1);

/*
 * cron/arranque.php — el arranque de los scripts de línea de comandos (08-oct-2026). Lo cargan crear_admin.php y los
 * que vengan (la purga de la bitácora). Hace lo de index.php que no es de la web: configuración, log, autoloader,
 * helpers y la base. Sin sesión, sin cabeceras y sin rutas.
 * ⛔ No corre desde la web: cron/ vive fuera de public_html y, además, aquí se sale si no es la línea de comandos.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
define('OFDEV', true);

$GLOBALS['ofdev_config'] = require dirname(__DIR__) . '/config/app.php';

(static function (array $config): void {
    // El log, en storage/logs/cron.log; en la terminal solo sale lo que el script escribe a propósito. Con
    // OFDEV_SOLO_LECTURA=1 (lo pone una llave SSH de solo lectura) no se escribe nada en el sitio:
    // ni la carpeta ni el log, que va a la salida de errores.
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('display_errors', '0');
    if (getenv('OFDEV_SOLO_LECTURA') === '1') {
        ini_set('error_log', 'php://stderr');
    } else {
        $logs = $config['rutas']['storage'] . '/logs';
        if (!is_dir($logs)) {
            @mkdir($logs, 0700, true);
        }
        ini_set('error_log', $logs . '/cron.log');
    }

    // El mismo autoloader de index.php (PSR-4 de App\ en app/).
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

    // Una excepción sin atrapar no sale muda (con display_errors apagado, PHP no decía nada y salía con 255):
    // una línea en la pantalla de errores, el lugar en el log, y la salida 1 («error»).
    set_exception_handler(static function (\Throwable $e): void {
        error_log('[OFDEV] cron: excepción sin atrapar · ' . get_class($e) . ' · código '
            . \App\Core\Transaccion::codigo($e) . ' · ' . basename($e->getFile()) . ':' . $e->getLine());
        fwrite(STDERR, 'ERROR inesperado (' . get_class($e) . '); el lugar, en el log' . "\n");
        exit(1);
    });
})($GLOBALS['ofdev_config']);
