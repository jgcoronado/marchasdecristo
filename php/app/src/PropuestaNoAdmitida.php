<?php

declare(strict_types=1);

namespace App;

/**
 * El almacén rechaza una propuesta nueva antes de escribirla (cupo del autor
 * agotado, tamaño excesivo). El mensaje es el código que el panel enseña en
 * ?err=, igual que los de AdminRepo.
 */
final class PropuestaNoAdmitida extends \RuntimeException
{
}
