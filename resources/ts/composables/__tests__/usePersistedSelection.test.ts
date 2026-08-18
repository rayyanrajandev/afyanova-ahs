/**
 * Selections restore the same way in every workspace.
 *
 * Each screen used to decide for itself, so the result was arbitrary: a
 * clinician's chart tab came back after a reload while the queue stage beside it
 * reset to "Waiting Doctor", and reception's queue behaved the same way.
 */

import { nextTick, ref, type Ref } from "vue";
import { beforeEach, describe, expect, it } from "vitest";
import {
  attachPersistence,
  makeValidator,
  persistedRef,
} from "@/composables/usePersistedSelection";

const STAGES = ["waiting_provider", "in_consultation", "admitted", "completed"] as const;
type Stage = (typeof STAGES)[number];

describe("persistedRef", () => {
  beforeEach(() => localStorage.clear());

  it("starts at the fallback when nothing was stored", () => {
    const { state } = persistedRef("k", STAGES, "waiting_provider");
    expect(state.value).toBe("waiting_provider");
  });

  it("restores what the last session left", async () => {
    const first = persistedRef("k", STAGES, "waiting_provider");
    first.state.value = "in_consultation";
    await nextTick();

    // A reload: a fresh ref off the same key.
    const second = persistedRef("k", STAGES, "waiting_provider");
    expect(second.state.value).toBe("in_consultation");
  });

  it("ignores a stored value that is no longer a real option", () => {
    // A renamed stage must not resurrect a view the workspace cannot render.
    localStorage.setItem("k", "a_stage_that_was_removed");
    const { state } = persistedRef("k", STAGES, "waiting_provider");
    expect(state.value).toBe("waiting_provider");
  });
});

describe("attachPersistence", () => {
  beforeEach(() => localStorage.clear());

  it("persists a ref a composable already owns, without a second copy", async () => {
    // The queue holds its own selectedStage; persisting it in place is what
    // stops a shadow ref drifting out of step with the one the UI reads.
    const owned = ref<Stage>("waiting_provider") as Ref<Stage>;
    attachPersistence(owned, "queue", makeValidator(STAGES));

    owned.value = "admitted";
    await nextTick();
    expect(localStorage.getItem("queue")).toBe("admitted");

    const restored = ref<Stage>("waiting_provider") as Ref<Stage>;
    attachPersistence(restored, "queue", makeValidator(STAGES));
    expect(restored.value).toBe("admitted");
  });

  it("survives a store that throws", () => {
    // Private browsing: the default is still correct, and nothing crashes.
    const original = Storage.prototype.getItem;
    Storage.prototype.getItem = () => {
      throw new Error("blocked");
    };

    try {
      const state = ref<Stage>("completed") as Ref<Stage>;
      expect(() => attachPersistence(state, "k", makeValidator(STAGES))).not.toThrow();
      expect(state.value).toBe("completed");
    } finally {
      Storage.prototype.getItem = original;
    }
  });
});
