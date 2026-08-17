import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import PatientHeader from "../PatientHeader.vue";
import type { Patient } from "@/stores/patientStore";
import type { VisitContext } from "@/stores/queueStore";

function makePatient(overrides: Partial<Patient> = {}): Patient {
  return {
    resourceType: "Patient",
    id: "p1",
    identifier: [{ system: "http://afyanova.health/mrn", value: "MRN-1002" }],
    name: [{ family: "Mussa", given: ["Zuberi"] }],
    birthDate: "1994-03-20",
    gender: "male",
    telecom: [],
    address: [],
    nationalId: null,
    countryCode: null,
    middleName: null,
    nextOfKinName: null,
    nextOfKinPhone: null,
    meta: { extension: { age: 32, allergies: [] } },
    ...overrides,
  };
}

function makeVisit(overrides: Partial<VisitContext> = {}): VisitContext {
  return {
    appointmentStatus: "waiting_triage",
    stage: "in_triage",
    arrivalMode: "walk_in",
    visitCategory: "opd_walk_in",
    encounterType: "outpatient",
    isAdmitted: false,
    ...overrides,
  };
}

describe("PatientHeader.vue", () => {
  const defaultProps = {
    patient: makePatient(),
    encounterId: "enc-100",
    allergies: [],
    isLoadingAllergies: false,
    hasEncounter: true,
    visit: makeVisit(),
    displayName: (p: Patient) => `${p.name[0]?.given?.join(" ")} ${p.name[0]?.family}`,
    initials: (name: string) => name.slice(0, 2).toUpperCase(),
  };

  it("renders patient identity, MRN and visit journey badge", () => {
    const wrapper = mount(PatientHeader, {
      props: defaultProps,
      global: {
        mocks: {
          t: (key: string) => {
            const map: Record<string, string> = {
              "patient.gender_male": "Male",
              "visit_category_opd_walk_in": "Walk-in OPD",
              "stage_in_triage": "In Triage",
              "nursing.record_vitals": "Record Vitals",
              "patient.no_allergies": "No known allergies",
            };
            return map[key] ?? key;
          },
        },
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: '<span><slot /></span>' },
          Button: { template: '<button><slot /></button>' },
          Popover: { template: '<div><slot /></div>' },
          PopoverTrigger: { template: '<div><slot /></div>' },
          PopoverContent: { template: '<div><slot /></div>' },
          PopoverClose: { template: '<div><slot /></div>' },
        },
      },
    });

    expect(wrapper.text()).toContain("Zuberi Mussa");
    expect(wrapper.text()).toContain("MRN-1002");
    expect(wrapper.text()).toContain("Walk-in OPD · In Triage");
  });

  it("emits openVitals when record vitals button is clicked", async () => {
    const wrapper = mount(PatientHeader, {
      props: defaultProps,
      global: {
        mocks: {
          t: (key: string) => key,
        },
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: '<span><slot /></span>' },
          Button: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
          Popover: true,
          PopoverTrigger: true,
          PopoverContent: true,
          PopoverClose: true,
        },
      },
    });

    const vitalsBtn = wrapper.find("button");
    await vitalsBtn.trigger("click");
    expect(wrapper.emitted("openVitals")).toBeTruthy();
  });
});

/**
 * The nursing contact control (2026-08-16).
 *
 * This header briefly offered "Record Vitals" and "Start With Patient" side by
 * side as unranked peers, which left nurses asking which to click first — and
 * made it possible to record vitals without ever claiming, so the board kept
 * showing the patient as waiting while a nurse stood in front of them.
 *
 * Claiming is now a consequence of opening the vitals or assessment form, so
 * this header offers exactly one contact control: handing the patient back.
 */
describe("PatientHeader.vue — nursing contact control", () => {
  const contactProps = {
    patient: makePatient(),
    encounterId: "enc-100",
    allergies: [],
    isLoadingAllergies: false,
    hasEncounter: true,
    visit: makeVisit(),
    displayName: (p: Patient) => `${p.name[0]?.given?.join(" ")} ${p.name[0]?.family}`,
    initials: (name: string) => name.slice(0, 2).toUpperCase(),
  };

  function mountHeader(extra: Record<string, unknown> = {}) {
    return mount(PatientHeader, {
      props: { ...contactProps, ...extra },
      global: {
        mocks: { t: (key: string) => key },
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: "<span><slot /></span>" },
          Popover: true,
          PopoverTrigger: true,
          PopoverContent: true,
          PopoverClose: true,
        },
      },
    });
  }

  it("offers no start-contact button — claiming follows from doing the work", () => {
    const wrapper = mountHeader({ hasPatientInContact: false });

    expect(wrapper.text()).not.toContain("nursing.claim_patient");
    expect(wrapper.emitted("claimPatient")).toBeFalsy();
  });

  it("offers only the hand-back control once the nurse has the patient", () => {
    const wrapper = mountHeader({ hasPatientInContact: true });

    expect(wrapper.text()).toContain("nursing.release_patient");
  });

  it("emits releasePatient when the hand-back control is used", async () => {
    const wrapper = mountHeader({ hasPatientInContact: true });

    const release = wrapper.findAll("button").find((b) => b.text().includes("nursing.release_patient"));
    expect(release).toBeTruthy();

    await release?.trigger("click");
    expect(wrapper.emitted("releasePatient")).toBeTruthy();
  });
});

/**
 * The advancing primary action (2026-08-16).
 *
 * "Record Vitals" stayed the primary action after vitals had been recorded, so
 * the slot that should answer "what now?" kept naming the step the nurse had
 * just finished — while the Recent Vitals panel called the same action
 * "Retake Vitals", giving one action two names in one view.
 *
 * Both continuations are legitimate after vitals (hand off to the clinician, or
 * carry on with an assessment/note), so the primary expresses the common
 * completion only; everything else stays one click away in the Actions hub.
 */
describe("PatientHeader.vue — advancing primary action", () => {
  function mountWithStatus(appointmentStatus: string, extra: Record<string, unknown> = {}) {
    return mount(PatientHeader, {
      props: {
        patient: makePatient(),
        encounterId: "enc-100",
        allergies: [],
        isLoadingAllergies: false,
        hasEncounter: true,
        visit: makeVisit({ appointmentStatus }),
        displayName: (p: Patient) => `${p.name[0]?.given?.join(" ")} ${p.name[0]?.family}`,
        initials: (name: string) => name.slice(0, 2).toUpperCase(),
        ...extra,
      },
      global: {
        mocks: { t: (key: string) => key },
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: "<span><slot /></span>" },
          Popover: { template: "<div><slot /></div>" },
          PopoverTrigger: { template: "<div><slot /></div>" },
          PopoverContent: { template: "<div><slot /></div>" },
          PopoverClose: { template: "<div><slot /></div>" },
        },
      },
    });
  }

  it("asks for vitals while the patient is still waiting for triage", () => {
    const wrapper = mountWithStatus("waiting_triage", { hasPatientInContact: true });

    expect(wrapper.text()).toContain("nursing.record_vitals");
    expect(wrapper.text()).not.toContain("nursing.retake_vitals");
  });

  it("stops naming the finished step once vitals have been recorded", () => {
    const wrapper = mountWithStatus("waiting_provider", { hasPatientInContact: true });

    // The primary is the handoff; "Record Vitals" no longer occupies the slot.
    expect(wrapper.text()).toContain("nursing.release_patient");
    expect(wrapper.text()).not.toContain("nursing.record_vitals");
  });

  it("keeps retaking vitals reachable rather than removing it", () => {
    // Demoted, not deleted — a deteriorating patient is a real reason to retake.
    const wrapper = mountWithStatus("waiting_provider", { hasPatientInContact: true });

    expect(wrapper.text()).toContain("nursing.retake_vitals");
  });

  it("keeps both continuations available, so neither path is forced", () => {
    const wrapper = mountWithStatus("waiting_provider", { hasPatientInContact: true });

    // Carry on working...
    expect(wrapper.text()).toContain("nursing.new_assessment");
    expect(wrapper.text()).toContain("nursing.new_note");
    // ...or hand the patient to the clinician.
    expect(wrapper.text()).toContain("nursing.release_patient");
  });
});
