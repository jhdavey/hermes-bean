<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hermes_bean.seed_onboarding_resources', false);
    }

    protected function apiToken(string $email = 'test@example.com'): string
    {
        config()->set('hermes_bean.seed_onboarding_resources', false);

        return $this->postJson('/api/auth/register', [
            'name' => str($email)->before('@')->title()->toString(),
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()->json('data.token');
    }

    protected function configureFakeHermesRuntime(string $contents): string
    {
        $tempDir = sys_get_temp_dir().'/hermes-test-runtime-'.bin2hex(random_bytes(6));
        File::makeDirectory($tempDir, 0755, true);

        $path = $tempDir.'/fake-hermes.php';
        File::put($path, $contents);
        chmod($path, 0755);

        config()->set('services.hermes_runtime.cli_path', $path);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $tempDir);

        $this->beforeApplicationDestroyed(function () use ($tempDir): void {
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        });

        return $path;
    }
}
