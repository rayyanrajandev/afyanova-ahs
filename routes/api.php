<?php

use App\Modules\Authentication\Presentation\Http\Controllers\AuthenticatedUserController;
use App\Modules\Platform\Presentation\Http\Controllers\DashboardContextController;
use App\Modules\Staff\Presentation\Http\Controllers\AttendanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared Platform & Core API Routes
|--------------------------------------------------------------------------
|
| Core platform and authentication endpoints shared across all AfyaNova
| workspaces. Dedicated workspace-scoped endpoints live in
| routes/api-workspaces.php. Both files share the 'api.platform'
| middleware group defined in bootstrap/app.php.
|
*/
Route::middleware('api.platform')
    ->prefix('v1')
    ->group(function (): void {
        // CSRF Token
        Route::get('auth/csrf-token', function (Request $request) {
            $request->session()->regenerateToken();

            return response()->json([
                'token' => csrf_token(),
            ]);
        })->middleware('throttle:30,1')->name('auth.csrf-token');

        // Dashboard & Context
        Route::get('dashboard/context', [DashboardContextController::class, 'show'])->name('dashboard.context');

        // Authenticated User
        Route::get('auth/me', [AuthenticatedUserController::class, 'me'])->name('auth.me');
        Route::get('auth/me/permissions', [AuthenticatedUserController::class, 'permissions'])->name('auth.me.permissions');
        Route::get('auth/me/security-status', [AuthenticatedUserController::class, 'securityStatus'])->name('auth.me.security-status');
    });

// Agent Token Attendance API
Route::middleware('agent.token')->prefix('v1')->group(function (): void {
    Route::post('attendance/agent/push-logs', [AttendanceController::class, 'pushLogs'])
        ->middleware('throttle:10,1')
        ->name('attendance.agent.push-logs');
    Route::post('attendance/agent/heartbeat', [AttendanceController::class, 'heartbeat'])
        ->middleware('throttle:10,1')
        ->name('attendance.agent.heartbeat');
});
