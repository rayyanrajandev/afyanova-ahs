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
        id: String(row.id),
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
    const error = ref<string | null>(null);
    // Total matching the current fetchPatients() call (server-side pagination
    // meta.total, not searchResults.value.length — the list endpoint's
    // perPage default is 50, so total can exceed what's actually loaded).
    // Powers the Patients tab's count badge (2026-08-11).
    const totalPatientCount = ref(0);

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

    async function fetchPatient(mrn: string): Promise<Patient | null> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`${BASE_URL}/reception/patients/${encodeURIComponent(mrn)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
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
            error.value = e instanceof Error ? e.message : 'Failed to fetch patient';
            return null;
        } finally {
            isLoading.value = false;
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
            const res = await fetch(`${BASE_URL}/reception/patients/${encodeURIComponent(id)}/summary`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
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
        isLoading,
        error,
        currentPatient,
        setCurrentPatient,
        clearCurrentPatient,
        cachePatient,
        fetchPatients,
        fetchPatient,
        searchPatients,
        patchPatient,
        fetchPatientSummary,
    };
});