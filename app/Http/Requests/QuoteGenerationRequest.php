<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** What is needed to price a generation. The prompt may still be empty while the user is typing. */
class QuoteGenerationRequest extends FormRequest
{
    public const MAX_PROMPT_LENGTH = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Blank rows in the form are "no image", not an error.
        $this->merge([
            'reference_images' => array_values(array_filter(
                (array) $this->input('reference_images', []),
                fn ($url) => is_string($url) && trim($url) !== '',
            )),
        ]);
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_map('trim', $this->validated('reference_images', []));
    }
}
