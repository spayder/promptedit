<?php

use App\Application\Ports\GenerationReportReader;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function reader(): GenerationReportReader
{
    return app(GenerationReportReader::class);
}

/** A succeeded generation that completed at a UTC moment, with the given amounts in nanodollars. */
function doneAt(User $user, string $completedAtUtc, int $cost, int $charge): GenerationRecord
{
    return GenerationRecord::factory()->for($user)->succeeded()->create([
        'completed_at' => $completedAtUtc,
        'provider_cost_nanos' => $cost,
        'charged_nanos' => $charge,
    ]);
}

it('adds up each user\'s succeeded generations per UTC day', function () {
    $ada = User::factory()->create(['name' => 'Ada']);
    doneAt($ada, '2026-11-17 09:00:00+00', 14_197_500, 17_746_875);
    doneAt($ada, '2026-11-17 18:30:00+00', 56_550_000, 70_687_500);

    $rows = reader()->completedPerDayAndUser('2026-11-17', '2026-11-17');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])
        ->date->toBe('2026-11-17')
        ->userId->toBe($ada->id)
        ->userName->toBe('Ada')
        ->generations->toBe(2)
        ->and($rows[0]->charged->toNanos())->toBe(88_434_375)
        ->and($rows[0]->providerCost->toNanos())->toBe(70_747_500)
        ->and($rows[0]->profit()->toNanos())->toBe(17_686_875);
});

it('puts a generation on the UTC day it completed, not the day it was created or the viewer\'s day', function () {
    $ada = User::factory()->create();
    // Created on the 16th, completed one second past midnight UTC on the 17th.
    GenerationRecord::factory()->for($ada)->succeeded()->create([
        'created_at' => '2026-11-16 23:59:50+00',
        'completed_at' => '2026-11-17 00:00:01+00',
    ]);
    // Completed in the last second of the 17th, which is already the 18th in Warsaw.
    GenerationRecord::factory()->for($ada)->succeeded()->create(['completed_at' => '2026-11-17 23:59:59+00']);

    $rows = reader()->completedPerDayAndUser('2026-11-16', '2026-11-18');

    expect(array_map(fn ($row) => [$row->date, $row->generations], $rows))->toBe([['2026-11-17', 2]]);
});

it('reads the day correctly whatever time zone the completion was written in', function () {
    $ada = User::factory()->create();
    $warsaw = doneAt($ada, '2026-11-17 12:00:00+00', 1, 1);
    $newYork = doneAt($ada, '2026-11-17 12:00:00+00', 1, 1);

    // Written straight to the database with an explicit offset (the model's date cast would drop it).
    // 01:30 on the 18th in Warsaw (UTC+1) and 19:30 on the 17th in New York (UTC-5) are both 00:30 on the 18th UTC.
    DB::table('generations')->where('id', $warsaw->id)->update(['completed_at' => '2026-11-18 01:30:00+01']);
    DB::table('generations')->where('id', $newYork->id)->update(['completed_at' => '2026-11-17 19:30:00-05']);

    $rows = reader()->completedPerDayAndUser('2026-11-17', '2026-11-18');

    expect(array_map(fn ($row) => [$row->date, $row->generations], $rows))->toBe([['2026-11-18', 2]]);
});

it('keeps users apart and lists the newest day first, then users by name', function () {
    $zoe = User::factory()->create(['name' => 'Zoe']);
    $ada = User::factory()->create(['name' => 'Ada']);
    doneAt($zoe, '2026-11-17 10:00:00+00', 10, 12);
    doneAt($ada, '2026-11-17 11:00:00+00', 20, 25);
    doneAt($zoe, '2026-11-18 10:00:00+00', 30, 38);

    $rows = reader()->completedPerDayAndUser('2026-11-17', '2026-11-18');

    expect(array_map(fn ($row) => [$row->date, $row->userName], $rows))->toBe([
        ['2026-11-18', 'Zoe'],
        ['2026-11-17', 'Ada'],
        ['2026-11-17', 'Zoe'],
    ]);
});

it('counts only succeeded generations', function () {
    $ada = User::factory()->create();
    GenerationRecord::factory()->for($ada)->failed()->create(['completed_at' => '2026-11-17 10:00:00+00']);
    GenerationRecord::factory()->for($ada)->queued()->create();
    GenerationRecord::factory()->for($ada)->processing()->create();
    GenerationRecord::factory()->for($ada)->create(); // pending

    expect(reader()->completedPerDayAndUser('2026-11-01', '2026-11-30'))->toBe([]);
});

it('includes both ends of the range and nothing outside it', function () {
    $ada = User::factory()->create();
    doneAt($ada, '2026-11-09 23:59:59+00', 1, 1);
    doneAt($ada, '2026-11-10 00:00:00+00', 1, 1);
    doneAt($ada, '2026-11-12 23:59:59+00', 1, 1);
    doneAt($ada, '2026-11-13 00:00:00+00', 1, 1);

    $rows = reader()->completedPerDayAndUser('2026-11-10', '2026-11-12');

    expect(array_column(array_map(fn ($row) => (array) $row, $rows), 'date'))->toBe(['2026-11-12', '2026-11-10']);
});

it('stays exact with large sums', function () {
    $ada = User::factory()->create();
    doneAt($ada, '2026-11-17 10:00:00+00', 3_000_000_000_000, 3_750_000_000_000);
    doneAt($ada, '2026-11-17 11:00:00+00', 3_000_000_000_001, 3_750_000_000_002);

    $row = reader()->completedPerDayAndUser('2026-11-17', '2026-11-17')[0];

    expect($row->providerCost->toNanos())->toBe(6_000_000_000_001)
        ->and($row->charged->toNanos())->toBe(7_500_000_000_002);
});
