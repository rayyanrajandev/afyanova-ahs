/**
 * Consultation Note Draft Autosave & Local Persistence (Volume 2.2 §7.2 / Volume 1.2 §7.5)
 * =========================================================================================
 * LocalStorage draft management for active clinical encounter notes.
 * Protects physician charting against accidental browser reloads, network drops, or tab switches.
 * Cleared once the consultation is signed and completed.
 */

import type { ClinicalDiagnosis } from "./composables/useClinicianEncounter";

const DRAFT_PREFIX = "afyanova:clinician:draft:";

export interface ConsultationDraftState {
  chiefComplaint: string;
  historyOfPresentIllness: string;
  reviewOfSystems: string;
  physicalExam: string;
  assessment: string;
  plan: string;
  diagnoses: ClinicalDiagnosis[];
  savedAt: string; // ISO timestamp
}

export function getDraftKey(encounterId: string): string {
  return `${DRAFT_PREFIX}${encounterId}`;
}

export function loadConsultationDraft(encounterId: string): ConsultationDraftState | null {
  if (typeof window === "undefined" || !encounterId) return null;
  try {
    const raw = window.localStorage.getItem(getDraftKey(encounterId));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as ConsultationDraftState | null;
    if (!parsed || typeof parsed !== "object") return null;
    return parsed;
  } catch {
    return null;
  }
}

export function saveConsultationDraft(
  encounterId: string,
  fields: Omit<ConsultationDraftState, "savedAt">
): ConsultationDraftState {
  const state: ConsultationDraftState = {
    ...fields,
    savedAt: new Date().toISOString(),
  };

  if (typeof window !== "undefined" && encounterId) {
    try {
      window.localStorage.setItem(getDraftKey(encounterId), JSON.stringify(state));
    } catch {
      // Storage full or unavailable
    }
  }

  return state;
}

export function clearConsultationDraft(encounterId: string) {
  if (typeof window !== "undefined" && encounterId) {
    try {
      window.localStorage.removeItem(getDraftKey(encounterId));
    } catch {
      // ignore
    }
  }
}

export function hasConsultationDraft(encounterId: string): boolean {
  return loadConsultationDraft(encounterId) !== null;
}
