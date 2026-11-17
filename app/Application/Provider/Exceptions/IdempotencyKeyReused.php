<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** The idempotency key was already used with different parameters (409). */
final class IdempotencyKeyReused extends ProviderException
{
}
