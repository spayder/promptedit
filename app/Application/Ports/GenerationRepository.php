<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Generation\GenerationNotFound;
use App\Application\Generation\NewGeneration;
use App\Domain\Generation\Generation;
use Closure;

/** Where generations are stored. The domain Generation never touches the database. */
interface GenerationRepository
{
    /** Record a new generation. It starts out pending. */
    public function add(NewGeneration $new): Generation;

    public function find(string $id): ?Generation;

    /** Our id for a Pixelforge generation id, or null if we do not know it. */
    public function findIdByProviderId(string $providerId): ?string;

    /**
     * Change a generation safely under concurrency.
     *
     * Runs in a transaction with the row locked, so two webhooks for the same
     * generation are applied one after the other, never interleaved: the second
     * one sees the state the first left behind. $change receives the domain
     * Generation, and whatever it changed is saved when it returns. If it
     * throws, nothing is saved. Returns what $change returned.
     *
     * @template T
     *
     * @param  Closure(Generation): T  $change
     * @return T
     *
     * @throws GenerationNotFound
     */
    public function update(string $id, Closure $change): mixed;
}
