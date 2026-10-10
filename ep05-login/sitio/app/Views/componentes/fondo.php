<?php
declare(strict_types=1);
/*
 * El fondo de las pantallas de acceso, en UNA pieza (decisión de Omar, 08-oct): lo decide la clave
 * acceso.pantalla.fondo de config/acceso.php ('estrellas' o 'ninguno'). Para cambiarlo o quitarlo se cambia esa clave
 * o esta pieza; las vistas solo la piden dos veces, con Vista::componente('fondo', ['parte' => …]):
 *   'hoja'  en el <head>: la hoja del fondo (no se descarga si el fondo está apagado);
 *   'capas' al abrir el <body>: las capas que dibuja esa hoja.
 * Con 'ninguno' queda el fondo liso de acceso.css.
 */
/** @var string $parte */
if (config('acceso.pantalla.fondo') === 'estrellas') {
    if ($parte === 'hoja') {
        echo '<link rel="stylesheet" href="' . e(asset('assets/css/fondo-estrellas.css')) . '">';
    } elseif ($parte === 'capas') {
        // Las clases, las mismas de fondo-estrellas.css: si cambia una lista, cambian las dos.
        echo '<div class="fondo-estrellas" aria-hidden="true">'
           . '<div class="capa-1"></div><div class="capa-1-m"></div><div class="capa-2"></div>'
           . '<div class="capa-2-m"></div><div class="capa-3"></div><div class="capa-3-m"></div>'
           . '<div class="titila-1"></div><div class="titila-2"></div><div class="titila-3"></div>'
           . '<div class="titila-4"></div><div class="fugaz fugaz-1"></div><div class="fugaz fugaz-2"></div></div>';
    } else {
        throw new \InvalidArgumentException('Parte del fondo desconocida');
    }
}
