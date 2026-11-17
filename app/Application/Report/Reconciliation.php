<?php

declare(strict_types=1);

namespace App\Application\Report;

/** Whether our records agree with Pixelforge's usage report. */
enum Reconciliation: string
{
    /** Same number of generations and exactly the same provider cost. */
    case Match = 'match';

    case Mismatch = 'mismatch';

    /** Pixelforge's report could not be fetched, so nothing can be said. */
    case Unavailable = 'unavailable';
}
