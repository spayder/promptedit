<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** Timeout, connection failure or an unexpected 5xx: the outcome of the call is unknown, so retry with the same idempotency key. */
final class ProviderUnavailable extends RetryableProviderException
{
}
