<?php

namespace App\Modules\Encounter\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounter\Application\UseCases\GetEncounterWorkspaceUseCase;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterResponseTransformer;
use App\Modules\MedicalRecord\Application\UseCases\GetMedicalRecordUseCase;
use App\Modules\MedicalRecord\Presentation\Http\Controllers\MedicalRecordDocumentController;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\ClinicalDocumentLookup;
use App\Support\Documents\DocumentAuditTrailManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class EncounterDocumentController extends Controller
{
    public function show(
        string $id,
        Request $request,
        GetEncounterWorkspaceUseCase $workspaceUseCase,
        GetMedicalRecordUseCase $recordUseCase,
        MedicalRecordDocumentController $medicalRecordDocumentController,
        DocumentContextLookup $documentContextLookup,
        ClinicalDocumentLookup $clinicalDocumentLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        $workspace = $workspaceUseCase->execute($id);
        abort_if($workspace === null, 404, 'Encounter not found.');

        $record = $this->resolveSignedPrimaryRecord($workspace);
        $encounter = is_array($workspace['encounter'] ?? null) ? $workspace['encounter'] : [];

        return Inertia::render('medical-records/Print', $medicalRecordDocumentController->buildPrintPayload(
            id: (string) $record['id'],
            request: $request,
            recordUseCase: $recordUseCase,
            documentContextLookup: $documentContextLookup,
            clinicalDocumentLookup: $clinicalDocumentLookup,
            brandingManager: $brandingManager,
            encounterSummary: EncounterResponseTransformer::transform($encounter),
        ));
    }

    public function downloadPdf(
        string $id,
        Request $request,
        GetEncounterWorkspaceUseCase $workspaceUseCase,
        GetMedicalRecordUseCase $recordUseCase,
        MedicalRecordDocumentController $medicalRecordDocumentController,
        DocumentContextLookup $documentContextLookup,
        ClinicalDocumentLookup $clinicalDocumentLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
        DocumentAuditTrailManager $documentAuditTrailManager,
        EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
    ): HttpResponse {
        $workspace = $workspaceUseCase->execute($id);
        abort_if($workspace === null, 404, 'Encounter not found.');

        $record = $this->resolveSignedPrimaryRecord($workspace);
        $encounter = is_array($workspace['encounter'] ?? null) ? $workspace['encounter'] : [];

        $payload = $medicalRecordDocumentController->buildPrintPayload(
            id: (string) $record['id'],
            request: $request,
            recordUseCase: $recordUseCase,
            documentContextLookup: $documentContextLookup,
            clinicalDocumentLookup: $clinicalDocumentLookup,
            brandingManager: $brandingManager,
            encounterSummary: EncounterResponseTransformer::transform($encounter),
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($encounter['encounter_number'] ?? $id),
            'encounter',
        );
        $filename = $pdfDocumentManager->makeBrandedFilename('encounter_chart_'.$safeIdentifier);

        $response = $pdfDocumentManager->downloadView(
            view: 'documents.medical-record',
            data: $payload,
            baseName: 'encounter_chart_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'encounter',
                'X-Document-Source-Id' => $id,
            ],
        );

        $documentAuditTrailManager->recordPdfDownload(
            request: $request,
            action: 'encounter.document.pdf.downloaded',
            source: 'encounter',
            sourceId: $id,
            filename: $filename,
            writer: static function (string $action, ?int $actorId, array $changes, array $metadata) use ($encounterAuditLogRepository, $id, $record): void {
                $encounterAuditLogRepository->write(
                    encounterId: $id,
                    action: $action,
                    actorId: $actorId,
                    changes: $changes,
                    metadata: array_merge($metadata, [
                        'primary_medical_record_id' => $record['id'] ?? null,
                    ]),
                );
            },
            extraMetadata: [
                'encounter_id' => $id,
                'encounter_number' => $encounter['encounter_number'] ?? null,
                'primary_medical_record_id' => $record['id'] ?? null,
                'patient_id' => $record['patient_id'] ?? null,
            ],
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $workspace
     * @return array<string, mixed>
     */
    private function resolveSignedPrimaryRecord(array $workspace): array
    {
        $record = is_array($workspace['primaryMedicalRecord'] ?? null)
            ? $workspace['primaryMedicalRecord']
            : null;
        abort_if($record === null, 404, 'No consultation note is linked to this encounter.');

        $status = strtolower(trim((string) ($record['status'] ?? '')));
        abort_if(
            ! in_array($status, ['finalized', 'amended'], true),
            403,
            'Signed chart packet requires a finalized consultation note.',
        );

        return $record;
    }
}
