<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Domain\Generation\GenerationStatus;

/** A generation that has been unfinished for longer than expected, and what we need to chase it. */
final readonly class OverdueGeneration
{
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        /** Null while still `pending`: Pixelforge never confirmed it. */
        public ?string $providerId,
    ) {
    }
}
