<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Pixelforge generation objects as the API documents them, for tests. */
final class PixelforgeApi
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generation(string $status = 'queued', array $overrides = []): array
    {
        $base = [
            'id' => 'gen_test0001',
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse at dusk',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => ['generation_id' => '0192a0a0-0000-7000-8000-000000000001'],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => PixelforgePricing::response()['rates'],
            'error' => null,
            'created_at' => '2026-11-17T12:00:00Z',
            'started_at' => null,
            'completed_at' => null,
        ];

        return array_replace($base, match ($status) {
            'processing' => ['started_at' => '2026-11-17T12:00:03Z'],
            'succeeded' => [
                'started_at' => '2026-11-17T12:00:03Z',
                'completed_at' => '2026-11-17T12:00:20Z',
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test0001.png?sig=abc',
                'image_url_expires_at' => '2026-11-18T12:00:20Z',
                // medium 1024x1024: 10 text + 1100 output + 1000 reasoning
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 1000,
                    'total_tokens' => 2110,
                ],
            ],
            'failed' => [
                'started_at' => '2026-11-17T12:00:03Z',
                'completed_at' => '2026-11-17T12:00:09Z',
                'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
            ],
            default => [],
        }, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(string $type, string $code, string $message = 'Something went wrong.', array $extra = []): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => $message] + $extra];
    }
}
