<?php
declare(strict_types=1);

/*
 * cron/crear_admin.php — el primer Super administrador y el restablecimiento de contraseñas (08-oct-2026).
 * Se corre por SSH, en una terminal; la lógica vive en AccesoService.
 *
 *   php cron/crear_admin.php                          crea el primer Super administrador (se niega si ya hay uno activo)
 *   php cron/crear_admin.php --restablecer <correo>   genera una contraseña temporal y obliga a cambiarla al entrar
 *
 * Reglas:
 *   - La contraseña se teclea sin eco, dos veces; ⛔ nunca va como argumento (quedaría en el historial y en `ps`);
 *   - Sin una terminal real (entrada o salida redirigida) se niega, y si no puede apagar el eco, también;
 *   - todo cambio se confirma escribiendo SI antes de hacerlo.
 * Salidas: 0 hecho · 1 el sistema se negó (el motivo, en pantalla) · 2 sin terminal o sin eco apagado · 64 mal uso.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$decir = static function (string $texto): void {
    fwrite(STDOUT, $texto . "\n");
};
$salir = static function (string $texto, int $codigo): never {
    fwrite(STDERR, $texto . "\n");
    exit($codigo);
};

// ANTES de cargar nada: con la entrada o la salida redirigidas no hay a quién preguntarle.
if (!stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
    $salir('Este script se corre en una terminal (SSH interactivo), con el teclado como entrada. Nada se cambió.', 2);
}

$args = array_slice($argv, 1);
$modo = match (true) {
    $args === []                                   => 'crear',
    count($args) === 2 && $args[0] === '--restablecer' => 'restablecer',
    default                                        => null,
};
if ($modo === null) {
    $salir("Uso:\n  php cron/crear_admin.php\n  php cron/crear_admin.php --restablecer <correo>", 64);
}

require __DIR__ . '/arranque.php';

/** Una línea con eco (nombre, correo, la confirmación). */
$preguntar = static function (string $pregunta): string {
    fwrite(STDOUT, $pregunta);
    $linea = fgets(STDIN, 4096);
    return $linea === false ? '' : rtrim($linea, "\r\n");
};

/**
 * stty SIN shell (medido el 08-oct): el PHP de línea de comandos del servidor tiene deshabilitadas shell_exec, exec,
 * system, passthru y popen (medido con ini_get('disable_functions')), y la primera versión, con shell_exec, murió con
 * «Error» al pedir la contraseña. proc_open no está deshabilitada. La terminal va como entrada de stty, que es sobre
 * la que actúa; el programa se llama directo (arreglo de argumentos), sin armar texto de comando.
 * @return string|null lo que imprimió, o null si no se pudo correr o salió con error
 */
$stty = static function (string ...$args): ?string {
    if (!function_exists('proc_open')) {
        return null;
    }
    $p = @proc_open(array_merge(['stty'], $args), [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
    if (!is_resource($p)) {
        return null;
    }
    $salida = (string) stream_get_contents($tubos[1]);
    fclose($tubos[1]);
    fclose($tubos[2]);
    return proc_close($p) === 0 ? $salida : null;
};

/**
 * Una línea SIN eco (la contraseña). Apaga el eco con stty y comprueba que de verdad quedó apagado; si no, se niega.
 * El eco se devuelve siempre: al terminar, con Ctrl+C (pcntl) y si el script muere.
 */
$oculto = static function (string $pregunta) use ($salir, $stty): string {
    $estado = trim((string) $stty('-g'));
    if ($estado === '' || preg_match('/^[0-9a-fA-F:]+$/', $estado) !== 1) {
        $salir('No se pudo leer la configuración de la terminal (stty). Nada se cambió.', 2);
    }
    $devolver = static function () use ($estado, $stty): void {
        $stty($estado);
    };
    register_shutdown_function($devolver);
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($devolver): void {
            $devolver();
            fwrite(STDERR, "\nCancelado. Nada se cambió.\n");
            exit(130);
        });
    }
    $stty('-echo');
    if (preg_match('/(^|\s)-echo(\s|$)/', (string) $stty('-a')) !== 1) {
        $devolver();
        $salir('No se pudo ocultar lo que se teclea. Nada se cambió.', 2);
    }
    fwrite(STDOUT, $pregunta);
    $linea = fgets(STDIN, 4096);
    $devolver();
    fwrite(STDOUT, "\n");
    return $linea === false ? '' : rtrim($linea, "\r\n");   // los espacios de la contraseña se respetan
};

/** La confirmación: «SI» o «SÍ», en mayúsculas o minúsculas. */
$confirmar = static function (string $pregunta) use ($preguntar): bool {
    return in_array(mb_strtoupper(trim($preguntar($pregunta)), 'UTF-8'), ['SI', 'SÍ'], true);
};

$mostrarMotivos = static function (array $r) use ($decir): void {
    foreach ((array) ($r['mensaje'] ?? 'No se pudo.') as $m) {
        $decir('  · ' . $m);
    }
};

$servicio = new \App\Services\AccesoService();

if ($modo === 'crear') {
    $decir('Crear el primer Super administrador de OmarFerDev.');
    $nombre = $preguntar('Nombre completo: ');
    $correo = $preguntar('Correo: ');
    $minimo = (int) config('acceso.password.min_caracteres');
    $password = $oculto("Contraseña (al menos $minimo caracteres; no se ve al teclear): ");
    $confirma = $oculto('Escríbela otra vez: ');
    if (!hash_equals($password, $confirma)) {
        $salir('Las dos contraseñas no coinciden. Nada se cambió.', 1);
    }
    if (!$confirmar("¿Crear a «{$nombre}» <{$correo}> como Super administrador? Escribe SI: ")) {
        $salir('Cancelado. Nada se cambió.', 1);
    }
    $r = $servicio->crearSuperadmin($nombre, $correo, $password);
    if (!empty($r['incierto'])) {
        // El commit pudo haber pasado. ⛔ No se dice «no se creó».
        $salir('NO SE SABE si se creó la cuenta (se perdió la confirmación de la base). Vuelve a correr el script: '
            . 'si ya se creó, te dirá que ya hay un Super administrador activo.', 1);
    }
    if (($r['exito'] ?? false) !== true) {
        $decir('No se creó la cuenta:');
        $mostrarMotivos($r);
        exit(1);
    }
    $decir('Listo: cuenta creada (id ' . (int) $r['id'] . '). Ya puedes entrar en el sitio con ese correo.');
    exit(0);
}

$correo = $args[1];
if (!$confirmar("¿Restablecer la contraseña de <{$correo}>? Sus sesiones abiertas se cortan. Escribe SI: ")) {
    $salir('Cancelado. Nada se cambió.', 1);
}
$r = $servicio->restablecerCli($correo);
if (!empty($r['incierto'])) {
    // Quizá se guardó una temporal que nadie verá: se dice, y ⛔ no se muestra (pudo no quedar guardada).
    $salir('NO SE SABE si se restableció (se perdió la confirmación de la base). Vuelve a correrlo: genera una '
        . 'temporal nueva y esa sí se muestra.', 1);
}
if (($r['exito'] ?? false) !== true) {
    $decir('No se restableció:');
    $mostrarMotivos($r);
    exit(1);
}
$decir('Contraseña temporal (se muestra SOLO esta vez; al entrar con ella, el sistema pide elegir una nueva):');
$decir('');
$decir('    ' . $r['temporal']);
$decir('');
if (($r['activo'] ?? true) !== true) {
    $decir('Ojo: la cuenta está INACTIVA; con esta contraseña no podrá entrar hasta que alguien la active.');
}
$decir('Cuando la hayas pasado, limpia la pantalla con: clear');
exit(0);
