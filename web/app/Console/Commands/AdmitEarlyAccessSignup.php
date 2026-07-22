<?php

namespace App\Console\Commands;

use App\Notifications\EarlyAccessAvailable;
use App\Services\EarlyAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AdmitEarlyAccessSignup extends Command
{
    protected $signature = 'early-access:admit {email} {--capacity= : Increase the real rollout capacity before admission}';

    protected $description = 'Admit and notify a waitlisted HeyBean early-access signup';

    public function handle(EarlyAccessService $earlyAccess): int
    {
        $capacity = $this->option('capacity');
        try {
            $signup = $earlyAccess->admitWaitlisted(
                (string) $this->argument('email'),
                is_numeric($capacity) ? (int) $capacity : null,
            );
            Notification::route('mail', $signup->email)->notify(new EarlyAccessAvailable);
            $this->info("Admitted and notified {$signup->email}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
