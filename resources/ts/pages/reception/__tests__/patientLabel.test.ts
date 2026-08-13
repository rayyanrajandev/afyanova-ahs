/**
 * Patient label markup (Volume 3.7 T2.7, Volume 2.1 §5.2 W5 / §6.3 step 4)
 * =========================================================================
 * The printable label payload must contain name, MRN, DOB and age — and the
 * Ctrl+P shortcut must trigger the same markup as the Print Label button.
 */

import { describe, expect, it } from "vitest";
import type { Patient } from "@/stores/patientStore";
import { patientLabelMarkup } from "../patientLabel";

function makePatient(overrides: Partial<Patient> = {}): Patient {
  return {
    resourceType: "Patient",
    id: "p1",
    identifier: [{ system: "http://afyanova.health/mrn", value: "00000001" }],
    name: [{ family: "Nguvumali", given: ["Asha"] }],
    birthDate: "1990-06-15",
    gender: "female",
    telecom: [],
    address: [],
    nationalId: null,
    countryCode: null,
    middleName: null,
    nextOfKinName: null,
    nextOfKinPhone: null,
    meta: { extension: { age: 36, allergies: [] } },
    ...overrides,
  };
}

describe("patient label markup (T2.7)", () => {
  it("contains the patient name, MRN, DOB and age", () => {
    const markup = patientLabelMarkup(makePatient());
    expect(markup).toContain("Asha Nguvumali");
    expect(markup).toContain("MRN 00000001");
    expect(markup).toContain("1990-06-15");
    expect(markup).toContain("36y");
  });

  it("escapes HTML in patient-controlled fields", () => {
    const markup = patientLabelMarkup(
      makePatient({ name: [{ family: "<script>", given: ["<b>"] }] }),
    );
    expect(markup).not.toContain("<script>");
    expect(markup).toContain("&lt;script&gt;");
  });
});
