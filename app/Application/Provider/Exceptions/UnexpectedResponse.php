<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** A response we could not make sense of (missing fields, unknown status, malformed rate, or an unmapped error). */
final class UnexpectedResponse extends ProviderException
{
}
