<?php
declare(strict_types=1);

namespace App\Core;

/**
 * El ÚNICO error de negocio del sistema: su mensaje se le enseña al usuario tal cual (sin ids, SQL ni tablas).
 * Se lanza dentro de la transacción para abortarla, y conReintentos() ⛔ no lo reintenta.
 * Es marcadora y opt-in: PDOException también es RuntimeException, así que nadie clasifica por RuntimeException.
 */
final class ErrorNegocio extends \RuntimeException
{
}
