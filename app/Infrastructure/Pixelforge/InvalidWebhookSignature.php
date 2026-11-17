<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use RuntimeException;

/** The request did not come from Pixelforge, or is too old to trust. */
final class InvalidWebhookSignature extends RuntimeException
{
}
