<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Builds and signs webhook deliveries the way Pixelforge does. */
final class PixelforgeWebhook
{
    public const SECRET = 'whsec_test_secret';

    /**
     * @param  array<string, mixed>  $generation  a generation object, see PixelforgeApi::generation()
     * @return array<string, mixed>
     */
    public static function event(string $type, array $generation, string $id = 'evt_test0001'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-17T12:00:20Z',
            'data' => ['object' => $generation],
        ];
    }

    /** The Pixelforge-Signature header for a raw body. */
    public static function signature(string $rawBody, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }
}
