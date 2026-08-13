<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admission\Presentation\Http\Transformers\AdmissionResponseTransformer;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoiceResponseTransformer;
use App\Modules\Laboratory\Presentation\Http\Transformers\LaboratoryOrderResponseTransformer;
use App\Modules\MedicalRecord\Presentation\Http\Transformers\MedicalRecordResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyOrderResponseTransformer;
use App\Modules\ClinicalProcedure\Presentation\Http\Transformers\ClinicalProcedureOrderResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfileResponseTransformer;
use App\Modules\Platform\Application\UseCases\ListCrossTenantAdminAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListCrossTenantAdminAuditLogHoldsUseCase;
use App\Modules\Platform\Application\UseCases\CreateCrossTenantAdminAuditLogHoldUseCase;
use App\Modules\Platform\Application\UseCases\ReleaseCrossTenantAdminAuditLogHoldUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminAdmissionsUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminAppointmentsUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminBillingInvoicesUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminLaboratoryOrdersUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminMedicalRecordsUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminPharmacyOrdersUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminClinicalProcedureOrdersUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminPatientsUseCase;
use App\Modules\Platform\Application\UseCases\SearchCrossTenantAdminStaffProfilesUseCase;
use App\Modules\Platform\Presentation\Http\Transformers\CrossTenantAdminAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\CrossTenantAdminAuditLogHoldResponseTransformer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAdminController extends Controller
{
    public function crossTenantAuditLogs(Request $request, ListCrossTenantAdminAuditLogsUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'operationType' => ['nullable', 'string', 'in:read,write'],
            'targetTenantCode' => ['nullable', 'string', 'max:32'],
            'targetResourceType' => ['nullable', 'string', 'max:50'],
            'outcome' => ['nullable', 'string', 'in:success,not_found,forbidden,validation_error,error'],
            'actorId' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $useCase->execute($validated);

        return response()->json([
            'data' => array_map([CrossTenantAdminAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function crossTenantAuditLogHolds(Request $request, ListCrossTenantAdminAuditLogHoldsUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'holdCode' => ['nullable', 'string', 'max:64'],
            'targetTenantCode' => ['nullable', 'string', 'max:32'],
            'action' => ['nullable', 'string', 'max:100'],
            'approvalCaseReference' => ['nullable', 'string', 'max:100'],
            'approvedByUserId' => ['nullable', 'integer', 'min:1'],
            'createdByUserId' => ['nullable', 'integer', 'min:1'],
            'releaseCaseReference' => ['nullable', 'string', 'max:100'],
            'releaseApprovedByUserId' => ['nullable', 'integer', 'min:1'],
            'releasedByUserId' => ['nullable', 'integer', 'min:1'],
            'createdFrom' => ['nullable', 'date'],
            'createdTo' => ['nullable', 'date', 'after_or_equal:createdFrom'],
            'releasedFrom' => ['nullable', 'date'],
            'releasedTo' => ['nullable', 'date', 'after_or_equal:releasedFrom'],
            'isActive' => ['nullable', 'boolean'],
            'sortBy' => ['nullable', 'string', 'in:createdAt,releasedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);

        return response()->json([
            'data' => array_map([CrossTenantAdminAuditLogHoldResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function createCrossTenantAuditLogHold(Request $request, CreateCrossTenantAdminAuditLogHoldUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'holdCode' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:255'],
            'approvalCaseReference' => ['required', 'string', 'max:100'],
            'approvedByUserId' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'reviewDueAt' => ['required', 'date'],
            'targetTenantCode' => ['nullable', 'string', 'max:32'],
            'action' => ['nullable', 'string', 'max:100'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
        ]);

        try {
            $created = $useCase->execute(
                payload: [
                    'hold_code' => $validated['holdCode'],
                    'reason' => $validated['reason'],
                    'approval_case_reference' => $validated['approvalCaseReference'],
                    'approved_by_user_id' => $validated['approvedByUserId'],
                    'review_due_at' => $validated['reviewDueAt'],
                    'target_tenant_code' => $validated['targetTenantCode'] ?? null,
                    'action' => $validated['action'] ?? null,
                    'starts_at' => $validated['startsAt'] ?? null,
                    'ends_at' => $validated['endsAt'] ?? null,
                ],
                actorId: $request->user()?->id,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'holdCode' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'data' => CrossTenantAdminAuditLogHoldResponseTransformer::transform($created),
        ], 201);
    }

    public function releaseCrossTenantAuditLogHold(
        string $id,
        Request $request,
        ReleaseCrossTenantAdminAuditLogHoldUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'releaseReason' => ['required', 'string', 'max:255'],
            'releaseCaseReference' => ['required', 'string', 'max:100'],
            'releaseApprovedByUserId' => ['required', 'integer', 'min:1', 'exists:users,id'],
        ]);

        try {
            $released = $useCase->execute(
                id: $id,
                releaseReason: (string) $validated['releaseReason'],
                releaseCaseReference: (string) $validated['releaseCaseReference'],
                releaseApprovedByUserId: (int) $validated['releaseApprovedByUserId'],
                actorId: $request->user()?->id,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'release' => [$exception->getMessage()],
                ],
            ], 422);
        }

        abort_if($released === null, 404, 'Audit log hold not found.');

        return response()->json([
            'data' => CrossTenantAdminAuditLogHoldResponseTransformer::transform($released),
        ]);
    }

    public function admissions(Request $request, SearchCrossTenantAdminAdmissionsUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:admitted,discharged,transferred,cancelled'],
            'ward' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:admissionNumber,admittedAt,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([AdmissionResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function appointments(Request $request, SearchCrossTenantAdminAppointmentsUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:scheduled,checked_in,completed,cancelled,no_show'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:appointmentNumber,scheduledAt,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([AppointmentResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function patients(Request $request, SearchCrossTenantAdminPatientsUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:patientNumber,firstName,lastName,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([PatientResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function billingInvoices(Request $request, SearchCrossTenantAdminBillingInvoicesUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:draft,issued,partially_paid,paid,cancelled,voided'],
            'currencyCode' => ['nullable', 'string', 'max:3'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:invoiceNumber,invoiceDate,totalAmount,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([BillingInvoiceResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function laboratoryOrders(Request $request, SearchCrossTenantAdminLaboratoryOrdersUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:ordered,collected,in_progress,completed,cancelled'],
            'priority' => ['nullable', 'string', 'in:routine,urgent,stat'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:orderNumber,orderedAt,status,priority,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([LaboratoryOrderResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function pharmacyOrders(Request $request, SearchCrossTenantAdminPharmacyOrdersUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:pending,in_preparation,partially_dispensed,dispensed,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:orderNumber,orderedAt,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([PharmacyOrderResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function clinicalProcedureOrders(Request $request, SearchCrossTenantAdminClinicalProcedureOrdersUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:ordered,scheduled,in_progress,completed,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:orderNumber,orderedAt,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([ClinicalProcedureOrderResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function medicalRecords(Request $request, SearchCrossTenantAdminMedicalRecordsUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'patientId' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:draft,finalized,amended,archived'],
            'recordType' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:recordNumber,encounterAt,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([MedicalRecordResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function staff(Request $request, SearchCrossTenantAdminStaffProfilesUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'targetTenantCode' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended'],
            'department' => ['nullable', 'string', 'max:100'],
            'employmentType' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sortBy' => ['nullable', 'string', 'in:employeeNumber,department,jobTitle,status,createdAt,updatedAt'],
            'sortDir' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $result = $useCase->execute($validated, $request->user()?->id);
        abort_if($result === null, 404, 'Target tenant not found.');

        return response()->json([
            'data' => array_map([StaffProfileResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }
}
