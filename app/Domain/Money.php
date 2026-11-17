<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use OverflowException;

/**
 * An exact amount of US dollars, stored as an integer number of nanodollars
 * (1 USD = 1_000_000_000 nanodollars).
 *
 * Pixelforge reports costs with 9 decimal places, so nanodollars represent
 * every provider amount exactly. No floats are used anywhere: arithmetic that
 * would overflow a 64-bit integer throws instead of silently turning into a
 * float.
 */
final readonly class Money
{
    private const int NANOS_PER_DOLLAR = 1_000_000_000;

    private function __construct(private int $nanos)
    {
    }

    public static function nanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a decimal dollar string such as "0.046125000" or "5.00".
     * At most 9 decimal places are accepted so nothing is ever rounded.
     */
    public static function fromDecimal(string $decimal): self
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', $decimal, $matches)) {
            throw new InvalidArgumentException("Invalid money amount \"{$decimal}\": expected up to 9 decimal places.");
        }

        $negative = $matches[1] === '-';
        $whole = (int) $matches[2];
        $fraction = (int) str_pad($matches[3] ?? '', 9, '0');

        $nanos = self::checked($whole * self::NANOS_PER_DOLLAR);
        $nanos = self::checked($nanos + $fraction);

        return new self($negative ? -$nanos : $nanos);
    }

    public function toNanos(): int
    {
        return $this->nanos;
    }

    /** Exact decimal dollars with 9 decimal places, e.g. "0.046125000". */
    public function toDecimal(): string
    {
        $abs = abs($this->nanos);

        return sprintf(
            '%s%d.%09d',
            $this->nanos < 0 ? '-' : '',
            intdiv($abs, self::NANOS_PER_DOLLAR),
            $abs % self::NANOS_PER_DOLLAR,
        );
    }

    public function add(self $other): self
    {
        return new self(self::checked($this->nanos + $other->nanos));
    }

    public function subtract(self $other): self
    {
        return new self(self::checked($this->nanos - $other->nanos));
    }

    public function multiply(int $quantity): self
    {
        return new self(self::checked($this->nanos * $quantity));
    }

    /**
     * Add a percentage markup, rounding UP to the next nanodollar, so the
     * result is never below the exact marked-up price (and never below the
     * original amount).
     */
    public function withMarkup(int $percent): self
    {
        if ($this->nanos < 0 || $percent < 0) {
            throw new InvalidArgumentException('Markup can only be applied to non-negative amounts and percentages.');
        }

        $scaled = self::checked($this->nanos * (100 + $percent));

        return new self(intdiv($scaled + 99, 100));
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    private static function checked(int|float $value): int
    {
        if (is_float($value)) {
            throw new OverflowException('Money amount exceeds the supported range.');
        }

        return $value;
    }
}
