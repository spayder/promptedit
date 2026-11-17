<?php

declare(strict_types=1);

namespace App\Domain\Generation;

/**
 * Where a generation is in its life. Pixelforge owns queued, processing,
 * succeeded and failed; `pending` is ours: the row exists but Pixelforge has
 * not accepted the request yet.
 *
 * Webhooks are delivered at least once and possibly out of order, so the
 * rule is: only ever move FORWARD, and succeeded/failed are final. A late
 * "processing" event can therefore never undo "succeeded".
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Whether moving from this status to $next is a real, allowed change.
     * Staying put returns false (it is a no-op, not a transition). Statuses
     * may be skipped, because an event can arrive before the earlier ones.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
