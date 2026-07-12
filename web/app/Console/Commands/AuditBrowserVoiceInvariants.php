<?php

namespace App\Console\Commands;

use App\Services\BrowserVoiceInvariantAuditService;
use Illuminate\Console\Command;

class AuditBrowserVoiceInvariants extends Command
{
    protected $signature = 'browser-voice:audit-invariants
        {--json : Emit machine-readable JSON}
        {--chunk=200 : Number of turns or runs read per chunk (1-500)}';

    protected $description = 'Read-only audit of Browser Voice v2 exact-once, terminal, deadline, run, and raw-audio invariants';

    public function handle(BrowserVoiceInvariantAuditService $audit): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);
        if ($chunk === false) {
            $this->error('The --chunk option must be an integer from 1 through 500.');

            return self::INVALID;
        }

        $report = $audit->audit($chunk);
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $passed = $report['status'] === 'pass';
            $this->{$passed ? 'info' : 'error'}('Browser Voice v2 invariant audit: '.strtoupper($report['status']));
            $this->line(sprintf(
                'Audited %d turn(s), %d message(s), %d run(s), and %d event(s).',
                $report['counts']['turns'],
                $report['counts']['messages'],
                $report['counts']['runs'],
                $report['counts']['events'],
            ));
            $this->line("Violations: {$report['counts']['violations']}");
            if ($report['violation_samples'] !== []) {
                $this->table(
                    ['Code', 'Turn', 'Run', 'Event', 'Message'],
                    collect($report['violation_samples'])->map(fn (array $row): array => [
                        $row['code'],
                        $row['turn_id'] ?? $row['turn_db_id'] ?? '-',
                        $row['run_id'] ?? '-',
                        $row['event_id'] ?? '-',
                        $row['message'],
                    ])->all(),
                );
            }
            if ($report['samples_truncated']) {
                $this->warn('Violation samples were truncated; totals by code remain complete.');
            }
        }

        return $report['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
