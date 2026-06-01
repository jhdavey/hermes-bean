<?php

namespace Tests\Feature;

use Tests\TestCase;

class HermesRuntimeConfigTest extends TestCase
{
    public function test_runtime_api_key_prefers_server_key_over_public_key(): void
    {
        $originalHermes = $_ENV['HERMES_API_KEY'] ?? null;
        $originalOpenAi = $_ENV['OPENAI_API_KEY'] ?? null;
        $originalPublic = $_ENV['OPENAI_PUBLIC_KEY'] ?? null;

        try {
            $_ENV['HERMES_API_KEY'] = '';
            $_SERVER['HERMES_API_KEY'] = '';
            $_ENV['OPENAI_API_KEY'] = 'sk-server-key';
            $_SERVER['OPENAI_API_KEY'] = 'sk-server-key';
            $_ENV['OPENAI_PUBLIC_KEY'] = 'pk-public-key';
            $_SERVER['OPENAI_PUBLIC_KEY'] = 'pk-public-key';

            $services = require base_path('config/services.php');

            $this->assertSame('sk-server-key', $services['hermes_runtime']['api_key']);
            $this->assertSame('OPENAI_API_KEY', $services['hermes_runtime']['api_key_source']);
            $this->assertSame('pk-public-key', $services['openai']['public_key']);
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
