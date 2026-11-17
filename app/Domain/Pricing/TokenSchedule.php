<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use InvalidArgumentException;

/**
 * Pixelforge's token tables (the non-rate half of GET /v1/pricing): how many
 * tokens a generation uses, as far as that is known before it runs.
 *
 * - output tokens are exact for a given quality and size,
 * - reasoning tokens are only known as a min..max range per quality,
 * - input text tokens are ~1 per N characters, off by up to a few percent,
 * - each reference image costs a fixed number of input image tokens.
 */
final readonly class TokenSchedule
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality value => size value => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality value => range
     */
    public function __construct(
        public int $charactersPerTextToken,
        public int $textTokenVariancePercent,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {
        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('Characters per text token must be at least 1.');
        }

        if ($textTokenVariancePercent < 0 || $textTokenVariancePercent > 100) {
            throw new InvalidArgumentException('Text token variance must be between 0 and 100 percent.');
        }

        if ($tokensPerReferenceImage < 0) {
            throw new InvalidArgumentException('Tokens per reference image cannot be negative.');
        }

        foreach (Quality::cases() as $quality) {
            foreach (ImageSize::cases() as $size) {
                $tokens = $outputTokens[$quality->value][$size->value] ?? null;

                if (! is_int($tokens) || $tokens < 0) {
                    throw new InvalidArgumentException("Missing output tokens for {$quality->value} {$size->value}.");
                }
            }

            $range = $reasoningTokens[$quality->value] ?? null;

            if ($range === null || $range['min'] < 0 || $range['min'] > $range['max']) {
                throw new InvalidArgumentException("Missing or invalid reasoning token range for {$quality->value}.");
            }
        }
    }

    public function outputTokens(Quality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    /** @return array{min: int, max: int} */
    public function reasoningRange(Quality $quality): array
    {
        return $this->reasoningTokens[$quality->value];
    }
}
