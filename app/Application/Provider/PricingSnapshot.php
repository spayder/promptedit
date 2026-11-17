<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Pricing\Rates;
use App\Domain\Pricing\TokenSchedule;

/** The provider's published prices and token tables (GET /v1/pricing). */
final readonly class PricingSnapshot
{
    public function __construct(
        public Rates $rates,
        public TokenSchedule $schedule,
    ) {
    }
}
