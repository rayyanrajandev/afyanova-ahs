<!--
  Calendar — shadcn-vue-style wrapper around reka-ui's headless CalendarRoot
  (Volume 0.2 §7, component-library audit 2026-08-11)
  ==========================================================================
  First calendar-grid component in the app — built because Registration/
  Edit Demographics' Date of Birth field and Schedule Appointment's Date
  field were both falling back to the raw browser-native `<input
  type="date">` widget, the one visibly "different product bolted on"
  element in an otherwise consistently custom-styled form (found via a
  direct UI audit, 2026-08-11). `reka-ui` (already a dependency, used
  throughout this app for Popover/Dialog/Tabs) already bundles the headless
  Calendar primitives used here — this is one more `components/ui/*`
  wrapper in the same established pattern, not a new architectural
  direction. `@internationalized/date` (CalendarRoot's value type) was
  already a transitive dependency of reka-ui; added directly to
  package.json since this component imports from it explicitly now.

  Standalone grid only — DatePicker.vue (Popover + trigger button +
  string<->CalendarDate conversion) is the thing form fields actually use;
  this component doesn't know about strings or Popovers at all, matching
  the layering every other `ui/*` atom already follows.

  Year → month → day drill-down header (Patient Registration UX direction
  §1, Phase 2, 2026-08-12): a receptionist entering an adult's date of
  birth previously had to click "previous month" up to ~500 times from
  today to reach a 1985 birth year — the single biggest real friction
  point the UX review named. reka-ui's CalendarRoot already exposes a
  controllable `placeholder` (the month/year currently on screen,
  independent of the selected date/modelValue — see CalendarRoot.js's own
  `placeholder` prop/`update:placeholder` emit) built exactly for this;
  the day grid below is unchanged, `view` just swaps it out for a month
  grid or year-page grid that jump `placeholder` in bigger steps before
  handing back to the day grid for the final pick.
-->

<script setup lang="ts">
import type { DateValue } from "@internationalized/date";
import {
  type CalendarDate,
  endOfMonth,
  getLocalTimeZone,
  today,
} from "@internationalized/date";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import {
  CalendarCell,
  CalendarCellTrigger,
  CalendarGrid,
  CalendarGridBody,
  CalendarGridHead,
  CalendarGridRow,
  CalendarHeadCell,
  CalendarHeading,
  CalendarNext,
  CalendarPrev,
  CalendarRoot,
} from "reka-ui";
import { computed, ref, shallowRef, watch } from "vue";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

const props = defineProps<{
  modelValue?: DateValue;
  maxValue?: DateValue;
  minValue?: DateValue;
  /** BCP-47 tag for weekday/month header labels only — day-of-month digits are locale-invariant either way. */
  locale?: string;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: DateValue | undefined): void;
}>();

type View = "day" | "month" | "year";
const view = ref<View>("day");

// Controlled placeholder (see file docblock): owning this ourselves —
// instead of leaving CalendarRoot to manage it passively — is what lets
// the month/year grids below jump it by more than one step at a time.
// `shallowRef`, not `ref`: CalendarDate is an immutable value object from
// @internationalized/date — Vue's deep reactive proxying of `ref()` erases
// its class identity (UnwrapRef structurally loosens it), which is exactly
// what broke CalendarRoot's own `placeholder` prop typing here.
const placeholder = shallowRef<CalendarDate>(
  (props.modelValue as CalendarDate | undefined) ?? today(getLocalTimeZone()),
);

// A freshly-selected date (new modelValue) should re-focus the grid on
// that date's month, same as CalendarRoot's own default passive behavior
// — this only reruns on an actual selection, not on plain navigation
// (which only ever touches `placeholder`, never `modelValue`).
watch(
  () => props.modelValue,
  (value) => {
    if (value) placeholder.value = value as CalendarDate;
  },
);

const YEAR_PAGE_SIZE = 12;
const yearPageStart = computed(
  () => Math.floor(placeholder.value.year / YEAR_PAGE_SIZE) * YEAR_PAGE_SIZE,
);
const yearPage = computed(() =>
  Array.from({ length: YEAR_PAGE_SIZE }, (_, i) => yearPageStart.value + i),
);

function monthLabel(monthStart: CalendarDate): string {
  return monthStart.toDate("UTC").toLocaleDateString(props.locale ?? "en-US", {
    month: "short",
  });
}

function isMonthDisabled(monthStart: CalendarDate): boolean {
  if (props.maxValue && monthStart.compare(props.maxValue) > 0) return true;
  if (props.minValue && endOfMonth(monthStart).compare(props.minValue) < 0) return true;
  return false;
}

function isYearDisabled(year: number): boolean {
  if (props.maxValue && year > props.maxValue.year) return true;
  if (props.minValue && year < props.minValue.year) return true;
  return false;
}

function pickMonth(monthIndex: number) {
  if (isMonthDisabled(placeholder.value.set({ month: monthIndex, day: 1 }))) return;
  placeholder.value = placeholder.value.set({ month: monthIndex });
  view.value = "day";
}

function pickYear(year: number) {
  if (isYearDisabled(year)) return;
  placeholder.value = placeholder.value.set({ year });
  view.value = "month";
}

function stepYear(delta: number) {
  placeholder.value =
    delta > 0
      ? placeholder.value.add({ years: delta })
      : placeholder.value.subtract({ years: -delta });
}

function stepYearPage(delta: number) {
  stepYear(delta * YEAR_PAGE_SIZE);
}
</script>

<template>
  <CalendarRoot
    v-slot="{ grid, weekDays }"
    :model-value="modelValue"
    :placeholder="placeholder"
    :max-value="maxValue"
    :min-value="minValue"
    :locale="locale ?? 'en-US'"
    weekday-format="short"
    initial-focus
    class="p-3"
    @update:model-value="(value) => emit('update:modelValue', value)"
    @update:placeholder="(value) => (placeholder = value as CalendarDate)"
  >
    <div class="flex items-center justify-between pb-2">
      <CalendarPrev
        v-if="view === 'day'"
        :class="cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'h-7 w-7 p-0')"
      >
        <ChevronLeft class="h-4 w-4" aria-hidden="true" />
      </CalendarPrev>
      <button
        v-else
        type="button"
        :class="cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'h-7 w-7 p-0')"
        :aria-label="view === 'month' ? 'Previous year' : 'Previous years'"
        @click="view === 'month' ? stepYear(-1) : stepYearPage(-1)"
      >
        <ChevronLeft class="h-4 w-4" aria-hidden="true" />
      </button>

      <CalendarHeading
        v-if="view === 'day'"
        as="button"
        type="button"
        class="rounded px-2 py-1 text-sm font-medium text-foreground hover:bg-accent"
        @click="view = 'month'"
      />
      <button
        v-else-if="view === 'month'"
        type="button"
        class="rounded px-2 py-1 text-sm font-medium text-foreground hover:bg-accent"
        @click="view = 'year'"
      >
        {{ placeholder.year }}
      </button>
      <span v-else class="px-2 py-1 text-sm font-medium text-foreground">
        {{ yearPageStart }} – {{ yearPageStart + YEAR_PAGE_SIZE - 1 }}
      </span>

      <CalendarNext
        v-if="view === 'day'"
        :class="cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'h-7 w-7 p-0')"
      >
        <ChevronRight class="h-4 w-4" aria-hidden="true" />
      </CalendarNext>
      <button
        v-else
        type="button"
        :class="cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'h-7 w-7 p-0')"
        :aria-label="view === 'month' ? 'Next year' : 'Next years'"
        @click="view === 'month' ? stepYear(1) : stepYearPage(1)"
      >
        <ChevronRight class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>

    <CalendarGrid
      v-for="month in view === 'day' ? grid : []"
      :key="month.value.toString()"
      class="w-full border-collapse select-none"
    >
      <CalendarGridHead>
        <CalendarGridRow class="flex">
          <CalendarHeadCell
            v-for="day in weekDays"
            :key="day"
            class="w-8 flex-1 text-center text-xs font-normal text-muted-foreground"
          >
            {{ day }}
          </CalendarHeadCell>
        </CalendarGridRow>
      </CalendarGridHead>
      <CalendarGridBody>
        <CalendarGridRow
          v-for="(weekDates, index) in month.rows"
          :key="`week-${index}`"
          class="mt-1 flex w-full"
        >
          <CalendarCell
            v-for="weekDate in weekDates"
            :key="weekDate.toString()"
            :date="weekDate"
            class="relative flex-1 p-0 text-center text-sm"
          >
            <CalendarCellTrigger
              :day="weekDate"
              :month="month.value"
              :class="
                cn(
                  buttonVariants({ variant: 'ghost' }),
                  'h-8 w-8 p-0 font-normal text-foreground',
                  'data-[today]:bg-accent data-[today]:text-accent-foreground',
                  'data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary data-[selected]:hover:text-primary-foreground',
                  'data-[outside-view]:text-muted-foreground/40',
                  'data-[disabled]:pointer-events-none data-[disabled]:opacity-40',
                  'data-[unavailable]:pointer-events-none data-[unavailable]:text-critical data-[unavailable]:line-through',
                )
              "
            />
          </CalendarCell>
        </CalendarGridRow>
      </CalendarGridBody>
    </CalendarGrid>

    <div v-if="view === 'month'" class="grid grid-cols-3 gap-1">
      <button
        v-for="m in 12"
        :key="m"
        type="button"
        :disabled="isMonthDisabled(placeholder.set({ month: m, day: 1 }))"
        :class="
          cn(
            buttonVariants({ variant: 'ghost' }),
            'h-9 font-normal text-foreground',
            m === placeholder.month && 'bg-accent text-accent-foreground',
            'disabled:pointer-events-none disabled:opacity-40',
          )
        "
        @click="pickMonth(m)"
      >
        {{ monthLabel(placeholder.set({ month: m, day: 1 })) }}
      </button>
    </div>

    <div v-else-if="view === 'year'" class="grid grid-cols-3 gap-1">
      <button
        v-for="y in yearPage"
        :key="y"
        type="button"
        :disabled="isYearDisabled(y)"
        :class="
          cn(
            buttonVariants({ variant: 'ghost' }),
            'h-9 font-normal text-foreground',
            y === placeholder.year && 'bg-accent text-accent-foreground',
            'disabled:pointer-events-none disabled:opacity-40',
          )
        "
        @click="pickYear(y)"
      >
        {{ y }}
      </button>
    </div>
  </CalendarRoot>
</template>
