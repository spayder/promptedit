<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** No such generation, or it belongs to another account (404). */
final class ProviderNotFound extends ProviderException
{
}
