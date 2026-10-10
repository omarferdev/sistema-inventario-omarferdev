<?php
declare(strict_types=1);

namespace App\Core;

/**
 * La sesión del personal.
 *
 * · Almacén propio en storage/sessions (el de fábrica del hosting es compartido y lo barre en 24 min, medido el
 *   08-oct), use_strict_mode, gc_maxlifetime igual a la vida absoluta, id de 160 bits y la cookie __Host- con
 *   Secure, HttpOnly y SameSite=Lax, que muere al cerrar el navegador.
 * · Nada editable se congela en $_SESSION: solo el id, la versión de la sesión y los relojes. autenticado() relee la
 *   cuenta de la base una vez por petición y corta la sesión si la cuenta o su rol están inactivos, o si la
 *   contraseña cambió (sesion_version distinta).
 * · Si esa lectura falla por un error de sentencia (no de conexión: ese termina antes), deja pasar SOLO si la sesión
 *   se validó hace menos de 5 minutos; si no, la corta. Queda en el log.
 * · El inicio parcial (cambio obligatorio de contraseña) guarda parcial_id y NO usuario_id: con él no se entra a nada.
 */
final class Sesion
{
    private const VENTANA_FALLA_ABIERTA = 300;

    private static bool $sincronizada = false;
    private static ?array $usuario = null;
    private static ?string $motivoCorte = null;
    private static ?int $idCortado = null;

    public static function iniciar(bool $cuentaComoUso = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $c = config('acceso.sesion');
        $https = self::esHttps();
        if (config('env') === 'production' && !$https) {
            error_log('[OFDEV] petición sin HTTPS en producción: no se abre sesión');
            http_response_code(400);
            exit('Usa la dirección con https.');
        }
        // Si ya salió algo de la página, PHP no deja abrir la sesión ni fijar la cookie: falla cerrado, en vez de
        // seguir con una sesión que no se guarda.
        if (headers_sent($archivo, $linea)) {
            error_log("[OFDEV] la sesión no se puede abrir: ya salió contenido en $archivo:$linea");
            exit('Servicio no disponible.');
        }
        $dir = config('rutas.storage') . '/sessions';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('[OFDEV] no se pudo crear storage/sessions');
            http_response_code(500);
            exit('Servicio no disponible.');
        }
        ini_set('session.save_path', $dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $c['vida_seg']);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.sid_length', (string) $c['sid_length']);
        ini_set('session.sid_bits_per_character', (string) $c['sid_bits']);
        // __Host- exige Secure: sin HTTPS (solo un laboratorio local) la cookie lleva otro nombre.
        session_name($https ? $c['nombre'] : 'OFDEV_SID_LOCAL');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $https,
            'httponly' => true, 'samesite' => $c['samesite'],
        ]);
        if (!session_start()) {
            error_log('[OFDEV] session_start() falló');
            http_response_code(500);
            exit('Servicio no disponible.');
        }
        self::caducar($cuentaComoUso);
    }

    /** HTTPS real. El servidor lo dice directo (HTTPS=on detrás del CDN, medido el 08-oct): ⛔ no se confía en
     *  X-Forwarded-Proto, que manda cualquiera. */
    public static function esHttps(): bool
    {
        return strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /** Pasada la inactividad o la vida absoluta, vacía las claves y regenera el id (⛔ no session_destroy: rompería
     *  el CSRF de la pantalla de entrar al redirigir). El sondeo de Live Sync llama con $cuentaComoUso = false. */
    private static function caducar(bool $cuentaComoUso): void
    {
        $c = config('acceso.sesion');
        $ahora = time();
        $creada = $_SESSION['_creada'] ?? null;
        $uso = $_SESSION['_ultimo_uso'] ?? null;
        if (is_int($creada) && is_int($uso)
            && ($ahora - $creada > $c['vida_seg'] || $ahora - $uso > $c['inactividad_seg'])) {
            self::$motivoCorte = 'CADUCADA';
            self::vaciar();
            return;
        }
        if ($cuentaComoUso && is_int($creada)) {
            $_SESSION['_ultimo_uso'] = $ahora;
        }
    }

    /** Al entrar: id nuevo (contra la fijación de sesión), las claves mínimas y el token CSRF nuevo. */
    public static function entrar(int $usuarioId, int $version): void
    {
        session_regenerate_id(true);
        $ahora = time();
        $_SESSION = ['usuario_id' => $usuarioId, 'sesion_version' => $version,
                     '_creada' => $ahora, '_ultimo_uso' => $ahora, '_validado_en' => $ahora];
        Csrf::rotar();
        self::$sincronizada = false;
        self::$usuario = null;
    }

    /** Inicio PARCIAL: solo deja cambiar la contraseña. */
    public static function entrarParcial(int $usuarioId, int $version): void
    {
        session_regenerate_id(true);
        $ahora = time();
        $_SESSION = ['parcial_id' => $usuarioId, 'sesion_version' => $version, '_creada' => $ahora, '_ultimo_uso' => $ahora];
        Csrf::rotar();
        self::$sincronizada = false;
        self::$usuario = null;
    }

    public static function parcialId(): ?int
    {
        return is_int($_SESSION['parcial_id'] ?? null) ? $_SESSION['parcial_id'] : null;
    }

    /** La sesion_version que guardó el inicio parcial: el cambio de contraseña la compara con la base. */
    public static function parcialVersion(): ?int
    {
        return self::parcialId() !== null && is_int($_SESSION['sesion_version'] ?? null) ? $_SESSION['sesion_version'] : null;
    }

    public static function salir(): void
    {
        self::vaciar();
        Csrf::rotar();
    }

    public static function autenticado(): bool
    {
        if (!is_int($_SESSION['usuario_id'] ?? null)) {
            return false;
        }
        if (!self::$sincronizada) {
            self::$sincronizada = true;
            return self::sincronizarDesdeBd();
        }
        return self::$usuario !== null;
    }

    /** El usuario de ESTA petición, leído de la base (nombre, rol y si es de sistema). null sin sesión. */
    public static function usuario(): ?array
    {
        return self::autenticado() ? self::$usuario : null;
    }

    /** Por qué se cortó la sesión en esta petición (CADUCADA, NO_EXISTE, INACTIVO, ROL_INACTIVO, VERSION, ERROR_BD). */
    public static function motivoCorte(): ?string
    {
        return self::$motivoCorte;
    }

    private static function sincronizarDesdeBd(): bool
    {
        $id = $_SESSION['usuario_id'];
        try {
            $u = Database::fetchOne(
                'SELECT u.id, u.nombre, u.activo, u.sesion_version, r.activo AS rol_activo, r.es_sistema, r.clave AS rol
                   FROM usuarios u JOIN roles r ON r.id = u.rol_id
                  WHERE u.id = :id', [':id' => $id]);
        } catch (\PDOException $e) {
            error_log('[OFDEV] no se pudo releer la sesión del usuario ' . $id . ' · código ' . Transaccion::codigo($e));
            $validado = $_SESSION['_validado_en'] ?? null;
            if (is_int($validado) && time() - $validado <= self::VENTANA_FALLA_ABIERTA) {
                // Falla abierta acotada: la sesión sigue, pero esta petición va SIN permisos (es_sistema 0), así
                // que nadie gana acceso con datos viejos; solo no se le saca por un bloqueo pasajero de la base.
                self::$usuario = ['id' => $id, 'nombre' => '', 'rol' => '', 'es_sistema' => 0];
                return true;
            }
            return self::cortar('ERROR_BD');
        }
        $motivo = match (true) {
            $u === null                                                 => 'NO_EXISTE',
            (int) $u['activo'] !== 1                                    => 'INACTIVO',
            (int) $u['rol_activo'] !== 1                                => 'ROL_INACTIVO',
            (int) $u['sesion_version'] !== ($_SESSION['sesion_version'] ?? -1) => 'VERSION',
            default                                                     => null,
        };
        if ($motivo !== null) {
            return self::cortar($motivo);
        }
        $_SESSION['_validado_en'] = time();
        self::$usuario = ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol'], 'es_sistema' => (int) $u['es_sistema']];
        return true;
    }

    /** El usuario cuya sesión se cortó en esta petición (para anotar SESION_CORTADA en la bitácora). */
    public static function idCortado(): ?int
    {
        return self::$idCortado;
    }

    private static function cortar(string $motivo): bool
    {
        self::$idCortado = is_int($_SESSION['usuario_id'] ?? null) ? $_SESSION['usuario_id'] : null;
        self::$motivoCorte = $motivo;
        self::$usuario = null;
        self::vaciar();
        return false;
    }

    private static function vaciar(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
