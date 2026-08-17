/**
 * nursingAdmissionStore — `createAdmission()` (Volume 2.3)
 * =======================================================================
 * Tests for the nursing admission escalation Pinia store.
 */

import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useNursingAdmissionStore, type NursingAdmissionInput } from "../nursingAdmissionStore";

function sampleAdmissionInput(): NursingAdmissionInput {
  return {
    patientId: "pt-123",
    encounterId: "enc-456",
    admittedAt: "2026-08-14T12:00:00Z",
    admissionReason: "Severe respiratory distress",
    ward: "Ward A",
    bed: "Bed 12",
  };
}

describe("nursingAdmissionStore — createAdmission", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("posts to /api/v1/nursing/admissions and returns admission response on success", async () => {
    const mockData = {
      admission: {
        id: "adm-789",
        admissionNumber: "ADM20260814001",
        status: "admitted",
        admittedAt: "2026-08-14T12:00:00Z",
        ward: "Ward A",
        bed: "Bed 12",
        admissionReason: "Severe respiratory distress",
      },
      encounter: {
        id: "enc-456",
        type: "inpatient",
        status: "opened",
        admissionId: "adm-789",
      },
    };

    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: mockData }),
    });
    vi.stubGlobal("fetch", fetchMock);

    const store = useNursingAdmissionStore();
    const result = await store.createAdmission(sampleAdmissionInput());

    expect(result).toEqual(mockData);
    expect(store.error).toBeNull();
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/v1/nursing/admissions",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify(sampleAdmissionInput()),
      }),
    );
  });

  it("returns null and sets error when API returns HTTP error status", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      json: async () => ({ message: "Selected ward and bed do not match an active ward-bed in the facility registry." }),
    });
    vi.stubGlobal("fetch", fetchMock);

    const store = useNursingAdmissionStore();
    const result = await store.createAdmission(sampleAdmissionInput());

    expect(result).toBeNull();
    expect(store.error).toBe("Selected ward and bed do not match an active ward-bed in the facility registry.");
  });
});
