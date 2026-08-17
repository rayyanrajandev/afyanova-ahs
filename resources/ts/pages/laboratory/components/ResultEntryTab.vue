/**
 * ResultEntryTab — Diagnostic Result Matrix & Clinical Range Evaluation (Volume 2.4 §7)
 * ======================================================================================
 * 2027 Modern Enterprise Hospital LIS Result Entry Station:
 * - Structured Multi-Parameter Analytical Grid (Values, Units, Reference Ranges)
 * - Live Automated Reference Range & Critical Acuity Evaluation
 * - Historical Comparison / Delta Checks
 * - One-click Normal Baseline Autofill for negative screening
 * - Panic Critical Alert Trigger with instant clinician read-back modal
 * - Full Internationalization (i18n) Support
 */

<script setup lang="ts">
import {
  Activity,
  AlertCircle,
  AlertTriangle,
  ArrowDown,
  ArrowRight,
  ArrowUp,
  Check,
  CheckCircle2,
  Clock,
  Eye,
  FileCheck,
  FlaskConical,
  HeartPulse,
  History,
  Info,
  PhoneCall,
  Save,
  Send,
  Sparkles,
  TrendingDown,
  TrendingUp,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { LabTestParameter, LaboratoryOrder, UseLaboratoryOrders } from "../composables/useLaboratoryOrders";
import CriticalAlertModal from "./CriticalAlertModal.vue";

const props = defineProps<{
  order: LaboratoryOrder;
  laboratory: UseLaboratoryOrders;
}>();

const emit = defineEmits<{
  verified: [];
}>();

const { t } = useI18n({ useScope: "global" });

const showCriticalModal = ref(false);

// Watch parameters to re-evaluate flags live as tech types
function handleValueChange(param: LabTestParameter) {
  param.flag = props.laboratory.evaluateParameterFlag(param);
}

// Check if any entered parameter is in panic critical state
const hasCriticalValue = computed(() => {
  return props.order.parameters.some((p) => p.flag === "critical_low" || p.flag === "critical_high");
});

const criticalParameters = computed(() => {
  return props.order.parameters.filter((p) => p.flag === "critical_low" || p.flag === "critical_high");
});

function handleFillNormal() {
  props.laboratory.fillNormalDefaults(props.order.id);
}

function handleVerifyOrder() {
  props.laboratory.verifyOrder(props.order.id);
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    
    <!-- Panic Critical Value Banner (Safety Alert P1) -->
    <div
      v-if="hasCriticalValue"
      class="rounded-lg border-2 border-rose-500 bg-rose-500/15 p-3 text-xs text-rose-950 dark:text-rose-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-md animate-pulse"
    >
      <div class="flex items-center gap-2.5">
        <div class="flex size-8 items-center justify-center rounded-full bg-rose-600 text-white shrink-0">
          <AlertTriangle class="size-4.5" />
        </div>
        <div>
          <h4 class="text-xs font-bold uppercase tracking-wider text-rose-700 dark:text-rose-300">
            {{ t('laboratory.critical_alert_title', 'Critical Panic Value Alert') }}
          </h4>
          <p class="text-[11px] text-rose-900 dark:text-rose-200 mt-0.5">
            <span v-for="(crit, idx) in criticalParameters" :key="crit.key">
              <strong>{{ crit.name }}: {{ crit.value }} {{ crit.unit }}</strong> (Ref: {{ crit.referenceRange }}){{ idx < criticalParameters.length - 1 ? ' · ' : '' }}
            </span>
          </p>
        </div>
      </div>

      <Button
        size="sm"
        class="h-7.5 text-xs font-bold gap-1.5 px-3 bg-rose-600 hover:bg-rose-700 text-white cursor-pointer shadow-xs shrink-0"
        @click="showCriticalModal = true"
      >
        <PhoneCall class="size-3.5" />
        <span>{{ t('laboratory.log_clinician_call', 'Log Clinician Call') }}</span>
      </Button>
    </div>

    <!-- Parameter Analytical Matrix Card -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
            <Activity class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>{{ order.testName }} — {{ t('laboratory.matrix_title', 'Analytical Result Matrix') }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">{{ t('laboratory.matrix_badge', 'Results') }}</Badge>
          </h3>
        </div>

        <div class="flex items-center gap-2">
          <!-- Quick Normal Fill Button -->
          <Button
            v-if="order.status !== 'completed'"
            variant="outline"
            size="sm"
            class="h-6.5 text-[11px] px-2 text-primary border-primary/40 hover:bg-primary/10 cursor-pointer gap-1"
            @click="handleFillNormal"
          >
            <Sparkles class="size-3" />
            <span>{{ t('laboratory.fill_normal', 'Fill Normal Defaults') }}</span>
          </Button>
        </div>
      </div>

      <!-- Table Matrix of Test Parameters -->
      <div class="rounded-lg border border-border bg-surface overflow-hidden">
        <table class="w-full text-left text-xs table-fixed">
          <thead class="border-b border-border/70 bg-muted/30 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">
            <tr>
              <th class="p-2.5 pl-3 w-[30%]">{{ t('laboratory.th_parameter', 'Investigation Parameter') }}</th>
              <th class="p-2.5 w-[22%]">{{ t('laboratory.th_value', 'Measured Value') }}</th>
              <th class="p-2.5 w-[12%]">{{ t('laboratory.th_units', 'Units') }}</th>
              <th class="p-2.5 w-[20%]">{{ t('laboratory.th_reference', 'Biological Reference') }}</th>
              <th class="p-2.5 w-[16%] text-right pr-3">{{ t('laboratory.th_flag', 'Status Flag') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr
              v-for="param in order.parameters"
              :key="param.key"
              class="hover:bg-muted/15 transition-colors"
              :class="{
                'bg-rose-500/10': param.flag === 'critical_low' || param.flag === 'critical_high',
                'bg-amber-500/5': param.flag === 'abnormal',
              }"
            >
              <!-- Parameter Name -->
              <td class="p-2.5 pl-3 font-semibold text-foreground text-[12px]">
                <div class="flex items-center gap-1.5">
                  <span>{{ param.name }}</span>
                  <span v-if="param.previousValue" class="text-[9.5px] text-muted-foreground font-mono font-normal">
                    ({{ t('reception.previous', 'Prev') }}: {{ param.previousValue }})
                  </span>
                </div>
              </td>

              <!-- Value Input -->
              <td class="p-2.5">
                <div class="relative">
                  <Input
                    v-model="param.value"
                    type="text"
                    placeholder="—"
                    class="h-7.5 text-xs font-mono font-bold"
                    :class="{
                      'border-rose-500 text-rose-600 bg-rose-500/10': param.flag === 'critical_low' || param.flag === 'critical_high',
                      'border-amber-500 text-amber-600 bg-amber-500/10': param.flag === 'abnormal',
                    }"
                    :disabled="order.status === 'completed'"
                    @input="handleValueChange(param)"
                  />
                </div>
              </td>

              <!-- Unit -->
              <td class="p-2.5 text-muted-foreground font-mono text-[11px]">
                {{ param.unit }}
              </td>

              <!-- Reference Range -->
              <td class="p-2.5 font-mono text-[11px] text-foreground">
                {{ param.referenceRange }}
              </td>

              <!-- Status Flag Badge -->
              <td class="p-2.5 pr-3 text-right">
                <Badge
                  variant="outline"
                  class="text-[9.5px] font-mono font-bold uppercase px-1.5 py-0.5"
                  :class="{
                    'border-emerald-500/40 text-emerald-600 bg-emerald-500/10': param.flag === 'normal',
                    'border-amber-500/40 text-amber-600 bg-amber-500/10': param.flag === 'abnormal',
                    'border-rose-500/50 text-rose-600 bg-rose-500/15 animate-pulse': param.flag === 'critical_low' || param.flag === 'critical_high',
                  }"
                >
                  <span v-if="param.flag === 'critical_high'">{{ t('laboratory.flag_crit_high', 'CRIT HIGH') }}</span>
                  <span v-else-if="param.flag === 'critical_low'">{{ t('laboratory.flag_crit_low', 'CRIT LOW') }}</span>
                  <span v-else-if="param.flag === 'abnormal'">{{ t('laboratory.flag_abnormal', 'ABNORMAL') }}</span>
                  <span v-else>{{ t('laboratory.flag_normal', 'NORMAL') }}</span>
                </Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Technician Notes & Interpretation -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 text-xs">
        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t('laboratory.technician_remarks', 'Technologist Analyzer Remarks / Equipment') }}
          </Label>
          <Textarea
            v-model="order.technicianNotes"
            rows="2"
            class="text-xs resize-none"
            :placeholder="t('laboratory.technician_remarks_placeholder', 'e.g. Analyzed on Sysmex XN-550 / Beckman AU480; calibrated today...')"
            :disabled="order.status === 'completed'"
          />
        </div>

        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t('laboratory.clinical_interpretation', 'Clinical Interpretation & Recommendation') }}
          </Label>
          <Textarea
            v-model="order.interpretation"
            rows="2"
            class="text-xs resize-none"
            :placeholder="t('laboratory.clinical_interpretation_placeholder', 'e.g. Findings consistent with microcytic hypochromic anemia...')"
            :disabled="order.status === 'completed'"
          />
        </div>
      </div>
    </section>

    <!-- Bottom Action Footer -->
    <div class="flex items-center justify-between gap-3 p-3 rounded-lg border border-border bg-surface shadow-2xs">
      <div class="flex items-center gap-2 text-xs text-muted-foreground font-mono">
        <Clock class="size-3.5 text-primary" />
        <span>{{ t('laboratory.status_label', 'Status:') }} <strong class="text-foreground uppercase">{{ order.status }}</strong></span>
      </div>

      <div class="flex items-center gap-2">
        <Button
          v-if="order.status !== 'completed' && order.status !== 'cancelled'"
          size="sm"
          class="h-8 text-xs font-semibold gap-1.5 px-4 cursor-pointer shadow-xs"
          :disabled="laboratory.isVerifying.value"
          @click="handleVerifyOrder"
        >
          <CheckCircle2 class="size-3.5" />
          <span>{{ laboratory.isVerifying.value ? t('laboratory.verifying', 'Verifying...') : t('laboratory.verify_and_release', 'Verify & Publish Results') }}</span>
        </Button>

        <span v-else-if="order.status === 'completed'" class="inline-flex items-center gap-1.5 text-emerald-600 font-bold text-xs font-mono">
          <CheckCircle2 class="size-4" />
          {{ t('laboratory.verified_and_published', 'Verified & Published to EMR') }}
        </span>
      </div>
    </div>

    <!-- Critical Alert Modal -->
    <CriticalAlertModal
      v-if="showCriticalModal"
      :order="order"
      @close="showCriticalModal = false"
      @logged="(clinician) => { laboratory.logCriticalNotification(order.id, clinician); showCriticalModal = false; }"
    />
  </div>
</template>
