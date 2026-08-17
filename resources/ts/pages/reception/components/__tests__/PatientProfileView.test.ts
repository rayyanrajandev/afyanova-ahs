/**
 * PatientProfileView — state-aware primary action (Volume 2.1 §8,
 * Reception workspace visual audit, 2026-08-12)
 * =======================================================================
 * Coverage for the header's primary action across the patient's three
 * visit states: no active visit ("Check In"), an active visit ("View in
 * Queue" — replaces the old disabled/relabeled "Checked In" button so a
 * patient already mid-visit can't be checked in twice, per
 * RegisterWalkInAndCheckInUseCase's backend guard), and a closed latest
 * encounter ("Start New Visit"). This mirrors the backend field name
 * exactly (`profileSummary.activeAppointment`) so a drift between the two
 * can never silently reintroduce the double-check-in bug.
 */

import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";
import type { Patient, PatientSummary } from "@/stores/patientStore";
import en from "../../../../i18n/locales/en/common.json";
import PatientProfileView from "../PatientProfileView.vue";

const patient: Patient = {
  resourceType: "Patient",
  id: "p1",
  identifier: [{ system: "http://afyanova.health/mrn", value: "MRN-1001" }],
  name: [{ family: "Mwangi", given: ["John"] }],
  birthDate: "1980-01-01",
  gender: "male",
  telecom: [],
  address: [],
  nationalId: null,
  countryCode: null,
  middleName: null,
  nextOfKinName: null,
  nextOfKinPhone: null,
  meta: { extension: { age: 46, allergies: [] } },
};

function baseSummary(): PatientSummary {
  return {
    contact: { email: null, addressLine: null, nextOfKinName: null, nextOfKinPhone: null },
    alerts: [],
    insurance: null,
    latestEncounter: null,
    upcomingAppointment: null,
    recentActivity: [],
    activeAppointment: null,
  };
}

function makeProfile(summary: PatientSummary | null) {
  return {
    profileSummary: { value: summary },
    isSummaryLoading: { value: false },
    upcomingAppointments: { value: [] },
    contactAddress: { value: null },
    auditFeed: { value: [] },
    fetchUpcomingAppointments: () => {},
    refreshSummary: () => {},
    fetchPatientActivityFeed: () => {},
    auditActionLabel: () => "",
    genderLabel: () => "Male",
  };
}

function mountView(summary: PatientSummary | null) {
  setActivePinia(createPinia());
  const i18n = createI18n({ legacy: false, locale: "en", messages: { en } });

  return mount(PatientProfileView, {
    global: {
      plugins: [i18n],
      // Tooltip requires a TooltipProvider ancestor — normally supplied
      // once by AppShell.vue at the real app root (see
      // TooltipProvider.vue's own docblock). This test only cares about
      // the Check-In button's disabled state, not tooltip behavior
      // elsewhere in the card, so it's stubbed out rather than wrapping
      // the whole mount in a real TooltipProvider.
      stubs: { Tooltip: true, TooltipTrigger: true, TooltipContent: true },
    },
    props: {
      patient,
      profile: makeProfile(summary) as any,
      arrivalIntake: { openArrivalDialog: () => {}, checkInAppointment: () => {} } as any,
      scheduling: { openScheduleDialogForPatient: () => {} } as any,
      insuranceForm: { openInsuranceForm: () => {}, verifyInsurance: () => {} } as any,
      openEditDemographics: () => {},
      printSelectedLabel: () => {},
    },
  });
}

describe("PatientProfileView — state-aware primary action", () => {
  it("shows an enabled Check In button when the patient has no active visit", () => {
    const wrapper = mountView(baseSummary());
    const button = wrapper.findAll("button").find((b) => b.text().includes("Check In"));

    expect(button).toBeTruthy();
    expect(button?.attributes("disabled")).toBeUndefined();
    expect(wrapper.text()).toContain("Not checked in");
  });

  it("replaces Check In with an enabled View in Queue button when a visit is active", () => {
    const summary = baseSummary();
    summary.activeAppointment = {
      id: "apt-1",
      appointmentNumber: "APT001",
      status: "waiting_triage",
      // Server-resolved step; the badge reads this, not `status`.
      visitStage: "waiting_triage",
      scheduledAt: "2026-08-12T10:00:00Z",
      department: "Emergency",
    };
    const wrapper = mountView(summary);
    const checkInButton = wrapper.findAll("button").find((b) => b.text() === "Check In");
    const viewInQueueButton = wrapper.findAll("button").find((b) => b.text().includes("View in Queue"));

    expect(checkInButton).toBeUndefined();
    expect(viewInQueueButton).toBeTruthy();
    expect(viewInQueueButton?.attributes("disabled")).toBeUndefined();
    expect(wrapper.text()).toContain("Checked in");

    void viewInQueueButton?.trigger("click");
    expect(wrapper.emitted("view-in-queue")).toBeTruthy();
  });

  it("shows Start New Visit when the patient's latest encounter is closed", () => {
    const summary = baseSummary();
    summary.latestEncounter = {
      id: "enc-1",
      encounterNumber: "ENC001",
      status: "closed",
      openedAt: "2026-08-01T09:00:00Z",
      closedAt: "2026-08-01T10:00:00Z",
      primaryClinicianName: "Dr. Test",
    };
    const wrapper = mountView(summary);
    const button = wrapper.findAll("button").find((b) => b.text().includes("Start New Visit"));

    expect(button).toBeTruthy();
    expect(button?.attributes("disabled")).toBeUndefined();
    expect(wrapper.text()).toContain("Visit Completed");
  });
});

/**
 * The journey badge beside the patient's name must show the server-resolved
 * flow step, not a status-derived approximation of it.
 *
 * This regressed twice on 2026-08-16. First the badge derived from
 * `activeAppointment.status`, which cannot express a nursing pickup. Then the
 * fix went to the wrong computed — the "Current visit" card lower down — while
 * the header badge kept reading the coarse `visitState`, so a patient the
 * reception queue showed as "With Nurse" still read "Waiting for Triage" here.
 * These assert the rendered text, at the layer that was actually wrong.
 */
describe("PatientProfileView — journey stage badge", () => {
  function summaryWithStage(status: string, visitStage: string | null): PatientSummary {
    const summary = baseSummary();
    summary.activeAppointment = {
      id: "apt-1",
      appointmentNumber: "APT001",
      status,
      visitStage,
      scheduledAt: "2026-08-16T10:00:00Z",
      department: "Emergency",
    };

    return summary;
  }

  it("shows With Nurse while a nurse has the patient, though the status is still waiting_triage", () => {
    const wrapper = mountView(summaryWithStage("waiting_triage", "with_nurse"));

    expect(wrapper.text()).toContain("With Nurse");
    expect(wrapper.text()).not.toContain("Waiting for Triage");
  });

  it("distinguishes waiting for triage from being in triage", () => {
    expect(mountView(summaryWithStage("waiting_triage", "waiting_triage")).text())
      .toContain("Waiting for Triage");

    expect(mountView(summaryWithStage("waiting_triage", "in_triage")).text())
      .toContain("In Triage");
  });

  it("shows With Doctor once the consultation has started", () => {
    expect(mountView(summaryWithStage("in_consultation", "with_clinician")).text())
      .toContain("With Doctor");
  });

  it("falls back to the status-derived label when no step is resolved", () => {
    // An older visit predating the flow log has no recorded step; the badge
    // degrades rather than going blank.
    expect(mountView(summaryWithStage("waiting_provider", null)).text())
      .toContain("Triaged · Waiting Doctor");
  });
});
