<?php
declare(strict_types=1);

namespace App\Core;

/**
 * El catálogo de iconos: se piden por CLAVE.
 * Hoy vive solo aquí, como el respaldo en el código; cuando nazca la tabla de iconos, la base ganará y estas
 * figuras quedarán de respaldo. Todo icono que se use va a los DOS sitios, o cae a un círculo (y queda en el log).
 * Son trazos propios de 24 × 24, en currentColor, decorativos (aria-hidden): el texto del control los nombra.
 */
final class Iconos
{
    public const SVG = [
        'correo'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'candado'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'ojo'         => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'ojo-tachado' => '<path d="M2 12s3.5-7 10-7c1.7 0 3.2.5 4.5 1.2M22 12s-3.5 7-10 7c-1.7 0-3.2-.5-4.5-1.2"/>'
                       . '<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M3 3l18 18"/>',
        'flecha'      => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'persona'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
        'llave'       => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M16 7l3 3"/><path d="M14 9l2 2"/>',
    ];

    private const RESPALDO = '<circle cx="12" cy="12" r="8"/>';

    public static function obtener(string $clave, int $tam = 18, string $clase = 'icono'): string
    {
        $figura = self::SVG[$clave] ?? null;
        if ($figura === null) {
            error_log('[OFDEV] icono inexistente: ' . $clave);
            $figura = self::RESPALDO;
        }
        return '<svg class="' . e($clase) . '" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none"'
             . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
             . ' focusable="false">' . $figura . '</svg>';
    }
}
