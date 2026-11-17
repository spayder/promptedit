<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** Over the requests-per-minute limit (429 rate_limit_exceeded). */
final class RateLimited extends RetryableProviderException
{
}
