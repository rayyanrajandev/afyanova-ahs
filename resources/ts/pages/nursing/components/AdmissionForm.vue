/**
 * AdmissionForm — Nursing Inpatient Admission Escalation (Volume 2.3)
 * =========================================================================
 * 2027 Modern Enterprise Clinical Workstation Edition:
 * - Inpatient Escalation Card with Ward, Bed, and Clinical Reason Fields
 * - Action bar with cancel and submit triggers
 */

<script setup lang="ts">
import { BedDouble, Building2, Send, X } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { UseNursingAdmission } from "@/pages/nursing/composables/useNursingAdmission";

/* eslint-disable vue/no-mutating-props -- v-model on the passed-in composable's form refs */

defineProps<{
  admission: UseNursingAdmission;
}>();

const emit = defineEmits<{
  cancel: [];
}>();

const { t } = useI18n();
</script>

<template>
  <div class="flex flex-1 flex-col overflow-hidden bg-background">
    <!-- Header -->
    <header class="flex shrink-0 items-center justify-between border-b border-border bg-surface px-4 py-2">
      <div class="flex items-center gap-2">
        <div class="flex size-7 items-center justify-center rounded-md bg-amber-500/10 text-amber-600">
          <BedDouble class="size-4" aria-hidden="true" />
        </div>
        <div>
          <h3 class="text-xs font-bold tracking-tight text-foreground flex items-center gap-1.5">
            <span>{{ t("nursing.escalate_admission", "Inpatient Admission Escalation") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">Inpatient</Badge>
          </h3>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <Button
          variant="ghost"
          size="sm"
          class="h-6.5 text-[11px] px-2 text-muted-foreground hover:text-foreground cursor-pointer"
          @click="emit('cancel')"
        >
          <X class="size-3 mr-1" />
          {{ t("common.cancel") }}
        </Button>
      </div>
    </header>

    <!-- Canvas -->
    <div class="flex-1 overflow-y-auto p-3.5 space-y-3 max-w-2xl">
      <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3 text-xs">
        <div class="space-y-1">
          <Label required class="text-xs font-semibold text-foreground">
            {{ t("nursing.admission_reason") }}
          </Label>
          <Textarea
            v-model="admission.form.value.admissionReason"
            rows="2"
            class="text-xs resize-none"
            :placeholder="t('nursing.admission_reason_placeholder')"
          />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <Label required class="text-xs font-semibold text-foreground flex items-center gap-1">
              <Building2 class="size-3 text-muted-foreground" />
              <span>{{ t("nursing.ward") }}</span>
            </Label>
            <Input
              v-model="admission.form.value.ward"
              class="h-8 text-xs"
              :placeholder="t('nursing.ward_placeholder')"
            />
          </div>

          <div class="space-y-1">
            <Label class="text-xs font-semibold text-foreground flex items-center gap-1">
              <BedDouble class="size-3 text-muted-foreground" />
              <span>{{ t("nursing.bed") }}</span>
            </Label>
            <Input
              v-model="admission.form.value.bed"
              class="h-8 text-xs"
              :placeholder="t('nursing.bed_placeholder')"
            />
          </div>
        </div>

        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t("nursing.document_description") }} (Clinical Handover Notes)
          </Label>
          <Textarea
            v-model="admission.form.value.notes"
            rows="2"
            class="text-xs resize-none"
            :placeholder="t('nursing.document_description_placeholder')"
          />
        </div>
      </section>
    </div>

    <!-- Footer -->
    <footer class="flex shrink-0 items-center justify-end gap-2 border-t border-border bg-surface px-3.5 py-1.5">
      <Button
        variant="secondary"
        size="sm"
        class="h-7 text-xs cursor-pointer"
        @click="emit('cancel')"
      >
        {{ t("common.cancel") }}
      </Button>

      <Button
        size="sm"
        class="h-7 text-xs font-semibold gap-1 px-3.5 cursor-pointer shadow-xs"
        :disabled="admission.isSaving.value || !admission.form.value.admissionReason.trim() || !admission.form.value.ward.trim()"
        @click="admission.saveAdmission()"
      >
        <Send class="size-3" />
        <span>{{ admission.isSaving.value ? t("common.saving", "Escalating...") : t("nursing.confirm_admission", "Confirm Admission") }}</span>
      </Button>
    </footer>
  </div>
</template>
