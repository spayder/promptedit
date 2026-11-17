<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Report\UserDayTotals;

/** Reads finished generations in the shape the report needs. Never writes. */
interface GenerationReportReader
{
    /**
     * Succeeded generations only (a failure is free and has no cost), grouped by
     * the UTC day each one completed and by user. This is the day Pixelforge bills
     * it on, so the totals can be compared with its usage report.
     *
     * @param  string  $startDate  YYYY-MM-DD, UTC, inclusive
     * @param  string  $endDate  YYYY-MM-DD, UTC, inclusive
     * @return list<UserDayTotals> newest day first, then by user name
     */
    public function completedPerDayAndUser(string $startDate, string $endDate): array;
}
