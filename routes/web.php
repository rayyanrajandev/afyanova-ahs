<?php

use App\Modules\Platform\Presentation\Http\Controllers\PlatformBrandingController;
use Illuminate\Http\Request;
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

Route::redirect('/', '/reception')->name('home');

Route::get('pending-setup', function () {
    return Inertia::render('errors/PendingSetup');
})->middleware(['auth'])->name('pending-setup');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'user.has-role', 'session.limits'])->name('dashboard');

/*
|--------------------------------------------------------------------------
| Afyanova Workspaces (Volume 1.3 §5.1 — URL scheme)
|--------------------------------------------------------------------------
| Each workspace has a real, deep-linkable URL. Workspaces are built as
| thin compositions of the Tier 0+1 platform (shell, tokens, components).
| The Reception workspace is the pilot (Volume 2.1).
|
*/
// RBAC gate (Volume 2.1 §13, Volume 3.7 audit 2026-08-10): previously only
// `auth`+`verified` — any authenticated user could load this page even with
// zero reception permissions (every API call underneath would then 403, but
// the page shell, nav icon, and layout were visible regardless). Gated on
// `patients.read` — the same permission `GET reception/patients` already
// requires (routes/api.php) — and `session.limits`, matching /dashboard's
// idle/absolute session timeout (Volume 2.1 §13 "Session").
Route::get('reception', fn () => Inertia::render('reception/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:patients.read'])
    ->name('reception');

/*
|--------------------------------------------------------------------------
| SPA Page Routes (Inertia)
|--------------------------------------------------------------------------
|
| These routes render the Inertia page shell for the SPA. Data is fetched
| client-side via the API routes registered in routes/api.php. Each route
| is gated by the same can: permission middleware as its API counterpart so
| that unauthorized users receive a 403 before the page is served.
|
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('staff', fn () => Inertia::render('staff/Index'))
        ->middleware('can:staff.read')
        ->name('staff.page');

    Route::get('staff-credentialing', fn () => Inertia::render('staff/CredentialingIndex'))
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing.page');

    Route::get('platform/admin/users', fn () => Inertia::render('platform/admin/users/Index'))
        ->middleware('can:platform.users.read')
        ->name('platform.admin.users.page');

    Route::get('platform/admin/roles', fn () => Inertia::render('platform/admin/roles/Index'))
        ->middleware('can:platform.rbac.read')
        ->name('platform.admin.roles.page');
});

require __DIR__.'/settings.php';
