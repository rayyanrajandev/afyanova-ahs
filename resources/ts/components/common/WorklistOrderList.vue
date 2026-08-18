<script setup lang="ts">
/**
 * WorklistOrderList — the orders belonging to one patient, in a queue card
 * ========================================================================
 * Every clinical workspace groups its left pane by patient and then has to
 * show what that patient actually has waiting. Laboratory grew wrapped chips,
 * pharmacy grew stacked rows, and the two drifted far enough apart that a
 * dispenser and a bench scientist read the same construct differently.
 *
 * This is the stacked row, and it is the one that survives contact with the
 * data. The names in this system are full clinical descriptors, not the short
 * coded identifiers a chip grid assumes — "Malaria Rapid Diagnostic Test
 * (mRDT)", "Amoxicillin/Clavulanate 875mg/125mg". A wrapped chip truncates
 * those around twenty characters, which for medicines is the look-alike /
 * sound-alike hazard by another name: hydrALAZINE and hydrOXYzine, DOPamine
 * and DOBUTamine all diverge *after* the point a chip cuts.
 *
 * One row is one line. A second line for dose or specimen doubles the height
 * of every patient card, and a queue is judged by how much of it fits on
 * screen at once — so everything except the name is `shrink-0` and the name
 * takes whatever is left. What comes off the row is still announced through
 * `aria-label` and shown in full in the detail pane; what stays is the name,
 * because that is the field a wrong answer gets read from.
 *
 * Two rules it enforces that the hand-rolled versions did not:
 * - The label is never capped at a fixed width. It takes the row and truncates
 *   only when it must, and carries `title` so the rest is one hover away.
 * - Status is never colour alone (Volume 0.3 §3). The dot is paired with a
 *   written label, so it survives a monochrome display and a red/green colour
 *   deficiency — both of which a dispensary has.
 */
export type WorklistTone =
  | "waiting"
  | "active"
  | "progress"
  | "released"
  | "verified"
  | "cancelled";

export interface WorklistOrderItem {
  id: string;
  /** The full clinical name. Never pre-truncate it; the row handles that. */
  label: string;
  /** Short leading token where one exists — a modality such as CT or XR. */
  code?: string | null;
  /**
   * Dose and route, specimen, body part. Announced, not drawn: at the width
   * of a queue pane it cannot share a line with a full clinical name without
   * eating the part of the name that distinguishes it. The detail pane shows
   * it in full.
   */
  detail?: string | null;
  tone: WorklistTone;
  /** Written status, because the dot alone does not carry it. */
  toneLabel: string;
}

const props = defineProps<{
  items: WorklistOrderItem[];
  selectedId?: string | null;
}>();

const emit = defineEmits<{ select: [id: string] }>();

/**
 * The row is one line, so the dot and the detail carry no text of their own.
 * Give assistive technology the whole thing in one utterance instead.
 */
function ariaLabel(item: WorklistOrderItem): string {
  return [item.code, item.label, item.detail, item.toneLabel]
    .filter(Boolean)
    .join(", ");
}

/** The palette the three workspaces had each spelled out for themselves. */
const TONE_DOT: Record<WorklistTone, string> = {
  waiting: "bg-amber-500",
  active: "bg-blue-500",
  progress: "bg-purple-500",
  released: "bg-sky-500",
  verified: "bg-emerald-500",
  cancelled: "bg-rose-500",
};
</script>

<template>
  <div class="flex flex-col gap-1 border-t border-border/40 pt-1">
    <button
      v-for="item in props.items"
      :key="item.id"
      type="button"
      class="flex w-full items-center gap-1.5 rounded border px-2 py-1 text-left transition-colors cursor-pointer"
      :class="[
        props.selectedId === item.id
          ? 'border-primary bg-primary text-primary-foreground'
          : 'border-border/60 bg-background hover:border-primary/40',
      ]"
      :aria-current="props.selectedId === item.id ? 'true' : undefined"
      :aria-label="ariaLabel(item)"
      @click.stop="emit('select', item.id)"
    >
      <!-- Leading, so the dots form a column the eye can run down. -->
      <span
        class="size-1.5 shrink-0 rounded-full"
        :class="TONE_DOT[item.tone]"
        aria-hidden="true"
      />

      <span
        v-if="item.code"
        class="shrink-0 rounded px-1 font-mono text-[9px] font-bold uppercase"
        :class="
          props.selectedId === item.id
            ? 'bg-primary-foreground/20 text-primary-foreground'
            : 'bg-muted text-muted-foreground'
        "
      >
        {{ item.code }}
      </span>

      <!-- Everything else is shrink-0, so the name takes the rest of the row. -->
      <span
        class="min-w-0 flex-1 truncate text-[11px] font-medium"
        :title="item.label"
      >
        {{ item.label }}
      </span>

      <span
        class="shrink-0 text-[9px] font-semibold uppercase"
        :class="
          props.selectedId === item.id
            ? 'text-primary-foreground/80'
            : 'text-muted-foreground'
        "
      >
        {{ item.toneLabel }}
      </span>
    </button>
  </div>
</template>
