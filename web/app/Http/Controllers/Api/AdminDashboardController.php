<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeanQualityTrace;
use App\Models\BeanUsageRecord;
use App\Models\CalendarEvent;
use App\Models\EarlyAccessSignup;
use App\Models\IssueReport;
use App\Models\Note;
use App\Models\PageViewEvent;
use App\Models\PersonalAccessToken;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class AdminDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $today = now()->startOfDay();
        $week = now()->subDays(6)->startOfDay();
        $month = now()->startOfMonth();
        $growthRange = $this->growthRange((string) $request->query('growth_range', 'last_30_days'));

        return response()->json(['data' => [
            'totals' => [
                'users' => User::count(),
                'workspaces' => Workspace::count(),
                'open_issue_reports' => IssueReport::where('status', 'open')->count(),
            ],
            'business' => $this->businessMetrics($today, $week, $month),
            'traffic' => $this->trafficMetrics($today, $week, $month),
            'activation' => $this->activationMetrics(),
            'app_usage' => $this->appUsageMetrics($today, $week, $month),
            'ai_usage' => $this->aiUsageMetrics($today, $week, $month),
            'bean_quality' => $this->beanQualityMetrics($today),
            'server' => $this->serverHealth(),
            'user_growth_range' => $growthRange,
            'user_growth' => $this->userGrowth($growthRange),
            'daily_activity' => $this->dailyActivity($growthRange),
        ]]);
    }

    private function monthlyRevenueForUser(User $user): float
    {
        $tier = $user->subscriptionTier();
        $interval = $this->billingIntervalForPriceId($user->stripe_price_id);
        $amount = (float) config("services.stripe.plan_amounts.{$tier}.{$interval}", 0);

        return $interval === 'yearly' ? $amount / 12 : $amount;
    }

    private function billingIntervalForPriceId(?string $priceId): string
    {
        if (! $priceId) {
            return 'monthly';
        }

        foreach (config('services.stripe.prices', []) as $configured) {
            if (is_array($configured)) {
                foreach ($configured as $interval => $configuredPriceId) {
                    if ($configuredPriceId && $configuredPriceId === $priceId) {
                        return $interval === 'yearly' ? 'yearly' : 'monthly';
                    }
                }
            } elseif ($configured && $configured === $priceId) {
                return 'monthly';
            }
        }

        return 'monthly';
    }

    private function monthOverMonthGrowthRate(): ?float
    {
        $now = now();
        $currentStart = $now->copy()->startOfMonth();
        $previousStart = $currentStart->copy()->subMonthNoOverflow();
        $previousEnd = $previousStart->copy()->addDays($now->day - 1)->endOfDay();
        if ($previousEnd->month !== $previousStart->month) {
            $previousEnd = $previousStart->copy()->endOfMonth();
        }

        $current = User::where('is_admin', false)->whereBetween('created_at', [$currentStart, $now])->count();
        $previous = User::where('is_admin', false)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        if ($previous === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function pageViewsQuery()
    {
        return PageViewEvent::query()
            ->where(function ($query): void {
                $query->whereNull('user_id')
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('is_admin', false));
            });
    }

    private function uniqueVisitorsSince(Carbon $since): int
    {
        return $this->pageViewsQuery()
            ->where('created_at', '>=', $since)
            ->whereNotNull('visitor_key')
            ->distinct('visitor_key')
            ->count('visitor_key');
    }

    private function topPages(Carbon $since): array
    {
        return $this->pageViewsQuery()
            ->select('path', DB::raw('count(*) as views'), DB::raw('count(distinct visitor_key) as visitors'))
            ->where('created_at', '>=', $since)
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'path' => $row->path,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    private function topTrafficSources(Carbon $since): array
    {
        $sourceExpression = "COALESCE(NULLIF(utm_source, ''), 'direct')";

        return $this->pageViewsQuery()
            ->selectRaw("{$sourceExpression} as source, count(*) as views, count(distinct visitor_key) as visitors")
            ->where('created_at', '>=', $since)
            ->groupByRaw($sourceExpression)
            ->orderByDesc('views')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'source' => $row->source,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    private function lastSeenByUser(): array
    {
        return PersonalAccessToken::query()
            ->select('user_id', DB::raw('max(last_used_at) as last_seen'))
            ->whereNotNull('last_used_at')
            ->groupBy('user_id')
            ->pluck('last_seen', 'user_id')
            ->map(fn ($value) => Carbon::parse($value))
            ->all();
    }

    private function activeUserCount(Carbon $since): int
    {
        $userIds = PersonalAccessToken::query()
            ->where('last_used_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');

        return $userIds->isEmpty()
            ? 0
            : User::whereIn('id', $userIds)->where('is_admin', false)->count();
    }

    private function domainCreatedCounts(Carbon $since): array
    {
        return [
            'tasks' => Task::where('created_at', '>=', $since)->count(),
            'reminders' => Reminder::where('created_at', '>=', $since)->count(),
            'calendar_events' => CalendarEvent::where('created_at', '>=', $since)->count(),
            'notes' => Note::where('created_at', '>=', $since)->count(),
            'workspaces' => Workspace::where('created_at', '>=', $since)->count(),
        ];
    }

    private function dailyActivity(string $range): array
    {
        $days = match ($range) {
            'today' => 1,
            'last_7_days' => 7,
            'all_time' => 90,
            default => 30,
        };
        $start = now()->subDays($days - 1)->startOfDay();
        $pageViews = $this->pageViewsQuery()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('count', 'day');
        $signups = User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->where('is_admin', false)
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('count', 'day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $pageViews, $signups): array {
            $day = $start->copy()->addDays($offset)->toDateString();

            return [
                'day' => $day,
                'page_views' => (int) ($pageViews[$day] ?? 0),
                'signups' => (int) ($signups[$day] ?? 0),
            ];
        })->all();
    }

    private function businessMetrics(Carbon $today, Carbon $week, Carbon $month): array
    {
        $activeSubscriptions = User::query()
            ->whereNotNull('stripe_subscription_id')
            ->where('subscription_status', 'active')
            ->get(['id', 'subscription_tier', 'stripe_price_id', 'created_at']);
        $mrr = $activeSubscriptions->sum(fn (User $user): float => $this->monthlyRevenueForUser($user));

        return [
            'daily_revenue_rate' => round($mrr / 30, 2),
            'weekly_revenue_rate' => round(($mrr / 30) * 7, 2),
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'active_paid_subscriptions' => $activeSubscriptions->count(),
            'trialing_subscriptions' => User::whereNotNull('stripe_subscription_id')->where('subscription_status', 'trialing')->count(),
            'new_paid_today' => $activeSubscriptions->where('created_at', '>=', $today)->count(),
            'new_paid_week' => $activeSubscriptions->where('created_at', '>=', $week)->count(),
            'new_paid_month' => $activeSubscriptions->where('created_at', '>=', $month)->count(),
            'subscription_mix' => collect(['base', 'premium', 'pro'])
                ->mapWithKeys(fn (string $tier): array => [$tier => $activeSubscriptions->where('subscription_tier', $tier)->count()])
                ->all(),
            'month_over_month_growth_rate' => $this->monthOverMonthGrowthRate(),
            'currency' => 'USD',
        ];
    }

    private function trafficMetrics(Carbon $today, Carbon $week, Carbon $month): array
    {
        return [
            'page_views_today' => $this->pageViewsQuery()->where('created_at', '>=', $today)->count(),
            'page_views_week' => $this->pageViewsQuery()->where('created_at', '>=', $week)->count(),
            'page_views_month' => $this->pageViewsQuery()->where('created_at', '>=', $month)->count(),
            'unique_visitors_today' => $this->uniqueVisitorsSince($today),
            'unique_visitors_week' => $this->uniqueVisitorsSince($week),
            'unique_visitors_month' => $this->uniqueVisitorsSince($month),
            'signups_today' => User::where('is_admin', false)->where('created_at', '>=', $today)->count(),
            'signups_week' => User::where('is_admin', false)->where('created_at', '>=', $week)->count(),
            'signups_month' => User::where('is_admin', false)->where('created_at', '>=', $month)->count(),
            'early_access_requests_month' => EarlyAccessSignup::where('created_at', '>=', $month)->count(),
            'top_pages' => $this->topPages($month),
            'top_sources' => $this->topTrafficSources($month),
        ];
    }

    private function activationMetrics(): array
    {
        $lastSeen = $this->lastSeenByUser();
        $users = User::query()->where('is_admin', false)->get(['id', 'created_at', 'subscription_tier']);
        $now = now();
        $inactiveCount = function (int $days) use ($users, $lastSeen, $now): int {
            $cutoff = $now->copy()->subDays($days);

            return $users->filter(function (User $user) use ($lastSeen, $cutoff): bool {
                $seenAt = $lastSeen[$user->id] ?? $user->created_at;

                return ! $seenAt || $seenAt->lt($cutoff);
            })->count();
        };

        return [
            'total_app_users' => $users->count(),
            'active_users_today' => $this->activeUserCount($now->copy()->startOfDay()),
            'active_users_7_days' => $this->activeUserCount($now->copy()->subDays(6)->startOfDay()),
            'active_users_30_days' => $this->activeUserCount($now->copy()->subDays(29)->startOfDay()),
            'inactive_users_3_days' => $inactiveCount(3),
            'inactive_users_10_days' => $inactiveCount(10),
            'inactive_users_30_days' => $inactiveCount(30),
            'verified_users' => User::whereNotNull('email_verified_at')->where('is_admin', false)->count(),
            'onboarded_users' => User::where('onboard_complete', true)->where('is_admin', false)->count(),
        ];
    }

    private function appUsageMetrics(Carbon $today, Carbon $week, Carbon $month): array
    {
        return [
            'created_today' => $this->domainCreatedCounts($today),
            'created_week' => $this->domainCreatedCounts($week),
            'created_month' => $this->domainCreatedCounts($month),
        ];
    }

    private function aiUsageMetrics(Carbon $today, Carbon $week, Carbon $month): array
    {
        if (! Schema::hasTable('bean_usage_records')) {
            return [
                'today' => $this->emptyUsagePeriod(),
                'week' => $this->emptyUsagePeriod(),
                'month' => $this->emptyUsagePeriod(),
                'pricing_assumptions' => $this->aiUsagePricingAssumptions(),
            ];
        }

        return [
            'today' => $this->usagePeriodMetrics($today),
            'week' => $this->usagePeriodMetrics($week),
            'month' => $this->usagePeriodMetrics($month),
            'pricing_assumptions' => $this->aiUsagePricingAssumptions(),
        ];
    }

    private function usagePeriodMetrics(Carbon $since): array
    {
        $records = BeanUsageRecord::query()
            ->with('user:id,name,email,subscription_tier')
            ->where('recorded_at', '>=', $since)
            ->get();
        $openAi = $records->where('provider', 'openai');
        $elevenLabs = $records->where('provider', 'elevenlabs');
        $landingRecords = $records->filter(fn (BeanUsageRecord $record): bool => $this->isLandingUsageRecord($record));
        $productRecords = $records->reject(fn (BeanUsageRecord $record): bool => $this->isLandingUsageRecord($record));

        return [
            'records' => $records->count(),
            'estimated_cost_usd' => round((float) $records->sum('estimated_cost_usd'), 4),
            'openai' => [
                'requests' => $openAi->count(),
                'input_tokens' => (int) $openAi->sum('input_tokens'),
                'output_tokens' => (int) $openAi->sum('output_tokens'),
                'total_tokens' => (int) $openAi->sum('total_tokens'),
                'estimated_cost_usd' => round((float) $openAi->sum('estimated_cost_usd'), 4),
                'by_model' => $openAi->groupBy(fn (BeanUsageRecord $record): string => (string) ($record->model ?: 'unknown'))
                    ->map(fn ($modelRecords): array => [
                        'requests' => $modelRecords->count(),
                        'input_tokens' => (int) $modelRecords->sum('input_tokens'),
                        'output_tokens' => (int) $modelRecords->sum('output_tokens'),
                        'total_tokens' => (int) $modelRecords->sum('total_tokens'),
                        'estimated_cost_usd' => round((float) $modelRecords->sum('estimated_cost_usd'), 4),
                    ])->all(),
            ],
            'elevenlabs' => [
                'sessions' => $elevenLabs->where('usage_type', 'voice_session')->count(),
                'voice_seconds' => round((float) $elevenLabs->where('usage_type', 'voice_session')->sum('quantity'), 1),
                'voice_minutes' => round(((float) $elevenLabs->where('usage_type', 'voice_session')->sum('quantity')) / 60, 2),
                'credits' => round((float) $elevenLabs->sum('credits'), 1),
                'estimated_cost_usd' => round((float) $elevenLabs->sum('estimated_cost_usd'), 4),
            ],
            'product_app' => $this->usageSegmentMetrics($productRecords),
            'landing_page' => $this->usageSegmentMetrics($landingRecords),
            'segments' => [
                'product_app' => $this->usageSegmentMetrics($productRecords),
                'landing_page' => $this->usageSegmentMetrics($landingRecords),
            ],
            'by_source' => $records->groupBy(fn (BeanUsageRecord $record): string => (string) ($record->source ?: 'unknown'))
                ->map(fn ($sourceRecords): array => [
                    'records' => $sourceRecords->count(),
                    'estimated_cost_usd' => round((float) $sourceRecords->sum('estimated_cost_usd'), 4),
                    'tokens' => (int) $sourceRecords->sum('total_tokens'),
                    'voice_seconds' => round((float) $sourceRecords->where('provider', 'elevenlabs')->sum('quantity'), 1),
                    'credits' => round((float) $sourceRecords->sum('credits'), 1),
                ])->all(),
            'top_users' => $records->groupBy('user_id')
                ->map(function ($userRecords, $userId): array {
                    $first = $userRecords->first();
                    $user = $first?->user;

                    return [
                        'user_id' => $userId ? (int) $userId : null,
                        'name' => $user?->name,
                        'email' => $user?->email,
                        'tier' => $user?->subscription_tier,
                        'estimated_cost_usd' => round((float) $userRecords->sum('estimated_cost_usd'), 4),
                        'openai_tokens' => (int) $userRecords->where('provider', 'openai')->sum('total_tokens'),
                        'elevenlabs_voice_seconds' => round((float) $userRecords->where('provider', 'elevenlabs')->sum('quantity'), 1),
                        'elevenlabs_credits' => round((float) $userRecords->where('provider', 'elevenlabs')->sum('credits'), 1),
                    ];
                })
                ->sortByDesc('estimated_cost_usd')
                ->values()
                ->take(8)
                ->all(),
        ];
    }

    private function emptyUsagePeriod(): array
    {
        return [
            'records' => 0,
            'estimated_cost_usd' => 0,
            'openai' => ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'estimated_cost_usd' => 0, 'by_model' => []],
            'elevenlabs' => ['sessions' => 0, 'voice_seconds' => 0, 'voice_minutes' => 0, 'credits' => 0, 'estimated_cost_usd' => 0],
            'product_app' => $this->emptyUsageSegment(),
            'landing_page' => $this->emptyUsageSegment(),
            'segments' => ['product_app' => $this->emptyUsageSegment(), 'landing_page' => $this->emptyUsageSegment()],
            'by_source' => [],
            'top_users' => [],
        ];
    }

    private function emptyUsageSegment(): array
    {
        return [
            'records' => 0,
            'estimated_cost_usd' => 0,
            'openai' => ['requests' => 0, 'total_tokens' => 0, 'estimated_cost_usd' => 0],
            'elevenlabs' => ['sessions' => 0, 'voice_seconds' => 0, 'voice_minutes' => 0, 'credits' => 0, 'estimated_cost_usd' => 0],
        ];
    }

    private function usageSegmentMetrics($records): array
    {
        $openAi = $records->where('provider', 'openai');
        $elevenLabsVoice = $records->where('provider', 'elevenlabs')->where('usage_type', 'voice_session');
        $voiceSeconds = (float) $elevenLabsVoice->sum('quantity');

        return [
            'records' => $records->count(),
            'estimated_cost_usd' => round((float) $records->sum('estimated_cost_usd'), 4),
            'openai' => [
                'requests' => $openAi->count(),
                'total_tokens' => (int) $openAi->sum('total_tokens'),
                'estimated_cost_usd' => round((float) $openAi->sum('estimated_cost_usd'), 4),
            ],
            'elevenlabs' => [
                'sessions' => $elevenLabsVoice->count(),
                'voice_seconds' => round($voiceSeconds, 1),
                'voice_minutes' => round($voiceSeconds / 60, 2),
                'credits' => round((float) $elevenLabsVoice->sum('credits'), 1),
                'estimated_cost_usd' => round((float) $elevenLabsVoice->sum('estimated_cost_usd'), 4),
            ],
        ];
    }

    private function isLandingUsageRecord(BeanUsageRecord $record): bool
    {
        $source = (string) ($record->source ?: '');
        $service = (string) ($record->service ?: '');

        return $source === 'landing_page'
            || str_contains($service, 'landing')
            || data_get($record->metadata, 'segment') === 'public_landing';
    }

    private function aiUsagePricingAssumptions(): array
    {
        return [
            'elevenlabs_agent_cost_per_minute_usd' => (float) config('bean.usage.elevenlabs_agent_cost_per_minute_usd', 0.08),
            'elevenlabs_agent_credits_per_minute' => (float) config('bean.usage.elevenlabs_agent_credits_per_minute', 10000 / 15),
            'elevenlabs_max_duration_seconds' => (int) config('bean.usage.elevenlabs_max_duration_seconds', 60),
            'elevenlabs_silence_timeout_seconds' => (int) config('bean.usage.elevenlabs_silence_timeout_seconds', 5),
            'elevenlabs_initial_wait_seconds' => (int) config('bean.usage.elevenlabs_initial_wait_seconds', 5),
            'elevenlabs_silence_end_call_seconds' => (int) config('bean.usage.elevenlabs_silence_end_call_seconds', 12),
            'elevenlabs_followup_idle_close_seconds' => (int) config('bean.usage.elevenlabs_followup_idle_close_seconds', 12),
            'openai_token_method' => 'estimated until provider token usage is available from the Hermes child process',
        ];
    }

    private function beanQualityMetrics(Carbon $today): array
    {
        $since = now()->subDay();
        $traces = BeanQualityTrace::query()
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(500)
            ->get();
        $flagged = $traces->filter(fn (BeanQualityTrace $trace): bool => count($trace->quality_flags ?? []) > 0)->values();
        $flagCounts = $traces
            ->flatMap(fn (BeanQualityTrace $trace): array => $trace->quality_flags ?? [])
            ->countBy()
            ->sortDesc();
        $total = $traces->count();
        $clean = max(0, $total - $flagged->count());
        $score = $total > 0 ? round(($clean / $total) * 100, 1) : null;

        return [
            'status' => $flagged->isNotEmpty() ? 'watch' : 'healthy',
            'score_24h' => $score,
            'traces_24h' => $total,
            'flagged_24h' => $flagged->count(),
            'voice_traces_24h' => $traces->where('voice', true)->count(),
            'average_latency_ms_24h' => $total > 0 ? (int) round($traces->avg('latency_ms')) : null,
            'top_quality_flags' => $flagCounts->keys()->take(5)->values()->all(),
            'quality_flag_counts' => $flagCounts->take(8)->all(),
            'recent_flagged_runs' => $flagged->take(5)->map(fn (BeanQualityTrace $trace): array => [
                'bean_run_id' => $trace->bean_run_id,
                'intent' => $trace->intent,
                'quality_flags' => $trace->quality_flags ?? [],
                'assistant_answer' => $trace->assistant_answer,
                'created_at' => $trace->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function directoryBytes(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (RuntimeException) {
            return $bytes;
        }

        return $bytes;
    }

    private function databaseBytes(): ?int
    {
        if (config('database.default') !== 'sqlite') {
            return null;
        }
        $path = (string) config('database.connections.sqlite.database');

        return is_file($path) ? filesize($path) : null;
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function availableCpuCores(): ?int
    {
        if (! function_exists('shell_exec')) {
            return null;
        }
        $cores = trim((string) @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));

        return ctype_digit($cores) && (int) $cores > 0 ? (int) $cores : null;
    }

    private function serverHealth(): array
    {
        $diskTotal = @disk_total_space(base_path()) ?: 0;
        $diskFree = @disk_free_space(base_path()) ?: 0;
        $diskUsed = max(0, $diskTotal - $diskFree);
        $diskUsedPercent = $diskTotal > 0 ? ($diskUsed / $diskTotal) * 100 : null;
        $memoryLimit = $this->memoryLimitBytes();
        $memoryPeak = memory_get_peak_usage(true);
        $memoryPeakPercent = $memoryLimit > 0 ? ($memoryPeak / $memoryLimit) * 100 : null;
        $loadAverage = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        $cpuCores = $this->availableCpuCores();
        $queueDepth = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $signals = [];

        if ($diskUsedPercent !== null && $diskUsedPercent >= 85) {
            $signals[] = ['severity' => 'critical', 'message' => 'Disk usage is above 85%. Increase storage or prune logs/backups.'];
        } elseif ($diskUsedPercent !== null && $diskUsedPercent >= 70) {
            $signals[] = ['severity' => 'warning', 'message' => 'Disk usage is above 70%. Watch storage growth.'];
        }
        if ($memoryPeakPercent !== null && $memoryPeakPercent >= 80) {
            $signals[] = ['severity' => 'warning', 'message' => 'PHP peak memory is above 80% of the configured limit.'];
        }
        if ($loadAverage && $cpuCores && (float) $loadAverage[0] >= $cpuCores * 1.5) {
            $signals[] = ['severity' => 'warning', 'message' => 'Server load is high for the available CPU cores.'];
        }
        if ($queueDepth !== null && $queueDepth >= 100) {
            $signals[] = ['severity' => 'warning', 'message' => 'Queue backlog is above 100 jobs.'];
        }
        if ($failedJobs !== null && $failedJobs > 0) {
            $signals[] = ['severity' => 'warning', 'message' => "{$failedJobs} failed queue job".($failedJobs === 1 ? '' : 's').' need review.'];
        }

        $status = collect($signals)->contains(fn (array $signal): bool => $signal['severity'] === 'critical')
            ? 'critical'
            : (count($signals) ? 'watch' : 'healthy');

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'disk' => [
                'total_bytes' => $diskTotal,
                'free_bytes' => $diskFree,
                'used_bytes' => $diskUsed,
                'used_percent' => $diskUsedPercent === null ? null : round($diskUsedPercent, 1),
                'storage_bytes' => $this->directoryBytes(storage_path()),
                'database_bytes' => $this->databaseBytes(),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'memory_limit_bytes' => $memoryLimit,
                'memory_peak_bytes' => $memoryPeak,
                'memory_peak_percent' => $memoryPeakPercent === null ? null : round($memoryPeakPercent, 1),
            ],
            'load_average' => $loadAverage ? [
                'one_minute' => round((float) $loadAverage[0], 2),
                'five_minutes' => round((float) $loadAverage[1], 2),
                'fifteen_minutes' => round((float) $loadAverage[2], 2),
                'cpu_cores' => $cpuCores,
            ] : null,
            'queue' => [
                'connection' => config('queue.default'),
                'pending_jobs' => $queueDepth,
                'failed_jobs' => $failedJobs,
            ],
            'signals' => $signals,
        ];
    }

    private function growthRange(string $range): string
    {
        return in_array($range, ['today', 'last_7_days', 'last_30_days', 'all_time'], true)
            ? $range
            : 'last_30_days';
    }

    private function userGrowth(string $range): array
    {
        if ($range === 'all_time') {
            return $this->allTimeUserGrowth();
        }
        $days = match ($range) {
            'today' => 1,
            'last_7_days' => 7,
            default => 30,
        };
        $start = now()->subDays($days - 1)->startOfDay();
        $dailySignups = User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as signups')
            ->where('is_admin', false)
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('signups', 'day');
        $runningTotal = User::query()->where('is_admin', false)->where('created_at', '<', $start)->count();

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $dailySignups, &$runningTotal): array {
            $day = $start->copy()->addDays($offset)->toDateString();
            $signups = (int) ($dailySignups[$day] ?? 0);
            $runningTotal += $signups;

            return ['day' => $day, 'new_users' => $signups, 'total_users' => $runningTotal];
        })->all();
    }

    private function allTimeUserGrowth(): array
    {
        $firstUser = User::query()->where('is_admin', false)->oldest('created_at')->first(['created_at']);
        if (! $firstUser?->created_at) {
            return [];
        }
        $start = $firstUser->created_at->copy()->startOfMonth();
        $end = now()->startOfMonth();
        $months = max(1, (int) $start->diffInMonths($end) + 1);
        $monthlySignups = User::query()
            ->where('is_admin', false)
            ->pluck('created_at')
            ->map(fn ($createdAt): string => $createdAt->copy()->startOfMonth()->toDateString())
            ->countBy();
        $runningTotal = 0;

        return collect(range(0, $months - 1))->map(function (int $offset) use ($start, $monthlySignups, &$runningTotal): array {
            $day = $start->copy()->addMonths($offset)->toDateString();
            $signups = (int) ($monthlySignups[$day] ?? 0);
            $runningTotal += $signups;

            return ['day' => $day, 'new_users' => $signups, 'total_users' => $runningTotal];
        })->all();
    }
}
