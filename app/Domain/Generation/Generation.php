<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\Money;
use App\Domain\Pricing\Biller;
use App\Domain\Pricing\Rates;
use DateTimeImmutable;
use DomainException;

/**
 * The lifecycle and billing of one image generation.
 *
 * This is where "record every cost exactly once" is enforced. Pixelforge
 * delivers events at least once and possibly out of order, so every mark*()
 * method is idempotent: it returns true if it changed anything and false if
 * the event was a duplicate or stale (and then changes nothing). Cost and
 * charge are computed only on the single transition into `succeeded`, and
 * `succeeded` / `failed` are final.
 *
 * The request parameters (prompt, size, ...) live in persistence; this class
 * owns only what must be protected: status and money.
 */
final class Generation
{
    public private(set) GenerationStatus $status;

    public private(set) ?string $providerId = null;

    public private(set) ?TokenUsage $usage = null;

    /** The frozen rates the cost was computed with, kept for audit. */
    public private(set) ?Rates $rates = null;

    /** What Pixelforge charged us. Null until finished; zero for failures. */
    public private(set) ?Money $providerCost = null;

    /** What the user is charged. Null until finished; zero for failures. */
    public private(set) ?Money $charge = null;

    public private(set) ?string $imageUrl = null;

    public private(set) ?DateTimeImmutable $imageExpiresAt = null;

    public private(set) ?string $errorCode = null;

    public private(set) ?string $errorMessage = null;

    public private(set) ?DateTimeImmutable $completedAt = null;

    private function __construct(public readonly string $id)
    {
        $this->status = GenerationStatus::Pending;
    }

    /** A generation we have recorded but Pixelforge has not accepted yet. */
    public static function pending(string $id): self
    {
        return new self($id);
    }

    /** Rebuild from stored state. Trusts storage: nothing is recomputed. */
    public static function restore(
        string $id,
        GenerationStatus $status,
        ?string $providerId,
        ?TokenUsage $usage,
        ?Rates $rates,
        ?Money $providerCost,
        ?Money $charge,
        ?string $imageUrl,
        ?DateTimeImmutable $imageExpiresAt,
        ?string $errorCode,
        ?string $errorMessage,
        ?DateTimeImmutable $completedAt,
    ): self {
        $generation = new self($id);
        $generation->status = $status;
        $generation->providerId = $providerId;
        $generation->usage = $usage;
        $generation->rates = $rates;
        $generation->providerCost = $providerCost;
        $generation->charge = $charge;
        $generation->imageUrl = $imageUrl;
        $generation->imageExpiresAt = $imageExpiresAt;
        $generation->errorCode = $errorCode;
        $generation->errorMessage = $errorMessage;
        $generation->completedAt = $completedAt;

        return $generation;
    }

    /**
     * Remember Pixelforge's id for this generation. Safe to repeat, but a
     * generation can never be re-pointed at a different provider generation.
     */
    public function linkProvider(string $providerId): void
    {
        if ($this->providerId !== null && $this->providerId !== $providerId) {
            throw new DomainException("Generation {$this->id} is already linked to {$this->providerId}, not {$providerId}.");
        }

        $this->providerId = $providerId;
    }

    public function markQueued(): bool
    {
        return $this->advanceTo(GenerationStatus::Queued);
    }

    public function markProcessing(): bool
    {
        return $this->advanceTo(GenerationStatus::Processing);
    }

    /**
     * The only place cost and charge are computed, and only on the one real
     * transition into `succeeded`. Rates must be the ones Pixelforge froze on
     * the generation when it was created.
     */
    public function markSucceeded(
        Biller $biller,
        TokenUsage $usage,
        Rates $rates,
        string $imageUrl,
        DateTimeImmutable $imageExpiresAt,
        DateTimeImmutable $completedAt,
    ): bool {
        if (! $this->advanceTo(GenerationStatus::Succeeded)) {
            return false;
        }

        $bill = $biller->bill($usage, $rates);

        $this->usage = $usage;
        $this->rates = $rates;
        $this->providerCost = $bill->providerCost;
        $this->charge = $bill->charge;
        $this->imageUrl = $imageUrl;
        $this->imageExpiresAt = $imageExpiresAt;
        $this->completedAt = $completedAt;

        return true;
    }

    /** Failed generations cost nothing, so both amounts are recorded as zero. */
    public function markFailed(string $errorCode, string $errorMessage, DateTimeImmutable $completedAt): bool
    {
        if (! $this->advanceTo(GenerationStatus::Failed)) {
            return false;
        }

        $this->providerCost = Money::zero();
        $this->charge = Money::zero();
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->completedAt = $completedAt;

        return true;
    }

    private function advanceTo(GenerationStatus $next): bool
    {
        if (! $this->status->canTransitionTo($next)) {
            return false;
        }

        $this->status = $next;

        return true;
    }
}
