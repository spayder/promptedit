<?php

use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\ProviderUnavailable;
use App\Http\Presenters\ReportPresenter;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\FakeImageProvider;
use Tests\Fixtures\PixelforgeUsage;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
    $this->ada = User::factory()->create(['name' => 'Ada']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
});

function finished(User $user, string $when, int $cost): void
{
    GenerationRecord::factory()->for($user)->succeeded()->create([
        'completed_at' => $when,
        'provider_cost_nanos' => $cost,
        'charged_nanos' => intdiv($cost * 125 + 99, 100),
    ]);
}

it('keeps guests out', function () {
    $this->get('/report')->assertRedirect('/login');
});

it('is open to every signed-in user, not only a special one', function () {
    $this->actingAs($this->bob)->get('/report')->assertOk();
    $this->actingAs($this->ada)->get('/report')->assertOk();
});

describe('the range', function () {
    it('defaults to the last 30 days ending today, UTC', function () {
        $this->actingAs($this->ada)->get('/report')->assertInertia(fn (Assert $page) => $page
            ->component('Report/Index')
            ->where('range.start', '2026-10-22')
            ->where('range.end', '2026-11-20')
            ->where('range.errors', []));

        expect($this->provider->usageRequests)->toBe([['2026-10-22', '2026-11-20']]);
    });

    it('uses the dates in the address', function () {
        $this->actingAs($this->ada)->get('/report?start_date=2026-11-01&end_date=2026-11-10')->assertInertia(fn (Assert $page) => $page
            ->where('range.start', '2026-11-01')
            ->where('range.end', '2026-11-10'));

        expect($this->provider->usageRequests)->toBe([['2026-11-01', '2026-11-10']]);
    });

    it('allows exactly 92 days and shortens anything longer, saying so', function () {
        $this->actingAs($this->ada)->get('/report?start_date=2026-08-21&end_date=2026-11-20')->assertInertia(fn (Assert $page) => $page
            ->where('range.start', '2026-08-21')
            ->where('range.errors', [])); // 92 days inclusive

        $this->actingAs($this->ada)->get('/report?start_date=2026-01-01&end_date=2026-11-20')->assertInertia(fn (Assert $page) => $page
            ->where('range.start', '2026-08-21')
            ->where('range.end', '2026-11-20')
            ->has('range.errors', 1));
    });

    it('falls back to the default and explains when a date is unreadable', function (string $query, string $error) {
        $this->actingAs($this->ada)->get("/report?{$query}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('range.start', '2026-10-22')
            ->where('range.end', '2026-11-20')
            ->where('range.errors.0', $error));
    })->with([
        'start not a date' => ['start_date=yesterday', 'Use the format YYYY-MM-DD for the start date.'],
        'end not a date' => ['end_date=2026-13-45', 'Use the format YYYY-MM-DD for the end date.'],
        'end in the future' => ['end_date=2026-11-21', 'The end date cannot be in the future.'],
    ]);

    it('explains when the start is after the end', function () {
        $this->actingAs($this->ada)->get('/report?start_date=2026-11-10&end_date=2026-11-01')->assertInertia(fn (Assert $page) => $page
            ->where('range.errors.0', 'The start date must not be after the end date.'));
    });
});

describe('the figures', function () {
    it('shows exact amounts per user and marks the signed-in user\'s own rows', function () {
        finished($this->ada, '2026-11-19 10:00:00+00', 14_197_500);
        finished($this->bob, '2026-11-19 11:00:00+00', 56_550_000);
        $this->provider->usage = PixelforgeUsage::report(['2026-11-19' => [2, '0.070747500']]);

        $this->actingAs($this->ada)->get('/report')->assertInertia(fn (Assert $page) => $page
            ->where('report.reconciliation', 'match')
            ->where('report.generations', 2)
            ->where('report.charged', '$0.088434375') // 17,746,875 + 70,687,500
            ->where('report.providerCost', '$0.070747500')
            ->where('report.profit', '$0.017686875')
            ->where('report.days.0.date', '2026-11-19')
            ->where('report.days.0.rows.0.name', 'Ada')
            ->where('report.days.0.rows.0.isYou', true)
            ->where('report.days.0.rows.0.charged', '$0.017746875')
            ->where('report.days.0.rows.1.name', 'Bob')
            ->where('report.days.0.rows.1.isYou', false)
            ->where('report.days.0.provider.cost', '$0.070747500'));
    });

    it('shows how far apart the numbers are when they differ', function () {
        finished($this->ada, '2026-11-19 10:00:00+00', 10_000_000);
        $this->provider->usage = PixelforgeUsage::report(['2026-11-19' => [1, '0.010000050']]);

        $this->actingAs($this->ada)->get('/report')->assertInertia(fn (Assert $page) => $page
            ->where('report.reconciliation', 'mismatch')
            ->where('report.days.0.reconciliation', 'mismatch')
            ->where('report.days.0.provider.difference', '$0.000000050')
            ->where('report.provider.difference', '$0.000000050'));
    });

    it('still shows our own figures when Pixelforge cannot be reached', function () {
        finished($this->ada, '2026-11-19 10:00:00+00', 10_000_000);
        $this->provider->usageFailure = new ProviderUnavailable('down', 5);

        $this->actingAs($this->ada)->get('/report')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.reconciliation', 'unavailable')
            ->where('report.provider', null)
            ->where('report.days.0.provider', null)
            ->where('report.days.0.providerCost', '$0.010000000'));
    });

    it('does not show email addresses or anything private about other users', function () {
        finished($this->bob, '2026-11-19 10:00:00+00', 10_000_000);

        $json = json_encode($this->actingAs($this->ada)->get('/report')->viewData('page')['props']['report']);

        expect($json)->not->toContain($this->bob->email)->not->toContain('password')->toContain('Bob');
    });
});

describe('money shown on the page', function () {
    it('keeps every decimal and handles a negative amount', function () {
        expect(ReportPresenter::dollars(App\Domain\Money::fromDecimal('0.017746875')))->toBe('$0.017746875')
            ->and(ReportPresenter::dollars(App\Domain\Money::zero()))->toBe('$0.000000000')
            ->and(ReportPresenter::dollars(App\Domain\Money::fromDecimal('-0.000000001')))->toBe('-$0.000000001')
            ->and(ReportPresenter::dollars(App\Domain\Money::fromDecimal('12.5')))->toBe('$12.500000000');
    });
});
