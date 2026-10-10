<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pinta app/Views/modulos/<plantilla>.php dentro de app/Views/layouts/<layout>.php (como $contenido), o sola si
 * $layout es null. extract() va con EXTR_SKIP: por eso las variables propias de este método llevan el prefijo «__»,
 * que ninguna clave de un controlador usa; si una clave coincidiera, la vista recibiría otra cosa sin error.
 * Todo dato impreso en una vista pasa por e().
 */
final class Vista
{
    public static function render(string $plantilla, array $vars = [], ?string $layout = 'app'): void
    {
        self::revisarClaves($vars);
        echo self::pintar('modulos/' . $plantilla, $vars, $layout);
    }

    /**
     * Pinta app/Views/componentes/<nombre>.php y devuelve el HTML. Corre en su PROPIO ámbito: solo ve $vars, no las
     * variables de la vista que lo llama, y las suyas no se quedan en ella.
     */
    public static function componente(string $nombre, array $vars = []): string
    {
        if (preg_match('#^[a-z0-9_-]+$#', $nombre) !== 1) {
            throw new \InvalidArgumentException('Nombre de componente no permitido');
        }
        self::revisarClaves($vars);
        return self::pintar('componentes/' . $nombre, $vars, null);
    }

    private static function revisarClaves(array $vars): void
    {
        foreach (array_keys($vars) as $__clave) {
            if (str_starts_with((string) $__clave, '__') || $__clave === 'this' || $__clave === 'contenido') {
                throw new \LogicException('Clave reservada para la vista: ' . $__clave);
            }
        }
    }

    private static function pintar(string $__plantilla, array $__vars, ?string $__layout): string
    {
        $__base = config('rutas.vistas');
        if (preg_match('#^[a-z0-9_/-]+$#', $__plantilla) !== 1 || str_contains($__plantilla, '..')) {
            throw new \InvalidArgumentException('Nombre de plantilla no permitido');
        }
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            require $__base . '/' . $__plantilla . '.php';
            $contenido = (string) ob_get_clean();
        } catch (\Throwable $__e) {
            ob_end_clean();
            throw $__e;
        }
        if ($__layout === null) {
            return $contenido;
        }
        if (preg_match('#^[a-z0-9_-]+$#', $__layout) !== 1) {
            throw new \InvalidArgumentException('Nombre de layout no permitido');
        }
        ob_start();
        try {
            require $__base . '/layouts/' . $__layout . '.php';
            return (string) ob_get_clean();
        } catch (\Throwable $__e) {
            ob_end_clean();
            throw $__e;
        }
    }
}
