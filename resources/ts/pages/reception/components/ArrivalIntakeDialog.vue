/**
 * ArrivalIntakeDialog — Walk-in / Emergency arrival with Insurance Clearance (Volume 2.1 §10.1)
 * ========================================================================
 * Provides multi-workspace insurance clearance verification before patients are
 * moved from Reception into the Triage/Clinical work queues.
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- see file header docblock */
import {
  LogIn,
  ShieldCheck,
  Siren,
  TriangleAlert,
  UserRound,
} from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import type { Patient, PatientInsuranceSummary } from "@/stores/patientStore";
import type { useArrivalIntake } from "../composables/useArrivalIntake";
import type { useInsuranceForm } from "../composables/useInsuranceForm";

const props = defineProps<{
  arrival: ReturnType<typeof useArrivalIntake>;
  patient: Patient | null;
  insurance?: PatientInsuranceSummary | null;
  insuranceForm?: ReturnType<typeof useInsuranceForm>;
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
    <DialogContent class="sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>{{ t("arrival.title") }}</DialogTitle>
      </DialogHeader>

      <div class="space-y-4">
        <!-- Arrival Mode Selector -->
        <div class="grid grid-cols-2 gap-3">
          <button
            type="button"
            class="focus-ring flex flex-col items-start gap-1 rounded-md border p-3 text-left transition-colors cursor-pointer"
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
            class="focus-ring flex flex-col items-start gap-1 rounded-md border p-3 text-left transition-colors cursor-pointer"
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

        <!-- Insurance Clearance & Multi-Workspace Safety Notice -->
        <div
          v-if="insurance"
          class="rounded-lg border p-3 text-xs"
          :class="insurance.verificationStatus === 'verified' ? 'bg-success/5 border-success/30' : 'bg-warning/10 border-warning/30'"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-start gap-2">
              <ShieldCheck v-if="insurance.verificationStatus === 'verified'" class="size-4 text-success shrink-0 mt-0.5" />
              <TriangleAlert v-else class="size-4 text-warning shrink-0 mt-0.5" />
              <div>
                <p class="font-semibold text-foreground">
                  {{ insurance.verificationStatus === 'verified' ? `${t('insurance.insurance')} (${t('insurance.verified')})` : t('insurance.unverified_warning_title') }}
                </p>
                <p class="text-muted-foreground mt-0.5">
                  <span>{{ insurance.insuranceProvider }}</span>
                  <span v-if="insurance.memberId"> · Member ID: <strong class="text-foreground font-mono">{{ insurance.memberId }}</strong></span>
                </p>
                <p v-if="insurance.verificationStatus !== 'verified'" class="text-[11px] text-warning-foreground mt-1">
                  {{ t('insurance.unverified_warning_desc') }}
                </p>
              </div>
            </div>
            <Button
              v-if="insurance.verificationStatus !== 'verified' && insurance.id && insuranceForm"
              type="button"
              variant="outline"
              size="sm"
              class="h-7 text-xs px-2 gap-1 text-primary shrink-0 border-primary/30 cursor-pointer"
              @click="patient && insuranceForm.verifyInsurance(patient.id, insurance.id)"
            >
              <ShieldCheck class="size-3" />
              {{ t('insurance.verify') }}
            </Button>
          </div>
        </div>
        <div v-else class="rounded-lg border border-border/60 bg-muted/40 p-2.5 text-xs flex items-center justify-between">
          <span class="text-muted-foreground">{{ t('insurance.billing_profile') }}: <strong class="text-foreground font-medium">{{ t('insurance.cash_self_pay') }}</strong></span>
          <Badge variant="secondary" class="text-[10px]">{{ t('nursing.self_pay') }}</Badge>
        </div>

        <!-- Arrival Reason Input -->
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
      </div>

      <DialogFooter class="mt-2">
        <Button variant="secondary" @click="arrival.closeArrivalDialog">
          {{ t("common.cancel") }}
        </Button>
        <Button
          :variant="arrival.arrivalMode.value === 'emergency' ? 'critical' : 'default'"
          class="inline-flex items-center gap-1.5 cursor-pointer"
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
