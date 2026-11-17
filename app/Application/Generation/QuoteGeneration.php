<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\PricingSource;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Domain\Pricing\CostCalculator;
use App\Domain\Pricing\CostEstimate;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\MarkupPolicy;

/**
 * What a generation will cost the user, as a min..max range with our markup
 * already included. The generate page shows this, and submitting stores the
 * same range, so the number on screen and the number recorded cannot differ.
 */
final class QuoteGeneration
{
    public function __construct(
        private readonly PricingSource $pricing,
        private readonly CostCalculator $costs,
        private readonly MarkupPolicy $markup,
    ) {
    }

    public function quote(string $prompt, Quality $quality, ImageSize $size, int $referenceImages): CostEstimate
    {
        $snapshot = $this->pricing->current();

        $providerCost = (new EstimateCalculator($snapshot->schedule, $this->costs))
            ->estimate($prompt, $quality, $size, $referenceImages, $snapshot->rates);

        return new CostEstimate(
            min: $this->markup->apply($providerCost->min),
            max: $this->markup->apply($providerCost->max),
        );
    }
}
