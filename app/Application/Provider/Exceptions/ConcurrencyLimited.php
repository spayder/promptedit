<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** Too many generations in flight at once (429 concurrency_limit_exceeded). */
final class ConcurrencyLimited extends RetryableProviderException
{
}
