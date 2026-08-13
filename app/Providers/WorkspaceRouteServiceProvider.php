<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads routes/api-workspaces.php — the fresh, workspace-scoped API route
 * surface (reception/*, clinician/*, nursing/*, and future workspaces),
 * extracted out of routes/api.php on 2026-08-10 so old (generic/shared) and
 * new (workspace-scoped) routes stop living in the same file. Not owned by
 * any single module (it spans Reception, Clinician, and Nursing today), so
 * it lives here rather than in one module's own ServiceProvider — mirrors
 * how BillingServiceProvider::boot() loads routes/billing-phase1.php.
 */
class WorkspaceRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/api-workspaces.php'));
    }
}
