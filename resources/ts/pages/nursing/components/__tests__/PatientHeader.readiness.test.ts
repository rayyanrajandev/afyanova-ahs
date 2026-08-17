import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import PatientHeader from "../PatientHeader.vue";
import type { Patient } from "@/stores/patientStore";
import type { ReadinessContext, VisitContext } from "@/stores/queueStore";

function makePatient(): Patient {
  return {
    resourceType: "Patient",
    id: "p-readiness",
    identifier: [{ system: "http://afyanova.health/mrn", value: "MRN-3001" }],
    name: [{ family: "Juma", given: ["Amani"] }],
    birthDate: "1995-03-20",
    gender: "male",
    telecom: [],
    address: [],
    nationalId: null,
    countryCode: null,
    middleName: null,
    nextOfKinName: null,
    nextOfKinPhone: null,
    meta: { extension: { age: 31, allergies: [] } },
  };
}

function makeVisit(): VisitContext {
  return {
    appointmentStatus: "waiting_triage",
    stage: "in_triage",
    arrivalMode: "walk_in",
    visitCategory: "opd_walk_in",
    encounterType: "outpatient",
    isAdmitted: false,
  };
}

describe("PatientHeader.vue readiness badges", () => {
  const baseProps = {
    patient: makePatient(),
    encounterId: "enc-300",
    allergies: [],
    isLoadingAllergies: false,
    hasEncounter: true,
    visit: makeVisit(),
    displayName: (p: Patient) => `${p.name[0]?.given?.join(" ")} ${p.name[0]?.family}`,
    initials: (name: string) => name.slice(0, 2).toUpperCase(),
  };

  it("renders insurance unverified badge when insuranceVerified is false", () => {
    const readiness: ReadinessContext = {
      coverageType: "insurance",
      insuranceVerified: false,
      insuranceProvider: "NHIF",
      verificationNotes: "Card expired",
    };

    const wrapper = mount(PatientHeader, {
      props: { ...baseProps, readiness },
      global: {
        mocks: {
          t: (key: string, params?: Record<string, any>) => {
            if (key === "nursing.insurance_unverified_with_provider") {
              return `Insurance unverified — ${params?.provider}`;
            }
            return key;
          },
        },
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: '<span><slot /></span>' },
          Button: true,
          Popover: true,
          PopoverTrigger: true,
          PopoverContent: true,
          PopoverClose: true,
        },
      },
    });

    expect(wrapper.text()).toContain("Insurance unverified — NHIF");
    expect(wrapper.text()).toContain("Card expired");
  });

  it("renders self-pay badge when coverageType is self_pay", () => {
    const readiness: ReadinessContext = {
      coverageType: "self_pay",
      insuranceVerified: null,
      insuranceProvider: null,
      verificationNotes: null,
    };

    const wrapper = mount(PatientHeader, {
      props: { ...baseProps, readiness },
      global: {
        mocks: {
          t: (key: string) => (key === "nursing.self_pay" ? "Self-pay" : key),
        },
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: '<span><slot /></span>' },
          Button: true,
          Popover: true,
          PopoverTrigger: true,
          PopoverContent: true,
          PopoverClose: true,
        },
      },
    });

    expect(wrapper.text()).toContain("Self-pay");
  });
});
