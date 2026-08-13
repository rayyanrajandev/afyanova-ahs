<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class MedicalRecordPolicy
{
    public function updateDraft(User $user, MedicalRecordModel $record): bool
    {
        if ((bool) $user->hasPermissionTo('medical.records.update')) {
            return true;
        }

        if (! (bool) $user->hasPermissionTo('medical.records.read')
            || ! (bool) $user->hasPermissionTo('medical.records.create')) {
            return false;
        }

        if ($record->status !== MedicalRecordStatus::DRAFT->value) {
            return false;
        }

        if ((int) $record->author_user_id === (int) $user->id) {
            return $this->matchesScope($record);
        }

        if ($record->handoff_status === 'accepted'
            && (int) $record->handed_off_to_user_id === (int) $user->id) {
            return $this->matchesScope($record);
        }

        return false;
    }

    private function matchesScope(MedicalRecordModel $record): bool
    {
        $scopeContext = app(CurrentPlatformScopeContextInterface::class);
        $tenantId = $scopeContext->tenantId();
        $facilityId = $scopeContext->facilityId();

        return ($tenantId === null || (string) $record->tenant_id === $tenantId)
            && ($facilityId === null || (string) $record->facility_id === $facilityId);
    }
}
