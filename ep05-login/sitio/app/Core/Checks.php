<?php
declare(strict_types=1);

namespace App\Core;

/**
 * El detector de CHECK desaparecidos, su parte pura. La
 * usa cron/verificar_checks.php; aquí no hay base ni archivos, para probarla sola.
 *
 * Por qué: los CHECK con nombre NO viajan en los respaldos de phpMyAdmin. Una restauración los borra sin aviso, y las
 * reglas que «la base garantiza» dejan de valer. Se compara la base viva contra la última foto buena (el manifiesto).
 */
final class Checks
{
    /**
     * Los CHECK de un SHOW CREATE TABLE: nombre => expresión, tal cual la imprime MariaDB (medido el 08-oct en
     * 11.8.9: uno por renglón, «  CONSTRAINT `nombre` CHECK (expresión)», con coma salvo el último).
     * @return array<string, string>
     */
    public static function extraer(string $createTable): array
    {
        preg_match_all('/^\s*CONSTRAINT `([^`]+)` CHECK \((.*)\),?$/m', $createTable, $m, PREG_SET_ORDER);
        $checks = [];
        foreach ($m as [, $nombre, $expresion]) {
            $checks[$nombre] = $expresion;
        }
        ksort($checks, SORT_STRING);
        return $checks;
    }

    /**
     * Compara el manifiesto (la foto buena) con la base viva. Las dos van como tabla => [nombre => expresión].
     * @param array<string, array<string, string>> $foto
     * @param array<string, array<string, string>> $vivo
     * @return array{desaparecidos: list<string>, cambiados: list<string>, nuevos: list<string>,
     *               tablas_faltan: list<string>, tablas_nuevas: list<string>}
     */
    public static function comparar(array $foto, array $vivo): array
    {
        $r = ['desaparecidos' => [], 'cambiados' => [], 'nuevos' => [], 'tablas_faltan' => [], 'tablas_nuevas' => []];
        foreach ($foto as $tabla => $checks) {
            if (!array_key_exists($tabla, $vivo)) {
                $r['tablas_faltan'][] = $tabla;
                foreach (array_keys($checks) as $n) {
                    $r['desaparecidos'][] = "$tabla.$n";
                }
                continue;
            }
            foreach ($checks as $n => $expr) {
                if (!array_key_exists($n, $vivo[$tabla])) {
                    $r['desaparecidos'][] = "$tabla.$n";
                } elseif ($vivo[$tabla][$n] !== $expr) {
                    $r['cambiados'][] = "$tabla.$n";
                }
            }
            foreach (array_keys(array_diff_key($vivo[$tabla], $checks)) as $n) {
                $r['nuevos'][] = "$tabla.$n";
            }
        }
        foreach (array_diff_key($vivo, $foto) as $tabla => $checks) {
            $r['tablas_nuevas'][] = $tabla;
            foreach (array_keys($checks) as $n) {
                $r['nuevos'][] = "$tabla.$n";
            }
        }
        foreach ($r as &$lista) {
            sort($lista, SORT_STRING);
        }
        return $r;
    }

    /** Cuántos CHECK hay en una foto. */
    public static function total(array $foto): int
    {
        return array_sum(array_map('count', $foto));
    }
}
