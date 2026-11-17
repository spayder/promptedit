<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\GenerationReportReader;
use App\Application\Report\UserDayTotals;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Money;
use Illuminate\Support\Facades\DB;

final class EloquentGenerationReportReader implements GenerationReportReader
{
    public function completedPerDayAndUser(string $startDate, string $endDate): array
    {
        // completed_at is stored with its time zone, so AT TIME ZONE 'UTC' gives the UTC wall clock
        // whatever the connection's time zone is.
        $day = "(generations.completed_at AT TIME ZONE 'UTC')::date";

        return DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereRaw("{$day} between ? and ?", [$startDate, $endDate])
            ->selectRaw("{$day} as day, generations.user_id, users.name as user_name, count(*) as generations, sum(generations.charged_nanos) as charged, sum(generations.provider_cost_nanos) as provider_cost")
            ->groupByRaw("{$day}, generations.user_id, users.name")
            ->orderByRaw("{$day} desc")
            ->orderBy('users.name')
            ->orderBy('generations.user_id')
            ->get()
            ->map(fn ($row) => new UserDayTotals(
                date: (string) $row->day,
                userId: (int) $row->user_id,
                userName: $row->user_name,
                generations: (int) $row->generations,
                charged: Money::nanos((int) $row->charged),
                providerCost: Money::nanos((int) $row->provider_cost),
            ))
            ->all();
    }
}
