/**
 * Recent & pinned items store (Volume 1.3 §9, Volume 3.7 T2.8 / T7.3)
 * ====================================================================
 * Tracks the last 10 accessed patients per user (max 10, newest-first,
 * deduped by id — Volume 1.3 §9.1 / --nav-recent-max). Persisted to
 * localStorage so recent items survive reloads; synced to the user profile
 * for cross-device access is out of scope for Phase 2 (see T7.3).
 *
 * Pinned items (Volume 1.3 §9.2): a user can pin up to 5 patients
 * (--nav-pinned-max) to keep them at the top of the list. Pinned patients
 * are surfaced as a quick-access section in the nav rail (Volume 1.1 §8.1)
 * and stay at the top of the context-pane recents stack. Pinning is per-user
 * and synced to the profile once the backend endpoint exists.
 */

import { defineStore } from "pinia";
import { computed, ref } from "vue";
import type { Patient } from "@/stores/patientStore";

export const RECENT_ITEMS_MAX = 10;
export const PINNED_ITEMS_MAX = 5;

const STORAGE_KEY = "afyanova:recent-patients";

/** A minimal, serializable recent-item row (Volume 1.3 §9.1). */
export interface RecentItem {
  id: string;
  name: string;
  mrn: string;
  /** Pinned to the top of the recent list (Volume 1.3 §9.2). */
  pinned?: boolean;
}

/** Entry from non-FHIR sources (e.g. command palette) for addRecentEntry. */
export interface RecentItemInput {
  id: string;
  name: string;
  mrn: string;
}

function readStored(): RecentItem[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? (JSON.parse(raw) as RecentItem[] | null) : [];
    return Array.isArray(parsed)
      ? parsed.map((i) => ({ ...i, pinned: Boolean(i.pinned) }))
      : [];
  } catch {
    return [];
  }
}

function writeStored(items: RecentItem[]) {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify(items.map((i) => ({ ...i, pinned: Boolean(i.pinned) }))),
    );
  } catch {
    // Storage full/disabled — non-fatal, recent items are best-effort.
  }
}

function nameOf(patient: Patient): string {
  return `${patient.name[0]?.given?.join(" ") ?? ""} ${patient.name[0]?.family ?? ""}`.trim();
}

/** Keeps pinned items above unpinned ones, preserving order within each block. */
function orderPinnedFirst(items: RecentItem[]): RecentItem[] {
  return [...items.filter((i) => i.pinned), ...items.filter((i) => !i.pinned)];
}

export const useRecentStore = defineStore("recent", () => {
  const items = ref<RecentItem[]>(readStored());

  /** Pinned-only quick-access list (Volume 1.3 §9.2). */
  const pinnedItems = computed(() => items.value.filter((i) => i.pinned));

  /** Add (or hoist) a patient to the top of the recent list (Volume 1.3 §9.1). */
  function addRecent(patient: Patient) {
    addRecentEntry({
      id: patient.id,
      name: nameOf(patient),
      mrn: patient.identifier[0]?.value ?? "",
    });
  }

  /**
   * Hoist an entry known only by id/name/mrn (e.g. opened from the command
   * palette or nav rail) to the top of the unpinned recents.
   */
  function addRecentEntry(entry: RecentItemInput) {
    const existingPinned = items.value.find((i) => i.id === entry.id)?.pinned;
    const rest = items.value.filter((i) => i.id !== entry.id);
    const pinned = rest.filter((i) => i.pinned);
    const unpinned = rest.filter((i) => !i.pinned);
    unpinned.unshift({ ...entry, pinned: Boolean(existingPinned) });
    items.value = [
      ...pinned.slice(0, PINNED_ITEMS_MAX),
      ...unpinned.slice(0, RECENT_ITEMS_MAX),
    ];
    writeStored(items.value);
  }

  /** Pin a patient to the top of the list. Returns false if the 5-cap is hit. */
  function pin(patientId: string): boolean {
    const target = items.value.find((i) => i.id === patientId);
    if (!target || target.pinned) return false;
    const pinnedCount = items.value.filter((i) => i.pinned).length;
    if (pinnedCount >= PINNED_ITEMS_MAX) return false;
    items.value = orderPinnedFirst(
      items.value.map((i) => (i.id === patientId ? { ...i, pinned: true } : i)),
    );
    writeStored(items.value);
    return true;
  }

  /** Unpin a patient; it falls back into the recent list. */
  function unpin(patientId: string) {
    items.value = orderPinnedFirst(
      items.value.map((i) =>
        i.id === patientId ? { ...i, pinned: false } : i,
      ),
    );
    writeStored(items.value);
  }

  /** Whether a patient is currently pinned (Volume 1.3 §9.2). */
  function isPinned(patientId: string): boolean {
    return Boolean(items.value.find((i) => i.id === patientId)?.pinned);
  }

  /** Remove a patient from the recent list. */
  function removeRecent(patientId: string) {
    items.value = items.value.filter((i) => i.id !== patientId);
    writeStored(items.value);
  }

  /**
   * Drop recent entries that no longer exist in the patient list (e.g. a
   * patient deleted from the DB). Recents are localStorage-persisted, so
   * without reconciliation they would keep showing deleted patients.
   */
  function reconcile(existingIds: Iterable<string>) {
    const known = new Set(existingIds);
    const next = items.value.filter((i) => known.has(i.id));
    if (next.length !== items.value.length) {
      items.value = next;
      writeStored(items.value);
    }
  }

  function clearRecent() {
    items.value = [];
    writeStored(items.value);
  }

  return {
    items,
    pinnedItems,
    addRecent,
    addRecentEntry,
    pin,
    unpin,
    isPinned,
    removeRecent,
    reconcile,
    clearRecent,
  };
});
