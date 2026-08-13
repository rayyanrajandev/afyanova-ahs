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
