<?php

namespace App\Modules\Patient\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patient\Application\Exceptions\DuplicatePatientException;
use App\Modules\Patient\Application\Support\PatientCsvSchema;
use App\Modules\Patient\Application\UseCases\BulkImportPatientsUseCase;
use App\Modules\Patient\Application\UseCases\CheckPatientDuplicatesUseCase;
use App\Modules\Patient\Application\UseCases\CreatePatientUseCase;
use App\Modules\Patient\Application\UseCases\ExportPatientsCsvUseCase;
use App\Modules\Patient\Application\UseCases\GetPatientSummaryUseCase;
use App\Modules\Patient\Application\UseCases\GetPatientUseCase;
use App\Modules\Patient\Application\UseCases\ListPatientAuditLogsUseCase;
use App\Modules\Patient\Application\UseCases\ListPatientStatusCountsUseCase;
use App\Modules\Patient\Application\UseCases\ListPatientsUseCase;
use App\Modules\Patient\Application\UseCases\UpdatePatientStatusUseCase;
use App\Modules\Patient\Application\UseCases\UpdatePatientUseCase;
use App\Modules\Patient\Presentation\Http\Requests\BulkImportPatientsRequest;
use App\Modules\Patient\Presentation\Http\Requests\CheckPatientDuplicatesRequest;
use App\Modules\Patient\Presentation\Http\Requests\StorePatientRequest;
use App\Modules\Patient\Presentation\Http\Requests\UpdatePatientRequest;
use App\Modules\Patient\Presentation\Http\Requests\UpdatePatientStatusRequest;
use App\Modules\Patient\Presentation\Http\Transformers\PatientActivityFeedEventResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientAuditLogResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientSummaryResponseTransformer;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\ServiceRequest\Application\UseCases\ListActiveWalkInsForPatientIdsUseCase;
use App\Modules\ServiceRequest\Application\UseCases\SummarizeActiveWalkInsForPatientIdsUseCase;
use App\Modules\ServiceRequest\Presentation\Http\Transformers\ServiceRequestResponseTransformer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(
        Request $request,
        ListPatientsUseCase $useCase,
        SummarizeActiveWalkInsForPatientIdsUseCase $walkInSummaries,
        ListActiveWalkInsForPatientIdsUseCase $activeWalkIns,
        FeatureFlagResolverInterface $featureFlags,
    ): JsonResponse {
        $result = $useCase->execute($request->all());

        $user = $request->user();
        $summariesByPatientId = [];
        $activeWalkInsByPatientId = [];

        $walkInSummaryViaPatientRead = $featureFlags->isEnabled(
            'clinical.walk_ins.routing_summary_on_patient_list',
            true,
        );

        $canSummarizeWalkIns =
            $user !== null
            && (
                $user->can('service.requests.read')
                || $user->can('service.requests.create')
                || (
                    $user->can('patients.read')
                    && $walkInSummaryViaPatientRead
                )
            );

        if ($canSummarizeWalkIns) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $result['data'];
            $ids = [];

            foreach ($rows as $row) {
                if (isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
                    $ids[] = $row['id'];
                }
            }

            $summariesByPatientId = $walkInSummaries->execute($ids);
            $activeWalkInsByPatientId = $activeWalkIns->execute($ids);
        }

        $data = array_map(function (array $patient) use ($summariesByPatientId, $activeWalkInsByPatientId): array {
            $transformed = PatientResponseTransformer::transform($patient);

            $id = is_string($transformed['id'] ?? null) ? (string) $transformed['id'] : '';
            $summary = $id !== '' && isset($summariesByPatientId[$id])
                ? $summariesByPatientId[$id]
                : null;

            return array_merge($transformed, [
                /** One-line clerk visibility for active walk-ins */
                'routingHandoffSummary' => $summary,
                'activeRoutingTickets' => array_map(
                    [ServiceRequestResponseTransformer::class, 'transform'],
                    $id !== '' ? ($activeWalkInsByPatientId[$id] ?? []) : [],
                ),
            ]);
        }, $result['data']);

        return response()->json([
            'data' => $data,
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListPatientStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function checkDuplicates(CheckPatientDuplicatesRequest $request, CheckPatientDuplicatesUseCase $useCase): JsonResponse
    {
        $validated = $request->validated();
        $excludePatientId = $validated['excludePatientId'] ?? null;
        unset($validated['excludePatientId']);

        $result = $useCase->execute(
            payload: $this->toPersistencePayload($validated),
            excludePatientId: $excludePatientId,
        );

        return response()->json(['data' => $result]);
    }

    public function store(StorePatientRequest $request, CreatePatientUseCase $useCase): JsonResponse
    {
        $validated = $request->validated();
        $syncContext = $this->registrationSyncContext($request, $validated);

        if ($syncContext !== null) {
            $existingSync = $this->findRegistrationSync($syncContext['idempotencyKey']);

            if ($existingSync !== null) {
                return $this->registrationSyncReplayResponse($existingSync, $syncContext['requestHash']);
            }
        }

        try {
            $result = DB::transaction(function () use ($syncContext, $useCase, $validated, $request): array {
                if ($syncContext !== null) {
                    $this->createRegistrationSyncPlaceholder($syncContext);
                }

                $created = $useCase->execute(
                    payload: $this->toPersistencePayload($validated),
                    actorId: $request->user()?->id,
                );

                if ($syncContext !== null) {
                    $this->completeRegistrationSync($syncContext['idempotencyKey'], $created);
                }

                return $created;
            });
        } catch (QueryException $exception) {
            if ($syncContext !== null) {
                $existingSync = $this->findRegistrationSync($syncContext['idempotencyKey']);

                if ($existingSync !== null) {
                    return $this->registrationSyncReplayResponse($existingSync, $syncContext['requestHash']);
                }
            }

            throw $exception;
        } catch (DuplicatePatientException $exception) {
            return response()->json([
                'message' => 'Another active patient already uses this National ID or patient number.',
                'duplicates' => $exception->getDuplicates(),
            ], 409);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        return response()->json([
            'data' => PatientResponseTransformer::transform($result['patient']),
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function show(
        string $id,
        Request $request,
        GetPatientUseCase $useCase,
        SummarizeActiveWalkInsForPatientIdsUseCase $walkInSummaries,
        ListActiveWalkInsForPatientIdsUseCase $activeWalkIns,
        FeatureFlagResolverInterface $featureFlags,
    ): JsonResponse {
        $patient = $useCase->execute($id);
        abort_if($patient === null, 404, 'Patient not found.');

        $routingHandoffSummary = null;
        $activeRoutingTickets = [];

        if ($this->canViewRoutingHandoff($request, $featureFlags)) {
            $routingHandoffSummary = $walkInSummaries->execute([$id])[$id] ?? null;
            $activeRoutingTickets = array_map(
                [ServiceRequestResponseTransformer::class, 'transform'],
                $activeWalkIns->execute([$id])[$id] ?? [],
            );
        }

        return response()->json([
            'data' => array_merge(PatientResponseTransformer::transform($patient), [
                'routingHandoffSummary' => $routingHandoffSummary,
                'activeRoutingTickets' => $activeRoutingTickets,
            ]),
        ]);
    }

    public function update(string $id, UpdatePatientRequest $request, UpdatePatientUseCase $useCase): JsonResponse
    {
        $patient = PatientModel::find($id);
        if ($patient === null) {
            abort(404, 'Patient not found.');
        }
        Gate::authorize('updateDemographics', $patient);

        try {
            $result = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (DuplicatePatientException $exception) {
            return response()->json([
                'message' => 'Another active patient already uses this National ID or patient number.',
                'duplicates' => $exception->getDuplicates(),
            ], 409);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }
        abort_if($result === null, 404, 'Patient not found.');

        return response()->json([
            'data' => PatientResponseTransformer::transform($result['patient']),
            'warnings' => $result['warnings'],
        ]);
    }

    public function updateStatus(
        string $id,
        UpdatePatientStatusRequest $request,
        UpdatePatientStatusUseCase $useCase
    ): JsonResponse {
        try {
            $patient = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (DuplicatePatientException $exception) {
            return response()->json([
                'message' => 'Another active patient already uses this National ID or patient number.',
                'duplicates' => $exception->getDuplicates(),
            ], 409);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($patient === null, 404, 'Patient not found.');

        return response()->json([
            'data' => PatientResponseTransformer::transform($patient),
        ]);
    }

    public function summary(string $id, GetPatientSummaryUseCase $useCase): JsonResponse
    {
        $summary = $useCase->execute($id);
        abort_if($summary === null, 404, 'Patient not found.');

        return response()->json([
            'data' => PatientSummaryResponseTransformer::transform($summary),
        ]);
    }

    public function activityFeed(string $id, Request $request, ListPatientAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(patientId: $id, filters: [
            'page' => $request->integer('page', 1),
            'perPage' => min(max($request->integer('perPage', 12), 1), 25),
        ]);

        abort_if($result === null, 404, 'Patient not found.');

        return response()->json([
            'data' => array_map([PatientActivityFeedEventResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function auditLogs(string $id, Request $request, ListPatientAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(patientId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Patient not found.');

        return response()->json([
            'data' => array_map([PatientAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListPatientAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            patientId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Patient not found.');

        $safeId = $this->safeExportIdentifier($id, 'patient');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('patient_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    patientId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function exportCsv(Request $request, ExportPatientsCsvUseCase $useCase): StreamedResponse
    {
        $export = $useCase->execute($request->all());
        $columns = $export['columns'];
        $rows = $export['rows'];

        return $this->streamCsvExport(
            baseName: sprintf('patients_backup_%s', now()->format('Ymd_His')),
            columns: $columns,
            writeRows: static function ($output) use ($columns, $rows): void {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $column) {
                        $line[] = $row[$column] ?? '';
                    }
                    fputcsv($output, $line);
                }
            },
            schemaHeaderName: 'X-Patients-Csv-Schema',
            schemaVersion: PatientCsvSchema::SCHEMA_VERSION,
        );
    }

    public function importTemplate(): StreamedResponse
    {
        $columns = PatientCsvSchema::COLUMNS;
        $example = PatientCsvSchema::exampleRow();

        return $this->streamCsvExport(
            baseName: 'patients_import_template',
            columns: $columns,
            writeRows: static function ($output) use ($columns, $example): void {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = $example[$column] ?? '';
                }
                fputcsv($output, $line);
            },
            schemaHeaderName: 'X-Patients-Csv-Schema',
            schemaVersion: PatientCsvSchema::SCHEMA_VERSION,
        );
    }

    public function bulkImport(BulkImportPatientsRequest $request, BulkImportPatientsUseCase $useCase): JsonResponse
    {
        $validated = $request->validated();

        $result = $useCase->execute(
            rows: $validated['rows'],
            dryRun: (bool) $validated['dryRun'],
            actorId: $request->user()?->id,
        );

        return response()->json(['data' => $result]);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function canViewRoutingHandoff(Request $request, FeatureFlagResolverInterface $featureFlags): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $user->can('service.requests.read')
            || $user->can('service.requests.create')
            || (
                $user->can('patients.read')
                && $featureFlags->isEnabled('clinical.walk_ins.routing_summary_on_patient_list', true)
            );
    }

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'firstName' => 'first_name',
            'middleName' => 'middle_name',
            'lastName' => 'last_name',
            'gender' => 'gender',
            'dateOfBirth' => 'date_of_birth',
            'phone' => 'phone',
            'email' => 'email',
            'nationalId' => 'national_id',
            'countryCode' => 'country_code',
            'region' => 'region',
            'district' => 'district',
            'addressLine' => 'address_line',
            'nextOfKinName' => 'next_of_kin_name',
            'nextOfKinPhone' => 'next_of_kin_phone',
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

    /**
     * @param array<string, mixed> $validated
     * @return array{idempotencyKey: string, offlineRegistrationId: string|null, requestHash: string, userId: int|null}|null
     */
    private function registrationSyncContext(Request $request, array $validated): ?array
    {
        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key', ''));

        if ($idempotencyKey === '') {
            return null;
        }

        $requestPayload = $this->toPersistencePayload($validated);
        ksort($requestPayload);

        return [
            'idempotencyKey' => Str::limit($idempotencyKey, 160, ''),
            'offlineRegistrationId' => $this->nullableHeader($request, 'X-Offline-Registration-Id'),
            'requestHash' => hash('sha256', json_encode($requestPayload, JSON_THROW_ON_ERROR)),
            'userId' => $request->user()?->id,
        ];
    }

    private function nullableHeader(Request $request, string $name): ?string
    {
        $value = trim((string) $request->header($name, ''));

        return $value === '' ? null : Str::limit($value, 160, '');
    }

    /**
     * @param array{idempotencyKey: string, offlineRegistrationId: string|null, requestHash: string, userId: int|null} $syncContext
     */
    private function createRegistrationSyncPlaceholder(array $syncContext): void
    {
        DB::table('patient_registration_syncs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'user_id' => $syncContext['userId'],
            'patient_id' => null,
            'idempotency_key' => $syncContext['idempotencyKey'],
            'offline_registration_id' => $syncContext['offlineRegistrationId'],
            'request_hash' => $syncContext['requestHash'],
            'response_payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function completeRegistrationSync(string $idempotencyKey, array $result): void
    {
        $patient = $result['patient'];
        $responsePayload = [
            'data' => PatientResponseTransformer::transform($patient),
            'warnings' => $result['warnings'],
        ];

        DB::table('patient_registration_syncs')
            ->where('idempotency_key', $idempotencyKey)
            ->update([
                'tenant_id' => $patient['tenant_id'] ?? null,
                'patient_id' => $patient['id'] ?? null,
                'response_payload' => json_encode($responsePayload, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function findRegistrationSync(string $idempotencyKey): ?object
    {
        return DB::table('patient_registration_syncs')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function registrationSyncReplayResponse(object $sync, string $requestHash): JsonResponse
    {
        if (($sync->request_hash ?? null) !== $requestHash) {
            return response()->json([
                'message' => 'This patient registration sync key was already used for a different payload.',
            ], 409);
        }

        if (empty($sync->patient_id) || empty($sync->response_payload)) {
            return response()->json([
                'message' => 'This patient registration is already being uploaded. Please try again shortly.',
            ], 409);
        }

        $payload = is_string($sync->response_payload)
            ? json_decode($sync->response_payload, true, 512, JSON_THROW_ON_ERROR)
            : (array) $sync->response_payload;

        return response()->json($payload, 200);
    }
}
