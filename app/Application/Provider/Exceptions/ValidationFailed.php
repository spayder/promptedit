<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

use Throwable;

/** The provider rejected a parameter (422 validation_failed). Nothing was created. */
final class ValidationFailed extends ProviderException
{
    /** @param  array<string, list<string>>  $errors  parameter => messages */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        ?string $providerCode = null,
        ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $providerCode, $httpStatus, $previous);
    }
}
