<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Application\Provider\ProviderUsageDay;
use App\Domain\Money;

/** One UTC day of the report: who was charged what, and how it compares with Pixelforge's figure. */
final readonly class ReportDay
{
    /** @param  list<UserDayTotals>  $rows */
    public function __construct(
        public string $date,
        public array $rows,
        public int $generations,
        public Money $charged,
        public Money $providerCost,
        /** Pixelforge's figure for the day; null when its report was unavailable. */
        public ?ProviderUsageDay $provider,
        public Reconciliation $reconciliation,
    ) {
    }

    public function profit(): Money
    {
        return $this->charged->subtract($this->providerCost);
    }
}
