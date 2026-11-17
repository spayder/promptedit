<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Generation\QuoteGeneration;
use App\Application\Provider\Exceptions\ProviderException;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Http\Presenters\MoneyPresenter;
use App\Http\Requests\QuoteGenerationRequest;
use Illuminate\Http\JsonResponse;

/**
 * The price shown while the form is being filled in. It uses the same
 * QuoteGeneration as submitting does, so the number on screen is the number recorded.
 */
final class GenerationQuoteController extends Controller
{
    public function __invoke(QuoteGenerationRequest $request, QuoteGeneration $quotes): JsonResponse
    {
        try {
            $estimate = $quotes->quote(
                (string) $request->validated('prompt', ''),
                Quality::from($request->validated('quality')),
                ImageSize::from($request->validated('size')),
                count($request->referenceImages()),
            );
        } catch (ProviderException) {
            return response()->json(['message' => 'The price is not available right now.'], 503);
        }

        return response()->json([
            'min' => MoneyPresenter::roundedDown($estimate->min),
            'max' => MoneyPresenter::roundedUp($estimate->max),
        ]);
    }
}
