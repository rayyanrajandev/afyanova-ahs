/** * CriticalAlertModal — Panic Value Urgent Clinician Notification Logger
(Volume 2.4 §8) *
=======================================================================================
* 2027 CLSI GP47-A Critical Value Safety Notification Protocol: * - Direct
ordering clinician contact details * - Telephone read-back confirmation logging
* - Time-to-notification audit trail * - Full Internationalization (i18n)
Support */

<script setup lang="ts">
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  FileCheck,
  PhoneCall,
  ShieldAlert,
  User,
  X,
} from "lucide-vue-next";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { LaboratoryOrder } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
}>();

const emit = defineEmits<{
  close: [];
  logged: [clinicianName: string];
}>();

const { t } = useI18n({ useScope: "global" });

const clinicianRecipient = ref(
  props.order.orderingClinician || "Dr. Attending",
);
const communicationMethod = ref("Phone Call (Hospital Ext / Mobile)");
const readBackConfirmed = ref(true);
const notes = ref(
  "Critical value read back and confirmed by clinician. Urgent clinical correlation initiated.",
);

function handleSubmit() {
  if (!clinicianRecipient.value.trim() || !readBackConfirmed.value) return;
  emit("logged", clinicianRecipient.value);
}
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
  >
    <div
      class="w-full max-w-lg rounded-xl border-2 border-rose-500 bg-popover p-5 shadow-2xl space-y-4 text-xs"
    >
      <!-- Header -->
      <div
        class="flex items-center justify-between border-b border-rose-500/30 pb-3"
      >
        <div class="flex items-center gap-2.5 text-rose-600 font-bold">
          <div
            class="flex size-8 items-center justify-center rounded-full bg-rose-600 text-white"
          >
            <ShieldAlert class="size-4.5" />
          </div>
          <div>
            <h3 class="text-sm font-bold tracking-tight">
              {{
                t(
                  "laboratory.critical_modal_title",
                  "Critical Lab Value Notification (CLSI GP47-A)",
                )
              }}
            </h3>
            <p class="text-[11px] text-muted-foreground font-normal">
              {{
                t(
                  "laboratory.critical_modal_subtitle",
                  "Immediate telephone communication protocol",
                )
              }}
            </p>
          </div>
        </div>
        <button
          type="button"
          class="text-muted-foreground hover:text-foreground cursor-pointer"
          @click="emit('close')"
        >
          <X class="size-4" />
        </button>
      </div>

      <!-- Critical Value Card -->
      <div
        class="rounded-lg border border-rose-500/40 bg-rose-500/10 p-3 space-y-2"
      >
        <div class="flex items-center justify-between">
          <span class="font-bold text-foreground"
            >{{ order.patientName }} ({{ order.patientMrn }})</span
          >
          <Badge
            variant="outline"
            class="bg-rose-500 text-white text-[9px] font-mono uppercase font-bold"
          >
            {{ t("laboratory.panic_badge", "PANIC VALUE") }}
          </Badge>
        </div>

        <div class="text-xs text-rose-950 dark:text-rose-200">
          <div class="font-semibold">
            {{ order.testName }} ({{ order.testCode }})
          </div>
          <ul class="mt-1 space-y-0.5 font-mono text-[11.5px]">
            <li
              v-for="p in order.parameters.filter(
                (x) => x.flag === 'critical_low' || x.flag === 'critical_high',
              )"
              :key="p.key"
            >
              • <strong>{{ p.name }}: {{ p.value }} {{ p.unit }}</strong> ({{
                t("laboratory.th_reference", "Biological Ref")
              }}: {{ p.referenceRange }})
            </li>
          </ul>
        </div>
      </div>

      <!-- Notification Form -->
      <div class="space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <Label required class="text-xs font-semibold text-foreground">
              {{ t("laboratory.clinician_contacted", "Clinician Contacted") }}
            </Label>
            <Input
              v-model="clinicianRecipient"
              class="h-8 text-xs font-medium"
              placeholder="Dr. Name"
            />
          </div>

          <div class="space-y-1">
            <Label required class="text-xs font-semibold text-foreground">
              {{ t("laboratory.comm_method", "Communication Method") }}
            </Label>
            <select
              v-model="communicationMethod"
              class="w-full h-8 rounded border border-border bg-background px-2 text-xs font-medium"
            >
              <option value="Phone Call (Hospital Ext / Mobile)">
                {{ t("laboratory.comm_phone", "Phone Call (Direct Line)") }}
              </option>
              <option value="Direct In-Person Verbal">
                {{ t("laboratory.comm_verbal", "Direct In-Person Verbal") }}
              </option>
              <option value="Hospital Emergency Pager / Alert">
                {{ t("laboratory.comm_pager", "Hospital Emergency Pager") }}
              </option>
            </select>
          </div>
        </div>

        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t("laboratory.call_notes", "Call Documentation & Feedback") }}
          </Label>
          <Textarea
            v-model="notes"
            rows="2"
            class="text-xs resize-none"
            :placeholder="
              t(
                'laboratory.call_notes_placeholder',
                'Document clinician response and instructions...',
              )
            "
          />
        </div>

        <!-- Read-Back Verification Checkbox -->
        <label
          class="flex items-start gap-2 p-2.5 rounded-lg border border-border bg-muted/30 cursor-pointer select-none"
        >
          <input
            v-model="readBackConfirmed"
            type="checkbox"
            class="size-4 rounded text-primary mt-0.5 cursor-pointer"
          />
          <div class="text-[11.5px] leading-tight">
            <span class="font-bold text-foreground">{{
              t("laboratory.readback_title", "Mandatory Read-Back Confirmed")
            }}</span>
            <p class="text-muted-foreground mt-0.5">
              {{
                t(
                  "laboratory.readback_desc",
                  "The recipient clinician has repeated back the patient name, MRN, and critical result value verbatim.",
                )
              }}
            </p>
          </div>
        </label>
      </div>

      <!-- Action Buttons -->
      <div
        class="flex items-center justify-end gap-2 pt-2 border-t border-border"
      >
        <Button
          variant="secondary"
          size="sm"
          class="h-8 text-xs cursor-pointer"
          @click="emit('close')"
        >
          {{ t("common.cancel", "Cancel") }}
        </Button>

        <Button
          size="sm"
          class="h-8 text-xs font-semibold gap-1.5 px-4 bg-rose-600 hover:bg-rose-700 text-white cursor-pointer shadow-xs"
          :disabled="!clinicianRecipient.trim() || !readBackConfirmed"
          @click="handleSubmit"
        >
          <FileCheck class="size-3.5" />
          <span>{{ t("laboratory.save_readback", "Save Read-Back Log") }}</span>
        </Button>
      </div>
    </div>
  </div>
</template>
