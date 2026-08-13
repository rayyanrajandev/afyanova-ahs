/**
 * ScheduleAppointmentDialog — create-appointment dialog (Volume 2.1 §9.2/§9.3)
 * ================================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — same fields, same validation, same conflict
 * handling. Shares its state with ScheduleView via the same
 * useAppointmentScheduling() instance (passed down from Index.vue), since
 * both the tab's "+ New" button and a patient profile's "Schedule" button
 * open this same dialog.
 *
 * `vue/no-mutating-props` is disabled below — see ScheduleView.vue's
 * docblock for the full reasoning (same shared-composable-instance pattern,
 * same reason it doesn't apply here).
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- see file header docblock */
import { CalendarPlus, TriangleAlert, X } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { TimePicker } from "@/components/ui/time-picker";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import type { Patient } from "@/stores/patientStore";
import type { useAppointmentScheduling } from "../composables/useAppointmentScheduling";
import { patientDisplayName } from "../receptionFormatters";

const props = defineProps<{
  scheduling: ReturnType<typeof useAppointmentScheduling>;
}>();

const { t, locale } = useI18n();

// DatePicker min-value (found + reflected here 2026-08-11, same reasoning
// as PatientRegistrationFields.vue's own todayIso): the backend already
// rejects a past-dated appointment (StoreAppointmentRequest's
// `after_or_equal:now`) — this just stops it being pickable at all
// instead of round-tripping a guaranteed validation error.
const todayIso = new Date().toISOString().slice(0, 10);

function selectPatient(patient: Patient) {
  props.scheduling.selectScheduleFormPatient(patient);
}
</script>

<template>
  <Dialog :open="scheduling.showScheduleDialog.value" @update:open="(v) => !v && scheduling.closeScheduleDialog()">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ t("appointment.new") }}</DialogTitle>
      </DialogHeader>

      <div class="space-y-3">
        <!-- Patient: locked display when opened from a profile, search otherwise -->
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-foreground">{{ t("appointment.patient") }}</label>
          <div
            v-if="scheduling.scheduleFormPatientLocked.value"
            class="rounded-md border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground"
          >
            {{ scheduling.scheduleFormPatientLabel.value }}
          </div>
          <div v-else class="relative">
            <div
              v-if="scheduling.scheduleFormPatientId.value"
              class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm"
            >
              <span class="font-medium text-foreground">{{ scheduling.scheduleFormPatientLabel.value }}</span>
              <!-- Tooltip added, aria-label corrected (workspace audit,
                   2026-08-11): this clears the selected patient so a
                   different one can be searched — it was mislabeled
                   `common.cancel` ("Cancel"), not what the button does. -->
              <Tooltip>
                <TooltipTrigger as-child>
                  <Button
                    variant="ghost"
                    size="sm"
                    class="h-6 w-6 p-0"
                    :aria-label="t('appointment.clear_patient_selection')"
                    @click="scheduling.clearScheduleFormPatient"
                  >
                    <X class="h-3 w-3" aria-hidden="true" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent>{{ t("appointment.clear_patient_selection") }}</TooltipContent>
              </Tooltip>
            </div>
            <Input
              v-else
              v-model="scheduling.scheduleFormPatientQuery.value"
              type="search"
              :placeholder="t('patient.search')"
              :aria-label="t('patient.search')"
              @input="scheduling.onScheduleFormPatientInput"
            />
            <ul
              v-if="scheduling.scheduleFormPatientResults.value.length > 0"
              class="mt-1 max-h-40 overflow-y-auto rounded-md border border-border"
            >
              <li
                v-for="patient in scheduling.scheduleFormPatientResults.value"
                :key="patient.id"
                class="cursor-pointer px-3 py-1.5 text-sm hover:bg-accent"
                @click="selectPatient(patient)"
              >
                <span class="font-medium text-foreground">{{
                  patientDisplayName(patient)
                }}</span>
                <span class="clinical-value ml-2 text-xs text-muted-foreground">{{
                  patient.identifier[0]?.value
                }}</span>
              </li>
            </ul>
          </div>
          <p v-if="scheduling.scheduleFormErrors.value.patientId" class="text-xs text-critical">
            {{ scheduling.scheduleFormErrors.value.patientId }}
          </p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label for="schedule-date" class="text-sm font-medium text-foreground">{{
              t("appointment.date")
            }}</label>
            <DatePicker
              id="schedule-date"
              v-model="scheduling.scheduleFormDate.value"
              :min-value="todayIso"
              :locale="locale"
              :placeholder="t('appointment.date')"
            />
          </div>
          <div class="space-y-1.5">
            <label for="schedule-time" class="text-sm font-medium text-foreground">{{
              t("appointment.time")
            }}</label>
            <TimePicker
              id="schedule-time"
              v-model="scheduling.scheduleFormTime.value"
              :placeholder="t('appointment.time')"
            />
          </div>
        </div>
        <p v-if="scheduling.scheduleFormErrors.value.scheduledAt" class="text-xs text-critical">
          {{ scheduling.scheduleFormErrors.value.scheduledAt }}
        </p>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-foreground">{{
              t("appointment.clinician")
            }}</label>
            <Select v-model="scheduling.scheduleFormClinicianUserId.value">
              <SelectTrigger class="w-full">
                <SelectValue :placeholder="t('appointment.clinician_unassigned')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="clinician in scheduling.clinicianOptions.value"
                  :key="clinician.id"
                  :value="String(clinician.id)"
                >
                  {{ clinician.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-foreground">
              {{ t("appointment.department") }}
              <span v-if="scheduling.scheduleFormNeedsDepartment.value" class="text-critical">*</span>
            </label>
            <Select v-model="scheduling.scheduleFormDepartment.value">
              <SelectTrigger class="w-full">
                <SelectValue :placeholder="t('appointment.department')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="dept in scheduling.departmentOptions.value"
                  :key="dept.value"
                  :value="dept.value"
                >
                  {{ dept.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <p v-if="scheduling.scheduleFormErrors.value.department" class="text-xs text-critical">
              {{ scheduling.scheduleFormErrors.value.department }}
            </p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <label for="schedule-duration" class="text-sm font-medium text-foreground">{{
              t("appointment.duration")
            }}</label>
            <Select v-model="scheduling.scheduleFormDuration.value">
              <SelectTrigger id="schedule-duration" class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="15">15 {{ t("appointment.minutes_short") }}</SelectItem>
                <SelectItem value="30">30 {{ t("appointment.minutes_short") }}</SelectItem>
                <SelectItem value="45">45 {{ t("appointment.minutes_short") }}</SelectItem>
                <SelectItem value="60">60 {{ t("appointment.minutes_short") }}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div class="space-y-1.5">
            <label for="schedule-reason" class="text-sm font-medium text-foreground">{{
              t("appointment.reason")
            }}</label>
            <Input
              id="schedule-reason"
              v-model="scheduling.scheduleFormReason.value"
              :placeholder="t('appointment.reason')"
            />
          </div>
        </div>

        <div
          v-if="scheduling.scheduleFormConflictMessage.value"
          class="flex items-start gap-2 rounded-md border border-critical bg-critical/5 p-3 text-sm text-critical"
          role="alert"
        >
          <TriangleAlert class="h-4 w-4 shrink-0" aria-hidden="true" />
          {{ scheduling.scheduleFormConflictMessage.value }}
        </div>
      </div>

      <DialogFooter>
        <Button variant="secondary" @click="scheduling.closeScheduleDialog">
          {{ t("common.cancel") }}
        </Button>
        <Button
          class="inline-flex items-center gap-1.5"
          :disabled="scheduling.scheduleFormSubmitting.value"
          @click="scheduling.submitScheduleForm"
        >
          <CalendarPlus class="h-3.5 w-3.5" aria-hidden="true" />
          {{ t("appointment.new") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
