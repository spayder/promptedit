<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money;

/** What a finished generation cost Pixelforge's customer (us) and what we charge the user. */
final readonly class Bill
{
    public function __construct(
        public Money $providerCost,
        public Money $charge,
    ) {
    }
}
