<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VoiceQualityReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminVoiceQualityController extends Controller
{
    public function index(Request $request, VoiceQualityReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.VoiceQualityReportService::MAX_WINDOW_DAYS,
            ],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (isset($data['days']) && (isset($data['from']) || isset($data['to']))) {
            throw ValidationException::withMessages([
                'days' => 'Choose either days or an explicit from/to date window, not both.',
            ]);
        }

        if (isset($data['from'], $data['to'])) {
            $from = CarbonImmutable::parse($data['from'], 'UTC')->startOfDay();
            $to = CarbonImmutable::parse($data['to'], 'UTC')->endOfDay();
            $inclusiveDays = (int) $from->diffInDays($to->startOfDay()) + 1;

            if ($inclusiveDays > VoiceQualityReportService::MAX_WINDOW_DAYS) {
                throw ValidationException::withMessages([
                    'from' => 'The voice quality date window may not exceed '.VoiceQualityReportService::MAX_WINDOW_DAYS.' days.',
                ]);
            }
        } else {
            $days = (int) ($data['days'] ?? VoiceQualityReportService::DEFAULT_WINDOW_DAYS);
            $to = CarbonImmutable::now('UTC');
            $from = $to->startOfDay()->subDays($days - 1);
        }

        return response()->json(['data' => $reports->report($from, $to)]);
    }
}
