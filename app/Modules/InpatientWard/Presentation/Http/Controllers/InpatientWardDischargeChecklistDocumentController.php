<?php

namespace App\Modules\InpatientWard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InpatientWard\Application\UseCases\GetInpatientWardDischargeChecklistUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardCarePlansUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardRoundNotesUseCase;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardFollowUpRailRepositoryInterface;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardCarePlanResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardDischargeChecklistResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardFollowUpRailResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardRoundNoteResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentAuditTrailManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class InpatientWardDischargeChecklistDocumentController extends Controller
{
    public function show(
        string $id,
        GetInpatientWardDischargeChecklistUseCase $getChecklistUseCase,
        ListInpatientWardRoundNotesUseCase $roundNotesUseCase,
        ListInpatientWardCarePlansUseCase $carePlansUseCase,
        InpatientWardFollowUpRailRepositoryInterface $followUpRailRepository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('inpatient-ward/Print', $this->buildPayload(
            id: $id,
            getChecklistUseCase: $getChecklistUseCase,
            roundNotesUseCase: $roundNotesUseCase,
            carePlansUseCase: $carePlansUseCase,
            followUpRailRepository: $followUpRailRepository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPdf(
        string $id,
        Request $request,
        GetInpatientWardDischargeChecklistUseCase $getChecklistUseCase,
        ListInpatientWardRoundNotesUseCase $roundNotesUseCase,
        ListInpatientWardCarePlansUseCase $carePlansUseCase,
        InpatientWardFollowUpRailRepositoryInterface $followUpRailRepository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
        DocumentAuditTrailManager $documentAuditTrailManager,
        InpatientWardDischargeChecklistAuditLogRepositoryInterface $auditLogRepository,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            getChecklistUseCase: $getChecklistUseCase,
            roundNotesUseCase: $roundNotesUseCase,
            carePlansUseCase: $carePlansUseCase,
            followUpRailRepository: $followUpRailRepository,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['admission']['admissionNumber'] ?? $id),
            'discharge_summary',
        );
        $filename = $pdfDocumentManager->makeBrandedFilename('discharge_summary_'.$safeIdentifier);

        $response = $pdfDocumentManager->downloadView(
            view: 'documents.inpatient-discharge',
            data: $payload,
            baseName: 'discharge_summary_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'inpatient-discharge',
                'X-Document-Source-Id' => (string) ($payload['checklist']['id'] ?? $id),
            ],
        );

        $documentAuditTrailManager->recordPdfDownload(
            request: $request,
            action: 'inpatient-ward-discharge-checklist.document.pdf.downloaded',
            source: 'inpatient-discharge',
            sourceId: (string) ($payload['checklist']['id'] ?? $id),
            filename: $filename,
            writer: static function (string $action, ?int $actorId, array $changes, array $metadata) use ($auditLogRepository, $payload, $id): void {
                $auditLogRepository->write(
                    inpatientWardDischargeChecklistId: (string) ($payload['checklist']['id'] ?? $id),
                    action: $action,
                    actorId: $actorId,
                    changes: $changes,
                    metadata: $metadata,
                );
            },
            extraMetadata: [
                'document_number' => $payload['admission']['admissionNumber'] ?? null,
                'patient_id' => $payload['patient']['id'] ?? null,
                'admission_id' => $payload['admission']['id'] ?? null,
                'checklist_status' => $payload['checklist']['status'] ?? null,
            ],
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $id,
        GetInpatientWardDischargeChecklistUseCase $getChecklistUseCase,
        ListInpatientWardRoundNotesUseCase $roundNotesUseCase,
        ListInpatientWardCarePlansUseCase $carePlansUseCase,
        InpatientWardFollowUpRailRepositoryInterface $followUpRailRepository,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $checklist = $getChecklistUseCase->execute($id);
        abort_if($checklist === null, 404, 'Inpatient ward discharge checklist not found.');

        $admissionId = trim((string) ($checklist['admission_id'] ?? ''));

        $roundNotes = [];
        $carePlans = [];
        $followUpRail = $this->emptyFollowUpRail($admissionId, $checklist['patient_id'] ?? null);

        if ($admissionId !== '') {
            $roundNotesResult = $roundNotesUseCase->execute([
                'admissionId' => $admissionId,
                'page' => 1,
                'perPage' => 3,
                'sortBy' => 'roundedAt',
                'sortDir' => 'desc',
            ]);

            $roundNotes = array_map(
                fn (array $note): array => array_merge(
                    InpatientWardRoundNoteResponseTransformer::transform($note),
                    [
                        'author' => $documentContextLookup->userSummary($note['author_user_id'] ?? null),
                        'acknowledgedBy' => $documentContextLookup->userSummary($note['acknowledged_by_user_id'] ?? null),
                    ],
                ),
                $roundNotesResult['data'] ?? [],
            );

            $carePlansResult = $carePlansUseCase->execute([
                'admissionId' => $admissionId,
                'page' => 1,
                'perPage' => 3,
                'sortBy' => 'updatedAt',
                'sortDir' => 'desc',
            ]);

            $carePlans = array_map(
                fn (array $carePlan): array => array_merge(
                    InpatientWardCarePlanResponseTransformer::transform($carePlan),
                    [
                        'author' => $documentContextLookup->userSummary($carePlan['author_user_id'] ?? null),
                        'lastUpdatedBy' => $documentContextLookup->userSummary($carePlan['last_updated_by_user_id'] ?? null),
                    ],
                ),
                $carePlansResult['data'] ?? [],
            );

            $followUpRail = InpatientWardFollowUpRailResponseTransformer::transform([
                'admissionId' => $admissionId,
                'patientId' => $checklist['patient_id'] ?? null,
                'generatedAt' => now()->toISOString(),
                'modules' => $followUpRailRepository->summarizeForAdmission($admissionId, 3),
            ]);
        }

        return [
            'checklist' => InpatientWardDischargeChecklistResponseTransformer::transform($checklist),
            'patient' => $documentContextLookup->patientSummary($checklist['patient_id'] ?? null),
            'admission' => $documentContextLookup->admissionSummary($admissionId),
            'reviewer' => $documentContextLookup->userSummary($checklist['last_reviewed_by_user_id'] ?? null),
            'roundNotes' => $roundNotes,
            'carePlans' => $carePlans,
            'followUpRail' => $followUpRail,
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFollowUpRail(string $admissionId, mixed $patientId): array
    {
        return InpatientWardFollowUpRailResponseTransformer::transform([
            'admissionId' => $admissionId !== '' ? $admissionId : null,
            'patientId' => is_string($patientId) && trim($patientId) !== '' ? trim($patientId) : null,
            'generatedAt' => now()->toISOString(),
            'modules' => [],
        ]);
    }
}
