<!--
  DatePicker — Popover + Calendar, string in/out (Volume 0.2 §7, 2026-08-11)
  ==========================================================================
  What form fields actually use — see Calendar.vue's own docblock for why
  this exists. Deliberately works in plain "YYYY-MM-DD" strings at the
  v-model boundary (not CalendarDate objects): every consumer
  (PatientRegistrationFields.vue's dateOfBirth, ScheduleAppointmentDialog's
  scheduleFormDate) is a vee-validate/plain-ref string field already wired
  to that exact format end-to-end (validation schema, backend payload) —
  converting to/from `@internationalized/date`'s CalendarDate happens
  entirely inside this component, so nothing upstream has to change shape
  to adopt it.

  Trigger label uses "en-GB" day/short-month/year (e.g. "11 Aug 2026"),
  not the `locale` prop, and not toLocaleDateString's default — this
  matches receptionFormatters.ts's formatClinicalDate() convention
  deliberately: a stored clinical date shouldn't visually change format
  when someone switches UI language, the same reasoning that formatter's
  own docblock states. Can't import that helper directly (a shared ui/
  atom must not depend on page-specific code, same layering every other
  components/ui/* file already respects) — replicated inline instead.
-->

<script setup lang="ts">
import { type CalendarDate, type DateValue, parseDate } from "@internationalized/date";
import { CalendarIcon } from "lucide-vue-next";
import { computed } from "vue";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { cn } from "@/lib/utils";

const props = defineProps<{
  /** "YYYY-MM-DD" or empty string — never null/undefined, matching every native-date-input field this replaces. */
  modelValue: string;
  id?: string;
  placeholder?: string;
  ariaDescribedby?: string;
  ariaInvalid?: boolean;
  disabled?: boolean;
  /** "YYYY-MM-DD" bound (e.g. today, for "no future date" fields). */
  maxValue?: string;
  minValue?: string;
  /** Calendar popup's weekday/month labels only — see file docblock for why the trigger label itself doesn't use this. */
  locale?: string;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
  /**
   * Forwarded from the trigger button's own native blur (2026-08-12, bug
   * fix — see PatientRegistrationFields.vue's own docblock on
   * `handleBlur`). `blur` doesn't bubble, so a consumer's `@blur` on
   * `<DatePicker>` would silently never fire without this explicit
   * re-emit — the actual focusable element is the Button two components
   * deep (Popover > PopoverTrigger > Button), not this component's own
   * root.
   */
  (e: "blur", value: FocusEvent): void;
}>();

function safeParse(value: string): CalendarDate | undefined {
  if (!value) return undefined;
  try {
    return parseDate(value);
  } catch {
    // A malformed/partial string (e.g. mid-typing state some other code
    // path wrote) shouldn't crash the picker — just show no selection.
    return undefined;
  }
}

const calendarValue = computed(() => safeParse(props.modelValue));
const maxCalendarValue = computed(() => (props.maxValue ? safeParse(props.maxValue) : undefined));
const minCalendarValue = computed(() => (props.minValue ? safeParse(props.minValue) : undefined));

const displayLabel = computed(() => {
  const value = calendarValue.value;
  if (!value) return null;
  return value.toDate("UTC").toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
});

function onSelect(value: DateValue | undefined) {
  emit("update:modelValue", value ? value.toString() : "");
}
</script>

<template>
  <Popover>
    <PopoverTrigger as-child>
      <Button
        :id="id"
        type="button"
        variant="outline"
        :disabled="disabled"
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        :class="
          cn(
            'h-[var(--size-control-md)] w-full justify-start gap-2 border-input bg-surface px-3 text-left text-sm font-normal shadow-xs',
            !displayLabel && 'text-muted-foreground',
          )
        "
        @blur="emit('blur', $event)"
      >
        <CalendarIcon class="h-4 w-4 shrink-0 opacity-60" aria-hidden="true" />
        <span class="min-w-0 truncate">{{ displayLabel ?? placeholder }}</span>
      </Button>
    </PopoverTrigger>
    <PopoverContent class="w-auto p-0" align="start">
      <Calendar
        :model-value="calendarValue"
        :max-value="maxCalendarValue"
        :min-value="minCalendarValue"
        :locale="locale"
        @update:model-value="onSelect"
      />
    </PopoverContent>
  </Popover>
</template>
