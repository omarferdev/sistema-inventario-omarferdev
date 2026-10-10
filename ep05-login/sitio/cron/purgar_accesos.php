<?php
declare(strict_types=1);

/*
 * cron/purgar_accesos.php — la purga de la bitácora de accesos (08-oct-2026).
 * La corre el cron del panel, una vez al día; también se puede correr a mano.
 *
 *   php cron/purgar_accesos.php
 *
 * Qué borra: las filas de `accesos` más viejas que su retención (config/acceso.php, decisión de Omar del 08-oct):
 *   - intentos y sesiones (ENTRADA, FALLIDA, BLOQUEADA, SALIDA): purga_dias (90);
 *   - cambios de cuenta (CUENTA_CREADA, PASSWORD_CAMBIADA, PASSWORD_RESTABLECIDA, SESION_CORTADA): purga_dias_cuenta (365).
 * Cómo:
 *   - en lotes de purga_lote (5 000) mientras un lote borre lleno, para no sostener locks largos;
 *   - el corte lo decide el reloj de la BASE (NOW() en la misma sentencia que borra);
 *   - la tabla y los eventos están fijos aquí, ⛔ nunca vienen de la entrada;
 *   - con un candado de la base: dos purgas a la vez no se pisan.
 * Falla cerrada (sale con 2 y no borra nada) si: una retención baja del PISO de 90 días; el ENUM `evento` de la tabla
 * tiene un valor que este script no conoce (un evento nuevo necesita su regla aquí antes de purgarse); o ya corre
 * otra purga.
 * Salidas: 0 hecho · 1 error de la base · 2 no concluyente (no borró nada). Imprime el conteo de CADA grupo.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/arranque.php';

use App\Core\Database;

const PISO_DIAS = 90;
const MAX_LOTES = 2000;   // 10 millones de filas a 5 000: si se llega aquí, algo anda mal y se dice
const CANDADO = 'ofdev_purga_accesos';
const GRUPOS = [
    'intentos y sesiones' => ['clave' => 'purga_dias',
                              'eventos' => ['ENTRADA', 'FALLIDA', 'BLOQUEADA', 'SALIDA']],
    'cambios de cuenta'   => ['clave' => 'purga_dias_cuenta',
                              'eventos' => ['CUENTA_CREADA', 'PASSWORD_CAMBIADA', 'PASSWORD_RESTABLECIDA', 'SESION_CORTADA']],
];

$decir = static function (string $texto): void {
    fwrite(STDOUT, $texto . "\n");
};
$noConcluyente = static function (string $texto): never {
    fwrite(STDERR, 'purgar_accesos: NO CONCLUYENTE · ' . $texto . ' · no se borró nada' . "\n");
    exit(2);
};

$cfg = config('acceso.bitacora');
$lote = (int) $cfg['purga_lote'];
if ($lote < 1 || $lote > 50000) {
    $noConcluyente('purga_lote fuera de rango (1 a 50 000)');
}
foreach (GRUPOS as $nombre => $g) {
    if ((int) $cfg[$g['clave']] < PISO_DIAS) {
        $noConcluyente("{$g['clave']} = {$cfg[$g['clave']]} baja del piso de " . PISO_DIAS . ' días');
    }
}

try {
    // Que cada valor del ENUM de la tabla tenga su regla aquí (con el esquema LITERAL de la configuración,
    // ⛔ nunca DATABASE()). Un evento sin regla no se purga a ciegas: se avisa y se para.
    $tipo = Database::fetchOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = :e AND TABLE_NAME = 'accesos' AND COLUMN_NAME = 'evento'",
        [':e' => config('db.nombre')]);
    if ($tipo === null) {
        $noConcluyente('no se encontró la columna accesos.evento (¿falta el SQL 002?)');
    }
    preg_match_all("/'([A-Z_]+)'/", (string) $tipo['t'], $m);
    $conocidos = array_merge(...array_column(GRUPOS, 'eventos'));
    $sinRegla = array_diff($m[1], $conocidos);
    $sobran = array_diff($conocidos, $m[1]);
    if ($m[1] === [] || $sinRegla !== [] || $sobran !== []) {
        $noConcluyente('el ENUM de accesos.evento no coincide con las reglas de este script'
            . ($sinRegla !== [] ? ' (sin regla: ' . implode(', ', $sinRegla) . ')' : '')
            . ($sobran !== [] ? ' (no existen: ' . implode(', ', $sobran) . ')' : ''));
    }

    if ((int) (Database::fetchOne('SELECT GET_LOCK(:n, 0) AS t', [':n' => CANDADO])['t'] ?? 0) !== 1) {
        $noConcluyente('ya corre otra purga');
    }
    try {
        $total = 0;
        foreach (GRUPOS as $nombre => $g) {
            $dias = (int) $cfg[$g['clave']];
            $lista = "'" . implode("', '", $g['eventos']) . "'";   // valores fijos de este archivo, no de la entrada
            $borradas = 0;
            $lotes = 0;
            do {
                $n = Database::query("DELETE FROM accesos WHERE evento IN ($lista)
                                         AND creado_en < NOW() - INTERVAL $dias DAY ORDER BY creado_en LIMIT $lote")->rowCount();
                $borradas += $n;
                $lotes++;
            } while ($n === $lote && $lotes < MAX_LOTES);
            $total += $borradas;
            $decir("accesos · $nombre, más de $dias días: $borradas filas en $lotes " . ($lotes === 1 ? 'lote' : 'lotes'));
            if ($lotes >= MAX_LOTES) {
                fwrite(STDERR, "purgar_accesos: se paró en " . MAX_LOTES . " lotes y quedan filas · se sigue en la próxima corrida\n");
                exit(2);
            }
        }
        $ahora = Database::fetchOne('SELECT NOW() AS n')['n'];
        $decir("total: $total filas · reloj de la base: $ahora");
    } finally {
        Database::query('DO RELEASE_LOCK(:n)', [':n' => CANDADO]);
    }
} catch (\PDOException $e) {
    error_log('[OFDEV] purgar_accesos · código ' . (int) ($e->errorInfo[1] ?? 0) . ' · ' . $e->getMessage());
    fwrite(STDERR, 'purgar_accesos: ERROR de la base (código ' . (int) ($e->errorInfo[1] ?? 0) . '); el detalle, en storage/logs/cron.log' . "\n");
    exit(1);
}
exit(0);
