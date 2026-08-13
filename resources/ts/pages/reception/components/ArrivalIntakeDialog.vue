/**
 * ArrivalIntakeDialog — Walk-in / Emergency arrival (Volume 2.1 §10.1)
 * ========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction, no behavior change.
 *
 * `vue/no-mutating-props` is disabled — see ScheduleView.vue's docblock for
 * the full reasoning (same shared-composable-instance pattern).
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- see file header docblock */
import { LogIn, Siren, TriangleAlert, UserRound } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import type { Patient } from "@/stores/patientStore";
import type { useArrivalIntake } from "../composables/useArrivalIntake";

const props = defineProps<{
  arrival: ReturnType<typeof useArrivalIntake>;
  patient: Patient | null;
}>();

const { t } = useI18n();

function submit() {
  void props.arrival.submitArrival(props.patient);
}
</script>

<template>
  <Dialog
    :open="arrival.showArrivalDialog.value"
    @update:open="(v) => !v && arrival.closeArrivalDialog()"
  >
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ t("arrival.title") }}</DialogTitle>
      </DialogHeader>

      <div class="grid grid-cols-2 gap-3">
        <button
          type="button"
          class="focus-ring flex flex-col items-start gap-1 rounded-md border p-3 text-left transition-colors"
          :class="
            arrival.arrivalMode.value === 'walk_in'
              ? 'border-primary bg-primary/5'
              : 'border-border hover:bg-accent'
          "
          :aria-pressed="arrival.arrivalMode.value === 'walk_in'"
          @click="arrival.arrivalMode.value = 'walk_in'"
        >
          <UserRound class="h-4 w-4 text-foreground" aria-hidden="true" />
          <span class="text-sm font-medium text-foreground">{{
            t("arrival.walk_in")
          }}</span>
          <span class="text-xs text-muted-foreground">{{
            t("arrival.walk_in_hint")
          }}</span>
        </button>
        <button
          type="button"
          class="focus-ring flex flex-col items-start gap-1 rounded-md border p-3 text-left transition-colors"
          :class="
            arrival.arrivalMode.value === 'emergency'
              ? 'border-critical bg-critical/5'
              : 'border-border hover:bg-accent'
          "
          :aria-pressed="arrival.arrivalMode.value === 'emergency'"
          @click="arrival.arrivalMode.value = 'emergency'"
        >
          <Siren class="h-4 w-4 text-critical" aria-hidden="true" />
          <span class="text-sm font-medium text-foreground">{{
            t("arrival.emergency")
          }}</span>
          <span class="text-xs text-muted-foreground">{{
            t("arrival.emergency_hint")
          }}</span>
        </button>
      </div>

      <div class="space-y-2">
        <label for="arrival-reason" class="text-sm font-medium text-foreground">
          {{ t("arrival.reason_label") }}
        </label>
        <Input
          id="arrival-reason"
          v-model="arrival.arrivalReason.value"
          :aria-label="t('arrival.reason_label')"
          :placeholder="t('arrival.reason_placeholder')"
          @keydown.enter="submit"
        />
      </div>

      <DialogFooter>
        <Button variant="secondary" @click="arrival.closeArrivalDialog">
          {{ t("common.cancel") }}
        </Button>
        <Button
          :variant="arrival.arrivalMode.value === 'emergency' ? 'critical' : 'default'"
          class="inline-flex items-center gap-1.5"
          :disabled="arrival.arrivalSubmitting.value"
          @click="submit"
        >
          <TriangleAlert
            v-if="arrival.arrivalMode.value === 'emergency'"
            class="h-3.5 w-3.5"
            aria-hidden="true"
          />
          <LogIn v-else class="h-3.5 w-3.5" aria-hidden="true" />
          {{ t("arrival.check_in") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
