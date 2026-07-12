<?php

namespace App\Services;

use App\Models\User;

class BrowserVoiceV2Gate
{
    public function enabledFor(User $user): bool
    {
        return (bool) config('features.browser_voice_v2', false);
    }
}
