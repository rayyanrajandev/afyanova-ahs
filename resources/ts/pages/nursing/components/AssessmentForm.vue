/**
 * AssessmentForm — Nursing Assessment & Triage Order Dispatch (Volume 2.3 §6)
 * =========================================================================
 * 2027 Modern Enterprise Clinical Workstation Edition:
 * - Structured Clinical Note & Downstream Service Items Entry
 * - Interactive Service Item Cart with quantity and service type pills
 * - Action bar with cancel and save triggers
 */

<script setup lang="ts">
import {
  Activity,
  Plus,
  Send,
  Trash2,
  X,
} from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { UseNursingAssessment } from "@/pages/nursing/composables/useNursingAssessment";

/* eslint-disable vue/no-mutating-props -- v-model on the passed-in composable's form refs */

defineProps<{
  assessment: UseNursingAssessment;
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
        <div class="flex size-7 items-center justify-center rounded-md bg-primary/10 text-primary">
          <Activity class="size-4" aria-hidden="true" />
        </div>
        <div>
          <h3 class="text-xs font-bold tracking-tight text-foreground flex items-center gap-1.5">
            <span>{{ t("nursing.new_assessment", "Nursing Assessment & Orders") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">Triage</Badge>
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
    <div class="flex-1 overflow-y-auto p-3.5 space-y-3 max-w-3xl">
      <!-- Section 1: Clinical Note -->
      <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-2 text-xs">
        <Label required class="text-xs font-semibold text-foreground">
          {{ t("nursing.clinical_note") }}
        </Label>
        <Textarea
          v-model="assessment.assessmentForm.value.clinicalNote"
          rows="3"
          class="text-xs resize-none"
          :placeholder="t('nursing.clinical_note_placeholder')"
        />
      </section>

      <!-- Section 2: Downstream Service Items -->
      <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3 text-xs">
        <div class="flex items-center justify-between border-b border-border/70 pb-1.5">
          <Label class="text-xs font-semibold text-foreground">
            {{ t("nursing.service_items") }} (Lab, Pharmacy, Radiology, Procedures)
          </Label>
          <Badge variant="secondary" class="text-[9px] font-mono px-1 py-0">
            {{ assessment.assessmentForm.value.items.length }} {{ assessment.assessmentForm.value.items.length === 1 ? 'Item' : 'Items' }}
          </Badge>
        </div>

        <!-- Selected Items Table -->
        <div v-if="assessment.assessmentForm.value.items.length > 0" class="rounded-md border border-border overflow-hidden">
          <ul class="divide-y divide-border/60">
            <li
              v-for="(item, index) in assessment.assessmentForm.value.items"
              :key="index"
              class="flex items-center justify-between p-2 text-xs hover:bg-muted/15"
            >
              <div class="flex items-center gap-2">
                <span class="font-semibold text-foreground">{{ item.itemName }}</span>
                <Badge variant="outline" class="text-[9px] uppercase font-mono px-1.5 py-0">
                  {{ t(`nursing.service_type_${item.serviceType}`) }}
                </Badge>
                <span class="text-muted-foreground font-mono text-[11px]">
                  Qty: {{ item.quantity }}
                </span>
              </div>
              <Button
                size="sm"
                variant="ghost"
                class="h-6 px-1.5 text-muted-foreground hover:text-critical cursor-pointer"
                @click="assessment.removeAssessmentItem(index)"
              >
                <Trash2 class="size-3" />
              </Button>
            </li>
          </ul>
        </div>
        <p v-else class="text-xs text-muted-foreground italic py-1">
          {{ t("nursing.no_service_items_needed", "No additional service items queued.") }}
        </p>

        <!-- Add Item Inputs -->
        <div class="flex flex-wrap items-end gap-2 pt-1 border-t border-border/50">
          <div class="flex-1 min-w-[140px] space-y-1">
            <Label class="text-[10.5px] text-muted-foreground">Item Name</Label>
            <Input
              v-model="assessment.newAssessmentItem.value.itemName"
              class="h-7.5 text-xs"
              :placeholder="t('nursing.item_name_placeholder')"
            />
          </div>

          <div class="w-36 space-y-1">
            <Label class="text-[10.5px] text-muted-foreground">Service Type</Label>
            <Select v-model="assessment.newAssessmentItem.value.serviceType">
              <SelectTrigger class="h-7.5 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="type in assessment.ASSESSMENT_SERVICE_TYPES"
                  :key="type"
                  :value="type"
                >
                  {{ t(`nursing.service_type_${type}`) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="w-16 space-y-1">
            <Label class="text-[10.5px] text-muted-foreground">Qty</Label>
            <Input
              v-model.number="assessment.newAssessmentItem.value.quantity"
              type="number"
              min="1"
              class="h-7.5 text-xs font-mono text-center"
            />
          </div>

          <Button
            size="sm"
            variant="secondary"
            class="h-7.5 text-xs font-semibold gap-1 cursor-pointer"
            @click="assessment.addAssessmentItem"
          >
            <Plus class="size-3 text-primary" />
            <span>{{ t("nursing.add_item", "Add") }}</span>
          </Button>
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
        :disabled="assessment.isSaving.value || !assessment.assessmentForm.value.clinicalNote.trim()"
        @click="assessment.saveAssessment"
      >
        <Send class="size-3" />
        <span>{{ assessment.isSaving.value ? t("common.saving", "Saving...") : t("common.save", "Complete Assessment") }}</span>
      </Button>
    </footer>
  </div>
</template>
