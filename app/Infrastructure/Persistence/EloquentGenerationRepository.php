<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Generation\GenerationNotFound;
use App\Application\Generation\NewGeneration;
use App\Application\Ports\GenerationRepository;
use App\Domain\Generation\Generation;
use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\TokenUsage;
use App\Domain\Money;
use App\Domain\Pricing\Rates;
use App\Models\Generation as GenerationRecord;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Stores domain Generations in the `generations` table.
 *
 * Only the columns the domain owns (status, provider id, usage, rates, amounts,
 * image, error, completion time) are ever written back by update(); the request
 * columns are written once, by add().
 */
final class EloquentGenerationRepository implements GenerationRepository
{
    public function add(NewGeneration $new): Generation
    {
        GenerationRecord::query()->create([
            'id' => $new->id,
            'user_id' => $new->userId,
            'status' => GenerationStatus::Pending,
            'prompt' => $new->prompt,
            'quality' => $new->quality,
            'size' => $new->size,
            'reference_images' => $new->referenceImages,
            'estimated_charge_min_nanos' => $new->estimatedCharge->min->toNanos(),
            'estimated_charge_max_nanos' => $new->estimatedCharge->max->toNanos(),
        ]);

        return Generation::pending($new->id);
    }

    public function find(string $id): ?Generation
    {
        $record = GenerationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findIdByProviderId(string $providerId): ?string
    {
        return GenerationRecord::query()->where('provider_id', $providerId)->value('id');
    }

    public function update(string $id, Closure $change): mixed
    {
        return DB::transaction(function () use ($id, $change) {
            $record = GenerationRecord::query()->lockForUpdate()->find($id);

            if ($record === null) {
                throw GenerationNotFound::withId($id);
            }

            $generation = $this->toDomain($record);
            $result = $change($generation);

            $this->applyTo($record, $generation);
            $record->save(); // writes nothing when the change was a no-op

            return $result;
        });
    }

    private function toDomain(GenerationRecord $record): Generation
    {
        return Generation::restore(
            id: $record->id,
            status: $record->status,
            providerId: $record->provider_id,
            usage: $this->usageOf($record),
            rates: $this->ratesOf($record),
            providerCost: $this->moneyOrNull($record->provider_cost_nanos),
            charge: $this->moneyOrNull($record->charged_nanos),
            imageUrl: $record->image_url,
            imageExpiresAt: $record->image_url_expires_at,
            errorCode: $record->error_code,
            errorMessage: $record->error_message,
            completedAt: $record->completed_at,
        );
    }

    private function applyTo(GenerationRecord $record, Generation $generation): void
    {
        $record->fill([
            'status' => $generation->status,
            'provider_id' => $generation->providerId,
            'rate_input_text_nanos' => $generation->rates?->inputTextNanos,
            'rate_input_image_nanos' => $generation->rates?->inputImageNanos,
            'rate_output_nanos' => $generation->rates?->outputNanos,
            'rate_reasoning_nanos' => $generation->rates?->reasoningNanos,
            'usage_input_text_tokens' => $generation->usage?->inputTextTokens,
            'usage_input_image_tokens' => $generation->usage?->inputImageTokens,
            'usage_output_tokens' => $generation->usage?->outputTokens,
            'usage_reasoning_tokens' => $generation->usage?->reasoningTokens,
            'provider_cost_nanos' => $generation->providerCost?->toNanos(),
            'charged_nanos' => $generation->charge?->toNanos(),
            'image_url' => $generation->imageUrl,
            'image_url_expires_at' => $generation->imageExpiresAt,
            'error_code' => $generation->errorCode,
            'error_message' => $generation->errorMessage,
            'completed_at' => $generation->completedAt,
        ]);
    }

    private function usageOf(GenerationRecord $record): ?TokenUsage
    {
        if ($record->usage_input_text_tokens === null) {
            return null;
        }

        return new TokenUsage(
            inputTextTokens: $record->usage_input_text_tokens,
            inputImageTokens: $record->usage_input_image_tokens,
            outputTokens: $record->usage_output_tokens,
            reasoningTokens: $record->usage_reasoning_tokens,
        );
    }

    private function ratesOf(GenerationRecord $record): ?Rates
    {
        if ($record->rate_input_text_nanos === null) {
            return null;
        }

        return new Rates(
            inputTextNanos: $record->rate_input_text_nanos,
            inputImageNanos: $record->rate_input_image_nanos,
            outputNanos: $record->rate_output_nanos,
            reasoningNanos: $record->rate_reasoning_nanos,
        );
    }

    private function moneyOrNull(?int $nanos): ?Money
    {
        return $nanos === null ? null : Money::nanos($nanos);
    }
}
