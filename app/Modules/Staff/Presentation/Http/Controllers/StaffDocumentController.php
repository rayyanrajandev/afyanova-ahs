<?php

namespace App\Modules\Staff\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Staff\Application\UseCases\CreateStaffDocumentUseCase;
use App\Modules\Staff\Application\UseCases\GetStaffDocumentUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffDocumentAuditLogsUseCase;
use App\Modules\Staff\Application\UseCases\ListStaffDocumentsUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffDocumentStatusUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffDocumentUseCase;
use App\Modules\Staff\Application\UseCases\UpdateStaffDocumentVerificationUseCase;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Staff\Presentation\Http\Requests\StoreStaffDocumentRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffDocumentRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffDocumentStatusRequest;
use App\Modules\Staff\Presentation\Http\Requests\UpdateStaffDocumentVerificationRequest;
use App\Modules\Staff\Presentation\Http\Transformers\StaffDocumentAuditLogResponseTransformer;
use App\Modules\Staff\Presentation\Http\Transformers\StaffDocumentResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffDocumentController extends Controller
{
    public function index(string $id, Request $request, ListStaffDocumentsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(staffProfileId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Staff profile not found.');

        return response()->json([
            'data' => array_map([StaffDocumentResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(
        string $id,
        StoreStaffDocumentRequest $request,
        CreateStaffDocumentUseCase $useCase,
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
        $relativePath = sprintf('staff-documents/%s/%s/%s', $tenantSegment, $id, $storedFilename);

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
                staffProfileId: $id,
                payload: [
                    'document_type' => $request->string('documentType')->value(),
                    'title' => $request->string('title')->value(),
                    'description' => $request->input('description'),
                    'file_path' => $relativePath,
                    'original_filename' => $originalFilename,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'file_size_bytes' => (int) $file->getSize(),
                    'checksum_sha256' => $checksum !== '' ? $checksum : str_repeat('0', 64),
                    'issued_at' => $request->input('issuedAt'),
                    'expires_at' => $request->input('expiresAt'),
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
            abort(404, 'Staff profile not found.');
        }

        return response()->json([
            'data' => StaffDocumentResponseTransformer::transform($document),
        ], 201);
    }

    public function show(string $id, string $documentId, GetStaffDocumentUseCase $useCase): JsonResponse
    {
        $document = $useCase->execute(staffProfileId: $id, staffDocumentId: $documentId);
        abort_if($document === null, 404, 'Staff document not found.');

        return response()->json([
            'data' => StaffDocumentResponseTransformer::transform($document),
        ]);
    }

    public function update(
        string $id,
        string $documentId,
        UpdateStaffDocumentRequest $request,
        UpdateStaffDocumentUseCase $useCase
    ): JsonResponse {
        try {
            $document = $useCase->execute(
                staffProfileId: $id,
                staffDocumentId: $documentId,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($document === null, 404, 'Staff document not found.');

        return response()->json([
            'data' => StaffDocumentResponseTransformer::transform($document),
        ]);
    }

    public function updateVerification(
        string $id,
        string $documentId,
        UpdateStaffDocumentVerificationRequest $request,
        UpdateStaffDocumentVerificationUseCase $useCase
    ): JsonResponse {
        try {
            $document = $useCase->execute(
                staffProfileId: $id,
                staffDocumentId: $documentId,
                verificationStatus: $request->string('verificationStatus')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($document === null, 404, 'Staff document not found.');

        return response()->json([
            'data' => StaffDocumentResponseTransformer::transform($document),
        ]);
    }

    public function updateStatus(
        string $id,
        string $documentId,
        UpdateStaffDocumentStatusRequest $request,
        UpdateStaffDocumentStatusUseCase $useCase
    ): JsonResponse {
        try {
            $document = $useCase->execute(
                staffProfileId: $id,
                staffDocumentId: $documentId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($document === null, 404, 'Staff document not found.');

        return response()->json([
            'data' => StaffDocumentResponseTransformer::transform($document),
        ]);
    }

    public function download(
        string $id,
        string $documentId,
        GetStaffDocumentUseCase $useCase
    ): StreamedResponse {
        $document = $useCase->execute(staffProfileId: $id, staffDocumentId: $documentId);
        abort_if($document === null, 404, 'Staff document not found.');

        $path = (string) ($document['file_path'] ?? '');
        abort_if($path === '', 404, 'Document file path is missing.');
        abort_if(! Storage::disk('local')->exists($path), 404, 'Document file not found.');

        $filename = (string) ($document['original_filename'] ?? basename($path));
        $safeFilename = trim($filename) !== '' ? $filename : basename($path);

        return Storage::disk('local')->download($path, $safeFilename);
    }

    public function auditLogs(
        string $id,
        string $documentId,
        Request $request,
        ListStaffDocumentAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            staffProfileId: $id,
            staffDocumentId: $documentId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Staff document not found.');

        return response()->json([
            'data' => array_map([StaffDocumentAuditLogResponseTransformer::class, 'transform'], $result['data']),
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

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'documentType' => 'document_type',
            'title' => 'title',
            'description' => 'description',
            'issuedAt' => 'issued_at',
            'expiresAt' => 'expires_at',
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
