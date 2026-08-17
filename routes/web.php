<?php

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
    if (Gate::allows('cashier.access')) {
        return redirect('/cashier');
    }
    if (Gate::allows('inventory.access')) {
        return redirect('/inventory');
    }
    if (Gate::allows('admin.access')) {
        return redirect('/admin');
    }

    return redirect('/reception');
})->name('home');

Route::get('landing', fn () => Inertia::render('Landing'))->name('landing');

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
| Each workspace has a real, deep-linkable URL gated by dedicated RBAC gates.
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

Route::get('cashier', fn () => Inertia::render('cashier/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:cashier.access'])
    ->name('cashier');

Route::get('inventory', fn () => Inertia::render('inventory/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:inventory.access'])
    ->name('inventory');

Route::get('admin', fn () => Inertia::render('admin/Index'))
    ->middleware(['auth', 'verified', 'session.limits', 'can:admin.access'])
    ->name('admin');

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

    Route::get('patient-flow/board', fn () => Inertia::render('patient-flow/Board'))
        ->middleware('can:appointments.read')
        ->name('patient-flow.board.page');
});

require __DIR__.'/settings.php';
