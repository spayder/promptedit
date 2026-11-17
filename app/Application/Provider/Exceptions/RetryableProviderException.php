<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

use Throwable;

/**
 * A failure that is expected to clear up by itself. Nothing was created or
 * billed, so trying again (with the same idempotency key) is safe.
 */
abstract class  RetryableProviderException extends ProviderException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        ?string $providerCode = null,
        ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $providerCode, $httpStatus, $previous);
    }
}
