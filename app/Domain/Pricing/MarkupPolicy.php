<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money;
use InvalidArgumentException;

/** What users pay on top of Pixelforge's cost. Rounds up to the next nanodollar. */
final readonly class MarkupPolicy
{
    private function __construct(public int $percent)
    {
    }

    public static function percent(int $percent): self
    {
        if ($percent < 0) {
            throw new InvalidArgumentException('Markup percentage cannot be negative.');
        }

        return new self($percent);
    }

    public function apply(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->percent);
    }
}
