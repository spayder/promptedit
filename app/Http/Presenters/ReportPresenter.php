<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Provider\ProviderUsageDay;
use App\Application\Report\ProfitReport;
use App\Application\Report\ReportDay;
use App\Application\Report\UserDayTotals;
use App\Domain\Money;

/**
 * The profit report for the page. Money is shown exactly (nine decimals, as
 * Pixelforge reports it) because the point of this page is to reconcile.
 */
final class ReportPresenter
{
    /** @return array<string, mixed> */
    public static function present(ProfitReport $report, int $viewerId): array
    {
        return [
            'generations' => $report->generations,
            'charged' => self::dollars($report->charged),
            'providerCost' => self::dollars($report->providerCost),
            'profit' => self::dollars($report->profit()),
            'reconciliation' => $report->reconciliation->value,
            'provider' => $report->provider === null ? null : [
                'generations' => $report->provider->totalGenerations,
                'cost' => self::dollars($report->provider->totalCost),
                'difference' => self::dollars($report->provider->totalCost->subtract($report->providerCost)),
            ],
            'days' => array_map(fn (ReportDay $day) => self::day($day, $viewerId), $report->days),
        ];
    }

    /** @return array<string, mixed> */
    private static function day(ReportDay $day, int $viewerId): array
    {
        return [
            'date' => $day->date,
            'generations' => $day->generations,
            'charged' => self::dollars($day->charged),
            'providerCost' => self::dollars($day->providerCost),
            'profit' => self::dollars($day->profit()),
            'reconciliation' => $day->reconciliation->value,
            'provider' => self::provider($day->provider, $day->providerCost),
            'rows' => array_map(fn (UserDayTotals $row) => [
                'userId' => $row->userId,
                'name' => $row->userName,
                'isYou' => $row->userId === $viewerId,
                'generations' => $row->generations,
                'charged' => self::dollars($row->charged),
                'providerCost' => self::dollars($row->providerCost),
                'profit' => self::dollars($row->profit()),
            ], $day->rows),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function provider(?ProviderUsageDay $theirs, Money $ourCost): ?array
    {
        if ($theirs === null) {
            return null;
        }

        return [
            'generations' => $theirs->generations,
            'cost' => self::dollars($theirs->cost),
            'difference' => self::dollars($theirs->cost->subtract($ourCost)),
        ];
    }

    /** "$0.017746875", or "-$0.000000001" for a negative amount. */
    public static function dollars(Money $money): string
    {
        $decimal = $money->toDecimal();

        return str_starts_with($decimal, '-') ? '-$'.substr($decimal, 1) : '$'.$decimal;
    }
}
