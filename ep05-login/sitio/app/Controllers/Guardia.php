<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Sesion;
use App\Services\AccesoService;

/**
 * La guardia de los controladores, en un solo sitio.
 *   pagina():  una página con sesión. Sin sesión, a /login; si la sesión se acaba de cortar por la cuenta (inactiva,
 *              rol inactivo, contraseña cambiada), queda SESION_CORTADA en la bitácora.
 *   post():    todo POST, en este orden: cuerpo descartado por tamaño (413) → origen del mismo sitio → CSRF.
 *              Por qué el 413 va antes: si un POST supera post_max_size, PHP tira el cuerpo y el usuario leería
 *              «token inválido» en bucle.
 */
final class Guardia
{
    public static function pagina(): void
    {
        if (Sesion::autenticado()) {
            return;
        }
        $id = Sesion::idCortado();
        $motivo = Sesion::motivoCorte();
        if ($id !== null && in_array($motivo, ['INACTIVO', 'ROL_INACTIVO', 'VERSION'], true)) {
            (new AccesoService())->anotarCorte($id, $motivo, (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        redirigir(Sesion::parcialId() !== null ? 'cambiar-password' : 'login');
    }

    public static function post(): void
    {
        // El 413 solo cuando el cuerpo de verdad pasó de post_max_size (antes, también un cuerpo
        // chico que no era de formulario, como un JSON, decía «demasiado grande»).
        if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::bytes((string) ini_get('post_max_size'))) {
            self::rechazar(413, 'Lo que enviaste es demasiado grande.');
        }
        if (!Csrf::origenValido() || !Csrf::valido($_POST['_csrf'] ?? null)) {
            self::rechazar(403, 'La página caducó. Recárgala e intenta de nuevo.', true);
        }
    }

    /** «8M», «512K», «1G» o bytes, como los escribe php.ini, a bytes. Vacío o 0: sin límite. */
    private static function bytes(string $valor): int
    {
        $v = trim($valor);
        if ($v === '' || (int) $v <= 0) {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtoupper(substr($v, -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }

    private static function rechazar(int $codigo, string $mensaje, bool $recargar = false): never
    {
        if (esAjax()) {
            responderJson(['exito' => false, 'mensaje' => $mensaje] + ($recargar ? ['recargar' => true] : []), $codigo);
        }
        \App\Core\Router::error($codigo, $mensaje);
    }
}
