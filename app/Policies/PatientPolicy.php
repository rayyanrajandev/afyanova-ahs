<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Support\Auth\RoleCodes;

class PatientPolicy
{
    public function view(User $user, PatientModel $patient): bool
    {
        if (! $user->hasPermissionTo('patients.read')) {
            return false;
        }

        return true;
    }

    public function updateDemographics(User $user, PatientModel $patient): bool
    {
        if (! $user->hasPermissionTo('patient.demographics.update')) {
            return false;
        }

        $roleCodes = $user->roleCodes();

        if (in_array(RoleCodes::ADMIN_REGISTRATION, $roleCodes, true)) {
            return true;
        }

        if ($this->isClinicalRole($roleCodes)) {
            return EncounterModel::query()
                ->where('patient_id', $patient->id)
                ->where('primary_clinician_user_id', $user->id)
                ->whereNull('closed_at')
                ->exists();
        }

        return true;
    }

    public function manageAllergies(User $user, PatientModel $patient): bool
    {
        return $user->hasPermissionTo('patient.allergies.manage');
    }

    public function manageMedications(User $user, PatientModel $patient): bool
    {
        return $user->hasPermissionTo('patient.medications.manage');
    }

    public function recordVitals(User $user, PatientModel $patient): bool
    {
        return $user->hasPermissionTo('patient.vitals.record')
            || $user->hasPermissionTo('emergency.triage.create');
    }

    /**
     * @param  array<int, string>  $roleCodes
     */
    private function isClinicalRole(array $roleCodes): bool
    {
        return array_intersect(RoleCodes::CLINICAL_ROLES, $roleCodes) !== [];
    }
}
