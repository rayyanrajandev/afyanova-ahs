import { beforeEach, describe, expect, it } from "vitest";
import {
  clearConsultationDraft,
  hasConsultationDraft,
  loadConsultationDraft,
  saveConsultationDraft,
} from "../consultationDraft";

describe("consultationDraft", () => {
  const encounterId = "enc-test-123";

  beforeEach(() => {
    window.localStorage.clear();
  });

  it("returns null when no draft exists", () => {
    expect(loadConsultationDraft(encounterId)).toBeNull();
    expect(hasConsultationDraft(encounterId)).toBe(false);
  });

  it("saves and loads consultation note draft with timestamp", () => {
    const draftData = {
      chiefComplaint: "Severe fever and chills for 2 days",
      historyOfPresentIllness: "Patient reports sudden onset of high fever...",
      reviewOfSystems: "Denies cough or chest pain",
      physicalExam: "Febrile, temperature 38.9C, no lymphadenopathy",
      assessment: "Provisional malaria",
      plan: "Ordered mRDT and Paracetamol",
      diagnoses: [
        {
          code: "B54",
          name: "Unspecified malaria",
          isPrimary: true,
          certainty: "provisional" as const,
        },
      ],
    };

    const saved = saveConsultationDraft(encounterId, draftData);
    expect(saved.chiefComplaint).toBe(draftData.chiefComplaint);
    expect(saved.savedAt).toBeDefined();

    const loaded = loadConsultationDraft(encounterId);
    expect(loaded).not.toBeNull();
    expect(loaded?.chiefComplaint).toBe(draftData.chiefComplaint);
    expect(loaded?.diagnoses).toHaveLength(1);
    expect(loaded?.diagnoses[0].code).toBe("B54");
    expect(hasConsultationDraft(encounterId)).toBe(true);
  });

  it("clears consultation draft", () => {
    saveConsultationDraft(encounterId, {
      chiefComplaint: "Test",
      historyOfPresentIllness: "",
      reviewOfSystems: "",
      physicalExam: "",
      assessment: "",
      plan: "",
      diagnoses: [],
    });

    expect(hasConsultationDraft(encounterId)).toBe(true);
    clearConsultationDraft(encounterId);
    expect(hasConsultationDraft(encounterId)).toBe(false);
    expect(loadConsultationDraft(encounterId)).toBeNull();
  });
});
