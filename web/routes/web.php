<?php

use App\Models\EarlyAccessSignup;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/pricing', 'pricing')->name('pricing');
Route::view('/login', 'app')->name('login');
Route::view('/register', 'app')->name('register');
Route::view('/forgot-password', 'app')->name('password.request');
Route::view('/app', 'app')->name('app');
Route::view('/dashboard', 'app')->name('dashboard');
Route::view('/admin', 'app')->name('admin');

Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/support', 'legal.support')->name('support');
Route::view('/account-deletion', 'legal.account-deletion')->name('account-deletion');

Route::get('/reset-password/{token}', function (Request $request, string $token) {
    return view('auth.reset-password', [
        'token' => $token,
        'email' => (string) $request->query('email', ''),
    ]);
})->name('password.reset');

Route::post('/reset-password', function (Request $request) {
    $request->merge([
        'email' => strtolower(trim((string) $request->input('email', ''))),
    ]);

    $credentials = $request->validate([
        'token' => ['required', 'string'],
        'email' => ['required', 'email', 'max:255'],
        'password' => ['required', 'confirmed', Password::min(12)],
    ]);

    $status = PasswordBroker::broker()->reset(
        $credentials,
        function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
        },
    );

    if ($status !== PasswordBroker::PASSWORD_RESET) {
        return back()->withErrors(['email' => __($status)])->withInput($request->only('email'));
    }

    return response()->view('auth.reset-complete');
})->name('password.update');

Route::get('/workspace-invitations/{token}/accept', function (string $token) {
    try {
        $membership = app(WorkspaceService::class)->acceptInviteFromEmailLink($token);

        return response()->view('workspace-invitations.accepted', [
            'workspace' => $membership->workspace,
        ]);
    } catch (AuthorizationException|InvalidArgumentException $exception) {
        return response()->view('workspace-invitations.unavailable', [
            'message' => $exception->getMessage(),
        ], 409);
    } catch (ModelNotFoundException) {
        return response()->view('workspace-invitations.unavailable', [
            'message' => 'This invitation link is invalid or has already been used.',
        ], 404);
    }
})->name('workspace-invitations.accept');

Route::post('/early-access', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email:rfc', 'max:255'],
        'plan' => ['sometimes', 'nullable', Rule::in(['free', 'premium', 'pro'])],
    ]);
    $requestedPlan = $validated['plan'] ?? null;

    EarlyAccessSignup::updateOrCreate(
        ['email' => strtolower($validated['email'])],
        [
            'name' => null,
            'use_case' => null,
            'requested_plan' => $requestedPlan,
            'source' => $requestedPlan ? 'pricing' : 'landing',
        ],
    );

    return redirect('/#early-access')->with(
        'early_access_status',
        'Thank you for signing up! We’ll send you an email as soon as we can share the app with you! We look forward to your help with making Bean great!',
    );
})->name('early-access.store');
