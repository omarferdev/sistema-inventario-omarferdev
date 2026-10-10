<?php
declare(strict_types=1);

/*
 * config/rutas.php — coincidencia exacta (App\Core\Router). Los ids viajan en el cuerpo del POST.
 * Salir es POST con CSRF: ⛔ nunca GET.
 */

use App\Controllers\AccesoController;
use App\Controllers\InicioController;
use App\Controllers\InstaladorController;

defined('OFDEV') || exit;

return [
    ['GET',  '/',                 [InicioController::class, 'index']],
    ['GET',  '/login',            [AccesoController::class, 'formulario']],
    ['POST', '/login',            [AccesoController::class, 'entrar']],
    ['GET',  '/cambiar-password', [AccesoController::class, 'formularioCambio']],
    ['POST', '/cambiar-password', [AccesoController::class, 'cambiar']],
    ['POST', '/logout',           [AccesoController::class, 'salir']],
    // El instalador sin SSH: responde 404 salvo con INSTALACION_CLAVE en env.php y sin ningún usuario.
    ['GET',  '/instalar',         [InstaladorController::class, 'formulario']],
    ['POST', '/instalar',         [InstaladorController::class, 'instalar']],
];
