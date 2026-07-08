<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your HeyBean password</title>
    @include('partials.utility-page-styles')
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
