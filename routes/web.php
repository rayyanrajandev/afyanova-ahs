<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformBrandingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('branding/logo', [PlatformBrandingController::class, 'logo'])->name('branding.logo');
Route::get('branding/icon', [PlatformBrandingController::class, 'icon'])->name('branding.icon');

Route::get('auth/csrf-token', function (Request $request) {
    $request->session()->regenerateToken();

    return response()->json([
        'token' => csrf_token(),
    ]);
})->middleware('throttle:30,1')->name('auth.csrf-token.web');

// Dynamic smart workspace redirector & Public Landing Page
Route::get('/', function (Request $request) {
    $user = $request->user();
    if (! $user) {
        return Inertia::render('Landing');
    }

    if (Gate::allows('clinician.access')) {
        return redirect('/clinician');
    }
    if (Gate::allows('nursing.access')) {
        return redirect('/nursing');
    }
    if (Gate::allows('reception.access')) {
        return redirect('/reception');
    }
    if (Gate::allows('laboratory.access')) {
        return redirect('/laboratory');
    }
    if (Gate::allows('radiology.access')) {
        return redirect('/radiology');
    }
    if (Gate::allows('pharmacy.access')) {
        return redirect('/pharmacy');
    }

    return redirect('/reception');
})->name('home');

Route::get('landing', fn () => redirect('/', 301))->name('landing');
Route::get('v1', fn () => redirect('/', 301))->name('landing.v1.alias');
Route::get('landing-v2', fn () => redirect('/', 301))->name('landing.v2');
Route::get('v2', fn () => redirect('/', 301))->name('landing.v2.alias');

Route::get('pending-setup', function () {
    return Inertia::render('errors/PendingSetup');
})->middleware(['auth'])->name('pending-setup');

Route::get('dashboard', function (Request $request) {
    return redirect('/');
})->middleware(['auth', 'verified', 'session.limits'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| Afyanova Workspaces (Volume 1.3 §5.1 — URL scheme)
|--------------------------------------------------------------------------
| Each active workspace has a real, deep-linkable URL gated by dedicated RBAC gates.
|
*/
Route::get('reception', fn () => Inertia::render('reception/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:reception.access'])
    ->name('reception');

Route::get('clinician', fn () => Inertia::render('clinician/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:clinician.access'])
    ->name('clinician');

Route::get('nursing', fn () => Inertia::render('nursing/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:nursing.access'])
    ->name('nursing');

Route::get('laboratory', fn () => Inertia::render('laboratory/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:laboratory.access'])
    ->name('laboratory');

Route::get('radiology', fn () => Inertia::render('radiology/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:radiology.access'])
    ->name('radiology');

Route::get('pharmacy', fn () => Inertia::render('pharmacy/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:pharmacy.access'])
    ->name('pharmacy');

/*
|--------------------------------------------------------------------------
| SPA Page Routes (Inertia)
|--------------------------------------------------------------------------
|
| Render Inertia page shell for active SPA views.
|
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('patient-flow/board', fn () => Inertia::render('patient-flow/Board'))
        ->middleware('can:appointments.read')
        ->name('patient-flow.board.page');
});

/*
|--------------------------------------------------------------------------
| Account Settings (Inertia)
|--------------------------------------------------------------------------
|
| Inlined from the former routes/settings.php on 2026-08-18 — it was a
| 31-line file loaded by a `require` at the bottom of this one, so it never
| had a middleware stack or URL prefix of its own to preserve. Kept last in
| this file so registration order is identical to what the `require` gave.
|
*/
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');
});
