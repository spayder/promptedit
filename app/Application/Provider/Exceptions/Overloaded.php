<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** The provider is overloaded (503 overloaded). Nothing was created. */
final class Overloaded extends RetryableProviderException
{
}
