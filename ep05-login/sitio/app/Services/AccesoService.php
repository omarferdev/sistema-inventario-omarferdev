<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\PoliticaPassword;
use App\Core\Sesion;

/**
 * Entrar y salir. Las cifras, de config/acceso.php.
 *
 * entrar(), en este orden:
 *   1. normaliza el correo (minúsculas, sin espacios en los extremos) y saca su huella HMAC-SHA256 con la clave de
 *      env.php (el correo tecleado ⛔ nunca se guarda en claro). Si no tiene forma de correo, la huella va vacía;
 *   2. toma un candado de aplicación por IP (GET_LOCK), para que dos intentos a la vez no se salten la cuenta;
 *   3. revisa los bloqueos ANTES de gastar la huella de la contraseña: por IP (20 fallos en 15 min) y por correo con
 *      esa MISMA IP (5 en 15 min), así nadie bloquea a otro desde fuera. Cuenta por la huella del correo,
 *      exista o no la cuenta, así el bloqueo no delata qué correos existen;
 *   4. verifica la contraseña SIEMPRE: contra la de la cuenta, aunque esté inactiva, o contra una huella señuelo si
 *      el correo no existe, para que todo fallo tarde lo mismo;
 *   5. anota el intento en accesos y contesta el MISMO mensaje para todo fallo. Si la bitácora no se puede escribir,
 *      no entra nadie (falla cerrado);
 *   6. al entrar: recalcula la huella si sus parámetros son viejos (sin mover actualizado_en) y abre la sesión,
 *      completa o parcial si debe cambiar la contraseña.
 * El bloqueo dura mientras los fallos sigan dentro de la ventana (ventana deslizante de 15 min).
 */
final class AccesoService
{
    public const MENSAJE_FALLA = 'Correo o contraseña incorrectos.';
    public const MENSAJE_BLOQUEO = 'Demasiados intentos. Espera unos minutos y vuelve a intentar.';
    public const MENSAJE_ERROR = 'No se pudo entrar en este momento. Intenta de nuevo.';
    public const MENSAJE_PARCIAL_VIEJA = 'Tu sesión ya no es válida. Vuelve a entrar.';
    // Con la sugerencia de quitar la clave, que ya no sirve (el instalador quedó apagado).
    public const MENSAJE_INSTALADO = 'Cuenta creada. Ya puedes entrar con tu correo y tu contraseña. El instalador '
        . 'quedó apagado: si quieres, borra la línea INSTALACION_CLAVE de tu env.php.';
    public const MENSAJE_ERROR_INSTALAR = 'No se pudo crear la cuenta en este momento. Intenta de nuevo.';
    public const MENSAJE_CAMBIO_INCIERTO = 'No se pudo confirmar si se guardó tu contraseña nueva. Recarga la página: '
        . 'si ya se guardó, entrarás al sistema; si no, vuelve a escribirla.';

    /** Huella Argon2id (19 MiB, 2 pasadas) de 32 bytes al azar que nadie conoce: no es un secreto. Solo iguala el
     *  tiempo de un correo que no existe con el de una contraseña equivocada. */
    private const SENUELO = '$argon2id$v=19$m=19456,t=2,p=1$aENtMmM5bEpmVW9GOVJFeA$gI6t0T37HalKxL9JRCiKM87Y6j9P7dKBT5j7Td0UZLQ';

    private const FORMA_CORREO = '/^[a-z0-9._%+-]+@[a-z0-9-]+([.][a-z0-9-]+)+$/';   // la misma del CHECK de usuarios

    /** @return array{exito: bool, mensaje?: string, cambiar?: bool} */
    public function entrar(string $correoTecleado, #[\SensitiveParameter] string $password, string $ip, string $agente): array
    {
        $cfg = config('acceso');
        $correo = PoliticaPassword::normalizar($correoTecleado);
        $conForma = strlen($correo) <= 254 && preg_match(self::FORMA_CORREO, $correo) === 1;
        $hmac = $conForma ? hash_hmac('sha256', $correo, config('hmac_correo')) : null;
        $ip = self::ip($ip);
        $rango = self::rango($ip);
        $agente = self::agente($agente, $cfg['bitacora']['agente_max']);

        // Los candados de aplicación son de TODO el servidor (hosting compartido): el nombre lleva la base. Tope de
        // 64 caracteres: va su huella. GET_LOCK da 1 (tomado), 0 (ocupado: otro intento de la misma red) o NULL (error).
        $candado = 'ofdev:' . substr(hash('sha256', config('db.nombre') . '|acceso|' . $rango[0]), 0, 40);
        try {
            $tomado = Database::fetchOne('SELECT GET_LOCK(:n, 2) AS t', [':n' => $candado])['t'] ?? null;
        } catch (\PDOException $e) {
            error_log('[OFDEV] acceso: no se pudo tomar el candado · código ' . (int) ($e->errorInfo[1] ?? 0));
            return ['exito' => false, 'mensaje' => self::MENSAJE_ERROR];
        }
        if ($tomado === null) {
            error_log('[OFDEV] acceso: GET_LOCK devolvió NULL');
            return ['exito' => false, 'mensaje' => self::MENSAJE_ERROR];
        }
        if ((int) $tomado !== 1) {
            return ['exito' => false, 'mensaje' => self::MENSAJE_ERROR];   // ocupado no es «demasiados intentos»
        }
        try {
            return $this->entrarBajoCandado($correo, $conForma, $hmac, $password, $ip, $rango, $agente, $cfg);
        } catch (\PDOException $e) {
            error_log('[OFDEV] acceso: falla de la base · código ' . (int) ($e->errorInfo[1] ?? 0));
            return ['exito' => false, 'mensaje' => self::MENSAJE_ERROR];
        } finally {
            try {
                Database::query('DO RELEASE_LOCK(:n)', [':n' => $candado]);
            } catch (\Throwable) {
                // el candado muere con la conexión
            }
        }
    }

    /** @param array{0: string, 1: string} $rango la red de la IP (ver rango()) */
    private function entrarBajoCandado(string $correo, bool $conForma, ?string $hmac,
                                       #[\SensitiveParameter] string $password, string $ip, array $rango, string $agente,
                                       array $cfg): array
    {
        $i = $cfg['intentos'];
        // Se cuenta por RED (la IPv4 exacta, o el /64 de IPv6), medido el 08-oct: BETWEEN sobre INET6 usa
        // ix_accesos_ip_fecha. La fila guarda la IP completa.
        $red = [':ini' => $rango[0], ':fin' => $rango[1]];
        $porIp = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM accesos WHERE ip BETWEEN :ini AND :fin AND evento = 'FALLIDA'
                AND creado_en > NOW() - INTERVAL :v SECOND", $red + [':v' => $i['ip_ventana']])['c'];
        $porCuenta = $hmac === null ? 0 : (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM accesos WHERE correo_hmac = :h AND ip BETWEEN :ini AND :fin AND evento = 'FALLIDA'
                AND creado_en > NOW() - INTERVAL :v SECOND", $red + [':h' => $hmac, ':v' => $i['cuenta_ip_ventana']])['c'];
        if ($porIp >= $i['ip_fallos'] || $porCuenta >= $i['cuenta_ip_fallos']) {
            $motivo = $porIp >= $i['ip_fallos'] ? 'LIMITE_IP' : 'LIMITE_CUENTA';
            // A lo sumo una fila BLOQUEADA por IP y ventana, para que un ataque no llene la bitácora.
            $ya = Database::fetchOne(
                "SELECT 1 AS s FROM accesos WHERE ip BETWEEN :ini AND :fin AND evento = 'BLOQUEADA'
                    AND creado_en > NOW() - INTERVAL :v SECOND LIMIT 1", $red + [':v' => $i['ip_ventana']]);
            if ($ya === null) {
                $this->anotar('BLOQUEADA', null, $hmac, $motivo, $ip, $agente);
            }
            return ['exito' => false, 'mensaje' => self::MENSAJE_BLOQUEO];
        }

        if (strlen($password) > $cfg['password']['max_bytes']) {
            // No se gasta la huella en una contraseña gigante; cuenta como fallo.
            $this->anotar('FALLIDA', null, $hmac, 'FORMATO', $ip, $agente);
            return ['exito' => false, 'mensaje' => self::MENSAJE_FALLA];
        }

        // Decisión de Omar (08-oct; va después del rechazo por tamaño, que es inmediato): con muchos fallos a la MISMA cuenta desde cualquier red, cada intento espera más
        // antes de verificar, hasta un tope. ⛔ No bloquea: nadie deja fuera a la cuenta desde fuera. Cuenta por la
        // huella del correo, exista o no la cuenta, así la espera no delata qué correos existen.
        if ($hmac !== null) {
            $global = (int) Database::fetchOne(
                "SELECT COUNT(*) AS c FROM accesos WHERE correo_hmac = :h AND evento = 'FALLIDA'
                    AND creado_en > NOW() - INTERVAL :v SECOND", [':h' => $hmac, ':v' => $i['cuenta_freno_ventana']])['c'];
            if ($global >= $i['cuenta_freno_umbral']) {
                usleep(1000 * min($i['cuenta_freno_max_ms'], $i['cuenta_freno_paso_ms'] * ($global - $i['cuenta_freno_umbral'] + 1)));
            }
        }

        $u = $conForma ? Database::fetchOne(
            'SELECT u.id, u.password_hash, u.activo, u.debe_cambiar_password, u.sesion_version, r.activo AS rol_activo
               FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.correo = :c', [':c' => $correo]) : null;

        $correcta = password_verify($password, $u['password_hash'] ?? self::SENUELO);
        $motivo = match (true) {
            !$conForma                      => 'FORMATO',
            $u === null                     => 'NO_EXISTE',
            !$correcta                      => 'PASSWORD',
            (int) $u['activo'] !== 1        => 'INACTIVO',
            (int) $u['rol_activo'] !== 1    => 'ROL_INACTIVO',
            default                         => null,
        };
        if ($motivo !== null) {
            $this->anotar('FALLIDA', $u === null ? null : (int) $u['id'], $hmac, $motivo, $ip, $agente);
            return ['exito' => false, 'mensaje' => self::MENSAJE_FALLA];
        }

        $id = (int) $u['id'];
        if (PoliticaPassword::necesitaRehash($u['password_hash'])) {
            // La huella sube a los parámetros de hoy sin mover actualizado_en (no es una edición de la cuenta).
            // Solo si la huella sigue siendo la que se verificó (un restablecimiento a la vez no se deshace).
            // Si falla, se anota y se entra igual: subir la huella es una mejora, no una condición para entrar.
            try {
                Database::query('UPDATE usuarios SET password_hash = :h, actualizado_en = actualizado_en
                                  WHERE id = :id AND password_hash = :viejo',
                    [':h' => PoliticaPassword::huella($password), ':id' => $id, ':viejo' => $u['password_hash']]);
            } catch (\PDOException $e) {
                error_log('[OFDEV] acceso: no se pudo recalcular la huella · código ' . (int) ($e->errorInfo[1] ?? 0));
            }
        }
        $this->anotar('ENTRADA', $id, $hmac, null, $ip, $agente);
        if ((int) $u['debe_cambiar_password'] === 1) {
            Sesion::entrarParcial($id, (int) $u['sesion_version']);
            return ['exito' => true, 'cambiar' => true];
        }
        Sesion::entrar($id, (int) $u['sesion_version']);
        return ['exito' => true];
    }

    /**
     * El cambio OBLIGATORIO (inicio parcial): la contraseña nueva pasa por la política, y en UNA transacción, con la
     * fila bloqueada y el estado en el WHERE, se guarda su huella, se apaga debe_cambiar_password, sube sesion_version
     * (corta cualquier otra sesión abierta) y se anota PASSWORD_CAMBIADA. Solo entonces se abre la sesión completa.
     * No pide la actual: quien está aquí la acaba de teclear para entrar («Mi cuenta» sí la pide).
     * $versionParcial es la sesion_version que guardó la sesión parcial al entrar; si la cuenta ya
     * tiene otra (la restablecieron, o cambió en otra pestaña), la sesión parcial ya no vale: 'recargar' y quien llama la
     * cierra. Sin esto, un --restablecer no echaba a quien ya tenía una sesión parcial.
     * @return array{exito: bool, mensaje?: string|list<string>, recargar?: bool}
     */
    public function cambiarObligatoria(int $usuarioId, int $versionParcial, #[\SensitiveParameter] string $nueva,
                                       #[\SensitiveParameter] string $confirma, string $ip, string $agente): array
    {
        if (!hash_equals($nueva, $confirma)) {
            return ['exito' => false, 'mensaje' => ['Las dos contraseñas no coinciden.']];
        }
        $u = Database::fetchOne('SELECT nombre, correo, password_hash FROM usuarios WHERE id = :id', [':id' => $usuarioId]);
        if ($u === null) {
            return ['exito' => false, 'mensaje' => [self::MENSAJE_ERROR]];
        }
        $motivos = PoliticaPassword::revisar($nueva, ['correo' => $u['correo'], 'nombre' => $u['nombre'],
                                                      'hash_actual' => $u['password_hash']]);
        if ($motivos !== []) {
            return ['exito' => false, 'mensaje' => $motivos];
        }
        $huella = PoliticaPassword::huella($nueva);
        $ip = self::ip($ip);
        $agente = self::agente($agente, config('acceso.bitacora.agente_max'));
        $r = conReintentos(function () use ($usuarioId, $versionParcial, $huella, $ip, $agente): array {
            $fila = Database::fetchOne('SELECT activo, debe_cambiar_password, sesion_version FROM usuarios
                                         WHERE id = :id FOR UPDATE', [':id' => $usuarioId]);
            if ($fila === null || (int) $fila['activo'] !== 1 || (int) $fila['debe_cambiar_password'] !== 1
                || (int) $fila['sesion_version'] !== $versionParcial) {
                return ['exito' => false, 'recargar' => true, 'mensaje' => self::MENSAJE_PARCIAL_VIEJA];
            }
            $cambio = Database::query('UPDATE usuarios SET password_hash = :h, debe_cambiar_password = 0,
                                              sesion_version = sesion_version + 1
                                        WHERE id = :id AND debe_cambiar_password = 1 AND sesion_version = :v',
                [':h' => $huella, ':id' => $usuarioId, ':v' => $versionParcial]);
            if ($cambio->rowCount() !== 1) {
                return ['exito' => false, 'recargar' => true, 'mensaje' => self::MENSAJE_PARCIAL_VIEJA];
            }
            $this->anotar('PASSWORD_CAMBIADA', $usuarioId, null, null, $ip, $agente);
            return ['exito' => true, 'version' => (int) $fila['sesion_version'] + 1];
        }, self::MENSAJE_ERROR);
        if (!empty($r['incierto'])) {
            // El commit pudo haber pasado. ⛔ No es un error: se dice qué hacer para saberlo.
            return ['exito' => false, 'incierto' => true, 'mensaje' => [self::MENSAJE_CAMBIO_INCIERTO]];
        }
        if (($r['exito'] ?? false) !== true) {
            return ['exito' => false, 'mensaje' => [(string) ($r['mensaje'] ?? self::MENSAJE_ERROR)]]
                + (!empty($r['recargar']) ? ['recargar' => true] : [])
                + (!empty($r['incierto']) ? ['incierto' => true] : []);
        }
        Sesion::entrar($usuarioId, $r['version']);
        return ['exito' => true];
    }

    /**
     * ¿La sesión parcial sigue valiendo? La cuenta activa, todavía obligada a cambiar la contraseña y con la
     * MISMA sesion_version que guardó la sesión parcial. La pantalla de cambio la pregunta antes de pintarse.
     */
    public function parcialVigente(int $usuarioId, int $versionParcial): bool
    {
        $f = Database::fetchOne('SELECT activo, debe_cambiar_password, sesion_version FROM usuarios WHERE id = :id',
            [':id' => $usuarioId]);
        return $f !== null && (int) $f['activo'] === 1 && (int) $f['debe_cambiar_password'] === 1
            && (int) $f['sesion_version'] === $versionParcial;
    }

    /** La SALIDA se anota ANTES de cerrar la sesión: después ya no hay usuario. */
    public function salir(string $ip, string $agente): void
    {
        $u = Sesion::usuario();
        if ($u !== null) {
            try {
                $this->anotar('SALIDA', $u['id'], null, null, self::ip($ip), self::agente($agente, config('acceso.bitacora.agente_max')));
            } catch (\PDOException $e) {
                error_log('[OFDEV] acceso: no se pudo anotar la salida · código ' . (int) ($e->errorInfo[1] ?? 0));
            }
        }
        Sesion::salir();
    }

    /** Cuando Sesion corta una sesión (cuenta inactiva, contraseña cambiada…), queda en la bitácora. */
    public function anotarCorte(int $usuarioId, string $motivo, string $ip, string $agente): void
    {
        try {
            $this->anotar('SESION_CORTADA', $usuarioId, null, $motivo, self::ip($ip), self::agente($agente, config('acceso.bitacora.agente_max')));
        } catch (\PDOException $e) {
            error_log('[OFDEV] acceso: no se pudo anotar el corte · código ' . (int) ($e->errorInfo[1] ?? 0));
        }
    }

    /**
     * El PRIMER Super administrador, desde cron/crear_admin.php (línea de comandos, con terminal). La única página
     * que crea uno es /instalar (instalar(), abajo), y solo mientras no hay NINGÚN usuario. En UNA
     * transacción y con el rol SUPERADMIN bloqueado (FOR UPDATE: dos corridas
     * a la vez se forman en fila), se niega si ya hay un Super administrador ACTIVO; si el único está inactivo, deja
     * crear otro (así se recupera el sistema). La cuenta nace sin obligación de cambiar la contraseña (la eligió quien
     * la teclea) y queda CUENTA_CREADA en la bitácora, con origen CLI.
     * @return array{exito: bool, mensaje?: string|list<string>, id?: int}
     */
    public function crearSuperadmin(string $nombreTecleado, string $correoTecleado,
                                    #[\SensitiveParameter] string $password): array
    {
        return $this->crearPrimeraCuenta($nombreTecleado, $correoTecleado, $password, false, '', '');
    }

    /** ¿Está abierto el instalador? Con INSTALACION_CLAVE en env.php y sin NINGÚN usuario (activo o no). */
    public static function instalacionDisponible(): bool
    {
        $clave = config('instalacion_clave');
        if (!is_string($clave) || $clave === '') {
            return false;
        }
        return (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM usuarios')['c'] ?? 1) === 0;
    }

    /**
     * El instalador web, /instalar (09-oct): la primera cuenta SIN SSH. En este orden:
     *   1. si ya no está disponible (sin clave en env.php, o con un usuario), 'no_disponible' y nada más;
     *   2. con el candado de la red (el mismo del login), el límite: 20 fallos de cualquier tipo o 5 claves de
     *      instalación equivocadas desde esa red en 15 min la bloquean;
     *   3. la clave se compara con hash_equals; equivocada, FALLIDA con motivo INSTALACION en la bitácora;
     *   4. las dos contraseñas iguales, y la cuenta con el MISMO camino que crear_admin.php, que dentro de la
     *      transacción vuelve a exigir «ningún usuario» (dos pestañas a la vez: entra una).
     * @return array{exito: bool, mensaje?: string|list<string>, id?: int, no_disponible?: bool, incierto?: bool}
     */
    public function instalar(#[\SensitiveParameter] string $claveTecleada, string $nombre, string $correo,
                             #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirma,
                             string $ip, string $agente): array
    {
        if (!self::instalacionDisponible()) {
            return ['exito' => false, 'no_disponible' => true];
        }
        $cfg = config('acceso');
        $ip = self::ip($ip);
        $rango = self::rango($ip);
        $agente = self::agente($agente, $cfg['bitacora']['agente_max']);
        $candado = 'ofdev:' . substr(hash('sha256', config('db.nombre') . '|acceso|' . $rango[0]), 0, 40);
        $tomado = Database::fetchOne('SELECT GET_LOCK(:n, 2) AS t', [':n' => $candado])['t'] ?? null;
        if ($tomado === null || (int) $tomado !== 1) {
            return ['exito' => false, 'mensaje' => [self::MENSAJE_ERROR_INSTALAR]];
        }
        try {
            $i = $cfg['intentos'];
            $red = [':ini' => $rango[0], ':fin' => $rango[1]];
            $porIp = (int) Database::fetchOne(
                "SELECT COUNT(*) AS c FROM accesos WHERE ip BETWEEN :ini AND :fin AND evento = 'FALLIDA'
                    AND creado_en > NOW() - INTERVAL :v SECOND", $red + [':v' => $i['ip_ventana']])['c'];
            $porClave = (int) Database::fetchOne(
                "SELECT COUNT(*) AS c FROM accesos WHERE ip BETWEEN :ini AND :fin AND evento = 'FALLIDA'
                    AND motivo = 'INSTALACION' AND creado_en > NOW() - INTERVAL :v SECOND",
                $red + [':v' => $i['cuenta_ip_ventana']])['c'];
            if ($porIp >= $i['ip_fallos'] || $porClave >= $i['cuenta_ip_fallos']) {
                return ['exito' => false, 'mensaje' => [self::MENSAJE_BLOQUEO]];
            }
            // Sin los espacios de los extremos (un espacio al pegar contaba como fallo); la clave
            // es hexadecimal, así que recortar no le quita nada.
            if (!hash_equals((string) config('instalacion_clave'), trim($claveTecleada))) {
                $this->anotar('FALLIDA', null, null, 'INSTALACION', $ip, $agente);
                return ['exito' => false, 'mensaje' => ['La clave de instalación no es correcta.']];
            }
        } finally {
            try {
                Database::query('DO RELEASE_LOCK(:n)', [':n' => $candado]);
            } catch (\Throwable) {
                // el candado muere con la conexión
            }
        }
        if (!hash_equals($password, $confirma)) {
            return ['exito' => false, 'mensaje' => ['Las dos contraseñas no coinciden.']];
        }
        return $this->crearPrimeraCuenta($nombre, $correo, $password, true, $ip, $agente);
    }

    /**
     * El camino compartido de crear_admin.php y del instalador. $instalador: además exige «ningún usuario» dentro de
     * la transacción (con el rol bloqueado) y anota con origen WEB y la IP; si no, origen CLI y sin IP.
     */
    private function crearPrimeraCuenta(string $nombreTecleado, string $correoTecleado,
                                        #[\SensitiveParameter] string $password, bool $instalador, string $ip,
                                        string $agente): array
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($nombreTecleado, 'UTF-8')));
        $correo = PoliticaPassword::normalizar($correoTecleado);
        if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 100
            || preg_match('/[\x00-\x1F\x7F-\x9F]/u', $nombre) === 1) {
            return ['exito' => false, 'mensaje' => ['El nombre va de 1 a 100 caracteres.']];
        }
        if (strlen($correo) > 254 || preg_match(self::FORMA_CORREO, $correo) !== 1) {
            return ['exito' => false, 'mensaje' => ['El correo no tiene la forma de un correo.']];
        }
        $motivos = PoliticaPassword::revisar($password, ['correo' => $correo, 'nombre' => $nombre]);
        if ($motivos !== []) {
            return ['exito' => false, 'mensaje' => $motivos];
        }
        $huella = PoliticaPassword::huella($password);
        return conReintentos(function () use ($nombre, $correo, $huella, $instalador, $ip, $agente): array {
            $rol = Database::fetchOne("SELECT id FROM roles WHERE clave = 'SUPERADMIN' FOR UPDATE");
            if ($rol === null) {
                throw new \App\Core\ErrorNegocio('Falta el rol SUPERADMIN: primero se corre el SQL 001.');
            }
            if ($instalador && (int) Database::fetchOne('SELECT COUNT(*) AS c FROM usuarios')['c'] > 0) {
                throw new \App\Core\ErrorNegocio('El sistema ya está instalado: entra con tu cuenta.');
            }
            $activo = Database::fetchOne('SELECT 1 AS s FROM usuarios WHERE rol_id = :r AND activo = 1 LIMIT 1',
                [':r' => (int) $rol['id']]);
            if ($activo !== null) {
                throw new \App\Core\ErrorNegocio('Ya hay un Super administrador activo: este script no crea otro.');
            }
            if (Database::fetchOne('SELECT 1 AS s FROM usuarios WHERE correo = :c', [':c' => $correo]) !== null) {
                throw new \App\Core\ErrorNegocio('Ya existe una cuenta con ese correo.');
            }
            $id = Database::insert('usuarios', ['rol_id' => (int) $rol['id'], 'nombre' => $nombre, 'correo' => $correo,
                'password_hash' => $huella, 'debe_cambiar_password' => 0]);
            $instalador ? $this->anotar('CUENTA_CREADA', $id, null, null, $ip, $agente) : $this->anotarCli('CUENTA_CREADA', $id);
            return ['exito' => true, 'id' => $id];
        }, 'No se pudo crear la cuenta.');
    }

    /**
     * Restablecer la contraseña de una cuenta desde cron/crear_admin.php --restablecer. La temporal la GENERA el
     * sistema (decisión de Omar, 08-oct): 20 caracteres al azar en 4 grupos, sin letras que se confundan, que se
     * muestran una sola vez. En UNA transacción, con la fila bloqueada: la huella nueva, debe_cambiar_password = 1 (al
     * entrar la cambia) y sesion_version + 1 (se corta toda sesión abierta), y PASSWORD_RESTABLECIDA en la bitácora.
     * ⬜ Que la contraseña temporal caduque llega con el módulo de Usuarios.
     * @return array{exito: bool, mensaje?: string|list<string>, temporal?: string, activo?: bool}
     */
    public function restablecerCli(string $correoTecleado): array
    {
        $correo = PoliticaPassword::normalizar($correoTecleado);
        if (strlen($correo) > 254 || preg_match(self::FORMA_CORREO, $correo) !== 1) {
            return ['exito' => false, 'mensaje' => ['El correo no tiene la forma de un correo.']];
        }
        $temporal = self::temporal();
        $huella = PoliticaPassword::huella($temporal);
        $r = conReintentos(function () use ($correo, $huella): array {
            $u = Database::fetchOne('SELECT id, activo FROM usuarios WHERE correo = :c FOR UPDATE', [':c' => $correo]);
            if ($u === null) {
                throw new \App\Core\ErrorNegocio('No hay una cuenta con ese correo.');
            }
            Database::query('UPDATE usuarios SET password_hash = :h, debe_cambiar_password = 1,
                                     sesion_version = sesion_version + 1 WHERE id = :id',
                [':h' => $huella, ':id' => (int) $u['id']]);
            $this->anotarCli('PASSWORD_RESTABLECIDA', (int) $u['id']);
            return ['exito' => true, 'activo' => (int) $u['activo'] === 1];
        }, 'No se pudo restablecer la contraseña.');
        return ($r['exito'] ?? false) === true ? $r + ['temporal' => $temporal] : $r;
    }

    /** 20 caracteres de un alfabeto sin 0/O, 1/l/I (unos 113 bits), en grupos de 5 para dictarla. */
    private static function temporal(): string
    {
        $abc = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 20; $i++) {
            $s .= $abc[random_int(0, strlen($abc) - 1)] . ($i % 5 === 4 && $i < 19 ? '-' : '');
        }
        return $s;
    }

    /** Lo que se hace por la línea de comandos: origen CLI, sin IP ni navegador (chk_accesos_ip_origen lo permite). */
    private function anotarCli(string $evento, int $usuarioId): void
    {
        self::insertarBitacora(['evento' => $evento, 'origen' => 'CLI', 'usuario_id' => $usuarioId]);
    }

    private function anotar(string $evento, ?int $usuarioId, ?string $hmac, ?string $motivo, string $ip, string $agente): void
    {
        self::insertarBitacora([
            'evento' => $evento, 'origen' => 'WEB', 'usuario_id' => $usuarioId, 'correo_hmac' => $hmac,
            'motivo' => $motivo, 'ip' => $ip, 'agente' => $agente === '' ? null : $agente,
        ]);
    }

    /**
     * Una fila de la bitácora. Fuera de una transacción, un deadlock o una espera (1213, 1205,
     * 40001) se reintenta dos veces, por CÓDIGO, como en conReintentos; antes se volvía «No se pudo entrar». Dentro de
     * una, se inserta y nada más: el reintento es de la transacción entera (Transaccion), y ⛔ no se anidan.
     */
    private static function insertarBitacora(array $fila): void
    {
        for ($quedan = 2; ; $quedan--) {
            try {
                Database::insert('accesos', $fila);
                return;
            } catch (\PDOException $e) {
                $espera = in_array(\App\Core\Transaccion::codigo($e), [1213, 1205], true) || ($e->errorInfo[0] ?? '') === '40001';
                if (Database::enTransaccion() || !$espera || $quedan === 0) {
                    throw $e;
                }
                usleep(random_int(30_000, 120_000));
            }
        }
    }

    /** REMOTE_ADDR tal cual (medido el 08-oct: es la IP real y no cambia con cabeceras falsas). Si no es una IP,
     *  se usa 0.0.0.0, que se ve en la bitácora y cuenta para el límite. */
    private static function ip(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    /**
     * La RED de una IP, para contar los intentos. IPv4 (y la IPv4 mapeada, ::ffff:a.b.c.d): ella sola.
     * IPv6: su /64, que es lo que tiene cualquier línea; sin esto, rotar direcciones esquivaba el límite.
     * @return array{0: string, 1: string} la primera y la última dirección, en texto (INET6 las compara como tales)
     */
    private static function rango(string $ip): array
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return ['0.0.0.0', '0.0.0.0'];
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) {
            $bin = substr($bin, 12);                       // IPv4 mapeada
        }
        if (strlen($bin) === 4) {
            $v4 = (string) inet_ntop($bin);
            return [$v4, $v4];
        }
        $red = substr($bin, 0, 8);
        return [(string) inet_ntop($red . str_repeat("\0", 8)), (string) inet_ntop($red . str_repeat("\xFF", 8))];
    }

    /** Sin caracteres de control (romperían una línea del log) y recortado al largo de la columna. También los
     *  C1 (U+0080 a U+009F): `[[:cntrl:]]` del CHECK los casa (medido el 08-oct) y sin quitarlos esa persona no entraba. */
    private static function agente(string $agente, int $max): string
    {
        $limpio = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', mb_scrub($agente, 'UTF-8')) ?? '';
        return mb_substr(trim($limpio), 0, $max, 'UTF-8');
    }
}
