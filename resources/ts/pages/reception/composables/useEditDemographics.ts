/**
 * Edit demographics (Volume 2.1 §8.3, Volume 3.7 audit 2026-08-10)
 * =====================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — pure extraction, no behavior change.
 *
 * Reopens the same registration schema/fields, pre-filled, PATCHing instead
 * of POSTing. Requires `patient.demographics.update` — granted to
 * ADMIN.REGISTRATION per explicit decision (routes/console.php).
 */

import { ref, type ComputedRef } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import {
  patientFromBackend,
  usePatientStore,
  type BackendPatientRow,
  type Patient,
} from "@/stores/patientStore";

export function useEditDemographics(
  selectedPatient: ComputedRef<Patient | null>,
  onSaved: (patientId: string) => void,
) {
  const { t } = useI18n();
  const toast = useToast();
  const patientStore = usePatientStore();

  const isEditingDemographics = ref(false);
  const editInitialValues = ref<Record<string, unknown>>({});

  function openEditDemographics() {
    const patient = selectedPatient.value;
    if (!patient) return;
    editInitialValues.value = {
      firstName: patient.name[0]?.given?.[0] ?? "",
      middleName: patient.middleName ?? "",
      lastName: patient.name[0]?.family ?? "",
      dateOfBirth: patient.birthDate,
      gender: patient.gender,
      phone: patient.telecom.find((t) => t.system === "phone")?.value ?? "",
      email: patient.telecom.find((t) => t.system === "email")?.value ?? "",
      addressLine: patient.address[0]?.line?.[0] ?? "",
      region: patient.address[0]?.city ?? "",
      district: patient.address[0]?.district ?? "",
      countryCode: patient.countryCode ?? "TZ",
      nationalId: patient.nationalId ?? "",
      nextOfKinName: patient.nextOfKinName ?? "",
      nextOfKinPhone: patient.nextOfKinPhone ?? "",
    };
    isEditingDemographics.value = true;
  }

  function closeEditDemographics() {
    isEditingDemographics.value = false;
  }

  async function submitEditDemographics(values: Record<string, unknown>) {
    const patient = selectedPatient.value;
    if (!patient) return;
    const patientId = patient.id;
    // Snapshot for rollback (Volume 1.4 §6 optimistic UI).
    const previous = patientStore.patients.get(patientId);

    // Optimistic UI: apply immediately, close the form right away.
    patientStore.patchPatient({
      id: patientId,
      name: [
        {
          family: String(values.lastName ?? ""),
          given: [values.firstName, values.middleName]
            .map((v) => (v ? String(v) : ""))
            .filter((v) => v !== ""),
        },
      ],
      middleName: values.middleName ? String(values.middleName) : null,
      birthDate: String(values.dateOfBirth ?? ""),
      gender: values.gender as Patient["gender"],
      telecom: [
        ...(values.phone
          ? [{ system: "phone" as const, value: String(values.phone) }]
          : []),
        ...(values.email
          ? [{ system: "email" as const, value: String(values.email) }]
          : []),
      ],
      address: [
        {
          line: values.addressLine ? [String(values.addressLine)] : [],
          city: String(values.region ?? ""),
          district: String(values.district ?? ""),
        },
      ],
      nationalId: values.nationalId ? String(values.nationalId) : null,
      countryCode: values.countryCode ? String(values.countryCode) : null,
      nextOfKinName: values.nextOfKinName ? String(values.nextOfKinName) : null,
      nextOfKinPhone: values.nextOfKinPhone ? String(values.nextOfKinPhone) : null,
    });
    isEditingDemographics.value = false;

    try {
      const res = await fetch(
        `/api/v1/reception/patients/${encodeURIComponent(patientId)}`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify(values),
        },
      );
      if (res.ok) {
        const body = (await res.json()) as { data?: BackendPatientRow };
        patientStore.cachePatient(patientFromBackend(body.data ?? {}));
        toast.success(t("patient.demographics_updated"));
        // Refresh the audit feed so the "Patient Profile Updated" entry this
        // PATCH just created shows up without requiring a reselect.
        onSaved(patientId);
      } else {
        if (previous) patientStore.cachePatient(previous);
        const body = await res.json().catch(() => null);
        toast.critical(body?.message ?? t("patient.demographics_update_failed"));
      }
    } catch {
      if (previous) patientStore.cachePatient(previous);
      toast.critical(t("patient.demographics_update_failed"));
    }
  }

  return {
    isEditingDemographics,
    editInitialValues,
    openEditDemographics,
    closeEditDemographics,
    submitEditDemographics,
  };
}
