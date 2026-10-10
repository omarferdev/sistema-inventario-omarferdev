<?php
declare(strict_types=1);

/*
 * cron/verificar_checks.php — el detector de CHECK desaparecidos (08-oct-2026). Lee la base (SHOW) y el manifiesto,
 * y solo escribe el autosello de abajo. Lo corre el cron del panel cada día; también se puede correr a mano.
 *
 *   php cron/verificar_checks.php               compara la base viva con storage/checks_manifest.json
 *   php cron/verificar_checks.php --snapshot    imprime la foto nueva (JSON) por pantalla; ⛔ nunca escribe
 *
 * Sellar de nuevo (con SSH, en la misma entrega que añade o cambia un CHECK): ⛔ nunca con «--snapshot >» directo al
 * manifiesto (la terminal lo vacía antes de que PHP lo lea y se sella a ciegas). La receta:
 *   php <ruta>/cron/verificar_checks.php --snapshot > /tmp/checks_nuevo.json    (el diff sale por la pantalla)
 *   head -12 /tmp/checks_nuevo.json                                             (mirar lo que se va a sellar)
 *   cp /tmp/checks_nuevo.json <ruta>/storage/checks_manifest.json
 *   php <ruta>/cron/verificar_checks.php                                        (esperado: SANO)
 *
 * Sin manifiesto, la primera corrida lo SELLA (autosello, 09-oct: para instalar sin SSH), salvo con OFDEV_SOLO_LECTURA=1.
 * Salidas: 0 sano (o recién sellado) · 1 hallazgo (un CHECK desaparecido, cambiado o sin vigilancia) · 2 no concluyente (sin manifiesto,
 * manifiesto de otra base, o la base tiene MENOS tablas que el manifiesto: el guard va antes de comparar).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/arranque.php';

use App\Core\Checks;
use App\Core\Database;

$args = array_slice($argv, 1);
if ($args !== [] && $args !== ['--snapshot']) {
    fwrite(STDERR, "Uso:\n  php cron/verificar_checks.php\n  php cron/verificar_checks.php --snapshot\n");
    exit(64);
}
$snapshot = $args === ['--snapshot'];
$noConcluyente = static function (string $texto): never {
    fwrite(STDERR, 'verificar_checks: NO CONCLUYENTE · ' . $texto . "\n");
    exit(2);
};
$esquema = (string) config('db.nombre');
$archivo = config('rutas.storage') . '/checks_manifest.json';

// La base viva: cada tabla real (SHOW FULL TABLES, no una lista escrita a mano) con sus CHECK.
try {
    $vivo = [];
    foreach (Database::fetchAll('SHOW FULL TABLES') as $fila) {
        [$tabla, $tipo] = array_values($fila);
        if ($tipo !== 'BASE TABLE') {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $tabla) !== 1) {
            $noConcluyente('una tabla con un nombre que no se sabe leer');
        }
        $create = array_values(Database::fetchOne("SHOW CREATE TABLE `$tabla`") ?? [])[1] ?? '';
        $vivo[$tabla] = Checks::extraer((string) $create);
    }
} catch (\PDOException $e) {
    error_log('[OFDEV] verificar_checks · código ' . (int) ($e->errorInfo[1] ?? 0) . ' · ' . $e->getMessage());
    $noConcluyente('error de la base (código ' . (int) ($e->errorInfo[1] ?? 0) . ')');
}
ksort($vivo, SORT_STRING);
if ($vivo === []) {
    $noConcluyente('la base no tiene tablas');
}

$foto = null;
if (is_file($archivo)) {
    $m = json_decode((string) file_get_contents($archivo), true);
    if (!is_array($m) || ($m['version'] ?? null) !== 1 || !is_array($m['tablas'] ?? null)) {
        $noConcluyente('el manifiesto no se puede leer: ' . $archivo);
    }
    if (($m['esquema'] ?? '') !== $esquema) {
        $noConcluyente('el manifiesto es de otra base («' . ($m['esquema'] ?? '') . '»)');
    }
    $foto = $m['tablas'];
}

$informe = static function (array $d): array {
    $lineas = [];
    foreach (['desaparecidos' => 'DESAPARECIDO', 'cambiados' => 'CAMBIADO', 'nuevos' => 'SIN VIGILANCIA (re-sellar)',
              'tablas_faltan' => 'TABLA QUE FALTA', 'tablas_nuevas' => 'TABLA NUEVA'] as $clave => $rotulo) {
        foreach ($d[$clave] as $x) {
            $lineas[] = "  $rotulo · $x";
        }
    }
    return $lineas;
};

if ($snapshot) {
    // El diff contra el manifiesto vigente va ANTES y por la pantalla de errores: así se ve qué cambia al sellar,
    // también lo desaparecido, sin importar el guard de tablas.
    if ($foto === null) {
        fwrite(STDERR, "verificar_checks: todavía no hay manifiesto; esta será la primera foto\n");
    } else {
        $lineas = $informe(Checks::comparar($foto, $vivo));
        fwrite(STDERR, 'verificar_checks: diferencias contra el manifiesto vigente: '
            . ($lineas === [] ? "ninguna\n" : "\n" . implode("\n", $lineas) . "\n"));
    }
    $sello = (string) (Database::fetchOne('SELECT NOW() AS n')['n'] ?? '');
    echo json_encode(['version' => 1, 'esquema' => $esquema, 'sellado' => $sello, 'tablas' => $vivo],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    fwrite(STDERR, 'verificar_checks: foto de ' . Checks::total($vivo) . ' CHECK en ' . count($vivo) . " tablas\n");
    exit(0);
}

if ($foto === null) {
    // El AUTOSELLO (09-oct): sin manifiesto, la primera corrida lo sella (para instalar sin SSH;
    // la primera foto se toma con la base recién hecha). ⛔ Nunca con OFDEV_SOLO_LECTURA=1: una llave de solo lectura no escribe.
    if (getenv('OFDEV_SOLO_LECTURA') === '1') {
        $noConcluyente('no hay manifiesto (storage/checks_manifest.json): se sella con la primera corrida del cron');
    }
    // Una base sin ningún CHECK es justo lo que este detector busca (un respaldo que se los llevó):
    // ⛔ no se sella como bueno. Y el temporal es de esta corrida: dos a la vez no se pisan.
    if (Checks::total($vivo) === 0) {
        $noConcluyente('no hay manifiesto y la base no tiene ningún CHECK: no se sella (¿faltan los SQL, o un respaldo se los llevó?)');
    }
    $sello = (string) (Database::fetchOne('SELECT NOW() AS n')['n'] ?? '');
    $json = json_encode(['version' => 1, 'esquema' => $esquema, 'sellado' => $sello, 'tablas' => $vivo],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @mkdir(dirname($archivo), 0700, true);
    $temporal = $archivo . '.' . getmypid() . '.tmp';
    if (file_put_contents($temporal, $json) === false || !rename($temporal, $archivo)) {
        $noConcluyente('no hay manifiesto y no se pudo escribir: ' . $archivo);
    }
    echo 'verificar_checks: SELLADO por primera vez · ' . Checks::total($vivo) . ' CHECK en ' . count($vivo) . " tablas\n";
    exit(0);
}
// El guard RELATIVO, antes de comparar: con menos tablas que la foto, algo grande pasó (una restauración a medias).
if (count($vivo) < count($foto)) {
    $noConcluyente('la base tiene ' . count($vivo) . ' tablas y el manifiesto ' . count($foto)
        . '; si fue un DROP TABLE a propósito, se re-sella');
}
$d = Checks::comparar($foto, $vivo);
$lineas = $informe($d);
if ($lineas !== []) {
    $n = count($lineas);
    echo 'verificar_checks: HALLAZGO · ' . $n . ($n === 1 ? ' diferencia' : ' diferencias') . " contra el manifiesto\n"
        . implode("\n", $lineas) . "\n";
    exit(1);
}
echo 'verificar_checks: SANO · ' . Checks::total($vivo) . ' CHECK en ' . count($vivo) . " tablas, como el manifiesto\n";
exit(0);
