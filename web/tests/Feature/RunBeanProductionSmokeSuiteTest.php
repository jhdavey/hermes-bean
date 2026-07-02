<?php

namespace Tests\Feature;

use App\Console\Commands\RunBeanProductionSmokeSuite;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class RunBeanProductionSmokeSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_limit_copy_counts_as_smoke_failure(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'containsFailureCopy');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's AI usage limit.",
        ));
        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's external lookup usage limit.",
        ));
        $this->assertFalse($method->invoke(
            $command,
            'Done - I added the three events to your calendar.',
        ));
    }

    public function test_smoke_account_reset_clears_ai_usage_logs(): void
    {
        $user = User::factory()->create();
        AiUsageLog::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'gpt-test-tools',
            'route_tier' => 'agent',
            'request_type' => 'text',
            'status' => 'completed',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 120,
            'estimated_cost_usd' => 0.01,
            'action_types' => ['calendar_event.create'],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'resetSmokeUserData');
        $method->setAccessible(true);
        $method->invoke($command, $user);

        $this->assertDatabaseMissing('ai_usage_logs', [
            'user_id' => $user->id,
        ]);
    }
}
