<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** Missing, malformed or revoked API key (401). Retrying will not help. */
final class AuthenticationFailed extends ProviderException
{
}
