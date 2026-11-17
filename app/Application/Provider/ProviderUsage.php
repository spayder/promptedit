<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Money;

/** Pixelforge's usage report (GET /v1/usage) for the API key we call it with. */
final readonly class ProviderUsage
{
    /** @param  list<ProviderUsageDay>  $days  every day in the range, including days with no usage */
    public function __construct(
        public array $days,
        public int $totalGenerations,
        public Money $totalCost,
    ) {
    }

    public function forDate(string $date): ?ProviderUsageDay
    {
        foreach ($this->days as $day) {
            if ($day->date === $date) {
                return $day;
            }
        }

        return null;
    }
}
