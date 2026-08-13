<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentReferralModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;

class DocumentContextLookup
{
    /**
     * @return array<string, mixed>|null
     */
    public function patientSummary(mixed $patientId): ?array
    {
        if (! is_string($patientId) || trim($patientId) === '') {
            return null;
        }

        $patient = PatientModel::query()->find($patientId);
        if (! $patient instanceof PatientModel) {
            return null;
        }

        return [
            'id' => (string) $patient->id,
            'patientNumber' => $patient->patient_number,
            'fullName' => $this->personName([
                $patient->first_name,
                $patient->middle_name,
                $patient->last_name,
            ]),
            'gender' => $patient->gender,
            'dateOfBirth' => $patient->date_of_birth?->format('Y-m-d'),
            'phone' => $patient->phone,
            'email' => $patient->email,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function appointmentSummary(mixed $appointmentId): ?array
    {
        if (! is_string($appointmentId) || trim($appointmentId) === '') {
            return null;
        }

        $appointment = AppointmentModel::query()->find($appointmentId);
        if (! $appointment instanceof AppointmentModel) {
            return null;
        }

        return [
            'id' => (string) $appointment->id,
            'appointmentNumber' => $appointment->appointment_number,
            'department' => $appointment->department,
            'scheduledAt' => optional($appointment->scheduled_at)?->toISOString(),
            'reason' => $appointment->reason,
            'status' => $appointment->status,
            'sourceAdmissionId' => $appointment->source_admission_id,
            'sourceAdmission' => $this->admissionSummary($appointment->source_admission_id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function admissionSummary(mixed $admissionId): ?array
    {
        if (! is_string($admissionId) || trim($admissionId) === '') {
            return null;
        }

        $admission = AdmissionModel::query()->find($admissionId);
        if (! $admission instanceof AdmissionModel) {
            return null;
        }

        return [
            'id' => (string) $admission->id,
            'admissionNumber' => $admission->admission_number,
            'ward' => $admission->ward,
            'bed' => $admission->bed,
            'admittedAt' => optional($admission->admitted_at)?->toISOString(),
            'dischargedAt' => optional($admission->discharged_at)?->toISOString(),
            'status' => $admission->status,
            'admissionReason' => $admission->admission_reason,
            'dischargeDestination' => $admission->discharge_destination,
            'followUpPlan' => $admission->follow_up_plan,
            'notes' => $admission->notes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function appointmentReferralSummary(mixed $appointmentReferralId): ?array
    {
        if (! is_string($appointmentReferralId) || trim($appointmentReferralId) === '') {
            return null;
        }

        $referral = AppointmentReferralModel::query()->find($appointmentReferralId);
        if (! $referral instanceof AppointmentReferralModel) {
            return null;
        }

        return [
            'id' => (string) $referral->id,
            'referralNumber' => $referral->referral_number,
            'referralType' => $referral->referral_type,
            'targetDepartment' => $referral->target_department,
            'targetFacilityName' => $referral->target_facility_name,
            'referralReason' => $referral->referral_reason,
            'clinicalNotes' => $referral->clinical_notes,
            'handoffNotes' => $referral->handoff_notes,
            'priority' => $referral->priority,
            'acceptedAt' => optional($referral->accepted_at)?->toISOString(),
            'handedOffAt' => optional($referral->handed_off_at)?->toISOString(),
            'completedAt' => optional($referral->completed_at)?->toISOString(),
            'status' => $referral->status,
            'statusReason' => $referral->status_reason,
            'requestedAt' => optional($referral->requested_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function theatreProcedureSummary(mixed $theatreProcedureId): ?array
    {
        if (! is_string($theatreProcedureId) || trim($theatreProcedureId) === '') {
            return null;
        }

        $procedure = TheatreProcedureModel::query()->find($theatreProcedureId);
        if (! $procedure instanceof TheatreProcedureModel) {
            return null;
        }

        return [
            'id' => (string) $procedure->id,
            'procedureNumber' => $procedure->procedure_number,
            'procedureType' => $procedure->procedure_type,
            'procedureName' => $procedure->procedure_name,
            'theatreRoomName' => $procedure->theatre_room_name,
            'status' => $procedure->status,
            'scheduledAt' => optional($procedure->scheduled_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function billingPayerSummary(mixed $billingPayerContractId): ?array
    {
        if (! is_string($billingPayerContractId) || trim($billingPayerContractId) === '') {
            return null;
        }

        $payer = BillingPayerContractModel::query()->find($billingPayerContractId);
        if (! $payer instanceof BillingPayerContractModel) {
            return null;
        }

        return [
            'id' => (string) $payer->id,
            'contractCode' => $payer->contract_code,
            'contractName' => $payer->contract_name,
            'payerType' => $payer->payer_type,
            'payerName' => $payer->payer_name,
            'payerPlanCode' => $payer->payer_plan_code,
            'payerPlanName' => $payer->payer_plan_name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userSummary(mixed $userId): ?array
    {
        $normalizedId = is_numeric($userId)
            ? (int) $userId
            : null;

        if ($normalizedId === null || $normalizedId <= 0) {
            return null;
        }

        $user = User::query()->find($normalizedId);
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @param  array<int, string|null>  $segments
     */
    private function personName(array $segments): string
    {
        $parts = array_values(array_filter(array_map(
            static fn (?string $segment): ?string => ($segment !== null && trim($segment) !== '') ? trim($segment) : null,
            $segments,
        )));

        return $parts !== [] ? implode(' ', $parts) : 'Unknown patient';
    }
}
