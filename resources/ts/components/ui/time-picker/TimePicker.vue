<!--
  TimePicker — discrete time-slot Select, not a free-form time input
  (Volume 0.2 §7, 2026-08-11)
  ==========================================================================
  Replaces the raw `<input type="time" step="900">` Schedule Appointment's
  Date/Time fields fell back to (found in the same audit as DatePicker.vue
  — see its docblock). The native input already only *accepted* 15-minute
  granularity via that `step` attribute, but hid that constraint behind
  tiny native spinner arrows nobody would discover by looking at it — a
  discrete list of the same slots, visible up front, makes the actual
  constraint the UI honestly shows instead of something you find out by
  trying to type an arbitrary value and watching it snap. Also the
  standard pattern real scheduling products (Calendly, Cal.com) use for
  exactly this "pick a bookable slot" case, not a general free-form time
  entry — appointments here are already duration-quantized (the sibling
  Duration field), so this matches the domain, not just a style choice.

  Built on the existing Select component (already used elsewhere in this
  same dialog for Duration/Clinician/Department) rather than a new
  primitive — this generates the option list, nothing about the
  interaction itself is new.
-->

<script setup lang="ts">
import { computed } from "vue";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const props = withDefaults(
  defineProps<{
    /** "HH:mm" (24-hour) or empty string. */
    modelValue: string;
    id?: string;
    placeholder?: string;
    disabled?: boolean;
    /** Slot granularity in minutes — default matches the native input's old `step="900"` (15 min). */
    stepMinutes?: number;
    /** First bookable hour of the day, 24-hour. */
    startHour?: number;
    /** Last bookable hour of the day, 24-hour — inclusive (an :00 slot at this hour is offered). */
    endHour?: number;
  }>(),
  {
    stepMinutes: 15,
    startHour: 6,
    endHour: 21,
  },
);

const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
}>();

const timeSlots = computed(() => {
  const slots: string[] = [];
  const startMinutes = props.startHour * 60;
  const endMinutes = props.endHour * 60;
  for (let minutes = startMinutes; minutes <= endMinutes; minutes += props.stepMinutes) {
    const hours24 = Math.floor(minutes / 60);
    const mins = minutes % 60;
    slots.push(`${String(hours24).padStart(2, "0")}:${String(mins).padStart(2, "0")}`);
  }
  return slots;
});

function onUpdate(value: string | number | bigint | Record<string, unknown> | null) {
  // Select's v-model type is generic (AcceptableValue) since it also
  // supports non-string option values elsewhere in the app — every
  // SelectItem this component itself renders always passes a plain
  // string (the "HH:mm" slot), so this narrows back to that reality
  // rather than actually needing to handle the other cases.
  emit("update:modelValue", typeof value === "string" ? value : "");
}
</script>

<template>
  <Select :model-value="modelValue" @update:model-value="onUpdate">
    <SelectTrigger :id="id" class="w-full" :disabled="disabled">
      <SelectValue :placeholder="placeholder" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem v-for="slot in timeSlots" :key="slot" :value="slot">
        {{ slot }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
