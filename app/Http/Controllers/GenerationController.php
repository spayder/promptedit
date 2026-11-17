<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Generation\SubmitGeneration;
use App\Application\Provider\Exceptions\ProviderException;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\Quality;
use App\Http\Presenters\GenerationPresenter;
use App\Http\Requests\QuoteGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController extends Controller
{
    private const PER_PAGE = 12;

    public function create(): Response
    {
        return Inertia::render('Generations/Create', [
            // Identifies this form, so submitting it twice is one generation.
            'submissionId' => (string) Str::uuid(),
            'limits' => [
                'maxPromptLength' => QuoteGenerationRequest::MAX_PROMPT_LENGTH,
                'maxReferenceImages' => QuoteGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'sizes' => array_map(fn (ImageSize $size) => $size->value, ImageSize::cases()),
            'qualities' => array_map(fn (Quality $quality) => $quality->value, Quality::cases()),
        ]);
    }

    public function store(StoreGenerationRequest $request, SubmitGeneration $submit): RedirectResponse
    {
        try {
            $submit->handle(
                userId: $request->user()->id,
                prompt: $request->validated('prompt'),
                quality: Quality::from($request->validated('quality')),
                size: ImageSize::from($request->validated('size')),
                referenceImages: $request->referenceImages(),
                id: $request->validated('submission_id'),
            );
        } catch (UniqueConstraintViolationException) {
            // The same form was submitted twice. The first one is already on its way.
        } catch (ProviderException) {
            // We could not price it (Pixelforge is down and nothing is cached). Nothing was recorded.
            return back()->withInput()->withErrors([
                'prompt' => 'We could not work out the price right now, so nothing was submitted. Please try again in a moment.',
            ]);
        }

        return to_route('generations.index')->with('status', 'Your image is on its way.');
    }

    public function index(Request $request): Response
    {
        $generations = Generation::query()
            ->whereBelongsTo($request->user())
            ->latest()
            ->paginate(self::PER_PAGE)
            ->through(fn (Generation $generation) => GenerationPresenter::present($generation));

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
        ]);
    }
}
