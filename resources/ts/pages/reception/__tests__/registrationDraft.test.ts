/**
 * Registration draft persistence (Volume 3.7 T2.3, Volume 1.2 §7.5)
 * ===================================================================
 * Draft is saved on field blur, restored when the form re-opens, and cleared
 * once the patient is successfully registered.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  clearDraft,
  hasDraft,
  loadDraft,
  saveDraft,
} from "../registrationDraft";

const DRAFT_KEY = "afyanova:reception:draft";

describe("registration draft (T2.3)", () => {
  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("saves a draft to localStorage with a timestamp", () => {
    const state = saveDraft({ firstName: "Asha", phone: "+255785123456" });
    const raw = localStorage.getItem(DRAFT_KEY);
    expect(raw).not.toBeNull();
    const parsed = JSON.parse(raw as string) as {
      values: Record<string, unknown>;
      savedAt: string;
    };
    expect(parsed.values.firstName).toBe("Asha");
    expect(parsed.savedAt).toBe(state.savedAt);
  });

  it("restores a previously saved draft", () => {
    saveDraft({ firstName: "Asha", district: "Nyamagana" });
    const restored = loadDraft();
    expect(restored?.values).toMatchObject({
      firstName: "Asha",
      district: "Nyamagana",
    });
  });

  it("reports no draft when nothing was saved", () => {
    expect(hasDraft()).toBe(false);
    expect(loadDraft()).toBeNull();
  });

  it("clears the draft after registration succeeds", () => {
    saveDraft({ firstName: "Asha" });
    expect(hasDraft()).toBe(true);
    clearDraft();
    expect(hasDraft()).toBe(false);
    expect(localStorage.getItem(DRAFT_KEY)).toBeNull();
  });

  it("is null-safe when localStorage holds corrupt JSON", () => {
    localStorage.setItem(DRAFT_KEY, "{not json");
    expect(loadDraft()).toBeNull();
    expect(hasDraft()).toBe(false);
  });
});
