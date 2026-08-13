<?php

namespace App\Modules\Encounter\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounter\Application\Exceptions\ClinicalDocumentationNotAllowedOnServiceEncounterException;
use App\Modules\Encounter\Application\UseCases\AddEncounterDiagnosisUseCase;
use App\Modules\Encounter\Application\UseCases\RemoveEncounterDiagnosisUseCase;
use App\Modules\Encounter\Presentation\Http\Requests\AddEncounterDiagnosisRequest;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterDiagnosisResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EncounterDiagnosisController extends Controller
{
    public function store(
        string $id,
        AddEncounterDiagnosisRequest $request,
        AddEncounterDiagnosisUseCase $useCase,
    ): JsonResponse {
        try {
            $diagnosis = $useCase->execute(
                encounterId: $id,
                data: $request->validated(),
                actorId: $request->user()?->id,
            );
        } catch (ClinicalDocumentationNotAllowedOnServiceEncounterException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        abort_if($diagnosis === null, 404, 'Encounter not found.');

        return response()->json([
            'data' => EncounterDiagnosisResponseTransformer::transform($diagnosis),
        ], 201);
    }

    public function destroy(
        string $id,
        string $diagnosisId,
        Request $request,
        RemoveEncounterDiagnosisUseCase $useCase,
    ): JsonResponse {
        $removed = $useCase->execute(
            encounterId: $id,
            diagnosisId: $diagnosisId,
            actorId: $request->user()?->id,
        );

        abort_if(! $removed, 404, 'Diagnosis not found.');

        return response()->json(['data' => null]);
    }
}
