<?php

use App\Application\Ports\ImageProvider;
use App\Application\Provider\Exceptions\ProviderUnavailable;
use App\Domain\Generation\GenerationStatus;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation as GenerationRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\FakeImageProvider;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
    $this->user = User::factory()->create();
});

function validForm(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => [],
        'submission_id' => (string) Illuminate\Support\Str::uuid(),
    ], $overrides);
}

describe('access', function () {
    it('keeps guests out of every page', function (string $method, string $uri) {
        $this->{$method}($uri)->assertRedirect('/login');
    })->with([['get', '/generate'], ['get', '/history'], ['post', '/generations'], ['post', '/generations/quote']]);

    it('sends the old dashboard address to the generate page', function () {
        $this->actingAs($this->user)->get('/dashboard')->assertRedirect('/generate');
    });
});

describe('the generate page', function () {
    it('offers the options and a fresh submission id', function () {
        $this->actingAs($this->user)->get('/generate')->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Create')
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high'])
            ->where('limits.maxPromptLength', 4000)
            ->where('limits.maxReferenceImages', 4)
            ->whereType('submissionId', 'string'));
    });

    it('gives a different submission id every time it is opened', function () {
        $first = $this->actingAs($this->user)->get('/generate')->viewData('page')['props']['submissionId'];
        $second = $this->actingAs($this->user)->get('/generate')->viewData('page')['props']['submissionId'];

        expect($first)->not->toBe($second);
    });
});

describe('the live price', function () {
    it('returns the range with the markup included, rounded outward', function () {
        $this->actingAs($this->user)
            ->postJson('/generations/quote', ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertOk()
            ->assertExactJson(['min' => '$0.0140', 'max' => '$0.0219']); // 14,000,000 and 21,812,500 nano
    });

    it('prices an empty prompt, so the form shows a price before anything is typed', function () {
        $this->actingAs($this->user)
            ->postJson('/generations/quote', ['size' => '1536x1024', 'quality' => 'high'])
            ->assertOk()
            ->assertJsonStructure(['min', 'max']);
    });

    it('counts reference images, ignoring blank rows', function () {
        $without = $this->actingAs($this->user)->postJson('/generations/quote', ['size' => '1024x1024', 'quality' => 'low'])->json();
        $with = $this->actingAs($this->user)->postJson('/generations/quote', [
            'size' => '1024x1024', 'quality' => 'low', 'reference_images' => ['https://example.com/a.png', '', '  '],
        ])->json();

        expect($with['min'])->not->toBe($without['min']); // one image: +765 tokens
    });

    it('rejects options we do not offer', function (array $bad) {
        $this->actingAs($this->user)->postJson('/generations/quote', array_replace(['size' => '1024x1024', 'quality' => 'low'], $bad))
            ->assertUnprocessable();
    })->with([
        'unknown size' => [['size' => '9x9']],
        'unknown quality' => [['quality' => 'ultra']],
        'too many images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')]],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)]],
    ]);

    it('says so when the price cannot be worked out', function () {
        $this->provider->pricingFailure = new ProviderUnavailable('down', 5);

        $this->actingAs($this->user)
            ->postJson('/generations/quote', ['size' => '1024x1024', 'quality' => 'low'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The price is not available right now.');
    });
});

describe('submitting', function () {
    it('records a pending generation for the user, queues it, and goes to the history', function () {
        $form = validForm();

        $this->actingAs($this->user)->post('/generations', $form)
            ->assertRedirect('/history')
            ->assertSessionHas('status');

        $record = GenerationRecord::query()->findOrFail($form['submission_id']);

        expect($record->user_id)->toBe($this->user->id)
            ->and($record->status)->toBe(GenerationStatus::Pending)
            ->and($record->prompt)->toBe('A lighthouse at dusk')
            ->and($record->estimated_charge_min_nanos)->toBe(14_025_000) // 5 text tokens: 4..6
            ->and($record->estimated_charge_max_nanos)->toBe(21_850_000);

        Queue::assertPushed(SubmitGenerationToProvider::class, fn ($job) => $job->generationId === $form['submission_id']);
    });

    it('stores the reference images, dropping blank rows', function () {
        $form = validForm(['reference_images' => ['https://example.com/a.png', '', ' https://example.com/b.png ']]);

        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/history');

        expect(GenerationRecord::query()->findOrFail($form['submission_id'])->reference_images)
            ->toBe(['https://example.com/a.png', 'https://example.com/b.png']);
    });

    it('is one generation however many times the same form is submitted', function () {
        $form = validForm();

        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/history');
        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/history');
        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/history');

        expect(GenerationRecord::query()->count())->toBe(1);
        Queue::assertPushed(SubmitGenerationToProvider::class, 1);
    });

    it('does not let someone else overwrite a generation by reusing its id', function () {
        $mine = validForm(['prompt' => 'mine']);
        $this->actingAs($this->user)->post('/generations', $mine);

        $other = User::factory()->create();
        $this->actingAs($other)->post('/generations', validForm(['prompt' => 'theirs', 'submission_id' => $mine['submission_id']]));

        expect(GenerationRecord::query()->count())->toBe(1)
            ->and(GenerationRecord::query()->first())->prompt->toBe('mine')->user_id->toBe($this->user->id);
    });

    it('explains what is wrong and records nothing for invalid input', function (array $bad, string $field) {
        $this->actingAs($this->user)->post('/generations', validForm($bad))->assertSessionHasErrors($field);

        expect(GenerationRecord::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'no prompt' => [['prompt' => ''], 'prompt'],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
        'bad size' => [['size' => 'huge'], 'size'],
        'bad quality' => [['quality' => 'perfect'], 'quality'],
        'too many references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
        'a reference that is not a web address' => [['reference_images' => ['not a url']], 'reference_images.0'],
        'a reference with another scheme' => [['reference_images' => ['file:///etc/passwd']], 'reference_images.0'],
        'a reference that is a javascript url' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
        'no submission id' => [['submission_id' => ''], 'submission_id'],
        'a submission id that is not a uuid' => [['submission_id' => '123'], 'submission_id'],
    ]);

    it('submits nothing, and says why, when the price cannot be worked out', function () {
        $this->provider->pricingFailure = new ProviderUnavailable('down', 5);

        $this->actingAs($this->user)->post('/generations', validForm())
            ->assertSessionHasErrors(['prompt' => 'We could not work out the price right now, so nothing was submitted. Please try again in a moment.']);

        expect(GenerationRecord::query()->count())->toBe(0);
    });
});

describe('the history', function () {
    it('lists only the signed-in user\'s generations, newest first', function () {
        $older = GenerationRecord::factory()->for($this->user)->create(['prompt' => 'older', 'created_at' => now()->subHour()]);
        $newer = GenerationRecord::factory()->for($this->user)->create(['prompt' => 'newer']);
        GenerationRecord::factory()->create(['prompt' => 'somebody else']);

        $this->actingAs($this->user)->get('/history')->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.id', $older->id));
    });

    it('pages through long histories', function () {
        GenerationRecord::factory()->count(15)->for($this->user)->create();

        $this->actingAs($this->user)->get('/history')->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 12)
            ->where('generations.last_page', 2));
    });
});

describe('what the history shows about each generation', function () {
    function presented(GenerationRecord $generation): array
    {
        return test()->actingAs($generation->user)->get('/history')->viewData('page')['props']['generations']['data'][0];
    }

    it('shows an estimate rounded outward while nothing is final', function () {
        $pending = GenerationRecord::factory()->for($this->user)->create([
            'estimated_charge_min_nanos' => 14_037_500, 'estimated_charge_max_nanos' => 21_875_000,
        ]);

        expect(presented($pending))
            ->status->toBe('pending')
            ->estimate->toBe(['min' => '$0.0140', 'max' => '$0.0219'])
            ->charge->toBeNull()
            ->imageUrl->toBeNull()
            ->error->toBeNull();
    });

    it('shows the exact charge and the image for a fresh success', function () {
        $done = GenerationRecord::factory()->for($this->user)->succeeded()->create(['charged_nanos' => 17_746_875]);

        expect(presented($done))
            ->status->toBe('succeeded')
            ->charge->toBe(['display' => '$0.0177', 'exact' => '0.017746875', 'free' => false])
            ->imageUrl->toBe('https://pixelforge.test/images/example.png')
            ->imageExpired->toBeFalse();
    });

    it('never shows what Pixelforge charged us', function () {
        $done = GenerationRecord::factory()->for($this->user)->succeeded()->create();

        expect(json_encode(presented($done)))->not->toContain('56550000')->not->toContain('provider');
    });

    it('stops offering the image once its link has expired', function () {
        $old = GenerationRecord::factory()->for($this->user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        expect(presented($old))->imageUrl->toBeNull()->imageExpired->toBeTrue()
            ->and(presented($old)['charge']['display'])->toBe('$0.0707'); // still shows what they paid
    });

    it('says a failure was free and why it failed', function () {
        $failed = GenerationRecord::factory()->for($this->user)->failed()->create();

        expect(presented($failed))
            ->status->toBe('failed')
            ->charge->toBe(['display' => '$0.0000', 'exact' => '0.00', 'free' => true])
            ->error->toBeString()->not->toBeEmpty()
            ->imageUrl->toBeNull()
            ->imageExpired->toBeFalse();
    });
});
