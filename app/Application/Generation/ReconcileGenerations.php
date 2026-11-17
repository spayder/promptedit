<?php

declare(strict_types=1);

namespace App\Application\Generation;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\ProviderException;
use App\Application\Provider\Exceptions\RetryableProviderException;
use App\Jobs\SubmitGenerationToProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks are not guaranteed, and queued jobs can be lost, so this goes and
 * looks. It is a safety net, not the main path: normally the webhook has
 * already done the work and there is nothing to find.
 *
 * - queued / processing for too long: ask Pixelforge what became of it.
 * - pending for too long: the job that submits it was lost, so queue a new one.
 *
 * Everything here is safe to repeat: applying a report is idempotent, and a
 * resubmitted generation reuses its idempotency key.
 */
final readonly class ReconcileGenerations
{
    public function __construct(
        private GenerationRepository $generations,
        private ImageProvider        $provider,
        private SyncGeneration       $sync,
    ) {
    }

    /**
     * @param  int  $idleSeconds  how long queued/processing may sit unchanged before we ask Pixelforge
     * @param  int  $pendingSeconds  how long a pending generation may wait before it is resubmitted
     * @param  int  $limit  most generations to look at in one run (Pixelforge limits requests per minute)
     */
    public function handle(int $idleSeconds, int $pendingSeconds, int $limit): ReconcileResult
    {
        $now = Date::now();

        $overdue = $this->generations->findOverdue(
            $now->copy()->subSeconds($idleSeconds)->toDateTimeImmutable(),
            $now->copy()->subSeconds($pendingSeconds)->toDateTimeImmutable(),
            $limit,
        );

        $checked = $updated = $resubmitted = $failed = 0;

        foreach ($overdue as $generation) {
            if ($generation->providerId === null) {
                $resubmitted += $this->resubmit($generation, $pendingSeconds) ? 1 : 0;

                continue;
            }

            try {
                $remote = $this->provider->getGeneration($generation->providerId);
            } catch (RetryableProviderException $e) {
                // Rate limited or Pixelforge is struggling: the rest would only fail too. Next run.
                Log::info('Reconcile paused, Pixelforge asked us to wait.', ['reason' => $e::class, 'seconds' => $e->retryAfterSeconds]);

                break;
            } catch (ProviderException $e) {
                Log::warning('Could not look up a generation at Pixelforge.', ['generation' => $generation->id, 'error' => $e->getMessage()]);
                $failed++;

                continue;
            }

            $checked++;

            if ($this->sync->sync($generation->id, $remote) !== $generation->status) {
                $updated++;
            }
        }

        return new ReconcileResult($checked, $updated, $resubmitted, $failed);
    }

    /**
     * Queue the submit job again, at most once per $pendingSeconds. That job
     * may legitimately retry for several minutes, and queueing a second copy on
     * every run in the meantime would pile up duplicates.
     */
    private function resubmit(OverdueGeneration $generation, int $pendingSeconds): bool
    {
        if (! Cache::add("reconcile.resubmitted.{$generation->id}", true, $pendingSeconds)) {
            return false;
        }

        Log::warning('Resubmitting a generation that never reached Pixelforge.', ['generation' => $generation->id]);
        SubmitGenerationToProvider::dispatch($generation->id);

        return true;
    }
}
