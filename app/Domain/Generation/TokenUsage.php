<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use InvalidArgumentException;

/** Actual token counts of a finished generation, as reported by Pixelforge. */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {
        foreach ([$inputTextTokens, $inputImageTokens, $outputTokens, $reasoningTokens] as $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException('Token counts cannot be negative.');
            }
        }
    }

    public function totalTokens(): int
    {
        return $this->inputTextTokens + $this->inputImageTokens + $this->outputTokens + $this->reasoningTokens;
    }
}
