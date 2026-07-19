<?php

namespace App\Services\Bean;

use App\Models\BeanRun;
use App\Models\BeanSession;

class BeanDashboardToolBridgeService
{
    public function __construct(private readonly BeanActionExecutor $executor) {}

    public function execute(array $context, array $payload): array
    {
        if (! $this->validSignature($context)) {
            return ['ok' => false, 'error' => 'Invalid Bean tool context signature.'];
        }
        if ((int) ($context['expires_at'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'Bean tool context expired.'];
        }

        $action = trim((string) ($payload['action'] ?? ''));
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        if ($action === '') {
            return ['ok' => false, 'error' => 'Missing Bean dashboard action.'];
        }

        $session = BeanSession::query()
            ->where('user_id', (int) $context['user_id'])
            ->find((int) $context['bean_session_id']);
        $run = BeanRun::query()
            ->where('user_id', (int) $context['user_id'])
            ->where('bean_session_id', (int) $context['bean_session_id'])
            ->find((int) $context['bean_run_id']);

        if (! $session || ! $run) {
            return ['ok' => false, 'error' => 'Bean session or run was not found.'];
        }

        return ['action' => $action, 'arguments' => $arguments, ...$this->executor->execute($session, $run, $action, $arguments)];
    }

    public function sign(array $context): string
    {
        $signed = $context;
        unset($signed['signature']);

        return hash_hmac('sha256', json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    public function validSignature(array $context): bool
    {
        $provided = (string) ($context['signature'] ?? '');
        if ($provided === '') {
            return false;
        }

        return hash_equals($this->sign($context), $provided);
    }
}
