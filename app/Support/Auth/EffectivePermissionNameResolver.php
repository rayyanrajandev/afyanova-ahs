<?php

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class EffectivePermissionNameResolver
{
    /**
     * @var array<int, string>
     */
    private const RESOLVED_ABILITIES = [
        // Composite abilities (no DB permission, computed via Gate closures)
        'appointments.record-triage',
        'appointments.start-consultation',
        'appointments.manage-provider-session',
        // New granular permissions with backward-compat fallback in Gate::before()
        'lab.order',
        'lab.sample.collect',
        'lab.sample.reject',
        'lab.test.perform',
        'lab.result.enter',
        'lab.result.verify',
        'lab.result.release',
        'medication.prescribe',
        'medication.dispense',
        'dispense.cancel',
        'imaging.order',
        'imaging.perform',
        'imaging.result.enter',
        'imaging.result.verify',
        'patient.demographics.update',
        'patient.allergies.manage',
        'patient.medications.manage',
        'patient.vitals.record',
        'appointment.reschedule',
        'appointment.cancel',
        'appointment.check-in',
        'appointment.check-out',
        'staff.employment.update',
        'staff.status.update',
    ];

    /**
     * @param array<int, string> $permissionNames
     * @return array<int, string>
     */
    public function resolve(mixed $user, array $permissionNames): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $permission): string => is_string($permission) ? trim($permission) : '',
            $permissionNames,
        ))));

        if (! $user instanceof Authenticatable) {
            sort($normalized);

            return $normalized;
        }

        $gate = Gate::forUser($user);
        foreach (self::RESOLVED_ABILITIES as $ability) {
            if ($gate->allows($ability)) {
                $normalized[] = $ability;
            }
        }

        $normalized = array_values(array_unique(array_filter($normalized)));
        sort($normalized);

        return $normalized;
    }
}
