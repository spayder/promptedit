<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Domain\Generation\TokenUsage;
use InvalidArgumentException;

/**
 * Estimates the provider cost of a generation before it is submitted, as a
 * min..max range. Only two things are uncertain up front:
 *
 * - reasoning tokens: somewhere in the quality's published range,
 * - input text tokens: ceil(chars / N), actual within +/- the variance.
 *
 * Output and reference image tokens are exact. The text interval is widened
 * outward (floor for min, ceil for max) so the true cost never falls outside.
 */
final readonly class EstimateCalculator
{
    public function __construct(
        private TokenSchedule $schedule,
        private CostCalculator $costs,
    ) {
    }

    public function estimate(
        string $prompt,
        Quality $quality,
        ImageSize $size,
        int $referenceImages,
        Rates $rates,
    ): CostEstimate {
        if ($referenceImages < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative.');
        }

        $textTokens = intdiv(mb_strlen($prompt, 'UTF-8') + $this->schedule->charactersPerTextToken - 1, $this->schedule->charactersPerTextToken);
        $variance = $this->schedule->textTokenVariancePercent;
        $minText = intdiv($textTokens * (100 - $variance), 100);
        $maxText = intdiv($textTokens * (100 + $variance) + 99, 100);

        $imageTokens = $referenceImages * $this->schedule->tokensPerReferenceImage;
        $outputTokens = $this->schedule->outputTokens($quality, $size);
        $reasoning = $this->schedule->reasoningRange($quality);

        return new CostEstimate(
            min: $this->costs->providerCost(new TokenUsage($minText, $imageTokens, $outputTokens, $reasoning['min']), $rates),
            max: $this->costs->providerCost(new TokenUsage($maxText, $imageTokens, $outputTokens, $reasoning['max']), $rates),
        );
    }
}
