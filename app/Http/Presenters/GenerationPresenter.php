<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Generation\GenerationStatus;
use App\Domain\Money;
use App\Models\Generation;
use Illuminate\Support\Facades\Date;

/**
 * A generation as the history page needs it. The user is shown what they are
 * charged, never what Pixelforge charged us, and never a half-finished picture:
 * the image is only offered while its signed link still works.
 */
final class GenerationPresenter
{
    /** @return array<string, mixed> */
    public static function present(Generation $generation): array
    {
        $finished = $generation->status === GenerationStatus::Succeeded;
        $imageExpired = $finished && $generation->image_url_expires_at !== null
            && $generation->image_url_expires_at->lte(Date::now());

        return [
            'id' => $generation->id,
            'status' => $generation->status->value,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'referenceImageCount' => count($generation->reference_images),
            'estimate' => [
                'min' => MoneyPresenter::roundedDown(Money::nanos($generation->estimated_charge_min_nanos)),
                'max' => MoneyPresenter::roundedUp(Money::nanos($generation->estimated_charge_max_nanos)),
            ],
            'charge' => $generation->charged_nanos === null ? null : [
                'display' => MoneyPresenter::nearest(Money::nanos($generation->charged_nanos)),
                'exact' => MoneyPresenter::exact(Money::nanos($generation->charged_nanos)),
                'free' => $generation->charged_nanos === 0,
            ],
            'imageUrl' => $finished && ! $imageExpired ? $generation->image_url : null,
            'imageExpiresAt' => $finished ? $generation->image_url_expires_at?->toIso8601String() : null,
            'imageExpired' => $imageExpired,
            'error' => $generation->status === GenerationStatus::Failed ? $generation->error_message : null,
            'createdAt' => $generation->created_at->toIso8601String(),
            'completedAt' => $generation->completed_at?->toIso8601String(),
        ];
    }
}
