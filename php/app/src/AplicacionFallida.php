<?php

declare(strict_types=1);

namespace App;

/**
 * Corta la transacción de una propuesta cuando un paso devuelve un código de
 * error (los repositorios no lanzan: devuelven ['code' => …]). Lleva ese
 * resultado para devolverlo tal cual tras el rollback.
 */
final class AplicacionFallida extends \RuntimeException
{
    /** @param array<string,mixed> $resultado */
    public function __construct(public readonly array $resultado)
    {
        parent::__construct((string) ($resultado['code'] ?? 'ERROR'));
    }
}
