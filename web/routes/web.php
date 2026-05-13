<?php

use App\Models\EarlyAccessSignup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/early-access', function (Request $request) {
    $validated = $request->validate([
        'name' => ['nullable', 'string', 'max:120'],
        'email' => ['required', 'email:rfc', 'max:255'],
        'use_case' => ['nullable', 'string', 'max:2000'],
    ]);

    EarlyAccessSignup::updateOrCreate(
        ['email' => strtolower($validated['email'])],
        [
            'name' => $validated['name'] ?? null,
            'use_case' => $validated['use_case'] ?? null,
            'source' => 'landing',
        ],
    );

    return redirect('/#early-access')->with(
        'early_access_status',
        'You are on the HeyBean early access list. We will reach out when your invite is ready.',
    );
})->name('early-access.store');
