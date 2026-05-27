<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsageController extends Controller
{
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
            ],
            'by_model' => $this->groupedUsage('model', $month),
            'by_route_tier' => $this->groupedUsage('route_tier', $month),
            'top_users' => $this->topUsers($month),
            'top_workspaces' => $this->topWorkspaces($month),
            'recent_logs' => $this->logsQuery()->limit(25)->get(),
            'alerts' => $this->alertsQuery()->limit(20)->get(),
        ]]);
    }

    public function logs(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 100)));

        return response()->json(['data' => $this->logsQuery()->limit($limit)->get()]);
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
            ->with(['user:id,name,email,subscription_tier', 'workspace:id,name,type'])
            ->latest('id');
    }

    private function alertsQuery()
    {
        return AiUsageAlert::query()
            ->with(['user:id,name,email', 'workspace:id,name,type'])
            ->latest('id');
    }
}
