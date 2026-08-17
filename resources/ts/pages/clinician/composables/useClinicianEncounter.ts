/**
 * Clinician Encounter & Consultation Composable (Volume 2.2 §6 / §7)
 * ====================================================================
 * Manages active encounter workspace loading, structured SOAP clinical notes,
 * ICD-10 diagnostic coding, draft persistence & autosave, and consultation signing.
 */

import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";
import { useEncounterStore } from "@/stores/encounterStore";
import type { Patient } from "@/stores/patientStore";
import {
  clearConsultationDraft,
  loadConsultationDraft,
  saveConsultationDraft,
  type ConsultationDraftState,
} from "../consultationDraft";

export interface ClinicalDiagnosis {
  id?: string;
  code: string;
  name: string;
  isPrimary: boolean;
  certainty: "provisional" | "confirmed";
}

export interface Icd10CatalogItem {
  code: string;
  name: string;
  category: string;
}

export const COMMON_ICD10_CATALOG: Icd10CatalogItem[] = [
  { code: "B54", name: "Unspecified malaria", category: "Infectious" },
  { code: "B50.9", name: "Plasmodium falciparum malaria, unspecified", category: "Infectious" },
  { code: "I10", name: "Essential (primary) hypertension", category: "Cardiovascular" },
  { code: "E11.9", name: "Type 2 diabetes mellitus without complications", category: "Endocrine" },
  { code: "J06.9", name: "Acute upper respiratory infection, unspecified", category: "Respiratory" },
  { code: "J18.9", name: "Pneumonia, unspecified organism", category: "Respiratory" },
  { code: "N39.0", name: "Urinary tract infection, site not specified", category: "Genitourinary" },
  { code: "A09", name: "Infectious gastroenteritis and colitis, unspecified", category: "Gastrointestinal" },
  { code: "K27.9", name: "Peptic ulcer, site unspecified, unspecified as acute or chronic", category: "Gastrointestinal" },
  { code: "A01.0", name: "Typhoid fever", category: "Infectious" },
  { code: "J45.909", name: "Unspecified asthma, uncomplicated", category: "Respiratory" },
  { code: "L03.90", name: "Cellulitis, unspecified", category: "Dermatology" },
  { code: "R51", name: "Headache", category: "Symptoms" },
  { code: "R50.9", name: "Fever, unspecified", category: "Symptoms" },
  { code: "R10.9", name: "Abdominal pain, unspecified", category: "Symptoms" },
  { code: "T14.90", name: "Injury, unspecified", category: "Trauma" },
];

export function useClinicianEncounter() {
  const { t } = useI18n({ useScope: "global" });
  const toast = useToast();
  const encounterStore = useEncounterStore();

  const activeEncounterId = ref<string | null>(null);
  const isEncounterLoading = ref(false);
  const encounterWorkspace = ref<any | null>(null);
  const primaryMedicalRecordId = ref<string | null>(null);

  // SOAP Form Fields
  const chiefComplaint = ref("");
  const historyOfPresentIllness = ref("");
  const reviewOfSystems = ref("");
  const physicalExam = ref("");
  const assessment = ref("");
  const plan = ref("");

  // Diagnoses
  const diagnoses = ref<ClinicalDiagnosis[]>([]);
  const isSavingNote = ref(false);
  const isSigningNote = ref(false);

  // Draft & Autosave State
  const isDraftDirty = ref(false);
  const lastSavedAt = ref<string | null>(null);
  const draftRestored = ref(false);
  const isHydrating = ref(false);
  let debounceSaveTimer: ReturnType<typeof setTimeout> | null = null;

  // Local draft persist helper
  function persistLocalDraft() {
    if (!activeEncounterId.value || isHydrating.value) return;
    const draft = saveConsultationDraft(activeEncounterId.value, {
      chiefComplaint: chiefComplaint.value,
      historyOfPresentIllness: historyOfPresentIllness.value,
      reviewOfSystems: reviewOfSystems.value,
      physicalExam: physicalExam.value,
      assessment: assessment.value,
      plan: plan.value,
      diagnoses: diagnoses.value,
    });
    if (!lastSavedAt.value) {
      lastSavedAt.value = draft.savedAt;
    }
  }

  // Trigger local save & schedule debounced server save on any field edit
  function onFieldChanged() {
    if (isHydrating.value || !activeEncounterId.value) return;
    isDraftDirty.value = true;
    persistLocalDraft();

    if (debounceSaveTimer) {
      clearTimeout(debounceSaveTimer);
    }
    debounceSaveTimer = setTimeout(() => {
      void saveDraftNote(true);
    }, 2000);
  }

  watch(
    [
      chiefComplaint,
      historyOfPresentIllness,
      reviewOfSystems,
      physicalExam,
      assessment,
      plan,
    ],
    () => {
      onFieldChanged();
    }
  );

  watch(
    diagnoses,
    () => {
      onFieldChanged();
    },
    { deep: true }
  );

  async function loadEncounterWorkspace(encounterId: string) {
    activeEncounterId.value = encounterId;
    isEncounterLoading.value = true;
    isHydrating.value = true;
    draftRestored.value = false;
    primaryMedicalRecordId.value = null;

    try {
      const res = await fetch(`/api/v1/clinician/encounters/${encodeURIComponent(encounterId)}?view=workspace`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (!res.ok) {
        throw new Error("Failed to load encounter workspace");
      }
      const body = await res.json();
      encounterWorkspace.value = body.data;

      // 1. Hydrate server primary medical record if present
      const record = body.data?.primaryMedicalRecord;
      if (record) {
        primaryMedicalRecordId.value = record.id || null;
        const subj = record.subjective || "";
        // Extract chief complaint if embedded with [CC: ...] prefix
        const ccMatch = subj.match(/^\[CC:\s*(.*?)\]\n?/);
        if (ccMatch) {
          chiefComplaint.value = ccMatch[1] || "";
          historyOfPresentIllness.value = subj.replace(/^\[CC:\s*.*?\]\n?/, "").trim();
        } else {
          chiefComplaint.value = "";
          historyOfPresentIllness.value = subj;
        }
        reviewOfSystems.value = "";
        physicalExam.value = record.objective || "";
        assessment.value = record.assessment || "";
        plan.value = record.plan || "";
        lastSavedAt.value = record.updatedAt || record.createdAt || null;
      } else {
        resetNoteFields();
      }

      // 2. Hydrate server diagnoses
      if (body.data?.diagnoses?.length > 0) {
        diagnoses.value = body.data.diagnoses.map((d: any) => ({
          id: d.id,
          code: d.code || d.icd10Code || "R69",
          name: d.name || d.description || d.diagnosisName || "Diagnosis",
          isPrimary: !!d.isPrimary,
          certainty: d.certainty === "confirmed" ? "confirmed" : "provisional",
        }));
      } else {
        diagnoses.value = [];
      }

      // 3. Check for local draft in localStorage
      const localDraft = loadConsultationDraft(encounterId);
      if (localDraft) {
        const hasContent =
          localDraft.chiefComplaint ||
          localDraft.historyOfPresentIllness ||
          localDraft.physicalExam ||
          localDraft.assessment ||
          localDraft.plan ||
          (localDraft.diagnoses && localDraft.diagnoses.length > 0);

        if (hasContent) {
          chiefComplaint.value = localDraft.chiefComplaint || "";
          historyOfPresentIllness.value = localDraft.historyOfPresentIllness || "";
          reviewOfSystems.value = localDraft.reviewOfSystems || "";
          physicalExam.value = localDraft.physicalExam || "";
          assessment.value = localDraft.assessment || "";
          plan.value = localDraft.plan || "";
          if (localDraft.diagnoses && localDraft.diagnoses.length > 0) {
            diagnoses.value = localDraft.diagnoses;
          }
          lastSavedAt.value = localDraft.savedAt;
          draftRestored.value = true;
        }
      }
    } catch (err: any) {
      toast.error(err.message || "Failed to load encounter details");
    } finally {
      isEncounterLoading.value = false;
      // Allow DOM to settle before enabling reactivity
      setTimeout(() => {
        isHydrating.value = false;
        isDraftDirty.value = false;
      }, 50);
    }
  }

  function resetNoteFields() {
    chiefComplaint.value = "";
    historyOfPresentIllness.value = "";
    reviewOfSystems.value = "";
    physicalExam.value = "";
    assessment.value = "";
    plan.value = "";
    diagnoses.value = [];
    isDraftDirty.value = false;
    draftRestored.value = false;
  }

  function discardDraft() {
    if (!activeEncounterId.value) return;
    clearConsultationDraft(activeEncounterId.value);

    // Revert to server data
    isHydrating.value = true;
    const record = encounterWorkspace.value?.primaryMedicalRecord;
    if (record) {
      const subj = record.subjective || "";
      const ccMatch = subj.match(/^\[CC:\s*(.*?)\]\n?/);
      if (ccMatch) {
        chiefComplaint.value = ccMatch[1] || "";
        historyOfPresentIllness.value = subj.replace(/^\[CC:\s*.*?\]\n?/, "").trim();
      } else {
        chiefComplaint.value = "";
        historyOfPresentIllness.value = subj;
      }
      reviewOfSystems.value = "";
      physicalExam.value = record.objective || "";
      assessment.value = record.assessment || "";
      plan.value = record.plan || "";
      lastSavedAt.value = record.updatedAt || record.createdAt || null;
    } else {
      chiefComplaint.value = "";
      historyOfPresentIllness.value = "";
      reviewOfSystems.value = "";
      physicalExam.value = "";
      assessment.value = "";
      plan.value = "";
      lastSavedAt.value = null;
    }

    const serverDiags = encounterWorkspace.value?.diagnoses;
    if (serverDiags && serverDiags.length > 0) {
      diagnoses.value = serverDiags.map((d: any) => ({
        id: d.id,
        code: d.code || d.icd10Code || "R69",
        name: d.name || d.description || d.diagnosisName || "Diagnosis",
        isPrimary: !!d.isPrimary,
        certainty: d.certainty === "confirmed" ? "confirmed" : "provisional",
      }));
    } else {
      diagnoses.value = [];
    }

    isDraftDirty.value = false;
    draftRestored.value = false;
    setTimeout(() => {
      isHydrating.value = false;
    }, 50);

    toast.info("Draft discarded. Reverted to server version.");
  }

  function addDiagnosis(item: Icd10CatalogItem, isPrimary = false, certainty: "provisional" | "confirmed" = "provisional") {
    const exists = diagnoses.value.some((d) => d.code === item.code);
    if (exists) {
      toast.warning(`Diagnosis ${item.code} is already in the list`);
      return;
    }

    if (isPrimary || diagnoses.value.length === 0) {
      diagnoses.value.forEach((d) => (d.isPrimary = false));
      diagnoses.value.unshift({
        code: item.code,
        name: item.name,
        isPrimary: true,
        certainty,
      });
    } else {
      diagnoses.value.push({
        code: item.code,
        name: item.name,
        isPrimary: false,
        certainty,
      });
    }
  }

  function removeDiagnosis(index: number) {
    diagnoses.value.splice(index, 1);
    if (diagnoses.value.length > 0 && !diagnoses.value.some((d) => d.isPrimary)) {
      diagnoses.value[0].isPrimary = true;
    }
  }

  function setPrimaryDiagnosis(index: number) {
    diagnoses.value.forEach((d, i) => {
      d.isPrimary = i === index;
    });
  }

  async function saveDraftNote(silent = false): Promise<boolean> {
    if (!activeEncounterId.value) return false;
    isSavingNote.value = true;
    try {
      const patientId =
        encounterWorkspace.value?.patient?.id ||
        encounterWorkspace.value?.encounter?.patientId ||
        null;

      const combinedSubjective = chiefComplaint.value
        ? `[CC: ${chiefComplaint.value}]\n${historyOfPresentIllness.value}`
        : historyOfPresentIllness.value;

      const primaryDiag = diagnoses.value.find((d) => d.isPrimary) || diagnoses.value[0] || null;

      let res: Response;
      if (primaryMedicalRecordId.value) {
        // Update existing record
        res = await fetch(`/api/v1/clinician/medical-records/${encodeURIComponent(primaryMedicalRecordId.value)}`, {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify({
            subjective: combinedSubjective,
            objective: physicalExam.value,
            assessment: assessment.value,
            plan: plan.value,
            diagnosisCode: primaryDiag?.code || null,
          }),
        });
      } else if (patientId) {
        // Create new record
        res = await fetch("/api/v1/clinician/medical-records", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify({
            patientId,
            encounterId: activeEncounterId.value,
            encounterAt: new Date().toISOString(),
            recordType: "outpatient_consultation",
            subjective: combinedSubjective,
            objective: physicalExam.value,
            assessment: assessment.value,
            plan: plan.value,
            diagnosisCode: primaryDiag?.code || null,
          }),
        });
      } else {
        // Fallback to local draft persistence only if patient context not loaded yet
        persistLocalDraft();
        isDraftDirty.value = false;
        lastSavedAt.value = new Date().toISOString();
        return true;
      }

      if (res.ok) {
        const body = await res.json();
        if (body.data?.id) {
          primaryMedicalRecordId.value = body.data.id;
        }
      }

      persistLocalDraft();
      isDraftDirty.value = false;
      lastSavedAt.value = new Date().toISOString();

      if (!silent) {
        toast.success(t("common.saved", "Draft note saved successfully"));
      }
      return true;
    } catch (err: any) {
      // Local draft is safely preserved in localStorage
      persistLocalDraft();
      if (!silent) {
        toast.error(err.message || "Failed to save draft note");
      }
      return false;
    } finally {
      isSavingNote.value = false;
    }
  }

  async function signAndCompleteConsultation(): Promise<boolean> {
    if (!activeEncounterId.value) return false;
    if (diagnoses.value.length === 0) {
      toast.warning(t("clinician.no_diagnoses_added", "Please record at least one diagnosis before completing."));
      return false;
    }

    isSigningNote.value = true;
    try {
      // 1. Save note content
      await saveDraftNote(true);

      if (primaryMedicalRecordId.value) {
        try {
          await fetch(`/api/v1/clinician/medical-records/${encodeURIComponent(primaryMedicalRecordId.value)}/status`, {
            method: "PATCH",
            headers: {
              "Content-Type": "application/json",
              "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({
              status: "finalized",
            }),
          });
        } catch {
          // Non-blocking if already finalized
        }
      }

      // 2. Sign & close encounter
      const res = await fetch(`/api/v1/clinician/notes/${encodeURIComponent(activeEncounterId.value)}/sign`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          status: "closed",
          acknowledgeCloseGaps: true,
          disposition: "discharged",
        }),
      });

      if (!res.ok) {
        const errJson = await res.json().catch(() => null);
        const errMsg = errJson?.message || "Failed to sign and complete consultation";
        throw new Error(errMsg);
      }

      // 3. Clear local draft
      clearConsultationDraft(activeEncounterId.value);
      isDraftDirty.value = false;
      draftRestored.value = false;

      toast.success(t("clinician.sign_and_complete", "Consultation signed & completed successfully!"));
      return true;
    } catch (err: any) {
      toast.error(err.message || "Failed to sign consultation");
      return false;
    } finally {
      isSigningNote.value = false;
    }
  }

  return {
    activeEncounterId,
    isEncounterLoading,
    encounterWorkspace,
    chiefComplaint,
    historyOfPresentIllness,
    reviewOfSystems,
    physicalExam,
    assessment,
    plan,
    diagnoses,
    isSavingNote,
    isSigningNote,
    isDraftDirty,
    lastSavedAt,
    draftRestored,
    loadEncounterWorkspace,
    resetNoteFields,
    discardDraft,
    addDiagnosis,
    removeDiagnosis,
    setPrimaryDiagnosis,
    saveDraftNote,
    signAndCompleteConsultation,
  };
}

