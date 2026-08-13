<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\ServiceRequest\Application\UseCases\CompleteNurseAssessmentUseCase;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use App\Modules\ServiceRequest\Presentation\Http\Requests\CompleteNurseAssessmentRequest;
use App\Modules\ServiceRequest\Presentation\Http\Transformers\ServiceRequestResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NurseQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) ($request->input('perPage', 20)), 1), 100);
        $page = max((int) ($request->input('page', 1)), 1);

        $encounters = EncounterModel::query()
            ->select('encounters.*')
            ->where('encounters.status', 'opened')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('service_requests')
                    ->whereColumn('service_requests.encounter_id', 'encounters.id')
                    ->whereNotNull('service_requests.assessed_by_user_id');
            })
            ->with(['patient' => function ($query) {
                $query->select(['id', 'patient_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'phone']);
            }])
            ->orderBy('encounters.opened_at', 'asc')
            ->paginate(perPage: $perPage, page: $page);

        $data = $encounters->map(function (EncounterModel $encounter): array {
            $patient = $encounter->patient;
            $age = null;
            if ($patient && $patient->date_of_birth) {
                $age = $patient->date_of_birth->diffInYears(now());
            }

            return [
                'id' => $encounter->id,
                'encounterNumber' => $encounter->encounter_number,
                'patientId' => $encounter->patient_id,
                'appointmentId' => $encounter->appointment_id,
                'status' => $encounter->status,
                'type' => $encounter->type,
                'openedAt' => $encounter->opened_at?->toISOString(),
                'patient' => $patient ? [
                    'id' => $patient->id,
                    'patientNumber' => $patient->patient_number,
                    'firstName' => $patient->first_name,
                    'middleName' => $patient->middle_name,
                    'lastName' => $patient->last_name,
                    'dateOfBirth' => $patient->date_of_birth?->toISOString(),
                    'gender' => $patient->gender,
                    'phone' => $patient->phone,
                    'age' => $age,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $encounters->currentPage(),
                'perPage' => $encounters->perPage(),
                'total' => $encounters->total(),
                'lastPage' => $encounters->lastPage(),
            ],
        ]);
    }

    public function assess(
        string $encounterId,
        CompleteNurseAssessmentRequest $request,
        CompleteNurseAssessmentUseCase $useCase,
    ): JsonResponse {
        $validated = $request->validated();

        $order = $useCase->execute(
            encounterId: $encounterId,
            clinicalNote: $validated['clinicalNote'],
            items: $validated['items'],
            actorId: $request->user()?->id,
        );

        return response()->json([
            'data' => ServiceRequestResponseTransformer::transform($order),
        ], 201);
    }
}
