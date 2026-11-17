<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money;
use InvalidArgumentException;

/**
 * The range a generation's cost is guaranteed to fall in, shown before submit.
 * Part of the cost (reasoning tokens, and a few percent of text tokens) is only
 * known once the generation finishes, so the honest answer is a min..max.
 */
final readonly class CostEstimate
{
    public function __construct(
        public Money $min,
        public Money $max,
    ) {
        if ($min->toNanos() > $max->toNanos()) {
            throw new InvalidArgumentException('Estimate minimum cannot exceed its maximum.');
        }
    }

    public function withMarkup(int $percent): self
    {
        return new self($this->min->withMarkup($percent), $this->max->withMarkup($percent));
    }
}
