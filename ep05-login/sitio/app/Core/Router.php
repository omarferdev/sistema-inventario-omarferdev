<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rutas de coincidencia EXACTA (sin comodines ni parámetros en la ruta: los ids viajan en el cuerpo del POST).
 * config/rutas.php devuelve [[MÉTODO, '/ruta', [Clase::class, 'metodo']], …]. Una ruta con otro verbo da 405 con su
 * cabecera Allow; una que no existe, 404; un handler que no existe, 500 con log.
 */
final class Router
{
    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}}> $rutas */
    public function __construct(private readonly array $rutas)
    {
    }

    /** @return array{estado: int, handler?: array, permitidos?: list<string>} */
    public function resolver(string $metodo, string $uri): array
    {
        $ruta = self::normalizar($uri);
        // HEAD es un GET sin cuerpo (el servidor no lo manda); antes, toda ruta daba 405 a HEAD.
        $pedido = strtoupper($metodo) === 'HEAD' ? 'GET' : strtoupper($metodo);
        $permitidos = [];
        foreach ($this->rutas as [$m, $r, $handler]) {
            if ($r !== $ruta) {
                continue;
            }
            if ($m === $pedido) {
                return ['estado' => 200, 'handler' => $handler];
            }
            $permitidos[] = $m;
        }
        return $permitidos === [] ? ['estado' => 404] : ['estado' => 405, 'permitidos' => $permitidos];
    }

    public function despachar(string $metodo, string $uri): void
    {
        $r = $this->resolver($metodo, $uri);
        if ($r['estado'] === 200) {
            [$clase, $accion] = $r['handler'];
            if (!class_exists($clase) || !method_exists($clase, $accion)) {
                error_log('[OFDEV] ruta con handler inexistente: ' . $clase . '::' . $accion);
                self::error(500, 'Ocurrió un error.');
            }
            (new $clase())->$accion();
            return;
        }
        if ($r['estado'] === 405 && !headers_sent()) {
            header('Allow: ' . implode(', ', $r['permitidos']));
        }
        self::error($r['estado'], $r['estado'] === 405 ? 'Método no permitido.' : 'No encontrado.');
    }

    /** La ruta decodificada, sin query, sin barras de más y sin barra final (salvo la raíz). */
    public static function normalizar(string $uri): string
    {
        $ruta = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $ruta = '/' . trim((string) preg_replace('#/+#', '/', $ruta), '/');
        return $ruta;
    }

    /** Una página de error mínima, con su propia CSP en <meta> (no pasa por una vista). */
    public static function error(int $codigo, string $texto): never
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, private');
        }
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">', csp_meta(),
             '<title>', e($texto), '</title></head><body><p>', e($texto), '</p>',
             // Toda página de error trae una salida (recargar un POST caducado volvía al mismo 403)
             '<p><a href="', e(url('login')), '">Volver a entrar</a></p></body></html>';
        exit;
    }
}
