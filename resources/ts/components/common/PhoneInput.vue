<!--
  PhoneInput — live Tanzania mobile formatting (Patient Registration UX
  direction §3, 2026-08-12)
  ==========================================================================
  "Optimize input for humans, normalize storage for the system. Do not
  make users conform to the database format." Staff can type
  0712345678, 712345678, or 255712345678 in any spacing — this reformats
  on every keystroke to a single canonical "+255 712 345 678" shape, the
  same three input shapes PatientPhoneNumber::normalize() (backend) and
  isTanzaniaPhone() (registrationSchema.ts) already both accept, so
  nothing downstream has to change to keep validating what this sends.

  Deliberately a frontend-only fix: the backend's `phone` column (as
  opposed to the separate `phone_normalized` search column) has stored
  whatever raw string was typed since before this control existed, and
  PatientApiTest.php has real, live assertions that a submitted phone
  round-trips byte-for-byte unchanged (e.g. "it updates patient profile
  fields"). Reformatting the stored column server-side would mean editing
  that shared contract — used well beyond Reception — for every existing
  caller, not just this form. Making every NEW submission already
  canonical at the source gets the same practical outcome (a consistently
  formatted `phone` column going forward) without touching that contract.

  Renders a bare native <input> (styled to match Input.vue's own classes)
  instead of wrapping <Input>, and corrects the DOM value *synchronously*
  inside the same `input` event handler (bug found 2026-08-12, live user
  report: typing fast enough — e.g. holding a key, or pasting extra
  digits — let more than 9 subscriber digits through, like "+255 789 999
  999999999"). Wrapping <Input> meant the correction had to round-trip
  through Vue props (child emits → parent's vee-validate ref updates →
  flows back down through :model-value → Input's own passive useVModel
  picks it up) — several reactivity ticks. The browser writes each
  keystroke to the DOM immediately and synchronously; if the user typed
  faster than that round-trip completed, raw uncapped text could still be
  sitting in the DOM when the *next* keystroke fired, so the cap in
  formatTanzaniaPhone() never actually got enforced against what the
  field displayed. Mutating `event.target.value` directly in the same
  handler that reads it closes that race — no reactivity round-trip for
  the DOM correction to lose.
-->

<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { cn } from "@/lib/utils";

const { t } = useI18n();

const props = defineProps<{
  /** Empty string, not null — matches every other plain-string form field this sits alongside. */
  modelValue: string;
  id?: string;
  required?: boolean;
  ariaDescribedby?: string;
  ariaInvalid?: boolean;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
}>();

function formatTanzaniaPhone(raw: string): string {
  let digits = raw.replace(/\D+/g, "");

  // Collapse all three accepted input shapes (255XXXXXXXXX, 0XXXXXXXXX,
  // XXXXXXXXX) down to the bare 9-digit subscriber number first, so the
  // grouping below only has one shape to deal with. Unconditional (not
  // length-gated): once "+255 " is already on screen, any "255"/leading
  // "0" at the start of the extracted digits is always noise from that
  // display text re-entering the parse, never a second country code the
  // user actually meant to type (bug found 2026-08-12: a length-gated
  // strip let the injected "+255 " prefix double up on itself).
  if (digits.startsWith("255")) {
    digits = digits.slice(3);
  } else if (digits.startsWith("0")) {
    digits = digits.slice(1);
  }
  digits = digits.slice(0, 9);

  if (digits.length === 0) return "";

  const groups = [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9)].filter(
    (group) => group !== "",
  );
  return `+255 ${groups.join(" ")}`;
}

function onInput(event: Event) {
  const target = event.target as HTMLInputElement;
  const formatted = formatTanzaniaPhone(target.value);
  target.value = formatted;
  emit("update:modelValue", formatted);
}
</script>

<template>
  <input
    :id="props.id"
    :value="props.modelValue"
    data-slot="input"
    type="tel"
    inputmode="tel"
    :placeholder="t('patient.phone_placeholder')"
    :required="props.required"
    :aria-describedby="props.ariaDescribedby"
    :aria-invalid="props.ariaInvalid"
    :class="
      cn(
        'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground border-input bg-surface flex h-[var(--size-control-md)] w-full min-w-0 rounded-md border px-3 py-1 text-base shadow-xs transition-[color,box-shadow,border-color] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
        'hover:border-ring/50',
        'focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
      )
    "
    @input="onInput"
  />
</template>
