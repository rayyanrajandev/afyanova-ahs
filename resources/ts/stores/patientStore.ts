/**
 * Patient Store (Volume 1.4 §3.1)
 * ================================
 * Manages patient demographics, current patient context, and search.
 * FHIR-aligned Patient shape (Volume 1.3 §2, Volume 1.4 §4.1).
 * Used by Reception (Volume 2.1) and all clinical workspaces.
 */

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

// ---- FHIR-aligned Patient type (Volume 1.4 §4.1) ----
export interface Patient {
    resourceType: 'Patient';
    id: string;
    identifier: { system: string; value: string }[]; // MRN
    name: { family: string; given: string[] }[];
    birthDate: string;
    gender: 'male' | 'female' | 'other' | 'unknown';
    telecom: { system: 'phone' | 'email'; value: string }[];
    address: { line: string[]; city: string; district: string }[];
    // Not part of FHIR's core Patient shape, but the backend already sends
    // both (PatientResponseTransformer) and Edit demographics (Volume 2.1
    // §8.3) needs them to pre-fill the form — added 2026-08-10 rather than
    // re-fetching the raw row separately.
    nationalId: string | null;
    countryCode: string | null;
    // Same reasoning, added 2026-08-12 (Patient Registration UX direction
    // Phase 2): PatientResponseTransformer already returns all three on
    // every reception patient response, but Edit Demographics silently
    // dropped them on the floor — `given[1]` already carries middleName
    // for FHIR-shaped name display elsewhere, but there was no flat field
    // for the edit form to read back out. Editing a patient without these
    // pre-filled from the real stored value would PATCH empty strings over
    // whatever middle name / next-of-kin data already existed (the same
    // "looks like data loss" bug class the Region/District casing fix
    // guarded against).
    middleName: string | null;
    nextOfKinName: string | null;
    nextOfKinPhone: string | null;
    meta: {
        extension: {
            age: number;
            allergies: { substance: string; severity: 'mild' | 'moderate' | 'severe' }[];
        };
    };
}

/**
 * Backend Patient rows are a flat shape (FirstName/LastName/Gender/… — see
 * PatientResponseTransformer). The workspace model is FHIR-aligned, so the
 * store adapts rows here instead of the pages doing per-field mapping.
 */
export interface BackendPatientRow {
    id: string;
    patientNumber: string;
    firstName: string | null;
    middleName?: string | null;
    lastName: string | null;
    gender: 'male' | 'female' | 'other' | 'unknown' | null;
    dateOfBirth: string | null;
    phone?: string | null;
    email?: string | null;
    addressLine?: string | null;
    region?: string | null;
    district?: string | null;
    nationalId?: string | null;
    countryCode?: string | null;
    nextOfKinName?: string | null;
    nextOfKinPhone?: string | null;
    createdAt?: string | null;
    updatedAt?: string | null;
}

const MRN_SYSTEM = 'http://afyanova.health/mrn';

/** Map a flat backend Patient row into the FHIR-aligned workspace shape. */
export function patientFromBackend(row: Partial<BackendPatientRow>): Patient {
    return toPatient(row);
}

function ageFrom(dateOfBirth: string | null | undefined): number {
    if (!dateOfBirth) return 0;
    const dob = new Date(dateOfBirth);
    if (Number.isNaN(dob.getTime())) return 0;
    const now = new Date();
    let age = now.getFullYear() - dob.getFullYear();
    const m = now.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age -= 1;
    return Math.max(age, 0);
}

function toPatient(row: Partial<BackendPatientRow>): Patient {
    return {
        resourceType: 'Patient',
        // Not String(row.id): that turns a missing id into the literal string
        // "undefined", which is truthy, so it survives every `if (!id)` guard
        // and gets sent to the API as a patient id. An empty string is falsy,
        // so a malformed payload fails where it happens instead of downstream.
        id: row.id != null ? String(row.id) : '',
        identifier: row.patientNumber
            ? [{ system: MRN_SYSTEM, value: row.patientNumber }]
            : [],
        name: [
            {
                family: row.lastName ?? '',
                given: [row.firstName ?? '', row.middleName ?? undefined].filter(
                    (v): v is string => typeof v === 'string' && v !== '',
                ),
            },
        ],
        birthDate: row.dateOfBirth ?? '',
        gender: row.gender ?? 'unknown',
        telecom: [
            ...(row.phone ? [{ system: 'phone' as const, value: row.phone }] : []),
            ...(row.email ? [{ system: 'email' as const, value: row.email }] : []),
        ],
        address: [
            {
                line: row.addressLine ? [row.addressLine] : [],
                city: row.region ?? '',
                district: row.district ?? '',
            },
        ],
        nationalId: row.nationalId ?? null,
        countryCode: row.countryCode ?? null,
        middleName: row.middleName ?? null,
        nextOfKinName: row.nextOfKinName ?? null,
        nextOfKinPhone: row.nextOfKinPhone ?? null,
        meta: {
            extension: {
                age: ageFrom(row.dateOfBirth),
                allergies: [],
            },
        },
    };
}

// ---- Patient summary (Volume 2.1 §8 profile sections) ----
// Backs GET /patients/{id}/summary — one aggregated round trip covering
// contact, insurance, allergies, latest encounter, and upcoming appointment,
// rather than the reception profile fanning out several requests itself.
export interface PatientAllergySummary {
    id: string;
    substanceName: string | null;
    reaction: string | null;
    severity: 'mild' | 'moderate' | 'severe' | null;
    status: string | null;
}

export interface PatientInsuranceSummary {
    // Added 2026-08-11 (§16 #10, Insurance add/verify UI) — the backend
    // transformer always included this (PatientInsuranceRecordResponseTransformer,
    // reused as-is by the summary endpoint), the frontend type just never
    // captured it since nothing needed to construct a PATCH .../insurance/{id}
    // URL before now.
    id: string | null;
    insuranceProvider: string | null;
    planName: string | null;
    memberId: string | null;
    policyNumber: string | null;
    coverageLevel: string | null;
    status: string | null;
    verificationStatus: string | null;
    expiryDate: string | null;
}

export interface PatientLatestEncounterSummary {
    id: string;
    encounterNumber: string | null;
    status: string | null;
    openedAt: string | null;
    closedAt: string | null;
    primaryClinicianName: string | null;
}

export interface PatientUpcomingAppointmentSummary {
    id: string;
    appointmentNumber: string | null;
    department: string | null;
    scheduledAt: string | null;
    reason: string | null;
}

/**
 * The patient's current unresolved visit, if any (2026-08-12,
 * duplicate-check-in fix) — `status` is one of AppointmentStatus's
 * arrived-not-yet-resolved values (waiting_triage/waiting_provider/
 * in_consultation; never 'scheduled', a merely-future booking doesn't
 * count as "already checked in"). Backs the Check-In button's disabled
 * state on PatientProfileView.vue.
 */
export interface PatientActiveAppointmentSummary {
    id: string;
    appointmentNumber: string | null;
    status: string | null;
    /**
     * Server-resolved flow step (PatientFlowStep) — authoritative for the badge.
     * `status` alone cannot express a nursing pickup, which is why the profile
     * pane read "Waiting for Triage" for a patient the queue beside it already
     * showed as "With Nurse".
     */
    visitStage: string | null;
    scheduledAt: string | null;
    department: string | null;
}

export interface PatientRecentActivityEntry {
    type: string | null;
    label: string | null;
    occurredAt: string | null;
}

export interface PatientSummary {
    contact: {
        email: string | null;
        addressLine: string | null;
        nextOfKinName: string | null;
        nextOfKinPhone: string | null;
    };
    alerts: PatientAllergySummary[];
    insurance: PatientInsuranceSummary | null;
    latestEncounter: PatientLatestEncounterSummary | null;
    upcomingAppointment: PatientUpcomingAppointmentSummary | null;
    recentActivity: PatientRecentActivityEntry[];
    activeAppointment: PatientActiveAppointmentSummary | null;
}

const BASE_URL = '/api/v1';

export const usePatientStore = defineStore('patient', () => {
    // ---- State (Map for O(1) lookup, Volume 1.4 §3.3) ----
    const patients = ref<Map<string, Patient>>(new Map());
    const currentPatientId = ref<string | null>(null);
    const searchResults = ref<Patient[]>([]);
    const isLoading = ref(false);
    /**
     * List/search failures only. Bound directly to the patient list panels'
     * `:error` slot, so nothing that isn't a failure of *the list itself* may
     * ever be written here — a single-patient lookup writing to this is what
     * made an emptied database render "Failed to fetch patient <uuid>" where
     * the empty-state placeholder belonged.
     */
    const error = ref<string | null>(null);
    /**
     * Single-patient lookup failures, kept apart from `error` for the reason
     * above. A 404 is deliberately NOT recorded here: a patient that no longer
     * exists is an expected state (deleted record, stale bookmark, stale
     * localStorage recent), not a fault to report.
     */
    const detailError = ref<string | null>(null);
    // Total matching the current fetchPatients() call (server-side pagination
    // meta.total, not searchResults.value.length — the list endpoint's
    // perPage default is 50, so total can exceed what's actually loaded).
    // Powers the Patients tab's count badge (2026-08-11).
    const totalPatientCount = ref(0);
    // Nursing's own patient list (Volume 2.3 §4.1, Volume 3.8 Phase 1) —
    // deliberately a separate ref from `searchResults` above, not reused:
    // that one is reception's search-box result set, a different concept
    // from "the ward patient list a nurse is looking at" even though both
    // resolve through this same store's `toPatient()`. Conflating them
    // would mean one workspace's fetch silently clobbers what the other
    // renders if both are ever active in the same session.
    const nursingPatients = ref<Patient[]>([]);
    const isNursingPatientsLoading = ref(false);
    const nursingPatientsError = ref<string | null>(null);

    // ---- Getters ----
    const currentPatient = computed(() =>
        currentPatientId.value ? patients.value.get(currentPatientId.value) ?? null : null,
    );

    // ---- Actions ----
    function setCurrentPatient(mrn: string) {
        currentPatientId.value = mrn;
    }

    function clearCurrentPatient() {
        currentPatientId.value = null;
    }

    function cachePatient(patient: Patient) {
        patients.value.set(patient.id, patient);
    }

    /** GET /api/v1/reception/patients (Volume 2.1 §12.2) — patient list, paginated, searchable */
    async function fetchPatients(query?: string): Promise<Patient[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const url = query?.trim()
                ? `${BASE_URL}/reception/patients?q=${encodeURIComponent(query.trim())}`
                : `${BASE_URL}/reception/patients`;
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch patients');
            const body = (await res.json()) as {
                data?: BackendPatientRow[];
                meta?: { total?: number };
            };
            const list = (body.data ?? []).map(toPatient);
            list.forEach(cachePatient);
            searchResults.value = list;
            totalPatientCount.value = body.meta?.total ?? list.length;
            return list;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch patients';
            searchResults.value = [];
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * GET /api/v1/nursing/patients (Volume 2.3 §12.2, Volume 3.8 Phase 1) —
     * same backend controller and response shape as `fetchPatients` above
     * (`PatientController::index`, confirmed live: identical `patientNumber`/
     * `firstName`/`lastName`/... fields), reached via nursing's own scoped
     * route rather than reception's — same rule as everywhere else in this
     * codebase, a workspace calls its own `/{workspace}/*` contract even
     * when it shares backend logic with another. Ward/bed (Volume 2.3 §4.1's
     * "ward/unit filtered" list) are NOT in this response — confirmed live,
     * not assumed — that's admission/encounter data, a different endpoint
     * this phase doesn't call; the nursing patient list shows what's
     * actually available (name/MRN/age/gender) rather than inventing
     * placeholder ward/bed values.
     */
    async function fetchNursingPatients(query?: string): Promise<Patient[]> {
        isNursingPatientsLoading.value = true;
        nursingPatientsError.value = null;
        try {
            // `?q=` (2026-08-13, direct user feedback comparing this tab to
            // reception's own): `GET /nursing/patients` shares
            // `ListPatientsUseCase` with reception's own list, which already
            // accepts `q` — confirmed by reading the use case, not assumed
            // from reception's usage alone.
            const url = query?.trim()
                ? `${BASE_URL}/nursing/patients?q=${encodeURIComponent(query.trim())}`
                : `${BASE_URL}/nursing/patients`;
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch nursing patients');
            const body = (await res.json()) as { data?: BackendPatientRow[] };
            const list = (body.data ?? []).map(toPatient);
            list.forEach(cachePatient);
            nursingPatients.value = list;
            return list;
        } catch (e) {
            nursingPatientsError.value =
                e instanceof Error ? e.message : 'Failed to fetch nursing patients';
            nursingPatients.value = [];
            return [];
        } finally {
            isNursingPatientsLoading.value = false;
        }
    }
    async function fetchClinicianPatients(query?: string): Promise<Patient[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const url = query?.trim()
                ? `${BASE_URL}/clinician/patients?q=${encodeURIComponent(query.trim())}`
                : `${BASE_URL}/clinician/patients`;
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch clinician patients');
            const body = (await res.json()) as { data?: BackendPatientRow[]; meta?: { total?: number } };
            const list = (body.data ?? []).map(toPatient);
            list.forEach(cachePatient);
            searchResults.value = list;
            totalPatientCount.value = body.meta?.total ?? list.length;
            return list;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch clinician patients';
            searchResults.value = [];
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Resolves one patient, or null when that patient does not exist.
     *
     * A 404 returns null quietly and evicts any cached copy: the patient was
     * deleted, or the id came from a stale bookmark or a localStorage recent
     * entry. Callers treat null as "show the empty state", which is what a
     * workspace opened against an emptied database should do.
     *
     * Deliberately does not touch `error` (see its declaration) and does not
     * set `isLoading` — both are the *list's* state, and a background detail
     * lookup must not put the patient list into a loading or failed state it
     * never entered.
     */
    async function fetchPatient(mrn: string): Promise<Patient | null> {
        detailError.value = null;
        try {
            // Try reception endpoint first, fallback to clinician or generic if needed
            let res = await fetch(`${BASE_URL}/reception/patients/${encodeURIComponent(mrn)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok && res.status === 403) {
                res = await fetch(`${BASE_URL}/clinician/patients/${encodeURIComponent(mrn)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
            }

            // Gone, not broken. Drop the stale cache entry and any selection
            // pointing at it so nothing keeps rendering a patient that isn't there.
            if (res.status === 404 || res.status === 410) {
                forgetPatient(mrn);
                return null;
            }

            if (!res.ok) throw new Error(`Failed to fetch patient ${mrn}`);
            const body = (await res.json()) as { data?: BackendPatientRow } | BackendPatientRow;
            const row: BackendPatientRow =
                body !== null && typeof body === 'object' && 'data' in body && body.data
                    ? body.data
                    : (body as BackendPatientRow);
            const patient = toPatient(row);
            cachePatient(patient);
            return patient;
        } catch (e) {
            // A real failure — network down, 500, malformed payload. Recorded
            // where a caller can surface it, never in the list's own error slot.
            detailError.value = e instanceof Error ? e.message : 'Failed to fetch patient';
            return null;
        }
    }

    /**
     * Forgets a patient that no longer exists: evicts the cache entry and
     * clears the current selection if it pointed there, so a deleted record
     * cannot survive in memory and keep being rendered after the DB says it
     * is gone.
     */
    function forgetPatient(patientId: string) {
        patients.value.delete(patientId);
        searchResults.value = searchResults.value.filter((p) => p.id !== patientId);
        nursingPatients.value = nursingPatients.value.filter((p) => p.id !== patientId);
        if (currentPatientId.value === patientId) {
            currentPatientId.value = null;
        }
    }

    async function searchPatients(query: string): Promise<Patient[]> {
        if (!query.trim()) {
            searchResults.value = [];
            return [];
        }
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`${BASE_URL}/reception/patients/search?q=${encodeURIComponent(query)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Search failed');
            const body = (await res.json()) as { data?: BackendPatientRow[] };
            const results = (body.data ?? []).map(toPatient);
            results.forEach(cachePatient);
            searchResults.value = results;
            return results;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Search failed';
            searchResults.value = [];
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    // ---- Real-time patch (Volume 1.4 §5.5) ----
    function patchPatient(update: Partial<Patient> & { id: string }) {
        const existing = patients.value.get(update.id);
        if (existing) {
            patients.value.set(update.id, { ...existing, ...update });
        }
    }

    /**
     * GET /api/v1/reception/patients/{id}/summary (Volume 2.1 §8 profile
     * sections) — contact, insurance, allergies, latest encounter, upcoming
     * appointment. Only requires `patients.read` (same permission the
     * reception patient list/profile already needs) — no extra RBAC gap to
     * close for this. Repointed (2026-08-10) from the generic
     * `/api/v1/patients/{id}/summary` — this store is reception-only today
     * (`usePatientStore` has no other callers), so there's no other
     * workspace this would affect; a future non-reception caller gets its
     * own workspace-scoped route the same way, not a reason to share this
     * one.
     */
    async function fetchPatientSummary(id: string): Promise<PatientSummary | null> {
        try {
            let res = await fetch(`${BASE_URL}/patients/${encodeURIComponent(id)}/summary`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                res = await fetch(`${BASE_URL}/reception/patients/${encodeURIComponent(id)}/summary`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
            }
            if (!res.ok) return null;
            const body = (await res.json()) as { data?: Partial<PatientSummary> };
            const data = body.data ?? {};
            return {
                contact: {
                    email: data.contact?.email ?? null,
                    addressLine: data.contact?.addressLine ?? null,
                    nextOfKinName: data.contact?.nextOfKinName ?? null,
                    nextOfKinPhone: data.contact?.nextOfKinPhone ?? null,
                },
                alerts: data.alerts ?? [],
                insurance: data.insurance ?? null,
                latestEncounter: data.latestEncounter ?? null,
                upcomingAppointment: data.upcomingAppointment ?? null,
                recentActivity: data.recentActivity ?? [],
                activeAppointment: data.activeAppointment ?? null,
            };
        } catch {
            return null;
        }
    }

    return {
        patients,
        currentPatientId,
        searchResults,
        totalPatientCount,
        nursingPatients,
        isNursingPatientsLoading,
        nursingPatientsError,
        isLoading,
        error,
        detailError,
        currentPatient,
        setCurrentPatient,
        clearCurrentPatient,
        cachePatient,
        forgetPatient,
        fetchPatients,
        fetchNursingPatients,
        fetchClinicianPatients,
        fetchPatient,
        searchPatients,
        patchPatient,
        fetchPatientSummary,
    };
});