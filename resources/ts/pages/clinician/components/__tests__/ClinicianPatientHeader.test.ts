/**
 * ClinicianPatientHeader — the journey badge beside the patient's name.
 *
 * The clinician workspace derived this badge from a coarse local enum keyed on
 * `appointmentStatus`, which can only express which queue a visit sits in. So a
 * patient a nurse had actively picked up read as "Waiting for Triage" or
 * "Triaged · Waiting Doctor" here, while the reception and clinician queues both
 * correctly showed "With Nurse" (2026-08-16).
 *
 * The same mistake was fixed twice in the reception profile before landing on
 * the right computed, so these assert rendered text rather than internals.
 */

import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";
import type { Patient } from "@/stores/patientStore";
import type { VisitContext } from "@/stores/queueStore";
import en from "../../../../i18n/locales/en/common.json";
import ClinicianPatientHeader from "../ClinicianPatientHeader.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  fallbackLocale: "en",
  messages: { en },
});

const patient: Patient = {
  resourceType: "Patient",
  id: "p1",
  identifier: [{ system: "http://afyanova.health/mrn", value: "MRN-2001" }],
  name: [{ family: "Kilongo", given: ["Juma"] }],
  birthDate: "1990-05-05",
  gender: "male",
  telecom: [],
  address: [],
  nationalId: null,
  countryCode: null,
  middleName: null,
  nextOfKinName: null,
  nextOfKinPhone: null,
  meta: { extension: { age: 36, allergies: [] } },
};

function visit(overrides: Partial<VisitContext> = {}): VisitContext {
  return {
    appointmentId: "apt-1",
    appointmentStatus: "waiting_triage",
    stage: "waiting_triage",
    visitStage: null,
    arrivalMode: "walk_in",
    visitCategory: "opd_walk_in",
    encounterType: "ambulatory",
    isAdmitted: false,
    ...overrides,
  };
}

function mountHeader(v: VisitContext) {
  return mount(ClinicianPatientHeader, {
    props: {
      patient,
      encounterId: null,
      visit: v,
      readiness: null,
      allergies: [],
      clinicalMode: "active" as const,
    },
    global: {
      plugins: [i18n],
      stubs: {
        Avatar: true,
        AvatarFallback: true,
        Badge: { template: "<span><slot /></span>" },
        Button: { template: "<button><slot /></button>" },
      },
    },
  });
}

describe("ClinicianPatientHeader — journey stage badge", () => {
  it("shows With Nurse while a nurse has the patient, though the status is still waiting_triage", () => {
    const wrapper = mountHeader(visit({ visitStage: "with_nurse" }));

    expect(wrapper.text()).toContain("With Nurse");
    expect(wrapper.text()).not.toContain("Waiting for Triage");
  });

  it("distinguishes waiting for triage from being in triage", () => {
    expect(mountHeader(visit({ visitStage: "waiting_triage" })).text()).toContain("Waiting for Triage");
    expect(mountHeader(visit({ visitStage: "in_triage" })).text()).toContain("In Triage");
  });

  it("shows With Doctor once the consultation has started", () => {
    const wrapper = mountHeader(
      visit({ appointmentStatus: "in_consultation", stage: "with_clinician", visitStage: "with_clinician" }),
    );

    expect(wrapper.text()).toContain("With Doctor");
  });

  it("distinguishes a first wait for the doctor from a return for review", () => {
    expect(mountHeader(visit({ visitStage: "waiting_clinician" })).text())
      .toContain("Waiting for Doctor");

    expect(mountHeader(visit({ visitStage: "waiting_clinician_review" })).text())
      .toContain("Waiting for Doctor Review");
  });

  it("falls back to the coarse label when no step has been resolved", () => {
    // A visit predating the flow work carries no step; the badge degrades to
    // the status-derived label rather than disappearing.
    const wrapper = mountHeader(
      visit({ appointmentStatus: "waiting_provider", stage: "waiting_provider", visitStage: null }),
    );

    expect(wrapper.text()).toContain("Triaged · Waiting Doctor");
  });
});

/**
 * Read-only until the doctor calls the patient in (2026-08-16).
 *
 * `waiting_provider` / `waiting_clinician` resolved to clinicalMode "active",
 * so a patient merely *queued* for a doctor had a fully writable chart, and the
 * header offered "Call Patient In", "Admit to Ward" and "Sign & Complete"
 * together with nothing saying which came first.
 *
 * That is a clinical-safety problem: documenting or ordering on a patient
 * nobody has called in produces a record of a consultation that never happened,
 * attributed to a doctor who never started one.
 */
describe("ClinicianPatientHeader — actions before the consultation starts", () => {
  function mountMode(mode: string, v = visit()) {
    return mount(ClinicianPatientHeader, {
      props: {
        patient,
        encounterId: "enc-1",
        visit: v,
        readiness: null,
        allergies: [],
        clinicalMode: mode as never,
        onStartConsultation: () => {},
        onOpenAdmissionDialog: () => {},
        onSignComplete: () => {},
      },
      global: {
        plugins: [i18n],
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: "<span><slot /></span>" },
          Button: { template: "<button><slot /></button>" },
        },
      },
    });
  }

  it("offers only Call Patient In while the patient is waiting", () => {
    const text = mountMode("awaiting_start", visit({ visitStage: "waiting_clinician" })).text();

    expect(text).toContain("Call Patient In");
    // Neither of these can legitimately happen before the consultation opens.
    expect(text).not.toContain("Admit");
    expect(text).not.toContain("Sign");
  });

  it("offers the full action set once the consultation is open", () => {
    const text = mountMode(
      "active",
      visit({ appointmentStatus: "in_consultation", stage: "with_clinician", visitStage: "with_clinician" }),
    ).text();

    expect(text).toContain("Admit");
    expect(text).toContain("Sign");
    // The finished step stops being offered.
    expect(text).not.toContain("Call Patient In");
  });

  it("renders Send for Diagnostics button when in consultation and callback is provided", async () => {
    let sent = false;
    const wrapper = mount(ClinicianPatientHeader, {
      props: {
        patient,
        encounterId: "enc-1",
        visit: visit({ appointmentStatus: "in_consultation", stage: "with_clinician", visitStage: "with_clinician" }),
        readiness: null,
        allergies: [],
        clinicalMode: "active" as const,
        onSendForDiagnostics: () => {
          sent = true;
        },
      },
      global: {
        plugins: [i18n],
        stubs: {
          Avatar: true,
          AvatarFallback: true,
          Badge: { template: "<span><slot /></span>" },
          Button: {
            template: "<button @click=\"$emit('click')\"><slot /></button>",
          },
        },
      },
    });

    expect(wrapper.text()).toContain("Send for Diagnostics");
    const sendBtn = wrapper.findAll("button").find((b) => b.text().includes("Send for Diagnostics"));
    expect(sendBtn).toBeDefined();
    await sendBtn?.trigger("click");
    expect(sent).toBe(true);
  });
});
