<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Jobs\GenerateAuditExportCsvJob;
use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\UseCases\CreateFeatureFlagOverrideUseCase;
use App\Modules\Platform\Application\UseCases\DeleteFeatureFlagOverrideUseCase;
use App\Modules\Platform\Application\UseCases\GetEffectiveFeatureFlagUseCase;
use App\Modules\Platform\Application\UseCases\GetCountryProfileUseCase;
use App\Modules\Platform\Application\UseCases\GetInteroperabilityAdapterEnvelopeBaselineUseCase;
use App\Modules\Platform\Application\UseCases\ListEffectiveFeatureFlagsUseCase;
use App\Modules\Platform\Application\UseCases\ListFeatureFlagsUseCase;
use App\Modules\Platform\Application\UseCases\ListFeatureFlagOverrideAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListFeatureFlagOverridesUseCase;
use App\Modules\Platform\Application\UseCases\UpdateFeatureFlagOverrideUseCase;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Modules\Platform\Infrastructure\Models\AuditExportRetryResumeTelemetryEventModel;
use App\Modules\Platform\Presentation\Http\Transformers\CountryProfileResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\FeatureFlagOverrideAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\FeatureFlagOverrideResponseTransformer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PlatformConfigurationController extends Controller
{
    public function accessScope(CurrentPlatformScopeContextInterface $scopeContext): JsonResponse
    {
        return response()->json([
            'data' => $scopeContext->toArray(),
        ]);
    }

    public function countryProfile(Request $request, GetCountryProfileUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->query('code'));
        abort_if($result === null, 404, 'Country profile not found.');

        return response()->json([
            'data' => CountryProfileResponseTransformer::transform($result),
        ]);
    }

    public function featureFlags(Request $request, ListFeatureFlagsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json($result);
    }

    public function interoperabilityAdapterEnvelope(
        Request $request,
        GetInteroperabilityAdapterEnvelopeBaselineUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($request->query('version'));
        abort_if($result === null, 404, 'Interoperability adapter envelope version not found.');

        return response()->json([
            'data' => $result,
        ]);
    }

    public function effectiveFeatureFlags(Request $request, ListEffectiveFeatureFlagsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json($result);
    }

    public function effectiveFeatureFlag(string $name, GetEffectiveFeatureFlagUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($name);
        abort_if($result === null, 404, 'Feature flag not found.');

        return response()->json($result);
    }

    public function featureFlagOverrides(Request $request, ListFeatureFlagOverridesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json($result);
    }

    public function auditExportJobsHealth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'failureLimit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $failureLimit = (int) ($validated['failureLimit'] ?? 5);
        $since = now()->startOfDay()->subDays($days - 1);
        $generatedAt = now();
        $user = $request->user();
        $userId = $user?->id;

        $moduleRegistry = [
            'billing' => [
                'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
                'permission' => 'billing-invoices.view-audit-logs',
                'label' => 'Billing',
            ],
            'laboratory' => [
                'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
                'permission' => 'laboratory-orders.view-audit-logs',
                'label' => 'Laboratory',
            ],
            'pharmacy' => [
                'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
                'permission' => 'pharmacy-orders.view-audit-logs',
                'label' => 'Pharmacy',
            ],
        ];

        $permissions = [];
        $accessibleModuleCodes = [];
        $moduleCodeByValue = [];
        foreach ($moduleRegistry as $moduleCode => $definition) {
            $isAccessible = $user !== null && $user->can((string) $definition['permission']);
            $permissions[$moduleCode] = $isAccessible;

            $moduleValue = (string) $definition['module'];
            $moduleCodeByValue[$moduleValue] = $moduleCode;
            if ($isAccessible) {
                $accessibleModuleCodes[] = $moduleValue;
            }
        }

        $emptyModuleSummary = static fn (array $definition, bool $isAccessible): array => [
            'label' => (string) $definition['label'],
            'module' => (string) $definition['module'],
            'accessible' => $isAccessible,
            'totalRecent' => 0,
            'recentCompleted' => 0,
            'recentFailed' => 0,
            'recentQueued' => 0,
            'recentProcessing' => 0,
            'recentOther' => 0,
            'currentBacklog' => 0,
        ];

        $modules = [];
        foreach ($moduleRegistry as $moduleCode => $definition) {
            $modules[$moduleCode] = $emptyModuleSummary($definition, (bool) ($permissions[$moduleCode] ?? false));
        }

        $trendIndex = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = now()->startOfDay()->subDays($days - 1 - $offset)->toDateString();
            $trendIndex[$date] = [
                'date' => $date,
                'failed' => 0,
                'backlogCreated' => 0,
                'total' => 0,
            ];
        }

        $recentFailuresPayload = [];

        if ($accessibleModuleCodes !== [] && $userId !== null) {
            $recentJobs = AuditExportJobModel::query()
                ->where('created_by_user_id', $userId)
                ->whereIn('module', $accessibleModuleCodes)
                ->where('created_at', '>=', $since)
                ->get([
                    'id',
                    'module',
                    'status',
                    'created_at',
                ]);

            foreach ($recentJobs as $job) {
                $moduleCode = $moduleCodeByValue[(string) $job->module] ?? null;
                if ($moduleCode === null || ! isset($modules[$moduleCode])) {
                    continue;
                }

                $modules[$moduleCode]['totalRecent']++;
                $status = strtolower((string) $job->status);
                if ($status === 'completed') {
                    $modules[$moduleCode]['recentCompleted']++;
                } elseif ($status === 'failed') {
                    $modules[$moduleCode]['recentFailed']++;
                } elseif ($status === 'queued') {
                    $modules[$moduleCode]['recentQueued']++;
                } elseif ($status === 'processing') {
                    $modules[$moduleCode]['recentProcessing']++;
                } else {
                    $modules[$moduleCode]['recentOther']++;
                }

                $createdDate = $job->created_at !== null
                    ? Carbon::parse((string) $job->created_at)->toDateString()
                    : null;
                if ($createdDate !== null && isset($trendIndex[$createdDate])) {
                    $trendIndex[$createdDate]['total']++;
                    if ($status === 'failed') {
                        $trendIndex[$createdDate]['failed']++;
                    }
                    if ($status === 'queued' || $status === 'processing') {
                        $trendIndex[$createdDate]['backlogCreated']++;
                    }
                }
            }

            $backlogJobs = AuditExportJobModel::query()
                ->where('created_by_user_id', $userId)
                ->whereIn('module', $accessibleModuleCodes)
                ->whereIn('status', ['queued', 'processing'])
                ->get(['module']);

            foreach ($backlogJobs as $job) {
                $moduleCode = $moduleCodeByValue[(string) $job->module] ?? null;
                if ($moduleCode === null || ! isset($modules[$moduleCode])) {
                    continue;
                }

                $modules[$moduleCode]['currentBacklog']++;
            }

            $recentFailures = AuditExportJobModel::query()
                ->where('created_by_user_id', $userId)
                ->whereIn('module', $accessibleModuleCodes)
                ->where('status', 'failed')
                ->orderByDesc('failed_at')
                ->orderByDesc('created_at')
                ->limit($failureLimit)
                ->get([
                    'id',
                    'module',
                    'target_resource_id',
                    'error_message',
                    'created_at',
                    'failed_at',
                ]);

            $recentFailuresPayload = $recentFailures
                ->map(function (AuditExportJobModel $job) use ($moduleCodeByValue): array {
                    $moduleCode = $moduleCodeByValue[(string) $job->module] ?? 'unknown';

                    return [
                        'id' => (string) $job->id,
                        'moduleKey' => $moduleCode,
                        'module' => (string) $job->module,
                        'targetResourceId' => (string) $job->target_resource_id,
                        'errorMessage' => $job->error_message,
                        'createdAt' => optional($job->created_at)?->toISOString(),
                        'failedAt' => optional($job->failed_at)?->toISOString(),
                    ];
                })
                ->values()
                ->all();
        }

        $aggregate = [
            'accessibleModuleCount' => count(array_filter($permissions)),
            'totalRecent' => 0,
            'recentCompleted' => 0,
            'recentFailed' => 0,
            'recentQueued' => 0,
            'recentProcessing' => 0,
            'recentOther' => 0,
            'currentBacklog' => 0,
        ];

        foreach ($modules as $moduleSummary) {
            if (! (bool) $moduleSummary['accessible']) {
                continue;
            }

            $aggregate['totalRecent'] += (int) $moduleSummary['totalRecent'];
            $aggregate['recentCompleted'] += (int) $moduleSummary['recentCompleted'];
            $aggregate['recentFailed'] += (int) $moduleSummary['recentFailed'];
            $aggregate['recentQueued'] += (int) $moduleSummary['recentQueued'];
            $aggregate['recentProcessing'] += (int) $moduleSummary['recentProcessing'];
            $aggregate['recentOther'] += (int) $moduleSummary['recentOther'];
            $aggregate['currentBacklog'] += (int) $moduleSummary['currentBacklog'];
        }

        return response()->json([
            'data' => [
                'windowDays' => $days,
                'generatedAt' => $generatedAt->toISOString(),
                'permissions' => $permissions,
                'aggregate' => $aggregate,
                'modules' => $modules,
                'trend' => array_values($trendIndex),
                'recentFailures' => $recentFailuresPayload,
            ],
        ]);
    }

    public function auditExportJobsHealthDrilldown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'in:all,billing,laboratory,pharmacy'],
            'statusGroup' => ['nullable', 'string', 'in:all,failed,backlog,completed'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $moduleFilter = (string) ($validated['module'] ?? 'all');
        $statusGroup = (string) ($validated['statusGroup'] ?? 'all');
        $dateFilter = isset($validated['date']) ? (string) $validated['date'] : null;
        $page = max((int) ($validated['page'] ?? 1), 1);
        $perPage = max(min((int) ($validated['perPage'] ?? 20), 50), 1);
        $generatedAt = now();

        $moduleRegistry = [
            'billing' => [
                'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
                'permission' => 'billing-invoices.view-audit-logs',
                'label' => 'Billing',
                'routePrefix' => 'billing-invoices',
            ],
            'laboratory' => [
                'module' => GenerateAuditExportCsvJob::MODULE_LABORATORY,
                'permission' => 'laboratory-orders.view-audit-logs',
                'label' => 'Laboratory',
                'routePrefix' => 'laboratory-orders',
            ],
            'pharmacy' => [
                'module' => GenerateAuditExportCsvJob::MODULE_PHARMACY,
                'permission' => 'pharmacy-orders.view-audit-logs',
                'label' => 'Pharmacy',
                'routePrefix' => 'pharmacy-orders',
            ],
        ];

        $user = $request->user();
        $userId = $user?->id;
        $permissions = [];
        $accessibleModuleValues = [];
        $moduleCodeByValue = [];
        foreach ($moduleRegistry as $moduleCode => $definition) {
            $isAccessible = $user !== null && $user->can((string) $definition['permission']);
            $permissions[$moduleCode] = $isAccessible;

            $moduleValue = (string) $definition['module'];
            $moduleCodeByValue[$moduleValue] = $moduleCode;
            if ($isAccessible) {
                $accessibleModuleValues[] = $moduleValue;
            }
        }

        $selectedModuleValues = [];
        if ($moduleFilter === 'all') {
            $selectedModuleValues = $accessibleModuleValues;
        } elseif (($permissions[$moduleFilter] ?? false) && isset($moduleRegistry[$moduleFilter])) {
            $selectedModuleValues = [(string) $moduleRegistry[$moduleFilter]['module']];
        }

        if ($userId === null || $selectedModuleValues === []) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'total' => 0,
                    'lastPage' => 1,
                    'filters' => [
                        'module' => $moduleFilter,
                        'statusGroup' => $statusGroup,
                        'date' => $dateFilter,
                    ],
                    'generatedAt' => $generatedAt->toISOString(),
                    'permissions' => $permissions,
                ],
            ]);
        }

        $query = AuditExportJobModel::query()
            ->where('created_by_user_id', $userId)
            ->whereIn('module', $selectedModuleValues);

        if ($statusGroup === 'failed') {
            $query->where('status', 'failed');
        } elseif ($statusGroup === 'backlog') {
            $query->whereIn('status', ['queued', 'processing']);
        } elseif ($statusGroup === 'completed') {
            $query->where('status', 'completed');
        }

        if ($dateFilter !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $dateFilter)->startOfDay();
            $end = (clone $start)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $paginator = $query
            ->orderByDesc('failed_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())
            ->map(function (AuditExportJobModel $job) use ($moduleCodeByValue, $moduleRegistry): array {
                $moduleCode = $moduleCodeByValue[(string) $job->module] ?? 'unknown';
                $moduleDefinition = $moduleRegistry[$moduleCode] ?? null;
                $routePrefix = isset($moduleDefinition['routePrefix']) ? (string) $moduleDefinition['routePrefix'] : null;

                $downloadUrl = null;
                if (
                    $routePrefix !== null
                    && $job->status === 'completed'
                    && is_string($job->file_path)
                    && trim($job->file_path) !== ''
                ) {
                    $downloadUrl = sprintf(
                        '/api/v1/%s/%s/audit-logs/export-jobs/%s/download',
                        $routePrefix,
                        $job->target_resource_id,
                        $job->id,
                    );
                }

                return [
                    'id' => (string) $job->id,
                    'moduleKey' => $moduleCode,
                    'module' => (string) $job->module,
                    'moduleLabel' => $moduleDefinition['label'] ?? 'Unknown',
                    'moduleRoute' => $routePrefix !== null ? '/'.$routePrefix : null,
                    'targetResourceId' => (string) $job->target_resource_id,
                    'status' => (string) $job->status,
                    'rowCount' => $job->row_count,
                    'errorMessage' => $job->error_message,
                    'createdAt' => optional($job->created_at)?->toISOString(),
                    'startedAt' => optional($job->started_at)?->toISOString(),
                    'completedAt' => optional($job->completed_at)?->toISOString(),
                    'failedAt' => optional($job->failed_at)?->toISOString(),
                    'downloadUrl' => $downloadUrl,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'filters' => [
                    'module' => $moduleFilter,
                    'statusGroup' => $statusGroup,
                    'date' => $dateFilter,
                ],
                'generatedAt' => $generatedAt->toISOString(),
                'permissions' => $permissions,
            ],
        ]);
    }

    public function recordAuditExportRetryResumeTelemetryEvent(
        Request $request,
        CurrentPlatformScopeContextInterface $scopeContext
    ): JsonResponse {
        $validated = $request->validate([
            'module' => ['required', 'string', 'in:billing,laboratory,pharmacy'],
            'event' => ['required', 'string', 'in:attempt,success,failure,reset'],
            'failureReason' => ['nullable', 'string', 'max:120'],
            'targetResourceId' => ['nullable', 'uuid'],
            'exportJobId' => ['nullable', 'uuid'],
            'handoffStatusGroup' => ['nullable', 'string', 'in:all,failed,backlog,completed'],
            'handoffPage' => ['nullable', 'integer', 'min:1'],
            'handoffPerPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $module = (string) $validated['module'];
        $event = (string) $validated['event'];
        $failureReason = isset($validated['failureReason'])
            ? trim((string) $validated['failureReason'])
            : null;
        if ($failureReason === '') {
            $failureReason = null;
        }
        $targetResourceId = isset($validated['targetResourceId'])
            ? (string) $validated['targetResourceId']
            : null;
        $exportJobId = isset($validated['exportJobId'])
            ? (string) $validated['exportJobId']
            : null;
        $handoffStatusGroup = isset($validated['handoffStatusGroup'])
            ? (string) $validated['handoffStatusGroup']
            : null;
        $handoffPage = isset($validated['handoffPage'])
            ? (int) $validated['handoffPage']
            : null;
        $handoffPerPage = isset($validated['handoffPerPage'])
            ? (int) $validated['handoffPerPage']
            : null;

        $moduleRegistry = [
            'billing' => [
                'permission' => 'billing-invoices.view-audit-logs',
            ],
            'laboratory' => [
                'permission' => 'laboratory-orders.view-audit-logs',
            ],
            'pharmacy' => [
                'permission' => 'pharmacy-orders.view-audit-logs',
            ],
        ];

        $user = $request->user();
        $permission = (string) ($moduleRegistry[$module]['permission'] ?? '');
        abort_if(
            $user === null || ! $user->can($permission),
            403,
            'You do not have permission to record telemetry for this module.',
        );

        $recordedAt = now();
        $eventModel = AuditExportRetryResumeTelemetryEventModel::query()->create([
            'module_key' => $module,
            'event_type' => $event,
            'failure_reason' => $event === 'failure' ? $failureReason : null,
            'actor_user_id' => $user->id,
            'tenant_id' => $scopeContext->tenantId(),
            'facility_id' => $scopeContext->facilityId(),
            'target_resource_id' => $targetResourceId,
            'export_job_id' => $exportJobId,
            'handoff_status_group' => $handoffStatusGroup,
            'handoff_page' => $handoffPage,
            'handoff_per_page' => $handoffPerPage,
            'occurred_at' => $recordedAt,
        ]);

        return response()->json([
            'data' => [
                'id' => (string) $eventModel->id,
                'module' => $module,
                'event' => $event,
                'recordedAt' => $recordedAt->toISOString(),
            ],
        ], 201);
    }

    public function auditExportRetryResumeTelemetryHealth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'failureLimit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $failureLimit = (int) ($validated['failureLimit'] ?? 5);
        $since = now()->startOfDay()->subDays($days - 1);
        $generatedAt = now();
        $user = $request->user();
        $userId = $user?->id;

        $moduleRegistry = [
            'billing' => [
                'permission' => 'billing-invoices.view-audit-logs',
                'label' => 'Billing',
                'routePrefix' => 'billing-invoices',
            ],
            'laboratory' => [
                'permission' => 'laboratory-orders.view-audit-logs',
                'label' => 'Laboratory',
                'routePrefix' => 'laboratory-orders',
            ],
            'pharmacy' => [
                'permission' => 'pharmacy-orders.view-audit-logs',
                'label' => 'Pharmacy',
                'routePrefix' => 'pharmacy-orders',
            ],
        ];

        $permissions = [];
        $accessibleModuleKeys = [];
        foreach ($moduleRegistry as $moduleKey => $definition) {
            $isAccessible = $user !== null && $user->can((string) $definition['permission']);
            $permissions[$moduleKey] = $isAccessible;
            if ($isAccessible) {
                $accessibleModuleKeys[] = $moduleKey;
            }
        }

        $modules = [];
        foreach ($moduleRegistry as $moduleKey => $definition) {
            $modules[$moduleKey] = [
                'label' => (string) $definition['label'],
                'module' => $moduleKey,
                'accessible' => (bool) ($permissions[$moduleKey] ?? false),
                'totalEvents' => 0,
                'attempts' => 0,
                'successes' => 0,
                'failures' => 0,
                'resets' => 0,
                'successRatePercent' => null,
                'lastEventAt' => null,
                'lastFailureReason' => null,
            ];
        }

        $trendIndex = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = now()->startOfDay()->subDays($days - 1 - $offset)->toDateString();
            $trendIndex[$date] = [
                'date' => $date,
                'totalEvents' => 0,
                'attempts' => 0,
                'successes' => 0,
                'failures' => 0,
                'resets' => 0,
            ];
        }

        $updateLatestIsoTimestamp = static function (?string $current, ?string $candidate): ?string {
            if ($candidate === null) {
                return $current;
            }

            if ($current === null) {
                return $candidate;
            }

            return strtotime($candidate) > strtotime($current) ? $candidate : $current;
        };

        $recentFailuresPayload = [];

        if ($userId !== null && $accessibleModuleKeys !== []) {
            $events = AuditExportRetryResumeTelemetryEventModel::query()
                ->where('actor_user_id', $userId)
                ->whereIn('module_key', $accessibleModuleKeys)
                ->where('occurred_at', '>=', $since)
                ->orderBy('occurred_at')
                ->get([
                    'module_key',
                    'event_type',
                    'failure_reason',
                    'occurred_at',
                ]);

            foreach ($events as $event) {
                $moduleKey = (string) $event->module_key;
                if (! isset($modules[$moduleKey])) {
                    continue;
                }

                $eventType = strtolower((string) $event->event_type);
                $occurredAtIso = optional($event->occurred_at)?->toISOString();
                $occurredDate = optional($event->occurred_at)?->toDateString();
                $failureReason = is_string($event->failure_reason)
                    ? trim((string) $event->failure_reason)
                    : null;

                $modules[$moduleKey]['totalEvents']++;
                $modules[$moduleKey]['lastEventAt'] = $updateLatestIsoTimestamp(
                    $modules[$moduleKey]['lastEventAt'],
                    $occurredAtIso,
                );

                if ($eventType === 'attempt') {
                    $modules[$moduleKey]['attempts']++;
                } elseif ($eventType === 'success') {
                    $modules[$moduleKey]['successes']++;
                } elseif ($eventType === 'failure') {
                    $modules[$moduleKey]['failures']++;
                    if ($failureReason !== '') {
                        $modules[$moduleKey]['lastFailureReason'] = $failureReason;
                    }
                } elseif ($eventType === 'reset') {
                    $modules[$moduleKey]['resets']++;
                }

                if ($occurredDate !== null && isset($trendIndex[$occurredDate])) {
                    $trendIndex[$occurredDate]['totalEvents']++;
                    if ($eventType === 'attempt') {
                        $trendIndex[$occurredDate]['attempts']++;
                    } elseif ($eventType === 'success') {
                        $trendIndex[$occurredDate]['successes']++;
                    } elseif ($eventType === 'failure') {
                        $trendIndex[$occurredDate]['failures']++;
                    } elseif ($eventType === 'reset') {
                        $trendIndex[$occurredDate]['resets']++;
                    }
                }
            }

            $recentFailuresPayload = AuditExportRetryResumeTelemetryEventModel::query()
                ->where('actor_user_id', $userId)
                ->whereIn('module_key', $accessibleModuleKeys)
                ->where('event_type', 'failure')
                ->where('occurred_at', '>=', $since)
                ->orderByDesc('occurred_at')
                ->limit($failureLimit)
                ->get([
                    'id',
                    'module_key',
                    'failure_reason',
                    'occurred_at',
                ])
                ->map(static function (AuditExportRetryResumeTelemetryEventModel $event): array {
                    $failureReason = is_string($event->failure_reason)
                        ? trim((string) $event->failure_reason)
                        : null;

                    return [
                        'id' => (string) $event->id,
                        'moduleKey' => (string) $event->module_key,
                        'module' => (string) $event->module_key,
                        'failureReason' => $failureReason !== '' ? $failureReason : null,
                        'occurredAt' => optional($event->occurred_at)?->toISOString(),
                    ];
                })
                ->values()
                ->all();
        }

        $aggregate = [
            'accessibleModuleCount' => count(array_filter($permissions)),
            'totalEvents' => 0,
            'attempts' => 0,
            'successes' => 0,
            'failures' => 0,
            'resets' => 0,
            'successRatePercent' => null,
            'lastEventAt' => null,
        ];

        foreach ($modules as &$moduleSummary) {
            if (! (bool) $moduleSummary['accessible']) {
                continue;
            }

            $attempts = (int) $moduleSummary['attempts'];
            $successes = (int) $moduleSummary['successes'];
            $moduleSummary['successRatePercent'] = $attempts > 0
                ? round(($successes / $attempts) * 100, 1)
                : null;

            $aggregate['totalEvents'] += (int) $moduleSummary['totalEvents'];
            $aggregate['attempts'] += $attempts;
            $aggregate['successes'] += $successes;
            $aggregate['failures'] += (int) $moduleSummary['failures'];
            $aggregate['resets'] += (int) $moduleSummary['resets'];
            $aggregate['lastEventAt'] = $updateLatestIsoTimestamp(
                $aggregate['lastEventAt'],
                is_string($moduleSummary['lastEventAt']) ? $moduleSummary['lastEventAt'] : null,
            );
        }
        unset($moduleSummary);

        $aggregate['successRatePercent'] = $aggregate['attempts'] > 0
            ? round(($aggregate['successes'] / $aggregate['attempts']) * 100, 1)
            : null;

        $cleanupRetentionDays = max(
            (int) config('platform_audit_retention.audit_export_retry_resume_telemetry.retention_days', 60),
            1,
        );
        $cleanupCutoff = now()->subDays($cleanupRetentionDays);
        $cleanupCutoffTimestamp = $cleanupCutoff->toDateTimeString();
        $cleanupModuleSlices = [];
        foreach ($moduleRegistry as $moduleKey => $definition) {
            $cleanupModuleSlices[$moduleKey] = [
                'module' => $moduleKey,
                'label' => (string) $definition['label'],
                'accessible' => (bool) ($permissions[$moduleKey] ?? false),
                'retainedRowsEstimate' => 0,
                'expiredRowsEstimate' => 0,
            ];
        }

        if ($userId !== null && $accessibleModuleKeys !== []) {
            $cleanupCountsByModule = AuditExportRetryResumeTelemetryEventModel::query()
                ->select('module_key')
                ->selectRaw(
                    'SUM(CASE WHEN occurred_at >= ? THEN 1 ELSE 0 END) AS retained_rows_estimate',
                    [$cleanupCutoffTimestamp]
                )
                ->selectRaw(
                    'SUM(CASE WHEN occurred_at < ? THEN 1 ELSE 0 END) AS expired_rows_estimate',
                    [$cleanupCutoffTimestamp]
                )
                ->where('actor_user_id', $userId)
                ->whereIn('module_key', $accessibleModuleKeys)
                ->groupBy('module_key')
                ->get();

            foreach ($cleanupCountsByModule as $moduleCount) {
                $moduleKey = (string) $moduleCount->module_key;
                if (! isset($cleanupModuleSlices[$moduleKey])) {
                    continue;
                }

                $cleanupModuleSlices[$moduleKey]['retainedRowsEstimate'] = (int) ($moduleCount->retained_rows_estimate ?? 0);
                $cleanupModuleSlices[$moduleKey]['expiredRowsEstimate'] = (int) ($moduleCount->expired_rows_estimate ?? 0);
            }
        }

        $retainedRowsEstimate = 0;
        $expiredRowsEstimate = 0;
        foreach ($cleanupModuleSlices as $moduleSlice) {
            if (! (bool) ($moduleSlice['accessible'] ?? false)) {
                continue;
            }

            $retainedRowsEstimate += (int) ($moduleSlice['retainedRowsEstimate'] ?? 0);
            $expiredRowsEstimate += (int) ($moduleSlice['expiredRowsEstimate'] ?? 0);
        }

        $cleanupLastReportPath = trim(
            str_replace(
                '\\',
                '/',
                (string) config(
                    'platform_audit_retention.audit_export_retry_resume_telemetry.observability.cleanup_last_report_path',
                    'platform-audit/retry-resume-telemetry-cleanup-last-report.json'
                )
            ),
            '/',
        );
        $cleanupStaleAfterHours = max(
            (int) config(
                'platform_audit_retention.audit_export_retry_resume_telemetry.observability.cleanup_stale_after_hours',
                30
            ),
            1,
        );

        $lastCleanup = null;
        if ($cleanupLastReportPath !== '' && Storage::disk('local')->exists($cleanupLastReportPath)) {
            try {
                $rawLastReport = Storage::disk('local')->get($cleanupLastReportPath);
                $parsedLastReport = json_decode($rawLastReport, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($parsedLastReport)) {
                    $ranAt = isset($parsedLastReport['ranAt']) && is_string($parsedLastReport['ranAt'])
                        ? $parsedLastReport['ranAt']
                        : null;
                    $lagHours = null;
                    if ($ranAt !== null) {
                        try {
                            $lagHours = max(Carbon::parse($ranAt)->diffInHours(now()), 0);
                        } catch (\Throwable) {
                            $lagHours = null;
                        }
                    }
                    $lagStatus = 'unknown';
                    if ($lagHours !== null) {
                        $lagStatus = $lagHours <= $cleanupStaleAfterHours ? 'fresh' : 'stale';
                    }

                    $totals = isset($parsedLastReport['totals']) && is_array($parsedLastReport['totals'])
                        ? $parsedLastReport['totals']
                        : [];

                    $lastCleanup = [
                        'status' => isset($parsedLastReport['status']) && is_string($parsedLastReport['status'])
                            ? $parsedLastReport['status']
                            : 'unknown',
                        'mode' => isset($parsedLastReport['mode']) && is_string($parsedLastReport['mode'])
                            ? $parsedLastReport['mode']
                            : 'unknown',
                        'ranAt' => $ranAt,
                        'lagHours' => $lagHours,
                        'lagStatus' => $lagStatus,
                        'retentionDays' => isset($parsedLastReport['retentionDays'])
                            ? (int) $parsedLastReport['retentionDays']
                            : null,
                        'batchSize' => isset($parsedLastReport['batchSize'])
                            ? (int) $parsedLastReport['batchSize']
                            : null,
                        'deletionPerformed' => isset($parsedLastReport['deletionPerformed'])
                            ? (bool) $parsedLastReport['deletionPerformed']
                            : null,
                        'truncatedByBatch' => isset($parsedLastReport['truncatedByBatch'])
                            ? (bool) $parsedLastReport['truncatedByBatch']
                            : null,
                        'totals' => [
                            'totalRows' => isset($totals['totalRows']) ? (int) $totals['totalRows'] : null,
                            'candidateRowsBefore' => isset($totals['candidateRowsBefore'])
                                ? (int) $totals['candidateRowsBefore']
                                : null,
                            'candidateRowsDeleted' => isset($totals['candidateRowsDeleted'])
                                ? (int) $totals['candidateRowsDeleted']
                                : null,
                            'candidateRowsRemaining' => isset($totals['candidateRowsRemaining'])
                                ? (int) $totals['candidateRowsRemaining']
                                : null,
                        ],
                    ];
                }
            } catch (\Throwable) {
                $lastCleanup = null;
            }
        }

        return response()->json([
            'data' => [
                'windowDays' => $days,
                'generatedAt' => $generatedAt->toISOString(),
                'permissions' => $permissions,
                'aggregate' => $aggregate,
                'modules' => $modules,
                'trend' => array_values($trendIndex),
                'recentFailures' => $recentFailuresPayload,
                'cleanupObservability' => [
                    'retentionDays' => $cleanupRetentionDays,
                    'staleAfterHours' => $cleanupStaleAfterHours,
                    'cutoffTimestamp' => $cleanupCutoff->toISOString(),
                    'retainedRowsEstimate' => $retainedRowsEstimate,
                    'expiredRowsEstimate' => $expiredRowsEstimate,
                    'moduleSlices' => $cleanupModuleSlices,
                    'lastCleanup' => $lastCleanup,
                ],
            ],
        ]);
    }

    public function auditExportRetryResumeTelemetryHealthDrilldown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'in:all,billing,laboratory,pharmacy'],
            'event' => ['nullable', 'string', 'in:all,attempt,success,failure,reset'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'failureReason' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $moduleFilter = (string) ($validated['module'] ?? 'all');
        $eventFilter = (string) ($validated['event'] ?? 'all');
        $dateFilter = isset($validated['date']) ? (string) $validated['date'] : null;
        $failureReasonFilter = isset($validated['failureReason'])
            ? trim((string) $validated['failureReason'])
            : null;
        if ($failureReasonFilter === '') {
            $failureReasonFilter = null;
        }
        $page = max((int) ($validated['page'] ?? 1), 1);
        $perPage = max(min((int) ($validated['perPage'] ?? 20), 50), 1);
        $generatedAt = now();

        $moduleRegistry = [
            'billing' => [
                'permission' => 'billing-invoices.view-audit-logs',
                'label' => 'Billing',
                'routePrefix' => 'billing-invoices',
            ],
            'laboratory' => [
                'permission' => 'laboratory-orders.view-audit-logs',
                'label' => 'Laboratory',
                'routePrefix' => 'laboratory-orders',
            ],
            'pharmacy' => [
                'permission' => 'pharmacy-orders.view-audit-logs',
                'label' => 'Pharmacy',
                'routePrefix' => 'pharmacy-orders',
            ],
        ];

        $user = $request->user();
        $userId = $user?->id;
        $permissions = [];
        $accessibleModuleKeys = [];
        foreach ($moduleRegistry as $moduleKey => $definition) {
            $isAccessible = $user !== null && $user->can((string) $definition['permission']);
            $permissions[$moduleKey] = $isAccessible;
            if ($isAccessible) {
                $accessibleModuleKeys[] = $moduleKey;
            }
        }

        $selectedModuleKeys = [];
        if ($moduleFilter === 'all') {
            $selectedModuleKeys = $accessibleModuleKeys;
        } elseif (($permissions[$moduleFilter] ?? false) && isset($moduleRegistry[$moduleFilter])) {
            $selectedModuleKeys = [$moduleFilter];
        }

        if ($userId === null || $selectedModuleKeys === []) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'total' => 0,
                    'lastPage' => 1,
                    'filters' => [
                        'module' => $moduleFilter,
                        'event' => $eventFilter,
                        'date' => $dateFilter,
                        'failureReason' => $failureReasonFilter,
                    ],
                    'failureReasonSlice' => [],
                    'generatedAt' => $generatedAt->toISOString(),
                    'permissions' => $permissions,
                ],
            ]);
        }

        $baseQuery = AuditExportRetryResumeTelemetryEventModel::query()
            ->where('actor_user_id', $userId)
            ->whereIn('module_key', $selectedModuleKeys);

        if ($eventFilter !== 'all') {
            $baseQuery->where('event_type', $eventFilter);
        }

        if ($dateFilter !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $dateFilter)->startOfDay();
            $end = (clone $start)->endOfDay();
            $baseQuery->whereBetween('occurred_at', [$start, $end]);
        }

        if ($failureReasonFilter !== null) {
            $baseQuery->where('failure_reason', 'like', '%'.$failureReasonFilter.'%');
        }

        $reasonSliceRows = (clone $baseQuery)
            ->whereNotNull('failure_reason')
            ->where('failure_reason', '<>', '')
            ->selectRaw('failure_reason, COUNT(*) as event_count')
            ->groupBy('failure_reason')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(5)
            ->get();

        $failureReasonSlice = $reasonSliceRows
            ->map(static function ($row): array {
                return [
                    'reason' => (string) ($row->failure_reason ?? ''),
                    'count' => (int) ($row->event_count ?? 0),
                ];
            })
            ->filter(static fn (array $item): bool => $item['reason'] !== '')
            ->values()
            ->all();

        $paginator = $baseQuery
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())
            ->map(static function (AuditExportRetryResumeTelemetryEventModel $event) use ($moduleRegistry): array {
                $moduleKey = (string) $event->module_key;
                $moduleLabel = isset($moduleRegistry[$moduleKey]['label'])
                    ? (string) $moduleRegistry[$moduleKey]['label']
                    : 'Unknown';
                $routePrefix = isset($moduleRegistry[$moduleKey]['routePrefix'])
                    ? (string) $moduleRegistry[$moduleKey]['routePrefix']
                    : null;
                $failureReason = is_string($event->failure_reason)
                    ? trim((string) $event->failure_reason)
                    : null;

                return [
                    'id' => (string) $event->id,
                    'moduleKey' => $moduleKey,
                    'module' => $moduleKey,
                    'moduleLabel' => $moduleLabel,
                    'moduleRoute' => $routePrefix !== null ? '/'.$routePrefix : null,
                    'event' => (string) $event->event_type,
                    'failureReason' => $failureReason !== '' ? $failureReason : null,
                    'targetResourceId' => $event->target_resource_id,
                    'exportJobId' => $event->export_job_id,
                    'handoffStatusGroup' => $event->handoff_status_group,
                    'handoffPage' => $event->handoff_page,
                    'handoffPerPage' => $event->handoff_per_page,
                    'occurredAt' => optional($event->occurred_at)?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'filters' => [
                    'module' => $moduleFilter,
                    'event' => $eventFilter,
                    'date' => $dateFilter,
                    'failureReason' => $failureReasonFilter,
                ],
                'failureReasonSlice' => $failureReasonSlice,
                'generatedAt' => $generatedAt->toISOString(),
                'permissions' => $permissions,
            ],
        ]);
    }

    public function exportAuditExportRetryResumeTelemetryHealthDrilldown(Request $request): Response
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'in:all,billing,laboratory,pharmacy'],
            'event' => ['nullable', 'string', 'in:all,attempt,success,failure,reset'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'failureReason' => ['nullable', 'string', 'max:120'],
        ]);

        $moduleFilter = (string) ($validated['module'] ?? 'all');
        $eventFilter = (string) ($validated['event'] ?? 'all');
        $dateFilter = isset($validated['date']) ? (string) $validated['date'] : null;
        $failureReasonFilter = isset($validated['failureReason'])
            ? trim((string) $validated['failureReason'])
            : null;
        if ($failureReasonFilter === '') {
            $failureReasonFilter = null;
        }

        $moduleRegistry = [
            'billing' => [
                'permission' => 'billing-invoices.view-audit-logs',
                'label' => 'Billing',
                'routePrefix' => 'billing-invoices',
            ],
            'laboratory' => [
                'permission' => 'laboratory-orders.view-audit-logs',
                'label' => 'Laboratory',
                'routePrefix' => 'laboratory-orders',
            ],
            'pharmacy' => [
                'permission' => 'pharmacy-orders.view-audit-logs',
                'label' => 'Pharmacy',
                'routePrefix' => 'pharmacy-orders',
            ],
        ];

        $user = $request->user();
        $userId = $user?->id;
        $permissions = [];
        $accessibleModuleKeys = [];
        foreach ($moduleRegistry as $moduleKey => $definition) {
            $isAccessible = $user !== null && $user->can((string) $definition['permission']);
            $permissions[$moduleKey] = $isAccessible;
            if ($isAccessible) {
                $accessibleModuleKeys[] = $moduleKey;
            }
        }

        $selectedModuleKeys = [];
        if ($moduleFilter === 'all') {
            $selectedModuleKeys = $accessibleModuleKeys;
        } elseif (($permissions[$moduleFilter] ?? false) && isset($moduleRegistry[$moduleFilter])) {
            $selectedModuleKeys = [$moduleFilter];
        }

        $rows = collect();
        if ($userId !== null && $selectedModuleKeys !== []) {
            $query = AuditExportRetryResumeTelemetryEventModel::query()
                ->where('actor_user_id', $userId)
                ->whereIn('module_key', $selectedModuleKeys);

            if ($eventFilter !== 'all') {
                $query->where('event_type', $eventFilter);
            }

            if ($dateFilter !== null) {
                $start = Carbon::createFromFormat('Y-m-d', $dateFilter)->startOfDay();
                $end = (clone $start)->endOfDay();
                $query->whereBetween('occurred_at', [$start, $end]);
            }

            if ($failureReasonFilter !== null) {
                $query->where('failure_reason', 'like', '%'.$failureReasonFilter.'%');
            }

            $rows = $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('created_at')
                ->get();
        }

        return $this->streamCsvExport(
            baseName: implode('_', [
                'audit_retry_resume_telemetry',
                $moduleFilter,
                $eventFilter,
                now()->format('Ymd_His'),
            ]),
            columns: [
                'occurredAt',
                'module',
                'event',
                'failureReason',
                'targetResourceId',
                'exportJobId',
                'handoffStatusGroup',
                'handoffPage',
                'handoffPerPage',
                'queueHandoffUrl',
            ],
            writeRows: function ($handle) use ($rows, $moduleRegistry): void {
                foreach ($rows as $event) {
                    fputcsv($handle, $this->auditExportRetryResumeTelemetryCsvRow($event, $moduleRegistry));
                }
            },
            schemaHeaderName: 'X-Audit-CSV-Schema-Version',
            schemaVersion: 'audit-retry-resume-telemetry-csv.v1',
        );
    }

    public function createFeatureFlagOverride(Request $request, CreateFeatureFlagOverrideUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'flagName' => ['required', 'string', 'max:150'],
            'scopeType' => ['required', 'string', 'in:country,tenant,facility'],
            'scopeKey' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $created = $useCase->execute(
                payload: $this->toOverridePersistencePayload($validated),
                actorId: $request->user()?->id,
            );
        } catch (DomainException $exception) {
            return $this->validationError('flagName', $exception->getMessage());
        }

        return response()->json([
            'data' => FeatureFlagOverrideResponseTransformer::transform($created),
        ], 201);
    }

    public function updateFeatureFlagOverride(
        string $id,
        Request $request,
        UpdateFeatureFlagOverrideUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'flagName' => ['prohibited'],
            'scopeType' => ['prohibited'],
            'scopeKey' => ['prohibited'],
        ]);

        if ($validated === []) {
            return $this->validationError('payload', 'At least one updatable field is required.');
        }

        $payload = [];
        if (array_key_exists('enabled', $validated)) {
            $payload['enabled'] = (bool) $validated['enabled'];
        }
        if (array_key_exists('reason', $validated)) {
            $payload['reason'] = $validated['reason'];
        }
        if (array_key_exists('metadata', $validated)) {
            $payload['metadata'] = $validated['metadata'];
        }

        $updated = $useCase->execute(
            id: $id,
            payload: $payload,
            actorId: $request->user()?->id,
        );

        abort_if($updated === null, 404, 'Feature flag override not found.');

        return response()->json([
            'data' => FeatureFlagOverrideResponseTransformer::transform($updated),
        ]);
    }

    public function deleteFeatureFlagOverride(
        string $id,
        Request $request,
        DeleteFeatureFlagOverrideUseCase $useCase
    ): Response {
        $deleted = $useCase->execute(id: $id, actorId: $request->user()?->id);
        abort_if(! $deleted, 404, 'Feature flag override not found.');

        return response()->noContent();
    }

    public function featureFlagOverrideAuditLogs(
        string $id,
        Request $request,
        ListFeatureFlagOverrideAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(featureFlagOverrideId: $id, filters: $request->all());

        return response()->json([
            'data' => array_map([FeatureFlagOverrideAuditLogResponseTransformer::class, 'transform'], $result['data']),
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

    /**
     * @param  array<string, array{permission:string,label:string,routePrefix:string}>  $moduleRegistry
     * @return array<int, string|null>
     */
    private function auditExportRetryResumeTelemetryCsvRow(
        AuditExportRetryResumeTelemetryEventModel $event,
        array $moduleRegistry,
    ): array {
        $moduleKey = (string) $event->module_key;
        $routePrefix = isset($moduleRegistry[$moduleKey]['routePrefix'])
            ? (string) $moduleRegistry[$moduleKey]['routePrefix']
            : null;

        $queueHandoffUrl = null;
        if ($routePrefix !== null) {
            $query = [];
            if (is_string($event->target_resource_id) && $event->target_resource_id !== '') {
                $query['q'] = $event->target_resource_id;
            }
            if (
                (string) $event->event_type === 'failure'
                && is_string($event->export_job_id)
                && $event->export_job_id !== ''
            ) {
                $query['auditExportJobId'] = $event->export_job_id;
                $query['auditAction'] = 'retry';
                $query['auditExportStatusGroup'] = $event->handoff_status_group ?: 'failed';
                $query['auditExportPage'] = $event->handoff_page ?: 1;
                $query['auditExportPerPage'] = $event->handoff_per_page ?: 15;
            }

            $queryString = http_build_query($query);
            $queueHandoffUrl = '/'.$routePrefix.($queryString !== '' ? '?'.$queryString : '');
        }

        $failureReason = is_string($event->failure_reason)
            ? trim((string) $event->failure_reason)
            : null;

        return [
            optional($event->occurred_at)?->toISOString(),
            $moduleKey,
            (string) $event->event_type,
            $failureReason !== '' ? $failureReason : null,
            $event->target_resource_id,
            $event->export_job_id,
            $event->handoff_status_group,
            $event->handoff_page,
            $event->handoff_per_page,
            $queueHandoffUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toOverridePersistencePayload(array $validated): array
    {
        $payload = [
            'flag_name' => (string) ($validated['flagName'] ?? ''),
            'scope_type' => (string) ($validated['scopeType'] ?? ''),
            'scope_key' => (string) ($validated['scopeKey'] ?? ''),
            'enabled' => (bool) ($validated['enabled'] ?? false),
        ];

        if (array_key_exists('reason', $validated)) {
            $payload['reason'] = $validated['reason'];
        }

        if (array_key_exists('metadata', $validated)) {
            $payload['metadata'] = $validated['metadata'];
        }

        return $payload;
    }
}
