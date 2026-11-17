<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domain\Pricing\Rates;
use App\Domain\Pricing\TokenSchedule;

/** Pixelforge's published pricing (GET /v1/pricing) as documented, for tests. */
final class PixelforgePricing
{
    /** @return array<string, mixed> */
    public static function response(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    public static function rates(): Rates
    {
        return Rates::fromPerMillionTokens(inputText: '5.00', inputImage: '10.00', output: '40.00', reasoning: '12.50');
    }

    public static function schedule(): TokenSchedule
    {
        $tokens = self::response()['tokens'];

        return new TokenSchedule(
            charactersPerTextToken: $tokens['characters_per_text_token'],
            textTokenVariancePercent: 15,
            tokensPerReferenceImage: $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }
}
