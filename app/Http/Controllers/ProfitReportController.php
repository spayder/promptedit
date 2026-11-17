<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Report\BuildProfitReport;
use App\Http\Presenters\ReportPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per UTC day and user: charged, Pixelforge's cost and profit, checked against
 * Pixelforge's own usage report. Open to every signed-in user: this is a demo
 * app with a handful of known users, not a multi-tenant product.
 */
final class ProfitReportController extends Controller
{
    /** Pixelforge's usage report allows at most 92 days. */
    private const int MAX_DAYS = 92;

    private const int DEFAULT_DAYS = 30;

    public function __invoke(Request $request, BuildProfitReport $build): Response
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $validator = Validator::make($request->query(), [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
        ], [
            'start_date.date_format' => 'Use the format YYYY-MM-DD for the start date.',
            'end_date.date_format' => 'Use the format YYYY-MM-DD for the end date.',
            'end_date.before_or_equal' => 'The end date cannot be in the future.',
        ]);

        $errors = $validator->errors()->all();

        $end = $validator->errors()->has('end_date') || ! $request->query('end_date')
            ? $today
            : CarbonImmutable::createFromFormat('Y-m-d', $request->query('end_date'), 'UTC')->startOfDay();

        $start = $validator->errors()->has('start_date') || ! $request->query('start_date')
            ? $end->subDays(self::DEFAULT_DAYS - 1)
            : CarbonImmutable::createFromFormat('Y-m-d', $request->query('start_date'), 'UTC')->startOfDay();

        if ($start->greaterThan($end)) {
            $errors[] = 'The start date must not be after the end date.';
            $start = $end->subDays(self::DEFAULT_DAYS - 1);
        }

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $errors[] = 'Pixelforge reports at most '.self::MAX_DAYS.' days at a time, so the range was shortened.';
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        $report = $build->handle($start->toDateString(), $end->toDateString());

        return Inertia::render('Report/Index', [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'today' => $today->toDateString(),
                'maxDays' => self::MAX_DAYS,
                'errors' => $errors,
            ],
            'report' => ReportPresenter::present($report, $request->user()->id),
        ]);
    }
}
