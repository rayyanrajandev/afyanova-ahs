import { describe, expect, it } from "vitest";
import {
  normalizePharmacyOrder,
  pharmacyStageOf,
  PHARMACY_STAGE_TAB,
  type PharmacyOrder,
} from "../composables/usePharmacyOrders";

describe("normalizePharmacyOrder", () => {
  it("normalizes patient details from the enriched patient object", () => {
    const raw = {
      id: "ord-1",
      orderNumber: "RX-1001",
      patientId: "pat-123",
      patient: {
        id: "pat-123",
        name: "Neema A Kweka",
        mrn: "MRN-5544",
        gender: "Female",
        age: "32 yrs",
        dob: "1994-05-12",
        phone: "+255700000123",
      },
      orderedBy: {
        id: 42,
        name: "Dr. Hamisi Juma",
        email: "hamisi@example.com",
      },
      medicationCode: "ATC:J01CA04",
      medicationName: "Amoxicillin 500mg",
      quantityPrescribed: 21,
      orderedAt: "2026-08-18T10:00:00Z",
      status: "pending",
      visitStage: "consultation",
    };

    const normalized = normalizePharmacyOrder(raw);

    expect(normalized.patientName).toBe("Neema A Kweka");
    expect(normalized.patientMrn).toBe("MRN-5544");
    expect(normalized.patientGender).toBe("Female");
    expect(normalized.patientAge).toBe("32 yrs");
    expect(normalized.patientDob).toBe("1994-05-12");
    expect(normalized.patientPhone).toBe("+255700000123");
    expect(normalized.orderingClinician).toBe("Dr. Hamisi Juma");
    expect(normalized.visitStage).toBe("consultation");
  });

  it("constructs full name from first, middle, last name if patient.name is missing", () => {
    const raw = {
      id: "ord-2",
      patientId: "pat-456",
      patient: {
        firstName: "Juma",
        middleName: "Rashid",
        lastName: "Massawe",
        patientNumber: "PT-9988",
        gender: "Male",
      },
      medicationCode: "MED-01",
      medicationName: "Paracetamol 500mg",
      quantityPrescribed: 10,
      orderedAt: "2026-08-18T10:00:00Z",
      status: "in_preparation",
    };

    const normalized = normalizePharmacyOrder(raw);

    expect(normalized.patientName).toBe("Juma Rashid Massawe");
    expect(normalized.patientMrn).toBe("PT-9988");
    expect(normalized.patientGender).toBe("Male");
  });

  it("calculates age from dateOfBirth if age is not explicitly set", () => {
    const raw = {
      id: "ord-3",
      patientId: "pat-789",
      patient: {
        name: "Amina Bakari",
        dateOfBirth: "2000-01-01",
      },
      medicationCode: "MED-02",
      medicationName: "Ibuprofen 400mg",
      quantityPrescribed: 14,
      orderedAt: "2026-08-18T10:00:00Z",
      status: "pending",
    };

    const normalized = normalizePharmacyOrder(raw);

    expect(normalized.patientName).toBe("Amina Bakari");
    expect(normalized.patientAge).toMatch(/\d+\s+yrs/);
  });

  it("uses safe fallback defaults if patient object is empty or missing", () => {
    const raw = {
      id: "ord-4",
      patientId: "pat-000",
      medicationCode: "MED-03",
      medicationName: "Cetirizine 10mg",
      quantityPrescribed: 30,
      orderedAt: "2026-08-18T10:00:00Z",
      status: "pending",
    };

    const normalized = normalizePharmacyOrder(raw);

    expect(normalized.patientName).toBe("Patient");
    expect(normalized.patientMrn).toBe("—");
    expect(normalized.patientGender).toBe("—");
    expect(normalized.patientAge).toBe("—");
  });
});

describe("pharmacyStageOf and PHARMACY_STAGE_TAB", () => {
  it("determines stages accurately", () => {
    expect(pharmacyStageOf({ status: "pending" } as PharmacyOrder)).toBe(
      "pending_review",
    );
    expect(pharmacyStageOf({ status: "in_preparation" } as PharmacyOrder)).toBe(
      "ready_for_dispense",
    );
    expect(
      pharmacyStageOf({ status: "partially_dispensed" } as PharmacyOrder),
    ).toBe("ready_for_dispense");
    expect(
      pharmacyStageOf({
        status: "dispensed",
        verifiedAt: null,
      } as PharmacyOrder),
    ).toBe("dispensed_unverified");
    expect(
      pharmacyStageOf({
        status: "dispensed",
        verifiedAt: "2026-08-18T12:00:00Z",
      } as PharmacyOrder),
    ).toBe("verified_completed");
    expect(pharmacyStageOf({ status: "cancelled" } as PharmacyOrder)).toBe(
      "cancelled",
    );
  });

  it("routes to the appropriate tab", () => {
    expect(PHARMACY_STAGE_TAB.pending_review).toBe("review");
    expect(PHARMACY_STAGE_TAB.ready_for_dispense).toBe("dispense");
    expect(PHARMACY_STAGE_TAB.dispensed_unverified).toBe("verify");
    expect(PHARMACY_STAGE_TAB.verified_completed).toBe("verify");
    expect(PHARMACY_STAGE_TAB.cancelled).toBe("review");
  });
});
