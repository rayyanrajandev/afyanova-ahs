/**
 * Selections that survive a refresh.
 *
 * Every workspace carries a handful of them — which tab is open, which queue
 * stage is being worked, how the worklist is filtered — and each one used to
 * decide for itself whether to persist. The result was arbitrary: a clinician's
 * chart tab came back after a reload while the queue stage filter beside it
 * silently reset to "Waiting Doctor", and reception behaved the same way.
 *
 * Pairs with useWorkspaceUrlSync: this restores what *this browser* last had,
 * the URL wins when it says something. localStorage is the fallback for a plain
 * visit to the workspace; a deep link or a refresh shows what the address bar
 * describes.
 */

import { ref, watch, type Ref } from "vue";

/** Type guard built from the same list a ref was declared with. */
export function makeValidator<T extends string>(allowed: readonly T[]) {
  return (value: unknown): value is T => allowed.includes(value as T);
}

/**
 * Read a stored selection without creating a ref.
 *
 * For state a composable takes as a constructor argument: a queue that fetches
 * per stage needs the restored stage *before* it makes its first request, or it
 * loads one stage's rows and labels them with another's.
 */
export function readPersistedValue<T extends string>(
  storageKey: string,
  isValid: (value: unknown) => value is T,
  fallback: T,
): T {
  try {
    const saved = localStorage.getItem(storageKey);
    if (isValid(saved)) return saved;
  } catch {
    // Private mode or a blocked store.
  }

  return fallback;
}

/**
 * Restore a selection from the last session and keep saving it.
 *
 * Takes an existing ref so state a composable already owns (a queue's own stage
 * filter, say) can be persisted where it lives, rather than shadowed by a second
 * copy that then has to be kept in step.
 */
export function attachPersistence<T extends string>(
  state: Ref<T>,
  storageKey: string,
  isValid: (value: unknown) => value is T,
): void {
  try {
    const saved = localStorage.getItem(storageKey);
    if (isValid(saved)) {
      state.value = saved;
    }
  } catch {
    // Private mode or a blocked store — the default is still correct.
  }

  watch(state, (value) => {
    try {
      localStorage.setItem(storageKey, value);
    } catch {
      // ignore
    }
  });
}

/** A new selection ref that persists from the moment it is created. */
export function persistedRef<T extends string>(
  storageKey: string,
  allowed: readonly T[],
  fallback: T,
): { state: Ref<T>; isValid: (value: unknown) => value is T } {
  const isValid = makeValidator(allowed);
  const state = ref<T>(fallback) as Ref<T>;
  attachPersistence(state, storageKey, isValid);

  return { state, isValid };
}
