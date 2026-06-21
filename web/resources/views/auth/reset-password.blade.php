<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your HeyBean password</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f1f7ee; color: #1f2937; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .card { width: min(440px, calc(100vw - 32px)); background: #fff; border: 1px solid rgba(148, 163, 184, .22); border-radius: 24px; padding: 28px; box-shadow: 0 20px 60px rgba(22, 163, 74, .12); }
        h1 { margin: 0 0 8px; font-size: 1.45rem; }
        p { color: #64748b; line-height: 1.5; }
        label { display: block; margin: 14px 0 6px; font-weight: 700; }
        input { box-sizing: border-box; width: 100%; border: 1px solid rgba(148, 163, 184, .45); border-radius: 999px; padding: 12px 14px; font: inherit; }
        button, .button { display: inline-flex; justify-content: center; align-items: center; margin-top: 18px; width: 100%; border: 0; border-radius: 999px; padding: 13px 16px; background: #16a34a; color: #fff; font-weight: 800; text-decoration: none; cursor: pointer; }
        .error { color: #dc2626; font-size: .92rem; margin-top: 6px; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Reset your password</h1>
        <p>Choose a new password for your HeyBean account. When it’s saved, you’ll be sent back to the app login screen.</p>
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">Account email</label>
            <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $email) }}" required>
            @error('email')<div class="error">{{ $message }}</div>@enderror

            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
            @error('password')<div class="error">{{ $message }}</div>@enderror

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>

            <button type="submit">Reset password</button>
        </form>
    </main>
</body>
</html>
