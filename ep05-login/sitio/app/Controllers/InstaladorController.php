<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\Vista;
use App\Services\AccesoService;

/**
 * /instalar — el primer Super administrador SIN SSH (09-oct-2026). Existe solo mientras haga
 * falta: con la clave INSTALACION_CLAVE en env.php y la tabla usuarios vacía. Con la primera cuenta se apaga para
 * siempre (responde el mismo 404 que una ruta que no existe). La lógica, en AccesoService::instalar().
 */
final class InstaladorController
{
    public function formulario(): void
    {
        if (!AccesoService::instalacionDisponible()) {
            Router::error(404, 'No encontrado.');
        }
        self::sinCache();
        Vista::render('acceso/instalar', ['error' => null, 'hecho' => false,
            'minimo' => config('acceso.password.min_caracteres')], null);
    }

    public function instalar(): void
    {
        Guardia::post();
        $r = (new AccesoService())->instalar(post_texto('clave'), post_texto('nombre'), post_texto('correo'),
            post_texto('nueva'), post_texto('confirma'), (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        // Se apagó entre la puerta de index.php y aquí (otra pestaña creó la cuenta): quien llegó tenía la página
        // abierta, así que se le dice qué pasó y adónde ir. El apagado de siempre ni llega aquí.
        if (!empty($r['no_disponible'])) {
            esAjax() ? responderJson(['exito' => false, 'mensaje' => 'El sistema ya está instalado: entra con tu cuenta.'], 404)
                : Router::error(404, 'No encontrado.');
        }
        if (esAjax()) {
            responderJson($r['exito']
                ? ['exito' => true, 'ir' => url('login'), 'aviso' => AccesoService::MENSAJE_INSTALADO]
                : ['exito' => false, 'mensaje' => $r['mensaje']] + (!empty($r['incierto']) ? ['incierto' => true] : []));
        }
        self::sinCache();
        Vista::render('acceso/instalar', ['error' => $r['exito'] ? null : (array) $r['mensaje'], 'hecho' => $r['exito'],
            'minimo' => config('acceso.password.min_caracteres')], null);
    }

    private static function sinCache(): void
    {
        header('Cache-Control: no-store, private');
    }
}
