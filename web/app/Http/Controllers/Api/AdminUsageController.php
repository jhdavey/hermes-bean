<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
use App\Models\IssueReport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsageController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings) {}

    public function summary(Request $request): JsonResponse
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();
        $logs = AiUsageLog::query();

        return response()->json(['data' => [
            'totals' => [
                'users' => User::count(),
                'workspaces' => Workspace::count(),
                'ai_actions_today' => (clone $logs)->where('created_at', '>=', $today)->count(),
                'ai_actions_month' => (clone $logs)->where('created_at', '>=', $month)->count(),
                'tokens_today' => (int) (clone $logs)->where('created_at', '>=', $today)->sum('total_tokens'),
                'tokens_month' => (int) (clone $logs)->where('created_at', '>=', $month)->sum('total_tokens'),
                'cost_today' => round((float) (clone $logs)->where('created_at', '>=', $today)->sum('estimated_cost_usd'), 4),
                'cost_month' => round((float) (clone $logs)->where('created_at', '>=', $month)->sum('estimated_cost_usd'), 4),
                'open_alerts' => AiUsageAlert::whereNull('acknowledged_at')->count(),
                'open_issue_reports' => IssueReport::where('status', 'open')->count(),
                'archived_issue_reports' => IssueReport::where('status', 'closed')->count(),
            ],
            'by_model' => $this->groupedUsage('model', $month),
            'by_route_tier' => $this->groupedUsage('route_tier', $month),
            'user_growth' => $this->userGrowth(),
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

    private function userGrowth(): array
    {
        $start = now()->subDays(29)->startOfDay();
        $dailySignups = User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as signups')
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('signups', 'day');
        $runningTotal = User::query()->where('created_at', '<', $start)->count();

        return collect(range(0, 29))->map(function (int $offset) use ($start, $dailySignups, &$runningTotal): array {
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
