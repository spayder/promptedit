<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Money;

/**
 * How amounts are shown to people. Display is rounded to 4 decimals (a
 * hundredth of a cent) with integer maths only, and the exact amount is always
 * available next to it. Rounding direction is chosen so a displayed range can
 * never exclude the true value: minimums round down, maximums round up.
 */
final class MoneyPresenter
{
    private const int UNIT = 100_000; // nanodollars in 0.0001 USD

    private const int PER_DOLLAR = 10_000; // units in 1 USD

    public static function nearest(Money $money): string
    {
        return self::format($money, intdiv($money->toNanos() + intdiv(self::UNIT, 2), self::UNIT));
    }

    public static function roundedDown(Money $money): string
    {
        return self::format($money, intdiv($money->toNanos(), self::UNIT));
    }

    public static function roundedUp(Money $money): string
    {
        return self::format($money, intdiv($money->toNanos() + self::UNIT - 1, self::UNIT));
    }

    /** The exact amount, without trailing zeros but never fewer than 2 decimals: "0.017746875", "1.50". */
    public static function exact(Money $money): string
    {
        [$whole, $fraction] = explode('.', $money->toDecimal());

        return $whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }

    private static function format(Money $money, int $units): string
    {
        if ($money->toNanos() < 0) {
            throw new \InvalidArgumentException('Negative amounts are not displayed.');
        }

        return '$'.intdiv($units, self::PER_DOLLAR).'.'.str_pad((string) ($units % self::PER_DOLLAR), 4, '0', STR_PAD_LEFT);
    }
}
