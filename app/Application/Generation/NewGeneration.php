<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Domain\Pricing\CostEstimate;

/**
 * Everything needed to record a generation before Pixelforge has seen it.
 * The estimate is the user-facing range (markup included) that was shown.
 */
final readonly class NewGeneration
{
    /** @param  list<string>  $referenceImages */
    public function __construct(
        public string $id,
        public int $userId,
        public string $prompt,
        public Quality $quality,
        public ImageSize $size,
        public array $referenceImages,
        public CostEstimate $estimatedCharge,
    ) {
    }
}
