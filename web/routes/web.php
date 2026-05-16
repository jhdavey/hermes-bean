<?php

use App\Models\EarlyAccessSignup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/support', 'legal.support')->name('support');
Route::view('/account-deletion', 'legal.account-deletion')->name('account-deletion');

Route::post('/early-access', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email:rfc', 'max:255'],
    ]);

    EarlyAccessSignup::updateOrCreate(
        ['email' => strtolower($validated['email'])],
        [
            'name' => null,
            'use_case' => null,
            'source' => 'landing',
        ],
    );

    return redirect('/#early-access')->with(
        'early_access_status',
        'Thank you for signing up! We’ll send you an email as soon as we can share the app with you! We look forward to your help with making Bean great!',
    );
})->name('early-access.store');
