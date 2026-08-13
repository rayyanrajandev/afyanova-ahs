<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffProfessionalRegistrationException;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffRegulatoryProfileException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffCredentialingDocumentAssignmentException;
use App\Modules\Staff\Application\Exceptions\UnverifiedStaffUserEmailException;
use App\Modules\Staff\Application\UseCases\BatchGetStaffCredentialingSummariesUseCase;
use App\Modules\Staff\Application\UseCases\CreateStaffProfessionalRegistrationUseCase;
use App\Modules\Staff\Application\UseCases\CreateStaffRegulatoryProfileUseCase;
use App\Modules\Staff\Application\UseCases\GetStaffCredentialingSummaryUseCase;
use App\Modules\Staff\Application\UseCases\GetStaffProfessionalRegistrationUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffCredentialingAlertsUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffCredentialingAuditLogsUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffProfessionalRegistrationsUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffProfessionalRegistrationUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffProfessionalRegistrationVerificationUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffRegulatoryProfileUseCase;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;
use App\Modules\Staff\Presentation\Http\Requests\StoreStaffProfessionalRegistrationRequest;
use App\Modules\Staff\Presentation\Http\Requests\StoreStaffRegulatoryProfileRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffProfessionalRegistrationRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffProfessionalRegistrationVerificationRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffRegulatoryProfileRequest;
use App\Modules\Staff\Presentation\Http\Transformers\StaffCredentialingAlertResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffCredentialingAuditLogResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffCredentialingSummaryResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffProfessionalRegistrationResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffRegulatoryProfileResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffCredentialingController extends Controller
{
    public function summaries(Request $request, BatchGetStaffCredentialingSummariesUseCase $useCase): JsonResponse
    {
        $ids = $this->parseStaffIds($request);

        return response()->json([
            'data' => array_map(
                [StaffCredentialingSummaryResponseTransformer::class, 'transform'],
                $useCase->execute($ids),
            ),
        ]);
    }

    public function summary(string $id, GetStaffCredentialingSummaryUseCase $useCase): JsonResponse
    {
        $summary = $useCase->execute($id);
        abort_if($summary === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffCredentialingSummaryResponseTransformer::transform($summary),
        ]);
    }

    public function showRegulatoryProfile(
        string $id,
        StaffProfileRepositoryInterface $staffProfileRepository,
        StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository
    ): JsonResponse
    {
        abort_if($staffProfileRepository->findById($id) === null, 404, 'Staff profile not found.');

        $profile = $staffRegulatoryProfileRepository->findByStaffProfileId($id);

        return response()->json([
            'data' => $profile === null ? null : StaffRegulatoryProfileResponseTransformer::transform($profile),
        ]);
    }

    public function storeRegulatoryProfile(
        string $id,
        StoreStaffRegulatoryProfileRequest $request,
        CreateStaffRegulatoryProfileUseCase $useCase
    ): JsonResponse {
        try {
            $profile = $useCase->execute(
                staffProfileId: $id,
                payload: $this->toRegulatoryProfilePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateStaffRegulatoryProfileException $exception) {
            return $this->validationError('payload', $exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        }

        abort_if($profile === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffRegulatoryProfileResponseTransformer::transform($profile),
        ], 201);
    }

    public function updateRegulatoryProfile(
        string $id,
        UpdateStaffRegulatoryProfileRequest $request,
        UpdateStaffRegulatoryProfileUseCase $useCase
    ): JsonResponse {
        try {
            $profile = $useCase->execute(
                staffProfileId: $id,
                payload: $this->toRegulatoryProfilePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        }

        abort_if($profile === null, 404, 'Staff regulatory profile not found.');

        return response()->json([
            'data' => StaffRegulatoryProfileResponseTransformer::transform($profile),
        ]);
    }

    public function registrations(
        string $id,
        Request $request,
        ListStaffProfessionalRegistrationsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(staffProfileId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffProfessionalRegistrationResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeRegistration(
        string $id,
        StoreStaffProfessionalRegistrationRequest $request,
        CreateStaffProfessionalRegistrationUseCase $useCase
    ): JsonResponse {
        try {
            $registration = $useCase->execute(
                staffProfileId: $id,
                payload: $this->toRegistrationPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateStaffProfessionalRegistrationException $exception) {
            return $this->validationError('registrationNumber', $exception->getMessage());
        } catch (InvalidStaffCredentialingDocumentAssignmentException $exception) {
            return $this->validationError('sourceDocumentId', $exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        }

        abort_if($registration === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => StaffProfessionalRegistrationResponseTransformer::transform($registration),
        ], 201);
    }

    public function showRegistration(
        string $id,
        string $registrationId,
        GetStaffProfessionalRegistrationUseCase $useCase
    ): JsonResponse {
        $registration = $useCase->execute(
            staffProfileId: $id,
            staffProfessionalRegistrationId: $registrationId,
        );
        abort_if($registration === null, 404, 'Staff professional registration not found.');

        return response()->json([
            'data' => StaffProfessionalRegistrationResponseTransformer::transform($registration),
        ]);
    }

    public function updateRegistration(
        string $id,
        string $registrationId,
        UpdateStaffProfessionalRegistrationRequest $request,
        UpdateStaffProfessionalRegistrationUseCase $useCase
    ): JsonResponse {
        try {
            $registration = $useCase->execute(
                staffProfileId: $id,
                staffProfessionalRegistrationId: $registrationId,
                payload: $this->toRegistrationPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DuplicateStaffProfessionalRegistrationException $exception) {
            return $this->validationError('registrationNumber', $exception->getMessage());
        } catch (InvalidStaffCredentialingDocumentAssignmentException $exception) {
            return $this->validationError('sourceDocumentId', $exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        }

        abort_if($registration === null, 404, 'Staff professional registration not found.');

        return response()->json([
            'data' => StaffProfessionalRegistrationResponseTransformer::transform($registration),
        ]);
    }

    public function updateRegistrationVerification(
        string $id,
        string $registrationId,
        UpdateStaffProfessionalRegistrationVerificationRequest $request,
        UpdateStaffProfessionalRegistrationVerificationUseCase $useCase
    ): JsonResponse {
        try {
            $registration = $useCase->execute(
                staffProfileId: $id,
                staffProfessionalRegistrationId: $registrationId,
                verificationStatus: $request->string('verificationStatus')->value(),
                reason: $request->input('reason'),
                verificationNotes: $request->input('verificationNotes'),
                hasVerificationNotes: $request->has('verificationNotes'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (UnverifiedStaffUserEmailException $exception) {
            return $this->validationError('linkedUser', $exception->getMessage());
        }

        abort_if($registration === null, 404, 'Staff professional registration not found.');

        return response()->json([
            'data' => StaffProfessionalRegistrationResponseTransformer::transform($registration),
        ]);
    }

    public function alerts(Request $request, ListStaffCredentialingAlertsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([StaffCredentialingAlertResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListStaffCredentialingAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            staffProfileId: $id,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffCredentialingAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    /**
     * @return array<int, string>
     */
    private function parseStaffIds(Request $request): array
    {
        $raw = $request->query('ids', []);
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $ids),
            static fn (string $value): bool => $value !== '',
        )));
    }

    private function toRegulatoryProfilePersistencePayload(array $validated): array
    {
        $fieldMap = [
            'primaryRegulatorCode' => 'primary_regulator_code',
            'cadreCode' => 'cadre_code',
            'professionalTitle' => 'professional_title',
            'registrationType' => 'registration_type',
            'practiceAuthorityLevel' => 'practice_authority_level',
            'supervisionLevel' => 'supervision_level',
            'goodStandingStatus' => 'good_standing_status',
            'goodStandingCheckedAt' => 'good_standing_checked_at',
            'notes' => 'notes',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }

    private function toRegistrationPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'regulatorCode' => 'regulator_code',
            'registrationCategory' => 'registration_category',
            'registrationNumber' => 'registration_number',
            'licenseNumber' => 'license_number',
            'registrationStatus' => 'registration_status',
            'licenseStatus' => 'license_status',
            'issuedAt' => 'issued_at',
            'expiresAt' => 'expires_at',
            'renewalDueAt' => 'renewal_due_at',
            'cpdCycleStartAt' => 'cpd_cycle_start_at',
            'cpdCycleEndAt' => 'cpd_cycle_end_at',
            'cpdPointsRequired' => 'cpd_points_required',
            'cpdPointsEarned' => 'cpd_points_earned',
            'sourceDocumentId' => 'source_document_id',
            'sourceSystem' => 'source_system',
            'notes' => 'notes',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}
