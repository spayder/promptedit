<?php

declare(strict_types=1);

namespace App\Application\Provider\Exceptions;

/** The daily generation quota is used up (429 daily_limit_exceeded). Retrying soon will not help. */
final class DailyLimitExceeded extends ProviderException
{
}
