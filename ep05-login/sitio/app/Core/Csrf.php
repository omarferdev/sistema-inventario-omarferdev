<?php
declare(strict_types=1);

namespace App\Core;

/**
 * El token CSRF: uno por sesión, de 256 bits, comparado con hash_equals, en TODO POST (salir incluido).
 * Vive en la sesión, ⛔ nunca en una cookie: en el dominio compartido otro sitio podría plantar cookies.
 * Se rota al entrar, al salir y al cambiar la contraseña. Además, el origen se revisa en cada POST.
 */
final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function campo(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valido(mixed $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && is_string($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function rotar(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    /**
     * Defensa en profundidad. Manda Sec-Fetch-Site, que pone el navegador y ninguna página puede falsificar:
     * debe decir same-origin (o none: el usuario tecleó la dirección). Si no viene, el Origin debe ser el nuestro.
     * Con Referrer-Policy: no-referrer, un formulario normal llega con «Origin: null» (medido el 08-oct en el
     * navegador: Origin 'null' y Sec-Fetch-Site 'same-origin'): «null» no es un origen, y si no hay nada más, decide
     * el token. Un navegador viejo sin ninguna de las dos cabeceras pasa aquí y lo frena el token.
     */
    public static function origenValido(): bool
    {
        $sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($sitio)) {
            return in_array($sitio, ['same-origin', 'none'], true);
        }
        $origen = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origen) && $origen !== 'null') {
            return hash_equals(config('url'), $origen);
        }
        return true;
    }
}
