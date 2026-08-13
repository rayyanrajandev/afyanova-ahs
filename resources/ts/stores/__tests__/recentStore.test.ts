/**
 * Recent items store (Volume 3.7 T2.8, Volume 1.3 §9)
 * =====================================================
 * Max 10 items, dedupe by id, newest-first, persisted to localStorage.
 */

import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import type { Patient } from "@/stores/patientStore";
import {
  PINNED_ITEMS_MAX,
  RECENT_ITEMS_MAX,
  useRecentStore,
} from "@/stores/recentStore";

function makePatient(id: string, name: string, mrn: string): Patient {
  const [given, ...rest] = name.split(" ");
  return {
    resourceType: "Patient",
    id,
    identifier: [{ system: "http://afyanova.health/mrn", value: mrn }],
    name: [{ family: rest.join(" ") || "N/A", given: [given] }],
    birthDate: "1990-01-01",
    gender: "female",
    telecom: [],
    address: [],
    nationalId: null,
    countryCode: null,
    middleName: null,
    nextOfKinName: null,
    nextOfKinPhone: null,
    meta: { extension: { age: 36, allergies: [] } },
  };
}

describe("recent items store (T2.8)", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
  });

  it("adds a patient to the top of the list", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));

    expect(store.items.map((i) => i.id)).toEqual(["p1", "p2"]);
    expect(store.items[0]).toMatchObject({ name: "John Mwangi", mrn: "MRN-1" });
  });

  it("dedupes by id and hoists the re-accessed patient to the top (Volume 1.3 §9.1)", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));

    expect(store.items.length).toBe(2);
    expect(store.items.map((i) => i.id)).toEqual(["p1", "p2"]);
  });

  it("caps the list at --nav-recent-max (10) items", () => {
    const store = useRecentStore();
    for (let i = 0; i < RECENT_ITEMS_MAX + 5; i++) {
      store.addRecent(makePatient(`p${i}`, `Patient ${i}`, `MRN-${i}`));
    }

    expect(store.items.length).toBe(RECENT_ITEMS_MAX);
    expect(store.items[0].id).toBe(`p${RECENT_ITEMS_MAX + 4}`); // newest first
  });

  it("persists recent items to localStorage so they survive reloads", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));

    const restored = useRecentStore();
    expect(restored.items.length).toBe(1);
    expect(restored.items[0].id).toBe("p1");
  });

  it("removes a patient from the list", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.removeRecent("p1");

    expect(store.items.map((i) => i.id)).toEqual(["p2"]);
  });

  it("reconciles away recents whose patients were deleted from the DB", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.addRecent(makePatient("p3", "Neema Kimaro", "MRN-3"));

    store.reconcile(["p1", "p3"]);

    expect(store.items.map((i) => i.id)).toEqual(["p3", "p1"]);
  });

  it("keeps the list unchanged when every recent still exists", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));

    store.reconcile(["p1", "other"]);

    expect(store.items.map((i) => i.id)).toEqual(["p1"]);
  });
});

describe("pinned items store (Volume 1.3 §9.2)", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
  });

  it("pins a patient to the top of the list", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.addRecent(makePatient("p3", "Neema Kimaro", "MRN-3"));

    expect(store.pin("p2")).toBe(true);

    expect(store.items.map((i) => i.id)).toEqual(["p2", "p3", "p1"]);
    expect(store.pinnedItems.map((i) => i.id)).toEqual(["p2"]);
  });

  it("keeps pinned patients at the top when re-accessed (Volume 1.3 §9.2)", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.pin("p1");

    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));

    expect(store.items.map((i) => i.id)).toEqual(["p1", "p2"]);
    expect(store.items[0].pinned).toBe(true);
  });

  it("caps pinned patients at --nav-pinned-max (5)", () => {
    const store = useRecentStore();
    for (let i = 0; i < 8; i++) {
      store.addRecent(makePatient(`p${i}`, `Patient ${i}`, `MRN-${i}`));
    }
    const results = [];
    for (let i = 0; i < 8; i++) {
      results.push(store.pin(`p${i}`));
    }
    expect(results.slice(0, PINNED_ITEMS_MAX)).toEqual([
      true,
      true,
      true,
      true,
      true,
    ]);
    expect(results.slice(5)).toEqual([false, false, false]);
    expect(store.pinnedItems.length).toBe(PINNED_ITEMS_MAX);
  });

  it("unpins a patient back into the recent list (top of the unpinned recents)", () => {
    const store = useRecentStore();
    store.addRecent(makePatient("p1", "John Mwangi", "MRN-1"));
    store.addRecent(makePatient("p2", "Asha Nguvumali", "MRN-2"));
    store.pin("p1");

    store.unpin("p1");

    expect(store.items[0].id).toBe("p1");
    expect(store.items[0].pinned).toBe(false);
    expect(store.items[1].id).toBe("p2");
    expect(store.pinnedItems.length).toBe(0);
  });

  it("hoists a pinned patient via addRecentEntry and keeps the flag", () => {
    const store = useRecentStore();
    store.addRecentEntry({ id: "p1", name: "John Mwangi", mrn: "MRN-1" });
    store.pin("p1");

    store.addRecentEntry({ id: "p1", name: "John Mwangi", mrn: "MRN-1" });

    expect(store.items.map((i) => i.id)).toEqual(["p1"]);
    expect(store.items[0].pinned).toBe(true);
  });
});
