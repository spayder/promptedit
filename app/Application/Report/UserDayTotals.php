<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Domain\Money;

/** What one user's succeeded generations that completed on one UTC day add up to. */
final readonly class UserDayTotals
{
    public function __construct(
        /** YYYY-MM-DD, UTC. */
        public string $date,
        public int $userId,
        public string $userName,
        public int $generations,
        /** What the user was charged. */
        public Money $charged,
        /** What Pixelforge charged us. */
        public Money $providerCost,
    ) {
    }

    public function profit(): Money
    {
        return $this->charged->subtract($this->providerCost);
    }
}
