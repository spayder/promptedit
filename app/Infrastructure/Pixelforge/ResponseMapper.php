<?php

declare(strict_types=1);

namespace App\Infrastructure\Pixelforge;

use App\Application\Provider\Exceptions\UnexpectedResponse;
use App\Application\Provider\PricingSnapshot;
use App\Application\Provider\ProviderGeneration;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\TokenUsage;
use App\Domain\Pricing\Rates;
use App\Domain\Pricing\TokenSchedule;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Translates Pixelforge's JSON into our own types. This is the only place that
 * knows the API's field names. It is used for API responses and for the
 * generation object inside webhook payloads, so both are read identically.
 *
 * Anything missing or malformed becomes an UnexpectedResponse rather than a
 * half-filled object: guessing is how money ends up wrong.
 */
final class ResponseMapper
{
    private const PROVIDER_STATUSES = [
        GenerationStatus::Queued,
        GenerationStatus::Processing,
        GenerationStatus::Succeeded,
        GenerationStatus::Failed,
    ];

    /** @param  array<string, mixed>  $data  a Pixelforge generation object */
    public static function generation(array $data): ProviderGeneration
    {
        try {
            $status = GenerationStatus::tryFrom(self::string($data, 'status'));

            if (! in_array($status, self::PROVIDER_STATUSES, true)) {
                throw new UnexpectedResponse('Unknown generation status "'.self::string($data, 'status').'".');
            }

            return new ProviderGeneration(
                id: self::string($data, 'id'),
                status: $status,
                rates: isset($data['rates']) ? self::rates($data['rates']) : null,
                usage: isset($data['usage']) ? self::usage($data['usage']) : null,
                imageUrl: $data['image_url'] ?? null,
                imageExpiresAt: self::dateOrNull($data['image_url_expires_at'] ?? null),
                errorCode: $data['error']['code'] ?? null,
                errorMessage: $data['error']['message'] ?? null,
                createdAt: new DateTimeImmutable(self::string($data, 'created_at')),
                completedAt: self::dateOrNull($data['completed_at'] ?? null),
                metadata: array_map('strval', (array) ($data['metadata'] ?? [])),
            );
        } catch (UnexpectedResponse $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnexpectedResponse('Could not read the generation: '.$e->getMessage(), previous: $e);
        }
    }

    /** @param  array<string, mixed>  $data  the GET /v1/pricing response */
    public static function pricing(array $data): PricingSnapshot
    {
        try {
            $tokens = $data['tokens'] ?? throw new UnexpectedResponse('Pricing response has no "tokens".');

            return new PricingSnapshot(
                rates: self::rates($data['rates'] ?? throw new UnexpectedResponse('Pricing response has no "rates".')),
                schedule: new TokenSchedule(
                    charactersPerTextToken: $tokens['characters_per_text_token'],
                    textTokenVariancePercent: self::wholePercent($tokens['text_token_variance']),
                    tokensPerReferenceImage: $tokens['per_reference_image'],
                    outputTokens: $tokens['output'],
                    reasoningTokens: $tokens['reasoning'],
                ),
            );
        } catch (UnexpectedResponse $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnexpectedResponse('Could not read the pricing: '.$e->getMessage(), previous: $e);
        }
    }

    /** @param  array<string, mixed>  $rates  USD per 1M tokens, as decimal strings */
    private static function rates(array $rates): Rates
    {
        return Rates::fromPerMillionTokens(
            inputText: self::string($rates, 'input_text_tokens'),
            inputImage: self::string($rates, 'input_image_tokens'),
            output: self::string($rates, 'output_tokens'),
            reasoning: self::string($rates, 'reasoning_tokens'),
        );
    }

    /** @param  array<string, mixed>  $usage */
    private static function usage(array $usage): TokenUsage
    {
        return new TokenUsage(
            inputTextTokens: self::int($usage, 'input_text_tokens'),
            inputImageTokens: self::int($usage, 'input_image_tokens'),
            outputTokens: self::int($usage, 'output_tokens'),
            reasoningTokens: self::int($usage, 'reasoning_tokens'),
        );
    }

    /** The API sends the text variance as a float (0.15); we keep whole percent and refuse anything finer. */
    private static function wholePercent(float|int $fraction): int
    {
        $percent = $fraction * 100;
        $rounded = (int) round($percent);

        if (abs($percent - $rounded) > 1e-6) {
            throw new InvalidArgumentException("Text token variance {$fraction} is not a whole percentage.");
        }

        return $rounded;
    }

    private static function dateOrNull(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }

    /** @param  array<string, mixed>  $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedResponse("Expected a non-empty string for \"{$key}\".");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new UnexpectedResponse("Expected an integer for \"{$key}\".");
        }

        return $value;
    }
}
