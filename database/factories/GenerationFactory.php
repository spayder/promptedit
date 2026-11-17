<?php

namespace Database\Factories;

use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a `pending` generation. The lifecycle states produce rows that
 * satisfy the table's CHECK constraints. The succeeded state uses a medium
 * 1024x1024 generation at Pixelforge's documented rates:
 *   10 text + 1100 output + 1000 reasoning tokens
 *   = 56_550_000 nanodollars provider cost, 70_687_500 charged (+25%).
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'estimated_charge_min_nanos' => 58_000_000,
            'estimated_charge_max_nanos' => 90_000_000,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.fake()->unique()->bothify('????????'),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Processing,
            'provider_id' => 'gen_'.fake()->unique()->bothify('????????'),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'provider_id' => 'gen_'.fake()->unique()->bothify('????????'),
            'rate_input_text_nanos' => 5_000,
            'rate_input_image_nanos' => 10_000,
            'rate_output_nanos' => 40_000,
            'rate_reasoning_nanos' => 12_500,
            'usage_input_text_tokens' => 10,
            'usage_input_image_tokens' => 0,
            'usage_output_tokens' => 1_100,
            'usage_reasoning_tokens' => 1_000,
            'provider_cost_nanos' => 56_550_000,
            'charged_nanos' => 70_687_500,
            'image_url' => 'https://pixelforge.test/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Failed,
            'provider_id' => 'gen_'.fake()->unique()->bothify('????????'),
            'provider_cost_nanos' => 0,
            'charged_nanos' => 0,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image.',
            'completed_at' => now(),
        ]);
    }
}
