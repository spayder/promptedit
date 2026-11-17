<?php

declare(strict_types=1);

namespace App\Application\Generation;

final readonly class ReconcileResult
{
    public function __construct(
        /** Generations we asked Pixelforge about. */
        public int $checked,
        /** Of those, how many had moved on and were brought up to date. */
        public int $updated,
        /** Pending generations whose submission was queued again. */
        public int $resubmitted,
        /** Lookups that failed for a reason that will not fix itself. */
        public int $failed,
    ) {
    }
}
