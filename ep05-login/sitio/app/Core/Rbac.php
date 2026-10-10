<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Permisos en CADA petición. Versión mínima del login: mientras solo exista el Super
 * administrador, pasa el rol de sistema (es_sistema = 1, leído de la base en esta petición) y TODO lo demás se niega.
 * Con los módulos Usuarios y Roles llegan modulos y permisos_rol, y esta clase crece sin cambiar su firma.
 */
final class Rbac
{
    public static function tieneAcceso(?string $modulo): bool
    {
        $u = Sesion::usuario();
        return $u !== null && $u['es_sistema'] === 1;
    }

    /** Para una página: si no tiene permiso, 403 y termina. */
    public static function requerirAcceso(string $modulo): void
    {
        if (!self::tieneAcceso($modulo)) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store, private');
            }
            exit('Sin permiso.');
        }
    }
}
