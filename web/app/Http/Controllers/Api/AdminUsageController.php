<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\EarlyAccessSignup;
use App\Models\IssueReport;
use App\Models\Note;
use App\Models\PageViewEvent;
use App\Models\PersonalAccessToken;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class AdminUsageController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings) {}

    public function summary(Request $request): JsonResponse
    {
        $today = now()->startOfDay();
        $week = now()->subDays(6)->startOfDay();
        $month = now()->startOfMonth();
        $logs = AiUsageLog::query();
        $userGrowthRange = $this->userGrowthRange((string) $request->query('user_growth_range', 'last_30_days'));

        return response()->json(['data' => [
            'totals' => [
                'users' => User::count(),
                'active_users_today' => $this->activeUserCount(now()->startOfDay()),
                'workspaces' => Workspace::count(),
                'ai_actions_today' => (clone $logs)->where('created_at', '>=', $today)->count(),
                'ai_actions_month' => (clone $logs)->where('created_at', '>=', $month)->count(),
                'tokens_today' => (int) (clone $logs)->where('created_at', '>=', $today)->sum('total_tokens'),
                'tokens_month' => (int) (clone $logs)->where('created_at', '>=', $month)->sum('total_tokens'),
                'tool_calls_today' => (int) (clone $logs)->where('created_at', '>=', $today)->sum('tool_call_count'),
                'tool_calls_month' => (int) (clone $logs)->where('created_at', '>=', $month)->sum('tool_call_count'),
                'cost_today' => round((float) (clone $logs)->where('created_at', '>=', $today)->sum('estimated_cost_usd'), 4),
                'cost_month' => round((float) (clone $logs)->where('created_at', '>=', $month)->sum('estimated_cost_usd'), 4),
                'open_alerts' => AiUsageAlert::whereNull('acknowledged_at')->count(),
                'open_issue_reports' => IssueReport::where('status', 'open')->count(),
                'archived_issue_reports' => IssueReport::where('status', 'closed')->count(),
            ],
            'business' => $this->businessMetrics($today, $week, $month),
            'traffic' => $this->trafficMetrics($today, $week, $month),
            'activation' => $this->activationMetrics(),
            'app_usage' => $this->appUsageMetrics($today, $week, $month),
            'server' => $this->serverHealth($today),
            'by_model' => $this->groupedUsage('model', $month),
            'by_route_tier' => $this->groupedUsage('route_tier', $month),
            'by_request_type' => $this->groupedUsage('request_type', $month),
            'user_growth_range' => $userGrowthRange,
            'user_growth' => $this->userGrowth($userGrowthRange),
            'daily_activity' => $this->dailyActivity($userGrowthRange),
            'top_users' => $this->topUsers($month),
            'top_workspaces' => $this->topWorkspaces($month),
            'recent_logs' => $this->logsQuery()->limit(25)->get()->map(fn (AiUsageLog $log): array => $this->logPayload($log))->values(),
            'alerts' => $this->alertsQuery()->limit(20)->get(),
            'issue_reports' => $this->issueReportsQuery('open')->limit(20)->get(),
            'archived_issue_reports' => $this->issueReportsQuery('closed')->limit(100)->get(),
            'settings' => $this->settings->payload(),
        ]]);
    }

    public function logs(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 100)));

        return response()->json(['data' => $this->logsQuery()->limit($limit)->get()->map(fn (AiUsageLog $log): array => $this->logPayload($log))->values()]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 100)));

        return response()->json(['data' => $this->alertsQuery()->limit($limit)->get()]);
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

        $current = User::where('is_admin', false)->where('created_at', '>=', $currentStart)->where('created_at', '<=', $now)->count();
        $previous = User::where('is_admin', false)->where('created_at', '>=', $previousStart)->where('created_at', '<=', $previousEnd)->count();
        if ($previous === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function trafficPageViewsQuery()
    {
        return PageViewEvent::query()
            ->where(function ($query): void {
                $query->whereNull('user_id')
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('is_admin', false));
            });
    }

    private function uniqueVisitorsSince(Carbon $since): int
    {
        return $this->trafficPageViewsQuery()
            ->where('created_at', '>=', $since)
            ->whereNotNull('visitor_key')
            ->distinct('visitor_key')
            ->count('visitor_key');
    }

    private function topPages(Carbon $since): array
    {
        return $this->trafficPageViewsQuery()
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

        return $this->trafficPageViewsQuery()
            ->selectRaw("COALESCE(NULLIF(utm_source, ''), 'direct') as source, count(*) as views, count(distinct visitor_key) as visitors")
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
        $lastSeen = [];
        $merge = function ($rows) use (&$lastSeen): void {
            foreach ($rows as $userId => $value) {
                $seenAt = $value ? Carbon::parse($value) : null;
                if (! $seenAt) {
                    continue;
                }
                if (! isset($lastSeen[$userId]) || $seenAt->gt($lastSeen[$userId])) {
                    $lastSeen[$userId] = $seenAt;
                }
            }
        };

        $merge(PersonalAccessToken::query()
            ->select('user_id', DB::raw('max(last_used_at) as last_seen'))
            ->whereNotNull('last_used_at')
            ->groupBy('user_id')
            ->pluck('last_seen', 'user_id'));
        $merge(AiUsageLog::query()
            ->select('user_id', DB::raw('max(created_at) as last_seen'))
            ->groupBy('user_id')
            ->pluck('last_seen', 'user_id'));
        $merge(ActivityEvent::query()
            ->select('user_id', DB::raw('max(created_at) as last_seen'))
            ->groupBy('user_id')
            ->pluck('last_seen', 'user_id'));

        return $lastSeen;
    }

    private function activeUserCount(Carbon $since): int
    {
        $tokenUsers = PersonalAccessToken::query()
            ->where('last_used_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');
        $aiUsers = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');
        $activityUsers = ActivityEvent::query()
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('user_id');

        $userIds = $tokenUsers->merge($aiUsers)->merge($activityUsers)->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            return 0;
        }

        return User::whereIn('id', $userIds)->where('is_admin', false)->count();
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

    private function aiSuccessRate(Carbon $since): ?float
    {
        $total = AiUsageLog::where('created_at', '>=', $since)->count();
        if ($total === 0) {
            return null;
        }

        $successful = AiUsageLog::where('created_at', '>=', $since)
            ->whereIn('status', ['completed', 'ok', 'success'])
            ->count();

        return round(($successful / $total) * 100, 1);
    }

    private function averageAiLatencyMs(Carbon $since): ?int
    {
        $latencies = AiUsageLog::where('created_at', '>=', $since)
            ->get(['metadata'])
            ->map(function (AiUsageLog $log): ?int {
                $metadata = $log->metadata ?? [];
                foreach ([
                    data_get($metadata, 'duration_ms'),
                    data_get($metadata, 'latency_ms'),
                    data_get($metadata, 'request_latency_ms'),
                    data_get($metadata, 'total_latency_ms'),
                    data_get($metadata, 'elapsed_ms'),
                    data_get($metadata, 'model_call_ms'),
                    data_get($metadata, 'tool_execution_ms'),
                ] as $value) {
                    if (is_numeric($value) && (float) $value > 0) {
                        return (int) round((float) $value);
                    }
                }

                return null;
            })
            ->filter();

        return $latencies->isEmpty() ? null : (int) round($latencies->avg());
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
        $aiActions = AiUsageLog::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('count', 'day');
        $pageViews = $this->trafficPageViewsQuery()
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

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $aiActions, $pageViews, $signups): array {
            $day = $start->copy()->addDays($offset)->toDateString();

            return [
                'day' => $day,
                'page_views' => (int) ($pageViews[$day] ?? 0),
                'ai_actions' => (int) ($aiActions[$day] ?? 0),
                'signups' => (int) ($signups[$day] ?? 0),
            ];
        })->all();
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
        if (function_exists('shell_exec')) {
            $cores = trim((string) @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));
            if (ctype_digit($cores) && (int) $cores > 0) {
                return (int) $cores;
            }
        }

        return null;
    }

    private function groupedUsage(string $column, mixed $since)
    {
        return AiUsageLog::query()
            ->select($column, DB::raw('count(*) as actions'), DB::raw('sum(total_tokens) as tokens'), DB::raw('sum(estimated_cost_usd) as cost'))
            ->where('created_at', '>=', $since)
            ->groupBy($column)
            ->orderByDesc('cost')
            ->get()
            ->map(fn (AiUsageLog $row): array => [
                'key' => $row->getAttribute($column) ?: 'unknown',
                'actions' => (int) $row->getAttribute('actions'),
                'tokens' => (int) $row->getAttribute('tokens'),
                'cost' => round((float) $row->getAttribute('cost'), 4),
            ]);
    }

    private function businessMetrics(Carbon $today, Carbon $week, Carbon $month): array
    {
        $activeSubscriptions = User::query()
            ->whereNotNull('stripe_subscription_id')
            ->where('subscription_status', 'active')
            ->get(['id', 'subscription_tier', 'stripe_price_id', 'created_at']);

        $mrr = $activeSubscriptions->sum(fn (User $user): float => $this->monthlyRevenueForUser($user));
        $trialing = User::query()
            ->whereNotNull('stripe_subscription_id')
            ->where('subscription_status', 'trialing')
            ->count();
        $activePaidToday = $activeSubscriptions->where('created_at', '>=', $today)->count();
        $activePaidWeek = $activeSubscriptions->where('created_at', '>=', $week)->count();
        $activePaidMonth = $activeSubscriptions->where('created_at', '>=', $month)->count();

        return [
            'daily_revenue_rate' => round($mrr / 30, 2),
            'weekly_revenue_rate' => round(($mrr / 30) * 7, 2),
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'active_paid_subscriptions' => $activeSubscriptions->count(),
            'trialing_subscriptions' => $trialing,
            'new_paid_today' => $activePaidToday,
            'new_paid_week' => $activePaidWeek,
            'new_paid_month' => $activePaidMonth,
            'new_paid_basis' => 'active paid subscriptions whose user account was created in the period',
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
            'page_views_today' => $this->trafficPageViewsQuery()->where('created_at', '>=', $today)->count(),
            'page_views_week' => $this->trafficPageViewsQuery()->where('created_at', '>=', $week)->count(),
            'page_views_month' => $this->trafficPageViewsQuery()->where('created_at', '>=', $month)->count(),
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
        $users = User::query()->where('is_admin', false)->get(['id', 'created_at', 'subscription_tier', 'subscription_status']);
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
            'base_users' => $users->where('subscription_tier', 'base')->count(),
            'premium_users' => $users->where('subscription_tier', 'premium')->count(),
            'pro_users' => $users->where('subscription_tier', 'pro')->count(),
        ];
    }

    private function appUsageMetrics(Carbon $today, Carbon $week, Carbon $month): array
    {
        return [
            'created_today' => $this->domainCreatedCounts($today),
            'created_week' => $this->domainCreatedCounts($week),
            'created_month' => $this->domainCreatedCounts($month),
            'chat_messages_today' => ConversationMessage::where('created_at', '>=', $today)->count(),
            'chat_messages_week' => ConversationMessage::where('created_at', '>=', $week)->count(),
            'chat_messages_month' => ConversationMessage::where('created_at', '>=', $month)->count(),
            'activity_events_today' => ActivityEvent::where('created_at', '>=', $today)->count(),
            'activity_events_week' => ActivityEvent::where('created_at', '>=', $week)->count(),
            'activity_events_month' => ActivityEvent::where('created_at', '>=', $month)->count(),
            'success_rate_today' => $this->aiSuccessRate($today),
            'success_rate_month' => $this->aiSuccessRate($month),
            'avg_request_latency_ms' => $this->averageAiLatencyMs($today),
        ];
    }

    private function serverHealth(Carbon $today): array
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
        $recentBlocked = AiUsageLog::where('created_at', '>=', $today)->where('status', 'blocked')->count();
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
            $signals[] = ['severity' => 'warning', 'message' => 'Server load is high for the available CPU cores. Add capacity or reduce background work.'];
        }
        if ($queueDepth !== null && $queueDepth >= 100) {
            $signals[] = ['severity' => 'warning', 'message' => 'Queue backlog is above 100 jobs. Add workers or check failing jobs.'];
        }
        if ($failedJobs !== null && $failedJobs > 0) {
            $signals[] = ['severity' => 'warning', 'message' => "{$failedJobs} failed queue job".($failedJobs === 1 ? '' : 's').' need review.'];
        }
        if ($recentBlocked > 0) {
            $signals[] = ['severity' => 'warning', 'message' => "{$recentBlocked} AI request".($recentBlocked === 1 ? '' : 's').' hit a limit today.'];
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

    private function userGrowthRange(string $range): string
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

            return [
                'day' => $day,
                'new_users' => $signups,
                'total_users' => $runningTotal,
            ];
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

            return [
                'day' => $day,
                'new_users' => $signups,
                'total_users' => $runningTotal,
            ];
        })->all();
    }

    private function topUsers(mixed $since)
    {
        return AiUsageLog::query()
            ->join('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->select('users.id', 'users.name', 'users.email', 'users.subscription_tier', DB::raw('count(*) as actions'), DB::raw('sum(total_tokens) as tokens'), DB::raw('sum(estimated_cost_usd) as cost'))
            ->where('ai_usage_logs.created_at', '>=', $since)
            ->groupBy('users.id', 'users.name', 'users.email', 'users.subscription_tier')
            ->orderByDesc('cost')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'subscription_tier' => $row->subscription_tier,
                'actions' => (int) $row->actions,
                'tokens' => (int) $row->tokens,
                'cost' => round((float) $row->cost, 4),
            ]);
    }

    private function topWorkspaces(mixed $since)
    {
        return AiUsageLog::query()
            ->leftJoin('workspaces', 'workspaces.id', '=', 'ai_usage_logs.workspace_id')
            ->select('workspaces.id', 'workspaces.name', 'workspaces.type', DB::raw('count(*) as actions'), DB::raw('sum(total_tokens) as tokens'), DB::raw('sum(estimated_cost_usd) as cost'))
            ->where('ai_usage_logs.created_at', '>=', $since)
            ->groupBy('workspaces.id', 'workspaces.name', 'workspaces.type')
            ->orderByDesc('cost')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'name' => $row->name ?: 'Unknown workspace',
                'type' => $row->type,
                'actions' => (int) $row->actions,
                'tokens' => (int) $row->tokens,
                'cost' => round((float) $row->cost, 4),
            ]);
    }

    private function logsQuery()
    {
        return AiUsageLog::query()
            ->with([
                'user:id,name,email,subscription_tier',
                'workspace:id,name,type',
                'conversationMessage:id,role,content,metadata',
            ])
            ->latest('id');
    }

    private function alertsQuery()
    {
        return AiUsageAlert::query()
            ->with(['user:id,name,email', 'workspace:id,name,type'])
            ->latest('id');
    }

    private function issueReportsQuery(string $status)
    {
        return IssueReport::query()
            ->with(['user:id,name,email', 'workspace:id,name,type'])
            ->where('status', $status)
            ->latest('id');
    }

    private function logPayload(AiUsageLog $log): array
    {
        $payload = $log->toArray();
        $request = trim((string) ($log->conversationMessage?->content ?? ''));
        $actionTypes = collect($log->action_types ?? [])
            ->map(fn (mixed $action): string => trim((string) $action))
            ->filter()
            ->values();

        $payload['request_preview'] = $request !== '' ? str($request)->limit(140)->toString() : null;
        $payload['request_full'] = $request !== '' ? $request : null;
        $payload['input_prompt_full'] = data_get($log->metadata, 'input_prompt') ?: $payload['request_full'];
        $payload['use_case'] = $this->useCaseForLog($log, $request, $actionTypes->all());
        $payload['action_summary'] = $actionTypes->isNotEmpty()
            ? $actionTypes->map(fn (string $action): string => $this->humanActionName($action))->unique()->take(4)->implode(', ')
            : null;

        return $payload;
    }

    private function useCaseForLog(AiUsageLog $log, string $request, array $actionTypes): string
    {
        $actions = implode(' ', $actionTypes);
        if (preg_match('/\b(external_lookup|weather|flight|store|stock|sports|traffic|news|price)\b/i', $actions) === 1) {
            return 'Live external lookup';
        }
        if (preg_match('/\b(calendar|event|day_context|get_day_context)\b/i', $actions) === 1) {
            return 'Calendar planning';
        }
        if (preg_match('/\btask\b/i', $actions) === 1) {
            return 'Task management';
        }
        if (preg_match('/\breminder\b/i', $actions) === 1) {
            return 'Reminder management';
        }
        if (preg_match('/\b(agent_profile|onboarding|preference|workspace_memory|memory)\b/i', $actions) === 1) {
            return 'Bean setup and memory';
        }
        if (preg_match('/\b(approval|blocker)\b/i', $actions) === 1) {
            return 'Workflow management';
        }

        $text = mb_strtolower($request);

        return match (true) {
            preg_match('/\b(weather|forecast|temperature|flight|store|hours|stock|price|sports|traffic|news)\b/', $text) === 1 => 'Live external lookup',
            preg_match('/\b(calendar|schedule|event|meeting|appointment|today|tomorrow|this week)\b/', $text) === 1 => 'Calendar planning',
            preg_match('/\b(task|todo|to do|trash|chore|complete|done)\b/', $text) === 1 => 'Task management',
            preg_match('/\b(remind|reminder|alert|notify)\b/', $text) === 1 => 'Reminder management',
            preg_match('/\b(dinner|recipe|workout|plan|idea|suggest|recommend)\b/', $text) === 1 => 'Advice and planning',
            (string) $log->route_tier === 'simple' => 'Quick chat',
            default => 'General Bean request',
        };
    }

    private function humanActionName(string $action): string
    {
        $action = str_replace(['_', '.'], ' ', $action);

        return str($action)->headline()->toString();
    }
}
