<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Application\Provider\ProviderUsage;
use App\Application\Provider\ProviderUsageDay;
use App\Domain\Money;

/** Pixelforge's GET /v1/usage as the docs show it, for tests. */
final class PixelforgeUsage
{
    /**
     * @param  array<string, array{0: int, 1: string}>  $days  date => [generations, cost_usd]
     * @return array<string, mixed>
     */
    public static function response(array $days, string $start = '2026-11-01', string $end = '2026-11-02'): array
    {
        $data = [];
        $count = 0;
        $nanos = 0;

        foreach ($days as $date => [$generations, $cost]) {
            $data[] = [
                'date' => $date,
                'generations' => $generations,
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => $cost,
            ];
            $count += $generations;
            $nanos += Money::fromDecimal($cost)->toNanos();
        }

        return [
            'object' => 'usage',
            'start_date' => $start,
            'end_date' => $end,
            'data' => $data,
            'total' => [
                'generations' => $count,
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => Money::nanos($nanos)->toDecimal(),
            ],
        ];
    }

    /** @param  array<string, array{0: int, 1: string}>  $days  date => [generations, cost_usd] */
    public static function report(array $days): ProviderUsage
    {
        $list = [];
        $count = 0;
        $total = Money::zero();

        foreach ($days as $date => [$generations, $cost]) {
            $list[] = new ProviderUsageDay($date, $generations, Money::fromDecimal($cost));
            $count += $generations;
            $total = $total->add(Money::fromDecimal($cost));
        }

        return new ProviderUsage($list, $count, $total);
    }
}
