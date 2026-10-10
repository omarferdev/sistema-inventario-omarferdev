<?php
declare(strict_types=1);

namespace App\Core;

/**
 * La ÚNICA política de contraseñas (ASVS 5.0, capítulo 6.2). La usan el alta, el cambio forzado, «Mi cuenta» y
 * cron/crear_admin.php. Las cifras salen de config/acceso.php.
 *
 * Reglas, en este orden (lo barato y lo que protege al servidor, primero):
 *   1. a lo sumo max_bytes bytes, ANTES de cualquier otra cosa (no se gasta CPU en una contraseña gigante);
 *   2. UTF-8 válido y sin caracteres de control (NUL incluido);
 *   3. al menos min_caracteres caracteres; sin reglas de composición (ASVS 6.2.5);
 *   4. si el algoritmo fuera bcrypt, a lo sumo 72 bytes (bcrypt ignora el resto): ⛔ se rechaza, no se recorta (6.2.8);
 *   5. no está en la lista local de comunes y filtradas (6.2.4 y 6.2.12; decisión de Omar, 08-oct);
 *   6. no es el correo, la parte del correo antes de la arroba, ni el nombre;
 *   7. no es la contraseña actual.
 * La lista y las comparaciones usan la forma normalizada (minúsculas y sin espacios en los extremos); la contraseña
 * se guarda y se verifica TAL COMO LLEGA.
 *
 * La lista: config/contrasenas-comunes.txt, de SecLists (MIT), archivo 100k-most-used-passwords-NCSC.txt del commit
 * 8420764e (23-sep-2026; huella de git 38eb3770), normalizada con esta misma regla: 97 745 entradas. Si falta,
 * la política FALLA CERRADA: no deja fijar ninguna contraseña.
 */
final class PoliticaPassword
{
    /** @var array<string, true>|null */
    private static ?array $comunes = null;

    /** @return list<string> los motivos de rechazo, para el usuario; vacía = aceptada */
    public static function revisar(#[\SensitiveParameter] string $password, array $contexto = []): array
    {
        $p = config('acceso.password');
        if (strlen($password) > $p['max_bytes']) {
            return ['Es demasiado larga.'];
        }
        if (!mb_check_encoding($password, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            return ['Tiene caracteres que no se permiten.'];
        }
        $motivos = [];
        if (trim($password) === '') {
            return ['No puede ser solo espacios.'];   // Los espacios DENTRO de la contraseña siguen permitidos
        }
        if (mb_strlen($password, 'UTF-8') < $p['min_caracteres']) {
            $motivos[] = 'Debe tener al menos ' . $p['min_caracteres'] . ' caracteres.';
        }
        if ($p['algoritmo'] === PASSWORD_BCRYPT && strlen($password) > 72) {
            $motivos[] = 'Es demasiado larga.';
        }
        $n = self::normalizar($password);
        $comunes = self::comunes();
        if ($comunes === null) {
            return ['No se puede revisar la contraseña en este momento. Intenta más tarde.'];
        }
        if (isset($comunes[$n])) {
            $motivos[] = 'Es una contraseña demasiado común: elige otra.';
        }
        $correo = self::normalizar((string) ($contexto['correo'] ?? ''));
        $local = explode('@', $correo)[0];
        $nombre = self::normalizar((string) ($contexto['nombre'] ?? ''));
        if (($correo !== '' && ($n === $correo || $n === $local)) || ($nombre !== '' && $n === $nombre)) {
            $motivos[] = 'No puede ser tu correo ni tu nombre.';
        }
        $actual = (string) ($contexto['hash_actual'] ?? '');
        if ($actual !== '' && password_verify($password, $actual)) {
            $motivos[] = 'Debe ser distinta de la actual.';
        }
        return $motivos;
    }

    public static function huella(#[\SensitiveParameter] string $password): string
    {
        $p = config('acceso.password');
        return password_hash($password, $p['algoritmo'], $p['opciones']);
    }

    /** Al entrar, una huella con otros parámetros se recalcula (sin mover actualizado_en). */
    public static function necesitaRehash(string $hash): bool
    {
        $p = config('acceso.password');
        return password_needs_rehash($hash, $p['algoritmo'], $p['opciones']);
    }

    public static function normalizar(string $texto): string
    {
        return mb_strtolower(trim($texto), 'UTF-8');
    }

    /** @return array<string, true>|null null si la lista no se pudo leer (y queda en el log) */
    private static function comunes(): ?array
    {
        if (self::$comunes !== null) {
            return self::$comunes;
        }
        $archivo = config('acceso.password.lista_comunes');
        $lineas = is_file($archivo) ? file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lineas === false || count($lineas) < 3000) {
            error_log('[OFDEV] la lista de contraseñas comunes falta o está incompleta: la política falla cerrada');
            return null;
        }
        return self::$comunes = array_fill_keys($lineas, true);
    }
}
