<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

use RuntimeException;
use Throwable;

/** Anything that goes wrong talking to the image provider. */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
