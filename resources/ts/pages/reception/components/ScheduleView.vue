/**
 * ScheduleView — context-pane Schedule tab content (Volume 2.1 §9.1)
 * ======================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — the day/week toggle, date nav, needs-clinician
 * filter, and the appointment list, unchanged.
 *
 * Receives the whole useAppointmentScheduling() instance as one prop
 * rather than ~15 individual props — Index.vue owns the single composable
 * instance (shared with ScheduleAppointmentDialog, which needs the same
 * state), this just reads/calls into it.
 *
 * Redesigned 2026-08-10 (component-library audit, following the SplitPane
 * resizable-context-pane work): bigger date navigation and two-line rows
 * (time+name, then department+clinician) instead of three cramped lines —
 * both were sized for the old fixed 320px pane. The assigned clinician's
 * name now shows on the row too (previously only an "Unassigned" badge
 * rendered, nothing when one *was* assigned) — see
 * `useAppointmentScheduling.ts`'s `clinicianName()`.
 *
 * `vue/no-mutating-props` is disabled below (deliberately, not silenced —
 * this is the first eslint-disable in this codebase, so worth being
 * explicit about why): the rule's premise is "a child shouldn't reach into
 * parent-owned data," which fits props that ARE the parent's own state.
 * `scheduling` isn't that — it's a shared composable instance both this
 * component and ScheduleAppointmentDialog jointly read *and* write by
 * design (that's the whole reason it's one composable instance passed
 * down, not two independent prop+emit pairs re-deriving the same state).
 * Assigning through `scheduling.scheduleView.value` mutates the *ref*
 * object itself, which Index.vue's own `scheduling` variable still points
 * to — not a prop-cloning footgun the rule is meant to catch.
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- see file header docblock */
import {
  CalendarPlus,
  ChevronLeft,
  ChevronRight,
  LogIn,
} from "lucide-vue-next";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import type { ScheduleAppointment, useAppointmentScheduling } from "../composables/useAppointmentScheduling";
import type { useArrivalIntake } from "../composables/useArrivalIntake";
import { isToday, patientInitials } from "../receptionFormatters";

const props = defineProps<{
  scheduling: ReturnType<typeof useAppointmentScheduling>;
  arrivalIntake: ReturnType<typeof useArrivalIntake>;
}>();

const { t } = useI18n();

/**
 * One-click Check In on today's rows (Volume 3.7 T4.6 follow-up, 2026-08-13).
 * T4.6 as originally scoped ("auto check-in the patient into the queue on
 * the appointment date") was deliberately not built — it would create a
 * "waiting" queue entry, with wait-time math already running, for a patient
 * who hasn't actually arrived (or may never show — that's what the real
 * No-show status is for). Every queue entry's `arrivalMode` is meant to be
 * recorded by a real action, not inferred from a booking existing. The
 * actual friction T4.6 was chasing was real, though: checking in a same-day
 * scheduled patient took 4 steps (open Schedule → click their row → wait
 * for the profile to load → find Check In on the Upcoming Appointments
 * card). This closes that gap without the data-integrity problem — same
 * explicit action (`checkInAppointment`), one click instead of four,
 * restricted to today's rows only (checking in a future date makes no more
 * sense here than it would from the profile card).
 *
 * `checkingInId` (not `scheduling.isScheduleLoading`) tracks in-flight
 * state per-row — this list refetches on check-in (see
 * `refreshScheduleIfLoaded`/`onCheckedIn`'s `scheduling.refreshScheduleIfLoaded()`
 * in Index.vue), so without a local guard a slow connection would let a
 * second click on the same row fire a second check-in before the list
 * re-render removed it.
 */
const checkingInId = ref<string | null>(null);

async function handleCheckIn(appt: ScheduleAppointment) {
  checkingInId.value = appt.id;
  try {
    await props.arrivalIntake.checkInAppointment(appt.id, appt.patientId);
  } finally {
    checkingInId.value = null;
  }
}
</script>

<template>
  <div class="space-y-3 border-b border-border p-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <!-- Restyled to match Queue.vue's Sort segmented control (workspace
           consistency audit, 2026-08-11) — both are the same UI pattern
           (a 2-3-way toggle between views of the same data, not
           navigation, so correctly a segmented control rather than the
           Tabs underline style — see TabsList.vue's docblock) but had
           drifted to two different constructions: this one was a bare
           bordered box with `bg-accent` for the active side, Queue's was
           a `bg-muted` padded track with a raised `bg-surface` segment.
           Unified on Queue's version, including dropping the `shadow-xs`
           its active segment had (fixed there too, same pass) — a
           raised segment flush inside its own track isn't a floating
           layer (Volume 0.2 §7.4), the same rule TabsTrigger's own
           active state was already corrected for. -->
      <div
        class="inline-flex rounded-md border border-border bg-muted p-0.5"
        role="radiogroup"
        :aria-label="t('appointment.view_toggle_label')"
      >
        <button
          type="button"
          role="radio"
          :aria-checked="scheduling.scheduleView.value === 'day'"
          class="rounded-sm px-3 py-1.5 text-sm font-medium transition-colors"
          :class="
            scheduling.scheduleView.value === 'day'
              ? 'bg-surface text-foreground'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="scheduling.scheduleView.value = 'day'"
        >
          {{ t("appointment.view_day") }}
        </button>
        <button
          type="button"
          role="radio"
          :aria-checked="scheduling.scheduleView.value === 'week'"
          class="rounded-sm px-3 py-1.5 text-sm font-medium transition-colors"
          :class="
            scheduling.scheduleView.value === 'week'
              ? 'bg-surface text-foreground'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="scheduling.scheduleView.value = 'week'"
        >
          {{ t("appointment.view_week") }}
        </button>
      </div>
      <Button
        size="sm"
        class="h-8 gap-1.5 px-3"
        @click="scheduling.openScheduleDialogGeneral"
      >
        <CalendarPlus class="h-4 w-4" aria-hidden="true" />
        {{ t("appointment.new") }}
      </Button>
    </div>

    <div class="flex items-center justify-between gap-2">
      <div class="flex min-w-0 items-center gap-1.5">
        <Tooltip>
          <TooltipTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="h-8 w-8 shrink-0 p-0"
              :aria-label="t('appointment.prev')"
              @click="scheduling.scheduleStep(-1)"
            >
              <ChevronLeft class="h-4 w-4" aria-hidden="true" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{{ t("appointment.prev") }}</TooltipContent>
        </Tooltip>
        <span class="clinical-value truncate text-sm font-semibold text-foreground">{{
          scheduling.scheduleRangeLabel.value
        }}</span>
        <Tooltip>
          <TooltipTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="h-8 w-8 shrink-0 p-0"
              :aria-label="t('appointment.next')"
              @click="scheduling.scheduleStep(1)"
            >
              <ChevronRight class="h-4 w-4" aria-hidden="true" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{{ t("appointment.next") }}</TooltipContent>
        </Tooltip>
      </div>
      <Button
        variant="ghost"
        size="sm"
        class="h-8 shrink-0 px-3 text-sm"
        @click="scheduling.scheduleGoToday"
      >
        {{ t("appointment.today") }}
      </Button>
    </div>

    <label class="flex items-center gap-1.5 text-sm text-muted-foreground">
      <!-- `v-model`, not `v-model:checked` — Checkbox's underlying reka-ui
           primitive uses `modelValue`/`update:modelValue` (see Checkbox.vue
           docblock); `checked` isn't a real prop on it, so this filter
           silently did nothing before (checkbox visual clicked fine, but
           never reached this ref — found & fixed during the Reception
           design-audit fixes, 2026-08-11). -->
      <Checkbox v-model="scheduling.scheduleNeedsClinicianOnly.value" />
      {{ t("appointment.needs_clinician_filter") }}
    </label>
  </div>

  <div class="flex-1 overflow-y-auto p-2">
    <!-- Skeleton loader (3 animated cards) -->
    <div v-if="scheduling.isScheduleLoading.value" class="space-y-2 p-1">
      <div
        v-for="n in 3"
        :key="n"
        class="rounded-lg border border-border bg-card p-3 space-y-2.5 animate-pulse"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <div class="h-5 w-14 rounded bg-secondary/80" />
            <div class="h-4 w-28 rounded bg-secondary/80" />
          </div>
          <div class="h-4 w-16 rounded bg-secondary/80" />
        </div>
        <div class="flex items-center justify-between">
          <div class="h-3.5 w-20 rounded bg-secondary/60" />
          <div class="h-3.5 w-24 rounded bg-secondary/60" />
        </div>
      </div>
    </div>

    <p v-else-if="scheduling.scheduleError.value" class="p-3 text-sm text-critical">
      {{ scheduling.scheduleError.value }}
    </p>

    <!-- Empty state -->
    <EmptyState
      v-else-if="scheduling.scheduleAppointments.value.length === 0"
      illustration="clipboard"
      :title="t('appointment.schedule_empty')"
      :description="t('appointment.schedule_empty_hint')"
      :action-label="scheduling.scheduleNeedsClinicianOnly.value ? t('appointment.schedule_empty_action') : undefined"
      @action="scheduling.scheduleNeedsClinicianOnly.value = false"
    />

    <!-- Appointment Cards List -->
    <ul v-else class="space-y-2">
      <li
        v-for="appt in scheduling.scheduleAppointments.value"
        :key="appt.id"
        class="group focus-ring cursor-pointer rounded-lg border border-border bg-card p-2.5 text-sm transition-all hover:bg-accent/60 hover:shadow-xs border-l-3"
        :class="appt.clinicianUserId ? 'border-l-primary/70' : 'border-l-warning'"
        tabindex="0"
        role="button"
        @click="scheduling.openScheduleAppointmentPatient(appt)"
        @keydown.enter="scheduling.openScheduleAppointmentPatient(appt)"
      >
        <!-- Time + Avatar + Patient Name + Consultation Type Badge -->
        <div class="flex items-center justify-between gap-2">
          <div class="flex min-w-0 items-center gap-2">
            <span class="clinical-value shrink-0 font-mono text-[11px] font-semibold text-foreground px-1.5 py-0.5 rounded bg-secondary border border-border/60">
              {{ scheduling.formatClinicalTime(appt.scheduledAt) }}
            </span>
            <Avatar class="size-5 shrink-0">
              <AvatarFallback class="text-[9px] font-semibold bg-primary/10 text-primary">
                {{ patientInitials(appt.patientName ?? "PT") }}
              </AvatarFallback>
            </Avatar>
            <span class="truncate font-semibold text-[12.5px] text-foreground">
              {{ appt.patientName ?? t("common.no_data") }}
            </span>
          </div>
          <Badge
            :variant="appt.consultationType === 'review' ? 'info' : 'success'"
            class="shrink-0 text-[10px] px-1.5 py-0 font-mono"
          >
            {{ scheduling.consultationTypeLabel(appt.consultationType) }}
          </Badge>
        </div>

        <!-- Department + Clinician assignment -->
        <div class="mt-1.5 flex items-center justify-between gap-2 text-xs text-muted-foreground">
          <span class="min-w-0 truncate text-[11.5px]">{{ appt.department ?? "—" }}</span>
          <span v-if="appt.clinicianUserId" class="min-w-0 truncate text-[11.5px] font-medium text-foreground/80">
            {{ scheduling.clinicianName(appt.clinicianUserId) }}
          </span>
          <Badge v-else variant="warning" class="shrink-0 text-[9.5px] px-1.5 py-0">
            {{ t("appointment.unassigned") }}
          </Badge>
        </div>

        <!-- One-click Check In for today's rows -->
        <div
          v-if="isToday(appt.scheduledAt)"
          class="mt-2 flex items-center justify-between border-t border-border/70 pt-1.5"
          @click.stop
        >
          <span class="text-[10.5px] text-muted-foreground font-mono">{{ t("appointment.today_visit") }}</span>
          <Button
            variant="ghost"
            size="sm"
            class="h-6 gap-1 px-2 text-[11px] text-primary hover:bg-primary/10 font-medium cursor-pointer"
            :disabled="checkingInId === appt.id"
            @click="handleCheckIn(appt)"
          >
            <LogIn class="size-3" aria-hidden="true" />
            {{ checkingInId === appt.id ? t("common.loading") : t("arrival.check_in") }}
          </Button>
        </div>
      </li>
    </ul>
  </div>
</template>
