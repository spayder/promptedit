<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Application\Provider\ProviderUsage;
use App\Domain\Money;

final readonly class ProfitReport
{
    /** @param  list<ReportDay>  $days  newest first; only days with activity on either side */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public int $generations,
        public Money $charged,
        public Money $providerCost,
        /** Pixelforge's totals for the range; null when its report was unavailable. */
        public ?ProviderUsage $provider,
        public Reconciliation $reconciliation,
    ) {
    }

    public function profit(): Money
    {
        return $this->charged->subtract($this->providerCost);
    }
}
