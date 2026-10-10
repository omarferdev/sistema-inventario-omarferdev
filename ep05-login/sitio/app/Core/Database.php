<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * La única conexión a MariaDB. Perezosa: conecta al primer uso.
 *
 * En cada conexión fija lo que el servidor no trae como lo necesita la app (medido el 07-oct): la zona '-06:00' (no
 * tiene zonas por nombre), el sql_mode estricto (el global no lo es), READ COMMITTED y la marca @ofdev_origen = 1, que
 * deja ver qué cambios se hicieron fuera de la app. Las excepciones de sentencia NO se capturan aquí: llegan al Service
 * con su errorInfo intacto (conReintentos las clasifica). Solo la de conexión termina la petición.
 */
final class Database
{
    private static ?array $config = null;
    private static ?PDO $pdo = null;

    public static function configurar(array $db): void
    {
        self::$config = $db;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (self::$config === null) {
            throw new \LogicException('Database::configurar() no se llamó');
        }
        $c = self::$config;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['puerto'], $c['nombre'], $c['charset']);
        try {
            $pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec('SET time_zone = ' . $pdo->quote($c['zona']));
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($c['sql_mode']));
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            if (PHP_SAPI !== 'cli') {
                $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) ($c['espera_web'] ?? 10));   // 10 s en la web: config/app.php dice por qué
            }
            $pdo->exec('SET @ofdev_origen = 1');
        } catch (\PDOException $e) {
            // Al log, el código y nada más: el mensaje de PDO puede traer el usuario o el host.
            error_log('[OFDEV] no se pudo conectar a la base · código ' . (string) ($e->errorInfo[1] ?? $e->getCode()));
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "No se pudo conectar a la base.\n");
                exit(2);
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
            }
            exit('Servicio no disponible.');
        }
        return self::$pdo = $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $fila = self::query($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Inserta con parámetros con nombre y devuelve el id. Los nombres de tabla y columna salen del código, nunca
     *  de la petición: aun así se validan, para que un error de programación no se vuelva una inyección. */
    public static function insert(string $tabla, array $datos): int
    {
        self::identificador($tabla);
        $cols = array_keys($datos);
        array_walk($cols, [self::class, 'identificador']);
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $tabla, implode('`, `', $cols),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)));
        self::query($sql, array_combine(array_map(static fn (string $c): string => ':' . $c, $cols), array_values($datos)));
        return (int) self::pdo()->lastInsertId();
    }

    /** Devuelve las filas CAMBIADAS, no las encontradas: un 0 se discrimina releyendo la fila. */
    public static function update(string $tabla, array $datos, string $where, array $params = []): int
    {
        self::identificador($tabla);
        $sets = [];
        $valores = [];
        foreach ($datos as $col => $valor) {
            self::identificador($col);
            $sets[] = sprintf('`%s` = :set_%s', $col, $col);
            $valores[':set_' . $col] = $valor;
        }
        if ($sets === [] || trim($where) === '') {
            throw new \InvalidArgumentException('update() sin columnas o sin WHERE');
        }
        return self::query(sprintf('UPDATE `%s` SET %s WHERE %s', $tabla, implode(', ', $sets), $where),
            $valores + $params)->rowCount();
    }

    public static function iniciar(): void
    {
        if (self::pdo()->inTransaction()) {
            throw new \LogicException('Ya hay una transacción abierta: no se anidan');
        }
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        self::pdo()->commit();
    }

    /** Puede lanzar si la conexión murió: conReintentos lo envuelve en su propio try. */
    public static function revertir(): void
    {
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public static function enTransaccion(): bool
    {
        return self::$pdo !== null && self::$pdo->inTransaction();
    }

    /** Tras perder la conexión (un resultado incierto, un script largo), la siguiente llamada vuelve a conectar. */
    public static function olvidarConexion(): void
    {
        self::$pdo = null;
    }

    private static function identificador(string $nombre): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $nombre) !== 1) {
            throw new \InvalidArgumentException('Identificador no permitido');
        }
    }
}
