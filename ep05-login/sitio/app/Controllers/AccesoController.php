<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Sesion;
use App\Core\Vista;
use App\Services\AccesoService;

/** Entrar, cambiar la contraseña obligatoria y salir. Delgado: guardia, Service y respuesta. */
final class AccesoController
{
    public function formulario(): void
    {
        if (Sesion::autenticado()) {
            redirigir('');
        }
        if (Sesion::parcialId() !== null) {
            redirigir('cambiar-password');
        }
        self::sinCache();
        Vista::render('acceso/entrar', ['error' => null], null);
    }

    public function entrar(): void
    {
        Guardia::post();
        $r = (new AccesoService())->entrar(post_texto('correo'), post_texto('password'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $destino = ($r['cambiar'] ?? false) ? 'cambiar-password' : '';
        if (esAjax()) {
            responderJson($r['exito'] ? ['exito' => true, 'ir' => url($destino)] : ['exito' => false, 'mensaje' => $r['mensaje']]);
        }
        if ($r['exito']) {
            redirigir($destino);
        }
        self::sinCache();
        Vista::render('acceso/entrar', ['error' => $r['mensaje']], null);
    }

    public function formularioCambio(): void
    {
        if (Sesion::parcialId() === null) {
            redirigir(Sesion::autenticado() ? '' : 'login');
        }
        // Una sesión parcial que ya no vale (la restablecieron, o se cambió en otra pestaña) se cierra.
        if (!(new AccesoService())->parcialVigente(Sesion::parcialId(), (int) Sesion::parcialVersion())) {
            Sesion::salir();
            redirigir('login');
        }
        self::sinCache();
        Vista::render('acceso/cambiar', ['error' => null, 'minimo' => config('acceso.password.min_caracteres')], null);
    }

    public function cambiar(): void
    {
        Guardia::post();
        $id = Sesion::parcialId();
        if ($id === null) {
            esAjax() ? responderJson(['exito' => false, 'mensaje' => 'Tu sesión ya no es válida. Vuelve a entrar.',
                                      'recargar' => true]) : redirigir('login');
        }
        $r = (new AccesoService())->cambiarObligatoria($id, (int) Sesion::parcialVersion(), post_texto('nueva'),
            post_texto('confirma'), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!empty($r['recargar'])) {
            Sesion::salir();   // La sesión parcial ya no vale; se cierra, y la página vuelve a entrar
            esAjax() ? responderJson(['exito' => false, 'mensaje' => $r['mensaje'], 'recargar' => true]) : redirigir('login');
        }
        if (esAjax()) {
            // El resultado en duda viaja como tal (la página lo pinta en ámbar, no como un error)
            responderJson($r['exito'] ? ['exito' => true, 'ir' => url('')]
                : ['exito' => false, 'mensaje' => $r['mensaje']] + (!empty($r['incierto']) ? ['incierto' => true] : []));
        }
        if ($r['exito']) {
            redirigir('');
        }
        self::sinCache();
        Vista::render('acceso/cambiar', ['error' => $r['mensaje'], 'minimo' => config('acceso.password.min_caracteres')], null);
    }

    /** POST con CSRF (⛔ nunca GET: un enlace desde otro sitio cerraría la sesión). */
    public function salir(): void
    {
        // Sin sesión (caducó por los 30 min sin uso) no hay nada que cerrar: a entrar, ANTES de la
        // guardia. Si no, el CSRF ya borrado daba «La página caducó» y recargar reenviaba el mismo POST.
        if (!Sesion::autenticado() && Sesion::parcialId() === null) {
            redirigir('login');
        }
        Guardia::post();
        (new AccesoService())->salir((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        redirigir('login');
    }

    /** Toda página con un token o con sesión: ⛔ ninguna caché la guarda. */
    private static function sinCache(): void
    {
        header('Cache-Control: no-store, private');
    }
}
