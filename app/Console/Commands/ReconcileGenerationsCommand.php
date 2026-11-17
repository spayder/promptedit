<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Generation\ReconcileGenerations;
use Illuminate\Console\Command;

class ReconcileGenerationsCommand extends Command
{
    protected $signature = 'generations:reconcile';

    protected $description = 'Catch up generations whose webhook never arrived, and resubmit ones that never reached Pixelforge';

    public function handle(ReconcileGenerations $reconcile): int
    {
        $result = $reconcile->handle(
            idleSeconds: (int) config('services.pixelforge.reconcile.idle_seconds'),
            pendingSeconds: (int) config('services.pixelforge.reconcile.pending_seconds'),
            limit: (int) config('services.pixelforge.reconcile.batch_size'),
        );

        $this->info(sprintf(
            'Checked %d, updated %d, resubmitted %d, failed lookups %d.',
            $result->checked, $result->updated, $result->resubmitted, $result->failed,
        ));

        return self::SUCCESS;
    }
}
