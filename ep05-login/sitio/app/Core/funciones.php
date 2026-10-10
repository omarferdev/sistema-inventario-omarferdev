<?php
declare(strict_types=1);

/*
 * Helpers globales de OmarFerDev (08-oct-2026). Cada uno, una sola vez en todo el sistema.
 * La política de seguridad (CSP) vive aquí y solo aquí: csp_politica() la arma y csp_meta() la imprime.
 */

use App\Core\Transaccion;

if (!function_exists('e')) {
    /** Escapa para HTML (texto y atributos). Dentro de un url() de CSS no sirve: ahí va otro escape. */
    function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /** Lee la configuración con punto: config('db.host'). Una clave que no existe es un error de programación. */
    function config(string $ruta): mixed
    {
        $valor = $GLOBALS['ofdev_config'] ?? null;
        foreach (explode('.', $ruta) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                throw new \LogicException('Clave de configuración inexistente: ' . $ruta);
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }
}

if (!function_exists('url')) {
    /** Una URL interna. La base sale de config/app.php, ⛔ nunca de HTTP_HOST. */
    function url(string $ruta = ''): string
    {
        return config('url') . '/' . ltrim($ruta, '/');
    }
}

if (!function_exists('asset')) {
    /** Un archivo de public_html con su versión por fecha (⛔ nunca time(): rompería la caché en cada petición). */
    function asset(string $ruta): string
    {
        $ruta = ltrim($ruta, '/');
        $archivo = config('rutas.publica') . '/' . $ruta;
        $version = is_file($archivo) ? (string) filemtime($archivo) : '0';
        return url($ruta) . '?v=' . $version;
    }
}

if (!function_exists('nonce')) {
    function nonce(): string
    {
        return $GLOBALS['ofdev_nonce'] ?? throw new \LogicException('El nonce se crea en index.php');
    }
}

if (!function_exists('csp_politica')) {
    /**
     * La política, en un solo sitio. $paraMeta quita lo que una <meta> ignora (frame-ancestors): esa protección la da
     * la cabecera X-Frame-Options. El CDN de Hostinger REEMPLAZA la cabecera CSP (medido el 08-oct), así que la que
     * vale es la <meta> de cada página.
     */
    function csp_politica(bool $paraMeta = false): string
    {
        $partes = [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . nonce() . "'",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        if (!$paraMeta) {
            $partes[] = "frame-ancestors 'none'";
        }
        return implode('; ', $partes);
    }
}

if (!function_exists('csp_meta')) {
    /** Va justo después de <meta charset> en TODAS las páginas: solo gobierna lo que viene después. */
    function csp_meta(): string
    {
        return '<meta http-equiv="Content-Security-Policy" content="' . e(csp_politica(true)) . '">';
    }
}

if (!function_exists('cabeceras_seguridad')) {
    /**
     * Las cabeceras de cada respuesta, también desde PHP (por si mod_headers no está). Medido el 08-oct: el CDN de
     * Hostinger REEMPLAZA la CSP (por eso vale la <meta>) y deja pasar las demás.
     * HSTS ⛔ sin includeSubDomains: el dominio temporal es un subdominio compartido. ⛔ Ninguna cabecera
     * Access-Control-Allow-*: el sistema es de un solo origen.
     */
    function cabeceras_seguridad(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Security-Policy: ' . csp_politica());
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        if (config('env') === 'production') {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header_remove('X-Powered-By');
    }
}

if (!function_exists('redirigir')) {
    /** Solo a rutas internas (sin ?next= abierto). Una ruta absoluta u otro host se cambia por la raíz. */
    function redirigir(string $ruta = ''): never
    {
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\\\\)#i', $ruta) === 1 || str_contains($ruta, "\n") || str_contains($ruta, "\r")) {
            $ruta = '';
        }
        header('Location: ' . url($ruta), true, 303);
        exit;
    }
}

if (!function_exists('esAjax')) {
    function esAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}

if (!function_exists('post_texto')) {
    /** Un campo del POST como texto. Si llega como arreglo (correo[]=x), vale '' en vez de un aviso
     *  «Array to string conversion» en el log y la palabra «Array». */
    function post_texto(string $clave): string
    {
        $v = $_POST[$clave] ?? '';
        return is_string($v) ? $v : '';
    }
}

if (!function_exists('responderJson')) {
    /** Termina la petición con JSON y no-store (hay respuestas que no deben guardarse en ninguna caché). */
    function responderJson(array $datos, int $codigo = 200): never
    {
        try {
            $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[OFDEV] responderJson no pudo serializar: ' . $e->getMessage());
            $codigo = 500;
            $json = '{"exito":false,"mensaje":"Ocurrió un error. Intenta de nuevo."}';
        }
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
        }
        echo $json;
        exit;
    }
}

if (!function_exists('conReintentos')) {
    /** Toda escritura con efecto: una transacción con reintento por código y la regla de la fase (App\Core\Transaccion). */
    function conReintentos(callable $trabajo, string $siFalla, bool $conEspera = false): array
    {
        return Transaccion::correr($trabajo, $siFalla, $conEspera);
    }
}
