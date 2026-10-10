<?php
declare(strict_types=1);

/*
 * config/app.php — la configuración de OmarFerDev (08-oct-2026).
 *
 * Lee env.php (fuera de la raíz web; lo escribe quien instala) y arma un solo arreglo. Si env.php falta,
 * le falta una clave o una clave no tiene la forma esperada, FALLA CERRADO: anota en el log qué clave falló (nunca
 * su valor) y responde un 500 genérico. Lo cargan index.php y los scripts de cron/ (ambos definen OFDEV antes).
 */

defined('OFDEV') || exit;

// Todo va dentro de una función: ninguna variable de aquí (ni $env, con la contraseña de la base) se queda
// en el ámbito de quien hace el require.
return (static function (): array {
    $raiz = dirname(__DIR__);

    $fallar = static function (string $motivo): never {
        error_log('[OFDEV] configuración inválida: ' . $motivo);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        if (PHP_SAPI === 'cli') {
            // Con salida 2: un exit('texto') sale con 0 y un cron que no arranca parecía sano (medido el 08-oct).
            fwrite(STDERR, "Configuración inválida: $motivo\n");
            exit(2);
        }
        exit('Servicio no disponible.');
    };

    $archivoEnv = $raiz . '/env.php';
    if (!is_file($archivoEnv)) {
        $fallar('falta env.php');
    }
    $env = require $archivoEnv;
    if (!is_array($env)) {
        $fallar('env.php no devuelve un arreglo');
    }

    // Cada clave con su forma. El valor nunca se imprime ni se anota.
    $formas = [
        'APP_ENV'           => static fn ($v): bool => in_array($v, ['production', 'local'], true),
        'APP_DEBUG'         => static fn ($v): bool => is_bool($v),
        'APP_URL'           => static fn ($v): bool => is_string($v) && preg_match('#^https?://[a-z0-9.-]+(:[0-9]+)?$#', $v) === 1,
        'DB_HOST'           => static fn ($v): bool => is_string($v) && $v !== '',
        'DB_NAME'           => static fn ($v): bool => is_string($v) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $v) === 1,
        'DB_USER'           => static fn ($v): bool => is_string($v) && preg_match('/^[A-Za-z0-9_]{1,80}$/', $v) === 1,
        // Una plantilla sin llenar («<…>») también falla aquí, y no hasta la primera conexión.
        'DB_PASS'           => static fn ($v): bool => is_string($v) && $v !== '' && !str_starts_with($v, '<'),
        'ACCESO_HMAC_CLAVE' => static fn ($v): bool => is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) === 1,
    ];
    foreach ($formas as $clave => $valida) {
        if (!array_key_exists($clave, $env)) {
            $fallar("falta la clave $clave");
        }
        if (!$valida($env[$clave])) {
            $fallar("la clave $clave no tiene la forma esperada");
        }
    }
    // Opcional: la clave del instalador web (/instalar). Solo mientras se instala: sin ella,
    // o con un usuario creado, el instalador responde 404. Si está, 64 hexadecimales (de generar-claves.html).
    // Vacía cuenta como quitada (quien la «borra» vaciándola no tumba el sitio con un 500); con \z,
    // un salto de línea al final no pasa (con $, sí: la clave nunca igualaría lo tecleado); y una clave degenerada
    // (64 ceros, «abab…») se rechaza: una al azar trae menos de 10 de los 16 dígitos una vez en ~860 mil millones.
    $instalacion = $env['INSTALACION_CLAVE'] ?? null;
    $instalacion = $instalacion === '' ? null : $instalacion;
    if ($instalacion !== null && (!is_string($instalacion) || preg_match('/\A[0-9a-f]{64}\z/', $instalacion) !== 1
        || count(array_unique(str_split($instalacion))) < 10)) {
        $fallar('la clave INSTALACION_CLAVE no tiene la forma esperada');
    }
    // Opcional: el puerto de la base (el servidor usa el de siempre, 3306; el laboratorio local, otro).
    $puerto = $env['DB_PORT'] ?? 3306;
    if (!is_int($puerto) || $puerto < 1 || $puerto > 65535) {
        $fallar('la clave DB_PORT no tiene la forma esperada');
    }
    if ($env['APP_ENV'] === 'production' && !str_starts_with($env['APP_URL'], 'https://')) {
        $fallar('APP_URL debe ser https:// en producción');
    }

    // México no tiene horario de verano desde 2022: la base usa el desfase fijo (no tiene zonas por nombre, medido 07-oct)
    // y PHP la zona por nombre. Dentro de la app, los dos relojes son el mismo.
    date_default_timezone_set('America/Mexico_City');
    mb_internal_encoding('UTF-8');

    return [
        'env'   => $env['APP_ENV'],
        // La red de seguridad del depurador: en producción nunca, aunque env.php diga otra cosa.
        'debug' => $env['APP_DEBUG'] === true && $env['APP_ENV'] !== 'production',
        'url'   => $env['APP_URL'],
        'zona'  => ['php' => 'America/Mexico_City', 'bd' => '-06:00'],
        'db'    => [
            'host'        => $env['DB_HOST'],
            'puerto'      => $puerto,
            'nombre'      => $env['DB_NAME'],
            'usuario'     => $env['DB_USER'],
            'clave'       => $env['DB_PASS'],
            'charset'     => 'utf8mb4',
            'zona'        => '-06:00',
            'aislamiento' => 'READ-COMMITTED',
            // El servidor NO es estricto (medido el 07-oct): la app lo fija en cada conexión.
            'sql_mode'    => 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            // En la web, un candado de fila se espera 10 s (el servidor trae 50): con los dos reintentos de
            // conReintentos, la petición sigue por debajo de los 60 s en que el hosting la reenvía. Cron, como viene.
            'espera_web'  => 10,
        ],
        // La clave del HMAC del correo en la bitácora. Solo la lee el Service de acceso.
        'hmac_correo' => $env['ACCESO_HMAC_CLAVE'],
        // La del instalador; null si no está (y entonces /instalar da 404). Solo la lee AccesoService.
        'instalacion_clave' => $instalacion,
        // Las cifras del acceso viven en config/acceso.php (una sola fuente); aquí solo se cuelgan: config('acceso.…').
        'acceso' => require __DIR__ . '/acceso.php',
        'rutas' => [
            'raiz'    => $raiz,
            'app'     => $raiz . '/app',
            'config'  => __DIR__,
            'vistas'  => $raiz . '/app/Views',
            'storage' => $raiz . '/storage',
            'publica' => $raiz . '/public_html',
        ],
        // index.php acota open_basedir a estas carpetas al arrancar (vacío en el servidor, medido el 08-oct). PHP 8 las
        // trata como CARPETAS, no como prefijos: «…/sitio» no deja pasar «…/sitio-otro» (medido el 08-oct en PHP 8.2,
        // con una prueba de laboratorio).
        'open_basedir' => [$raiz, sys_get_temp_dir()],
    ];
})();
