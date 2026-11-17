<?php

use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\ProviderUnavailable;
use App\Application\Report\BuildProfitReport;
use App\Application\Report\ProfitReport;
use App\Application\Report\Reconciliation;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Tests\Fixtures\FakeImageProvider;
use Tests\Fixtures\PixelforgeUsage;

beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
    $this->ada = User::factory()->create(['name' => 'Ada']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
});

/** One succeeded generation, with its provider cost in nanodollars and the charge (cost + 25%). */
function finishedOn(User $user, string $when, int $cost): void
{
    GenerationRecord::factory()->for($user)->succeeded()->create([
        'completed_at' => $when,
        'provider_cost_nanos' => $cost,
        'charged_nanos' => intdiv($cost * 125 + 99, 100),
    ]);
}

function buildReport(string $start = '2026-11-17', string $end = '2026-11-18'): ProfitReport
{
    return app(BuildProfitReport::class)->handle($start, $end);
}

it('works out charged, provider cost and profit per user and per day', function () {
    finishedOn($this->ada, '2026-11-17 10:00:00+00', 40_000_000); // charged 50,000,000
    finishedOn($this->ada, '2026-11-17 12:00:00+00', 8_000_000); // charged 10,000,000
    finishedOn($this->bob, '2026-11-17 13:00:00+00', 4_000_000); // charged 5,000,000
    $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [3, '0.052000000'], '2026-11-18' => [0, '0.000000000']]);

    $report = buildReport();
    $day = $report->days[0];

    expect($report->days)->toHaveCount(1)
        ->and($day->date)->toBe('2026-11-17')
        ->and($day->rows)->toHaveCount(2)
        ->and($day->generations)->toBe(3)
        ->and($day->providerCost->toNanos())->toBe(52_000_000)
        ->and($day->charged->toNanos())->toBe(65_000_000)
        ->and($day->profit()->toNanos())->toBe(13_000_000)
        ->and($day->rows[0]->userName)->toBe('Ada')
        ->and($day->rows[0]->profit()->toNanos())->toBe(12_000_000) // 60,000,000 charged - 48,000,000 cost
        ->and($report->charged->toNanos())->toBe(65_000_000)
        ->and($report->profit()->toNanos())->toBe(13_000_000);
});

it('says so when our records match Pixelforge exactly', function () {
    finishedOn($this->ada, '2026-11-17 10:00:00+00', 14_197_500);
    finishedOn($this->bob, '2026-11-18 10:00:00+00', 56_550_000);
    $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [1, '0.014197500'], '2026-11-18' => [1, '0.056550000']]);

    $report = buildReport();

    expect($report->reconciliation)->toBe(Reconciliation::Match)
        ->and(array_map(fn ($day) => $day->reconciliation, $report->days))->toBe([Reconciliation::Match, Reconciliation::Match]);
});

it('asks Pixelforge for exactly the range being reported', function () {
    buildReport('2026-11-01', '2026-11-30');

    expect($this->provider->usageRequests)->toBe([['2026-11-01', '2026-11-30']]);
});

describe('when the numbers differ', function () {
    it('flags a day where the cost differs by a single nanodollar', function () {
        finishedOn($this->ada, '2026-11-17 10:00:00+00', 14_197_500);
        $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [1, '0.014197501']]);

        $report = buildReport();

        expect($report->days[0]->reconciliation)->toBe(Reconciliation::Mismatch)
            ->and($report->reconciliation)->toBe(Reconciliation::Mismatch)
            ->and($report->provider->totalCost->toNanos())->toBe(14_197_501);
    });

    it('flags a day where the number of generations differs even if the cost happens to agree', function () {
        finishedOn($this->ada, '2026-11-17 10:00:00+00', 1_000);
        $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [2, '0.000001000']]);

        expect(buildReport()->days[0]->reconciliation)->toBe(Reconciliation::Mismatch);
    });

    it('shows a day Pixelforge billed that we have no record of', function () {
        finishedOn($this->ada, '2026-11-17 10:00:00+00', 10_000_000);
        $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [1, '0.010000000'], '2026-11-18' => [2, '0.030000000']]);

        $report = buildReport();
        $missing = $report->days[0];

        expect($missing->date)->toBe('2026-11-18')
            ->and($missing->rows)->toBe([])
            ->and($missing->generations)->toBe(0)
            ->and($missing->provider->cost->toNanos())->toBe(30_000_000)
            ->and($missing->reconciliation)->toBe(Reconciliation::Mismatch)
            ->and($report->days[1]->reconciliation)->toBe(Reconciliation::Match)
            ->and($report->reconciliation)->toBe(Reconciliation::Mismatch);
    });

    it('flags a day we recorded that Pixelforge never billed', function () {
        finishedOn($this->ada, '2026-11-17 10:00:00+00', 10_000_000);
        $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [0, '0.000000000']]);

        $day = buildReport()->days[0];

        expect($day->reconciliation)->toBe(Reconciliation::Mismatch)
            ->and($day->provider->generations)->toBe(0);
    });

    it('treats a day missing from Pixelforge\'s report as zero, not as unknown', function () {
        finishedOn($this->ada, '2026-11-17 10:00:00+00', 10_000_000);
        $this->provider->usage = PixelforgeUsage::report([]);

        expect(buildReport()->days[0]->reconciliation)->toBe(Reconciliation::Mismatch);
    });
});

describe('when Pixelforge cannot be asked', function () {
    it('still shows our figures and says they were not checked', function () {
        finishedOn($this->ada, '2026-11-17 10:00:00+00', 10_000_000);
        $this->provider->usageFailure = new ProviderUnavailable('down', 5);

        $report = buildReport();

        expect($report->reconciliation)->toBe(Reconciliation::Unavailable)
            ->and($report->provider)->toBeNull()
            ->and($report->days)->toHaveCount(1)
            ->and($report->days[0]->reconciliation)->toBe(Reconciliation::Unavailable)
            ->and($report->days[0]->provider)->toBeNull()
            ->and($report->days[0]->providerCost->toNanos())->toBe(10_000_000);
    });
});

it('has nothing to show for a quiet range, and that counts as matching', function () {
    $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [0, '0.000000000'], '2026-11-18' => [0, '0.000000000']]);

    $report = buildReport();

    expect($report->days)->toBe([])
        ->and($report->generations)->toBe(0)
        ->and($report->charged->isZero())->toBeTrue()
        ->and($report->reconciliation)->toBe(Reconciliation::Match);
});

it('does not count failed generations anywhere', function () {
    GenerationRecord::factory()->for($this->ada)->failed()->create(['completed_at' => '2026-11-17 10:00:00+00']);
    $this->provider->usage = PixelforgeUsage::report(['2026-11-17' => [0, '0.000000000']]);

    expect(buildReport()->days)->toBe([]);
});
