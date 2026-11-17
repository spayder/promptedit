<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Application\Ports\GenerationReportReader;
use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\ProviderUsage;
use App\Application\Provider\ProviderUsageDay;
use App\Domain\Money;
use Illuminate\Support\Facades\Log;

/**
 * Per UTC day and user: what was charged, what Pixelforge charged us, and the
 * profit. Each day is checked against Pixelforge's own usage report (the source
 * of truth for provider cost), so a recording mistake shows up as a mismatch
 * instead of staying hidden.
 */
final class BuildProfitReport
{
    public function __construct(
        private readonly GenerationReportReader $reader,
        private readonly ImageProvider $provider,
    ) {
    }

    /**
     * @param  string  $startDate  YYYY-MM-DD, UTC, inclusive
     * @param  string  $endDate  YYYY-MM-DD, UTC, inclusive; at most 92 days after the start
     */
    public function handle(string $startDate, string $endDate): ProfitReport
    {
        $ours = [];
        foreach ($this->reader->completedPerDayAndUser($startDate, $endDate) as $row) {
            $ours[$row->date][] = $row;
        }

        $usage = $this->usage($startDate, $endDate);

        $dates = array_keys($ours);
        foreach ($usage?->days ?? [] as $day) {
            if (! $day->isEmpty()) {
                $dates[] = $day->date; // Pixelforge billed something we have no record of
            }
        }
        $dates = array_unique($dates);
        rsort($dates);

        $days = array_map(fn (string $date) => $this->day($date, $ours[$date] ?? [], $usage), $dates);

        $generations = array_sum(array_map(fn (ReportDay $day) => $day->generations, $days));
        $charged = $this->sum(array_map(fn (ReportDay $day) => $day->charged, $days));
        $providerCost = $this->sum(array_map(fn (ReportDay $day) => $day->providerCost, $days));

        return new ProfitReport(
            startDate: $startDate,
            endDate: $endDate,
            days: $days,
            generations: $generations,
            charged: $charged,
            providerCost: $providerCost,
            provider: $usage,
            reconciliation: $usage === null
                ? Reconciliation::Unavailable
                : ($this->agrees($generations, $providerCost, $usage->totalGenerations, $usage->totalCost) ? Reconciliation::Match : Reconciliation::Mismatch),
        );
    }

    /** @param  list<UserDayTotals>  $rows */
    private function day(string $date, array $rows, ?ProviderUsage $usage): ReportDay
    {
        $generations = array_sum(array_map(fn (UserDayTotals $row) => $row->generations, $rows));
        $charged = $this->sum(array_map(fn (UserDayTotals $row) => $row->charged, $rows));
        $providerCost = $this->sum(array_map(fn (UserDayTotals $row) => $row->providerCost, $rows));

        $theirs = $usage === null ? null : ($usage->forDate($date) ?? new ProviderUsageDay($date, 0, Money::zero()));

        return new ReportDay(
            date: $date,
            rows: $rows,
            generations: $generations,
            charged: $charged,
            providerCost: $providerCost,
            provider: $theirs,
            reconciliation: $theirs === null
                ? Reconciliation::Unavailable
                : ($this->agrees($generations, $providerCost, $theirs->generations, $theirs->cost) ? Reconciliation::Match : Reconciliation::Mismatch),
        );
    }

    private function agrees(int $ourGenerations, Money $ourCost, int $theirGenerations, Money $theirCost): bool
    {
        return $ourGenerations === $theirGenerations && $ourCost->equals($theirCost);
    }

    /** @param  list<Money>  $amounts */
    private function sum(array $amounts): Money
    {
        return array_reduce($amounts, fn (Money $total, Money $amount) => $total->add($amount), Money::zero());
    }

    private function usage(string $startDate, string $endDate): ?ProviderUsage
    {
        try {
            return $this->provider->usage($startDate, $endDate);
        } catch (ProviderException $e) {
            Log::warning('Could not fetch the Pixelforge usage report.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
