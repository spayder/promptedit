<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money;
use InvalidArgumentException;

/**
 * Pixelforge's token prices, held as whole nanodollars PER SINGLE TOKEN.
 *
 * Pixelforge quotes rates in USD per 1M tokens (e.g. "5.00"). One dollar per
 * million tokens is exactly 1,000 nanodollars per token, so every rate with at
 * most three decimals converts without rounding and cost = tokens * rate stays
 * pure integer arithmetic. Anything finer is rejected rather than rounded.
 *
 * A generation's rates are frozen by Pixelforge when it is created, so these
 * must be stored with the generation and used for its cost.
 */
final readonly class Rates
{
    private const int NANOS_PER_MILLION_TOKENS = 1_000_000;

    public function __construct(
        public int $inputTextNanos,
        public int $inputImageNanos,
        public int $outputNanos,
        public int $reasoningNanos,
    ) {
        foreach ([$inputTextNanos, $inputImageNanos, $outputNanos, $reasoningNanos] as $nanos) {
            if ($nanos < 0) {
                throw new InvalidArgumentException('Token rates cannot be negative.');
            }
        }
    }

    /** Build from Pixelforge's decimal strings, quoted in USD per 1M tokens. */
    public static function fromPerMillionTokens(
        string $inputText,
        string $inputImage,
        string $output,
        string $reasoning,
    ): self {
        return new self(
            self::nanosPerToken($inputText),
            self::nanosPerToken($inputImage),
            self::nanosPerToken($output),
            self::nanosPerToken($reasoning),
        );
    }

    private static function nanosPerToken(string $usdPerMillionTokens): int
    {
        $nanosPerMillion = Money::fromDecimal($usdPerMillionTokens)->toNanos();

        if ($nanosPerMillion < 0) {
            throw new InvalidArgumentException("Token rate \"{$usdPerMillionTokens}\" cannot be negative.");
        }

        if ($nanosPerMillion % self::NANOS_PER_MILLION_TOKENS !== 0) {
            throw new InvalidArgumentException(
                "Token rate \"{$usdPerMillionTokens}\" per 1M tokens is not a whole number of nanodollars per token.",
            );
        }

        return intdiv($nanosPerMillion, self::NANOS_PER_MILLION_TOKENS);
    }
}
