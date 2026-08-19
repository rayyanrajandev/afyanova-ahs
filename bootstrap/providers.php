<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\WorkspaceRouteServiceProvider::class,
    App\Modules\Revenue\RevenueServiceProvider::class,
    App\Modules\Reception\ReceptionServiceProvider::class,
    App\Modules\PatientFlow\PatientFlowServiceProvider::class,
    App\Modules\Notifications\NotificationsServiceProvider::class,
];
