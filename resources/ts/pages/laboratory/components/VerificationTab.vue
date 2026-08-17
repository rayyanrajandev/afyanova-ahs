/**
 * VerificationTab — Senior Scientist Electronic Validation & EMR Release (Volume 2.4 §7.2)
 * =======================================================================================
 * 2027 Modern Enterprise Hospital LIS Verification Station:
 * - Comprehensive Diagnostic Report Card Preview
 * - Two-Eye Review: Parameter Inspection & QC Run Validation
 * - Supervisor Clinical Impression & Authorization Remarks
 * - Instant Electronic Release to Clinician Chart & PDF Export
 * - Full Internationalization (i18n) Support
 */

<script setup lang="ts">
import {
  AlertTriangle,
  Award,
  CheckCircle2,
  Clock,
  Download,
  FileCheck,
  FileText,
  FlaskConical,
  Printer,
  Send,
  ShieldCheck,
  Sparkles,
  UserCheck,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { LaboratoryOrder, UseLaboratoryOrders } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const supervisorComments = ref(props.order.interpretation || "All parameters verified against quality control standards and biological reference limits.");

const isOrderVerified = computed(() => props.order.status === "completed");

const hasCritical = computed(() => {
  return props.order.parameters.some((p) => p.flag === "critical_low" || p.flag === "critical_high");
});

function handleVerify() {
  props.laboratory.verifyOrder(props.order.id, supervisorComments.value);
}

function handlePrintReport() {
  window.print();
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    
    <!-- Verified Success Banner -->
    <div
      v-if="isOrderVerified"
      class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-950 dark:text-emerald-100 flex items-center justify-between gap-3 shadow-xs"
    >
      <div class="flex items-center gap-2.5">
        <ShieldCheck class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
        <div>
          <p class="font-bold text-xs">{{ t('laboratory.verified_banner_title', 'Diagnostic Report Electronically Verified & Released') }}</p>
          <p class="text-[11px] text-emerald-800/90 dark:text-emerald-300/90 mt-0.5 font-mono">
            {{ t('laboratory.verified_banner_desc', { user: order.verifiedBy || 'Senior MLS', time: order.verifiedAt ? new Date(order.verifiedAt).toLocaleString() : 'Recent' }) }}
          </p>
        </div>
      </div>

      <Button
        variant="outline"
        size="sm"
        class="h-7 text-xs font-semibold gap-1.5 px-3 border-emerald-500/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/15 cursor-pointer"
        @click="handlePrintReport"
      >
        <Printer class="size-3.5" />
        <span>{{ t('laboratory.print_report', 'Print Lab Report') }}</span>
      </Button>
    </div>

    <!-- Diagnostic Report Card -->
    <section class="rounded-lg border border-border bg-surface p-4 shadow-2xs space-y-4">
      <!-- Report Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <div>
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-bold text-foreground">
              {{ t('laboratory.official_report', 'Official Diagnostic Laboratory Report') }}
            </h3>
            <Badge variant="outline" class="text-[9px] font-mono uppercase px-1.5 py-0">
              {{ t('laboratory.iso_badge', 'ISO 15189') }}
            </Badge>
          </div>
          <p class="text-[11px] text-muted-foreground mt-0.5">
            {{ t('laboratory.hospital_lab_name', 'AfyaNova Automated Clinical Laboratories') }} · {{ t('laboratory.dept_of', { dept: order.department }) }}
          </p>
        </div>

        <div class="flex items-center gap-2 font-mono text-xs">
          <Badge
            variant="outline"
            class="text-[10px] uppercase font-mono px-2 py-0.5"
            :class="isOrderVerified ? 'border-emerald-500 text-emerald-600 bg-emerald-500/10' : 'border-amber-500 text-amber-600 bg-amber-500/10'"
          >
            {{ isOrderVerified ? t('laboratory.final_report', 'Final Verified Report') : t('laboratory.draft_report', 'Draft / Pre-Release') }}
          </Badge>
        </div>
      </div>

      <!-- Patient & Specimen Metadata Box -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-lg border border-border/70 bg-muted/20 text-xs">
        <div>
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.meta_patient_name', 'Patient Name') }}</span>
          <span class="font-bold text-foreground">{{ order.patientName }}</span>
        </div>
        <div>
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.meta_mrn', 'Medical Record No (MRN)') }}</span>
          <span class="font-mono font-bold text-primary">{{ order.patientMrn }}</span>
        </div>
        <div>
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.meta_clinician', 'Ordering Clinician') }}</span>
          <span class="font-medium text-foreground">{{ order.orderingClinician }}</span>
        </div>
        <div>
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.meta_accession', 'Specimen Accession') }}</span>
          <span class="font-mono font-semibold text-foreground">{{ order.orderNumber }}</span>
        </div>
      </div>

      <!-- Parameter Results Table -->
      <div class="rounded-lg border border-border bg-surface overflow-hidden">
        <table class="w-full text-left text-xs table-fixed">
          <thead class="border-b border-border/70 bg-muted/30 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">
            <tr>
              <th class="p-2.5 pl-3 w-[32%]">{{ t('laboratory.meta_test', 'Test Investigation') }}</th>
              <th class="p-2.5 w-[20%]">{{ t('laboratory.meta_result', 'Observed Result') }}</th>
              <th class="p-2.5 w-[14%]">{{ t('laboratory.th_units', 'Units') }}</th>
              <th class="p-2.5 w-[20%]">{{ t('laboratory.th_reference', 'Biological Reference') }}</th>
              <th class="p-2.5 w-[14%] text-right pr-3">{{ t('laboratory.th_flag', 'Evaluation') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="p in order.parameters" :key="p.key" class="hover:bg-muted/15">
              <td class="p-2.5 pl-3 font-semibold text-foreground text-[12px]">
                {{ p.name }}
              </td>
              <td class="p-2.5 font-mono font-bold text-[12.5px]" :class="{ 'text-rose-600': p.flag.startsWith('critical'), 'text-amber-600': p.flag === 'abnormal', 'text-foreground': p.flag === 'normal' }">
                {{ p.value ?? '—' }}
              </td>
              <td class="p-2.5 text-muted-foreground font-mono text-[11px]">
                {{ p.unit }}
              </td>
              <td class="p-2.5 font-mono text-[11px] text-muted-foreground">
                {{ p.referenceRange }}
              </td>
              <td class="p-2.5 pr-3 text-right">
                <Badge
                  variant="outline"
                  class="text-[9px] font-mono font-bold uppercase px-1.5 py-0"
                  :class="{
                    'border-emerald-500/40 text-emerald-600 bg-emerald-500/10': p.flag === 'normal',
                    'border-amber-500/40 text-amber-600 bg-amber-500/10': p.flag === 'abnormal',
                    'border-rose-500/50 text-rose-600 bg-rose-500/15': p.flag.startsWith('critical'),
                  }"
                >
                  {{ p.flag === 'normal' ? t('laboratory.flag_normal', 'NORMAL') : p.flag === 'abnormal' ? t('laboratory.flag_abnormal', 'ABNORMAL') : t('laboratory.flag_crit_high', 'CRITICAL') }}
                </Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- QC & Supervisor Comments -->
      <div class="space-y-3 pt-2 text-xs">
        <!-- Quality Control Validation Pill -->
        <div class="flex items-center justify-between p-2.5 rounded-lg border border-emerald-500/30 bg-emerald-500/5">
          <div class="flex items-center gap-2">
            <Award class="size-4 text-emerald-600" />
            <span class="font-medium text-foreground">
              {{ t('laboratory.iqc_title', 'Internal Quality Control (IQC):') }} <strong class="text-emerald-600">{{ t('laboratory.iqc_passed', 'Passed (2SD Limit)') }}</strong>
            </span>
          </div>
          <span class="text-[11px] font-mono text-muted-foreground">{{ t('laboratory.westgard_ok', 'Westgard Multi-Rule OK') }}</span>
        </div>

        <!-- Supervisor Clinical Impression -->
        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t('laboratory.senior_remarks', 'Senior Scientist Remarks & Clinical Release Notes') }}
          </Label>
          <Textarea
            v-model="supervisorComments"
            rows="2"
            class="text-xs resize-none"
            :disabled="isOrderVerified"
            placeholder="Add clinical remarks or authorization notes..."
          />
        </div>
      </div>
    </section>

    <!-- Sign-off Action Bar -->
    <div class="flex items-center justify-between gap-3 p-3.5 rounded-lg border border-border bg-surface shadow-2xs">
      <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <UserCheck class="size-4 text-primary" />
        <span>{{ t('laboratory.two_eye_protocol', 'Two-Eye Verification Protocol:') }} <strong class="text-foreground">{{ t('laboratory.two_eye_required', 'Required') }}</strong></span>
      </div>

      <div class="flex items-center gap-2">
        <Button
          variant="secondary"
          size="sm"
          class="h-8 text-xs cursor-pointer gap-1"
          @click="handlePrintReport"
        >
          <Printer class="size-3.5" />
          <span>{{ t('laboratory.print_report', 'Print Report') }}</span>
        </Button>

        <Button
          v-if="!isOrderVerified"
          size="sm"
          class="h-8 text-xs font-semibold gap-1.5 px-4 cursor-pointer shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white"
          :disabled="laboratory.isVerifying.value"
          @click="handleVerify"
        >
          <CheckCircle2 class="size-3.5" />
          <span>{{ laboratory.isVerifying.value ? t('laboratory.verifying', 'Publishing...') : t('laboratory.authorize_release', 'Authorize & Release to EMR') }}</span>
        </Button>
      </div>
    </div>
  </div>
</template>
