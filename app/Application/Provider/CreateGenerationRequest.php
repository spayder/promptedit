<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;

final readonly class CreateGenerationRequest
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata  echoed back by the provider on the generation and in every webhook
     */
    public function __construct(
        public string $prompt,
        public Quality $quality,
        public ImageSize $size,
        public array $referenceImages = [],
        public array $metadata = [],
    ) {
    }
}
