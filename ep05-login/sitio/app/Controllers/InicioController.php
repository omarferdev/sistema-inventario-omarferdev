<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Core\Sesion;
use App\Core\Vista;

/** El inicio provisional: «Bienvenido» y «Salir». Lo reemplazan el menú y el tablero. */
final class InicioController
{
    private const MODULO = 'inicio';

    public function index(): void
    {
        Guardia::pagina();
        Rbac::requerirAcceso(self::MODULO);
        header('Cache-Control: no-store, private');
        Vista::render('inicio/index', ['titulo' => 'Inicio', 'nombre' => Sesion::usuario()['nombre']]);
    }
}
