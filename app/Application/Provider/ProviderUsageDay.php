<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Money;

/** What Pixelforge billed on one UTC day (the day each generation completed). */
final readonly class ProviderUsageDay
{
    public function __construct(
        /** YYYY-MM-DD, UTC. */
        public string $date,
        public int $generations,
        public Money $cost,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->generations === 0 && $this->cost->isZero();
    }
}
