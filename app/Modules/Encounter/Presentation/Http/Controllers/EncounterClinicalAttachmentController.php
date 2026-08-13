<?php

namespace App\Modules\Encounter\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounter\Application\UseCases\CreateEncounterClinicalDocumentUseCase;
use App\Modules\Encounter\Application\UseCases\GetEncounterClinicalDocumentUseCase;
use App\Modules\Encounter\Application\UseCases\ListEncounterClinicalDocumentsUseCase;
use App\Modules\Encounter\Application\UseCases\UpdateEncounterClinicalDocumentStatusUseCase;
use App\Modules\Encounter\Application\UseCases\UpdateEncounterClinicalDocumentUseCase;
use App\Modules\Encounter\Domain\Repositories\EncounterAuditLogRepositoryInterface;
use App\Modules\Encounter\Presentation\Http\Requests\StoreEncounterClinicalDocumentRequest;
use App\Modules\Encounter\Presentation\Http\Requests\UpdateEncounterClinicalDocumentRequest;
use App\Modules\Encounter\Presentation\Http\Requests\UpdateEncounterClinicalDocumentStatusRequest;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterClinicalDocumentResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EncounterClinicalAttachmentController extends Controller
{
    public function index(
        string $id,
        Request $request,
        ListEncounterClinicalDocumentsUseCase $useCase,
    ): JsonResponse {
        $result = $useCase->execute(encounterId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Encounter not found.');

        return response()->json([
            'data' => array_map(
                [EncounterClinicalDocumentResponseTransformer::class, 'transform'],
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function store(
        string $id,
        StoreEncounterClinicalDocumentRequest $request,
        CreateEncounterClinicalDocumentUseCase $useCase,
        CurrentPlatformScopeContextInterface $scopeContext,
    ): JsonResponse {
        $file = $request->file('file');
        if ($file === null) {
            return $this->validationError('file', 'Document file is required.');
        }

        $originalFilename = $file->getClientOriginalName();
        $tenantSegment = $scopeContext->tenantId() ?? 'unscoped';
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $storedFilename = (string) Str::uuid().($extension !== '' ? '.'.$extension : '');
        $relativePath = sprintf('encounter-documents/%s/%s/%s', $tenantSegment, $id, $storedFilename);

        Storage::disk('local')->putFileAs(
            path: dirname($relativePath),
            file: $file,
            name: basename($relativePath),
        );

        $fullPath = $file->getRealPath();
        $checksum = is_string($fullPath) && $fullPath !== ''
            ? hash_file('sha256', $fullPath) ?: ''
            : '';

        try {
            $document = $useCase->execute(
                encounterId: $id,
                payload: [
                    'document_type' => $request->string('documentType')->value(),
                    'title' => $request->string('title')->value(),
                    'description' => $request->input('description'),
                    'file_path' => $relativePath,
                    'original_filename' => $originalFilename,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'file_size_bytes' => (int) $file->getSize(),
                    'checksum_sha256' => $checksum !== '' ? $checksum : str_repeat('0', 64),
                ],
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            Storage::disk('local')->delete($relativePath);

            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($relativePath);
            throw $exception;
        }

        if ($document === null) {
            Storage::disk('local')->delete($relativePath);
            abort(404, 'Encounter not found.');
        }

        return response()->json([
            'data' => EncounterClinicalDocumentResponseTransformer::transform($document),
        ], 201);
    }

    public function show(
        string $id,
        string $documentId,
        GetEncounterClinicalDocumentUseCase $useCase,
    ): JsonResponse {
        $document = $useCase->execute(encounterId: $id, documentId: $documentId);
        abort_if($document === null, 404, 'Clinical document not found.');

        return response()->json([
            'data' => EncounterClinicalDocumentResponseTransformer::transform($document),
        ]);
    }

    public function update(
        string $id,
        string $documentId,
        UpdateEncounterClinicalDocumentRequest $request,
        UpdateEncounterClinicalDocumentUseCase $useCase,
    ): JsonResponse {
        try {
            $document = $useCase->execute(
                encounterId: $id,
                documentId: $documentId,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($document === null, 404, 'Clinical document not found.');

        return response()->json([
            'data' => EncounterClinicalDocumentResponseTransformer::transform($document),
        ]);
    }

    public function updateStatus(
        string $id,
        string $documentId,
        UpdateEncounterClinicalDocumentStatusRequest $request,
        UpdateEncounterClinicalDocumentStatusUseCase $useCase,
    ): JsonResponse {
        try {
            $document = $useCase->execute(
                encounterId: $id,
                documentId: $documentId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($document === null, 404, 'Clinical document not found.');

        return response()->json([
            'data' => EncounterClinicalDocumentResponseTransformer::transform($document),
        ]);
    }

    public function download(
        string $id,
        string $documentId,
        Request $request,
        GetEncounterClinicalDocumentUseCase $useCase,
        EncounterAuditLogRepositoryInterface $encounterAuditLogRepository,
    ): StreamedResponse {
        $document = $useCase->execute(encounterId: $id, documentId: $documentId);
        abort_if($document === null, 404, 'Clinical document not found.');

        $path = (string) ($document['file_path'] ?? '');
        abort_if($path === '', 404, 'Document file path is missing.');
        abort_if(! Storage::disk('local')->exists($path), 404, 'Document file not found.');

        $filename = (string) ($document['original_filename'] ?? basename($path));
        $safeFilename = trim($filename) !== '' ? $filename : basename($path);

        $encounterAuditLogRepository->write(
            encounterId: $id,
            action: 'encounter.clinical-document.downloaded',
            actorId: $request->user()?->id,
            changes: [],
            metadata: [
                'clinical_document_id' => $documentId,
                'original_filename' => $document['original_filename'] ?? null,
                'document_type' => $document['document_type'] ?? null,
            ],
        );

        return Storage::disk('local')->download($path, $safeFilename);
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

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'documentType' => 'document_type',
            'title' => 'title',
            'description' => 'description',
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
