<?php
declare(strict_types=1);

namespace App\Core;

/**
 * conReintentos(): toda escritura con efecto pasa por aquí.
 *
 * Corre $trabajo en UNA transacción. $trabajo valida bajo lock y escribe, y lanza ErrorNegocio para abortar con un
 * texto para el usuario. Se re-ejecuta ENTERO en cada vuelta: no toca nada fuera de la base. Lo posterior al commit
 * (auditoría, Live Sync) lo hace quien llama, si 'exito' no es false.
 *
 * El orden de las ramas no es estético:
 *   1.º la FASE: si falla con la bandera de commit puesta, el resultado es INCIERTO y ⛔ no se reintenta;
 *   2.º ErrorNegocio: su texto, sin reintento;
 *   3.º el corte de un FOR UPDATE WAIT n puesto a propósito (1205 con $conEspera): sin reintento;
 *   4.º deadlock o espera (1213, 1205, 40001) y choque de folio (1062 sobre un índice de folio): reintento por CÓDIGO;
 *   5.º lo demás: al log, y un texto fijo por código. ⛔ Nunca $e->getMessage() al usuario.
 * El token de idempotencia se engancha justo antes de la fase commit cuando exista el primer módulo con altas.
 */
final class Transaccion
{
    private const MENSAJES = [
        1062 => 'Ya existe un registro con esos datos.',
        1451 => 'No se puede: el registro está en uso.',
        1452 => 'Falta el registro al que apunta.',
        4025 => 'El formato de algún campo no es válido.',
        3819 => 'El formato de algún campo no es válido.',
        1406 => 'Un texto es más largo de lo permitido.',
    ];

    public static function correr(callable $trabajo, string $siFalla, bool $conEspera = false): array
    {
        $quedanEspera = 2;
        $quedanFolio = 1;              // contadores DISTINTOS: uno no se come al otro
        while (true) {
            $fase = 'tx';              // se reinicia en cada vuelta
            try {
                Database::iniciar();
                $res = $trabajo();
                $fase = 'commit';      // JUSTO antes del commit
                Database::commit();
                return is_array($res) ? $res : ['exito' => true];
            } catch (\Throwable $e) {
                self::revertirProtegido();
                if ($fase === 'commit') {
                    error_log('[OFDEV] resultado INCIERTO · ' . get_class($e) . ' · código ' . self::codigo($e));
                    Database::olvidarConexion();
                    return ['exito' => false, 'incierto' => true,
                            'mensaje' => 'No se pudo confirmar si se guardó. Recarga y comprueba antes de repetir.'];
                }
                if ($e instanceof ErrorNegocio) {
                    return ['exito' => false, 'mensaje' => $e->getMessage()];
                }
                $codigo = self::codigo($e);
                if ($conEspera && $codigo === 1205) {
                    return ['exito' => false, 'mensaje' => 'Otra persona está modificando esto. Intenta en un momento.'];
                }
                if (self::esEspera($e) && $quedanEspera-- > 0) {
                    usleep(random_int(30_000, 120_000));
                    continue;
                }
                if (self::esChoqueDeFolio($e) && $quedanFolio-- > 0) {
                    continue;
                }
                error_log('[OFDEV] ' . get_class($e) . ' · código ' . $codigo . ' · ' . self::mensajeSeguro($e));
                return ['exito' => false, 'mensaje' => self::MENSAJES[$codigo] ?? $siFalla];
            }
        }
    }

    /**
     * El mensaje para el LOG, sin los valores que trae MariaDB. Un 1062 dice «Duplicate entry
     * '<el correo>' for key …» y un 1366 «Incorrect … value: '<lo tecleado>' for column …»: el valor se cambia por «…».
     * Los nombres de índice, columna y CHECK se quedan: son lo que sirve para diagnosticar.
     */
    public static function mensajeSeguro(\Throwable $e): string
    {
        $m = $e instanceof \PDOException ? (string) ($e->errorInfo[2] ?? $e->getMessage()) : $e->getMessage();
        return (string) preg_replace(["/entry '.*?' for key/s", "/value: '.*?' for/s"], ["entry '…' for key", "value: '…' for"], $m);
    }

    /** El código nativo de MariaDB (errorInfo[1]); 0 si no es un error de la base. */
    public static function codigo(\Throwable $e): int
    {
        return $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    }

    private static function esEspera(\Throwable $e): bool
    {
        $codigo = self::codigo($e);
        return $codigo === 1213 || $codigo === 1205
            || ($e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '40001');
    }

    /** Solo el 1062 de un índice de folio o serie se reintenta; otro UNIQUE es «ya existe». */
    private static function esChoqueDeFolio(\Throwable $e): bool
    {
        return self::codigo($e) === 1062
            && preg_match("/for key '[^']*(folio|serie)[^']*'/i", (string) ($e->errorInfo[2] ?? '')) === 1;
    }

    private static function revertirProtegido(): void
    {
        try {
            Database::revertir();
        } catch (\Throwable $e) {
            error_log('[OFDEV] no se pudo revertir · código ' . self::codigo($e));
            Database::olvidarConexion();
        }
    }
}
