<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use InvalidArgumentException;

/**
 * Checks the `Pixelforge-Signature: t=<unix>,v1=<hex>` header.
 *
 * The signature is HMAC-SHA256 over "<t>.<raw body>" with the API key's
 * signing secret (whsec_...). It must be computed over the exact bytes that
 * were received, never over re-encoded JSON. Old (and far-future) timestamps
 * are rejected so a captured request cannot be replayed later. Several v1
 * values may be present (secret rotation): any one matching is enough.
 */
final class WebhookSignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds,
    ) {
        if (trim($secret) === '') {
            throw new InvalidArgumentException('The Pixelforge webhook secret is empty.');
        }
    }

    /**
     * @param  int  $now  current unix time, passed in so it can be tested
     *
     * @throws InvalidWebhookSignature
     */
    public function verify(string $rawBody, ?string $header, int $now): void
    {
        [$timestamp, $signatures] = $this->parse((string) $header);

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        $matches = false;
        foreach ($signatures as $signature) {
            // No early exit: every candidate is compared, in constant time.
            $matches = hash_equals($expected, $signature) || $matches;
        }

        if (! $matches) {
            throw new InvalidWebhookSignature('The signature does not match.');
        }

        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignature('The timestamp is outside the allowed window.');
        }
    }

    /** @return array{0: string, 1: list<string>} timestamp and the v1 signatures */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            throw new InvalidWebhookSignature('The signature header has no valid timestamp.');
        }

        if ($signatures === []) {
            throw new InvalidWebhookSignature('The signature header has no v1 signature.');
        }

        return [$timestamp, $signatures];
    }
}
