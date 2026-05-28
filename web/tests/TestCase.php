<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function apiToken(string $email = 'test@example.com'): string
    {
        return $this->postJson('/api/auth/register', [
            'name' => str($email)->before('@')->title()->toString(),
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()->json('data.token');
    }
}
