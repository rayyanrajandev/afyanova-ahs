<?php

use App\Modules\PatientFlow\Application\Services\PatientFlowBoardChannelAuthorizer;
use App\Modules\Platform\Application\Services\ClinicalWorkstationChannelAuthorizer;
use App\Modules\Revenue\Application\Services\CashierQueueChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Patient-Flow Board live updates (Phase 2 of the board's roadmap) — one
// facility-scoped private channel. Authorization logic lives in
// PatientFlowBoardChannelAuthorizer (directly unit-tested there) rather than
// inline here, since the test suite forces BROADCAST_CONNECTION=null.
Broadcast::channel(
    'patient-flow.{facilityId}',
    fn ($user, string $facilityId): bool => app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId),
);

Broadcast::channel(
    'notifications.{userId}',
    fn ($user, int $userId): bool => $user->id === $userId,
);

// Reception Call action (AppointmentCalled, §16 #3, 2026-08-11) — reuses
// PatientFlowBoardChannelAuthorizer rather than a near-duplicate class: the
// authorization rule is identical (`appointments.read` + facility_user
// membership), and that class's `authorize()` isn't tied to any specific
// channel name. A separate channel from patient-flow.{facilityId} itself
// (not reused) since Call is Reception-only signaling with its own
// content-bearing payload — see AppointmentCalled's docblock.
Broadcast::channel(
    'reception-queue.{facilityId}',
    fn ($user, string $facilityId): bool => app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId),
);

// Cashier queue (Cashier Phase 8). Its own channel rather than
// patient-flow.{facilityId}: a charge being raised or settled is of no
// interest to nursing, laboratory or radiology, and the cashier has no reason
// to be woken by every clinical transition in the building.
Broadcast::channel(
    'cashier-queue.{facilityId}',
    fn ($user, string $facilityId): bool => app(CashierQueueChannelAuthorizer::class)->authorize($user, $facilityId),
);

// Departmental Clinical Workstation Queues
Broadcast::channel(
    'laboratory-queue.{facilityId}',
    fn ($user, string $facilityId): bool => app(ClinicalWorkstationChannelAuthorizer::class)->authorize($user, $facilityId, 'laboratory.orders.read'),
);

Broadcast::channel(
    'radiology-queue.{facilityId}',
    fn ($user, string $facilityId): bool => app(ClinicalWorkstationChannelAuthorizer::class)->authorize($user, $facilityId, 'radiology.orders.read'),
);

Broadcast::channel(
    'pharmacy-queue.{facilityId}',
    fn ($user, string $facilityId): bool => app(ClinicalWorkstationChannelAuthorizer::class)->authorize($user, $facilityId, 'pharmacy.orders.read'),
);

Broadcast::channel(
    'procedure-queue.{facilityId}',
    fn ($user, string $facilityId): bool => app(ClinicalWorkstationChannelAuthorizer::class)->authorize($user, $facilityId, 'clinical-procedure.orders.read'),
);

