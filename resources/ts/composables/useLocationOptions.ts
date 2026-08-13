/**
 * Region/District options (Patient Registration UX direction §2, 2026-08-12)
 * ============================================================================
 * Reads GET /reception/location-options — a reception-scoped route reusing
 * PlatformConfigurationController::countryProfile + GetCountryProfileUseCase
 * as-is (see routes/api-workspaces.php's own comment for the full story:
 * the backend already had a complete Tanzania region→district dataset,
 * config/patient_location_presets.php, served by a working endpoint;
 * Reception's registration form was the only thing that never asked for it,
 * rendering Region/District as two disconnected free-text inputs instead).
 *
 * Module-level cache (not a Pinia store — this is static reference data,
 * not per-session app state, so the extra machinery isn't warranted): both
 * RegistrationForm.vue and EditDemographicsForm.vue render
 * PatientRegistrationFields.vue, so without this a patient's second form
 * open in the same session would re-fetch a payload that can't have
 * changed. Same lazy-load-once shape as useAppointmentScheduling.ts's own
 * `scheduleOptionsLoaded`.
 */

import { computed, ref } from "vue";
import type { SearchableSelectOption } from "@/components/common/SearchableSelect.vue";

interface LocationPreset {
  value: string;
  label: string;
  districts: string[];
}

interface PatientAddressingLabels {
  regionLabel: string | null;
  districtLabel: string | null;
  regionPlaceholder: string | null;
  districtPlaceholder: string | null;
  addressLabel: string | null;
  addressPlaceholder: string | null;
}

const locations = ref<LocationPreset[]>([]);
const addressing = ref<PatientAddressingLabels | null>(null);
const isLoading = ref(false);
const hasLoadedOnce = ref(false);
let loadPromise: Promise<void> | null = null;

async function loadOnce(): Promise<void> {
  if (hasLoadedOnce.value) return;
  if (loadPromise) return loadPromise;

  isLoading.value = true;
  loadPromise = (async () => {
    try {
      const res = await fetch("/api/v1/reception/location-options", {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (!res.ok) return;
      const body = (await res.json()) as {
        data?: {
          profile?: {
            patientLocations?: LocationPreset[];
            patientAddressing?: PatientAddressingLabels;
          } | null;
        };
      };
      locations.value = body.data?.profile?.patientLocations ?? [];
      addressing.value = body.data?.profile?.patientAddressing ?? null;
      hasLoadedOnce.value = true;
    } catch {
      // Leave locations empty — SearchableSelect's own empty state
      // ("No regions found") is the honest failure mode here, not a
      // separate error banner for what's ultimately a reference-data
      // convenience, not a blocking dependency of registration itself.
    } finally {
      isLoading.value = false;
      loadPromise = null;
    }
  })();

  return loadPromise;
}

export function useLocationOptions() {
  void loadOnce();

  const regionOptions = computed<SearchableSelectOption[]>(() =>
    locations.value.map((location) => ({ value: location.value, label: location.label })),
  );

  /**
   * Empty until a region is chosen — the Combobox's disabled state is what
   * actually blocks interaction; this just has nothing to offer yet.
   *
   * Case-insensitive match (bug found live-testing Edit Demographics on a
   * real pre-existing patient, 2026-08-12): region/district were free text
   * before this change, so records saved earlier can carry a casing that
   * doesn't exactly match this config's canonical spelling — "Dar es
   * salaam" vs. "Dar es Salaam" — and an exact-match lookup would silently
   * return zero districts for a patient who very much has a real,
   * already-saved district. Existing data must keep working, not just new
   * data entered through the new control.
   *
   * `regionValue` typed as `string` but guarded against `undefined`/`null`
   * anyway (bug found by the test suite, 2026-08-12): vee-validate's
   * `useField<string>("region")` doesn't itself default to `""` — during
   * a component's very first render pass, before any initial value has
   * propagated, the field's runtime value can genuinely be `undefined`
   * even though its declared type says `string`. The template calls this
   * on every render, so it has to survive that render, not just the
   * "after the form has settled" case.
   */
  function districtOptionsFor(regionValue: string | null | undefined): SearchableSelectOption[] {
    if (!regionValue) return [];
    const normalized = regionValue.trim().toLowerCase();
    const region = locations.value.find(
      (location) => location.value.trim().toLowerCase() === normalized,
    );
    return (region?.districts ?? []).map((district) => ({ value: district, label: district }));
  }

  return {
    regionOptions,
    districtOptionsFor,
    addressing,
    isLoading,
  };
}
