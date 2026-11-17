<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** A real submission: the prompt is required and every reference must be a web address. */
class StoreGenerationRequest extends QuoteGenerationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
            // Made when the form was opened. Submitting twice (double click, retry, back button)
            // is then the same generation, not two.
            'submission_id' => ['required', 'uuid'],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference image must be a full web address starting with http:// or https://.',
            'prompt.required' => 'Describe the image you want.',
        ];
    }
}
