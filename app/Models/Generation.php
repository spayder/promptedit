<?php

namespace App\Models;

use App\Domain\Generation\GenerationStatus;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistence model for the `generations` table.
 *
 * It is deliberately thin: it holds the stored columns and their casts. The
 * rules about status and money live in {@see \App\Domain\Generation\Generation};
 * the repository maps between the two. Money columns are plain integer
 * nanodollars (see {@see \App\Domain\Money}).
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property int $estimated_charge_min_nanos
 * @property int $estimated_charge_max_nanos
 * @property int|null $rate_input_text_nanos
 * @property int|null $rate_input_image_nanos
 * @property int|null $rate_output_nanos
 * @property int|null $rate_reasoning_nanos
 * @property int|null $usage_input_text_tokens
 * @property int|null $usage_input_image_tokens
 * @property int|null $usage_output_tokens
 * @property int|null $usage_reasoning_tokens
 * @property int|null $provider_cost_nanos
 * @property int|null $charged_nanos
 * @property string|null $image_url
 * @property \Carbon\CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property \Carbon\CarbonImmutable|null $completed_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    // Rows are only ever written by our own code (the repository and factory),
    // never from request input, so mass assignment is not a concern here.
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'image_url_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
