/**
 * Registration draft autosave (Volume 2.1 §6.2 / Volume 1.2 §7.5, Volume 3.7 T2.3)
 * ================================================================================
 * Draft saved to localStorage on field blur, restored when the form re-opens,
 * cleared once registration succeeds. The dirty-tracking is left to the page
 * (it owns the form values); this module only manages persistence and the
 * "draft saved" indicator.
 */

const DRAFT_KEY = "afyanova:reception:draft";

export interface DraftState {
  values: Record<string, unknown>;
  savedAt: string; // ISO timestamp, shown in the "draft saved" indicator
}

export function loadDraft(): DraftState | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(DRAFT_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as DraftState | null;
    if (!parsed || typeof parsed.values !== "object" || parsed.values === null)
      return null;
    return parsed;
  } catch {
    return null;
  }
}

export function saveDraft(values: Record<string, unknown>): DraftState {
  const state: DraftState = { values, savedAt: new Date().toISOString() };
  if (typeof window !== "undefined") {
    try {
      window.localStorage.setItem(DRAFT_KEY, JSON.stringify(state));
    } catch {
      // Storage full/disabled — draft is best-effort.
    }
  }
  return state;
}

export function clearDraft() {
  if (typeof window !== "undefined") {
    try {
      window.localStorage.removeItem(DRAFT_KEY);
    } catch {
      // ignore
    }
  }
}

export function hasDraft(): boolean {
  return loadDraft() !== null;
}
