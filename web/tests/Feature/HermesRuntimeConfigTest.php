<?php

namespace Tests\Feature;

use Tests\TestCase;

class HermesRuntimeConfigTest extends TestCase
{
    public function test_openai_public_key_is_used_for_all_openai_runtime_keys(): void
    {
        $originalHermes = $_ENV['HERMES_API_KEY'] ?? null;
        $originalOpenAi = $_ENV['OPENAI_API_KEY'] ?? null;
        $originalPublic = $_ENV['OPENAI_PUBLIC_KEY'] ?? null;

        try {
            $_ENV['HERMES_API_KEY'] = 'sk-ignored-hermes-key';
            $_SERVER['HERMES_API_KEY'] = 'sk-ignored-hermes-key';
            $_ENV['OPENAI_API_KEY'] = 'sk-server-key';
            $_SERVER['OPENAI_API_KEY'] = 'sk-server-key';
            $_ENV['OPENAI_PUBLIC_KEY'] = 'sk-project-key-named-public';
            $_SERVER['OPENAI_PUBLIC_KEY'] = 'sk-project-key-named-public';

            $services = require base_path('config/services.php');

            $this->assertSame('sk-project-key-named-public', $services['hermes_runtime']['api_key']);
            $this->assertSame('OPENAI_PUBLIC_KEY', $services['hermes_runtime']['api_key_source']);
            $this->assertSame('sk-project-key-named-public', $services['openai']['public_key']);
            $this->assertSame('sk-project-key-named-public', $services['openai']['server_api_key']);
        } finally {
            $this->restoreEnv('HERMES_API_KEY', $originalHermes);
            $this->restoreEnv('OPENAI_API_KEY', $originalOpenAi);
            $this->restoreEnv('OPENAI_PUBLIC_KEY', $originalPublic);
        }
    }

    public function test_missing_openai_public_key_leaves_runtime_keys_empty(): void
    {
        $originalHermes = $_ENV['HERMES_API_KEY'] ?? null;
        $originalOpenAi = $_ENV['OPENAI_API_KEY'] ?? null;
        $originalPublic = $_ENV['OPENAI_PUBLIC_KEY'] ?? null;

        try {
            foreach (['HERMES_API_KEY', 'OPENAI_API_KEY', 'OPENAI_PUBLIC_KEY'] as $key) {
                $_ENV[$key] = '';
                $_SERVER[$key] = '';
            }

            $services = require base_path('config/services.php');

            $this->assertSame('', $services['hermes_runtime']['api_key']);
            $this->assertNull($services['hermes_runtime']['api_key_source']);
            $this->assertSame('', $services['openai']['server_api_key']);
        } finally {
            $this->restoreEnv('HERMES_API_KEY', $originalHermes);
            $this->restoreEnv('OPENAI_API_KEY', $originalOpenAi);
            $this->restoreEnv('OPENAI_PUBLIC_KEY', $originalPublic);
        }
    }

    private function restoreEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
