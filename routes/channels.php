<?php

use App\Modules\PatientFlow\Application\Services\PatientFlowBoardChannelAuthorizer;
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
