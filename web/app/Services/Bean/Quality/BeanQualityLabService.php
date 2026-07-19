<?php

namespace App\Services\Bean\Quality;

use Illuminate\Support\Facades\File;

class BeanQualityLabService
{
    public function __construct(private readonly BeanQualityAuditService $audit) {}

    public function productionSmoke(int $recent = 200): array
    {
        return $this->audit->productionSmokeReport($recent);
    }

    public function writeJsonReport(array $report, string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function writeMarkdownReport(array $report, string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->markdown($report));
    }

    public function markdown(array $report): string
    {
        $lines = [
            '# Bean Quality Production Smoke Report',
            '',
            '- Generated: '.($report['generated_at'] ?? now()->toIso8601String()),
            '- Traces audited: '.($report['trace_count'] ?? 0),
            '- Flagged traces: '.($report['flagged_trace_count'] ?? 0),
            '- Average latency: '.($report['average_latency_ms'] ?? 0).'ms',
            '- Guidance: '.($report['guidance'] ?? 'Read-only audit.'),
            '',
            '## Top quality flags',
        ];

        foreach (($report['quality_flag_counts'] ?? []) as $flag => $count) {
            $lines[] = '- '.$flag.': '.$count;
        }

        $lines[] = '';
        $lines[] = '## Recent flagged runs';
        foreach (($report['recent_flagged_runs'] ?? []) as $run) {
            $lines[] = '- #'.($run['bean_run_id'] ?? '?').' '.($run['intent'] ?? 'unknown').': '.implode(', ', $run['quality_flags'] ?? []);
        }

        return implode("\n", $lines)."\n";
    }
}
