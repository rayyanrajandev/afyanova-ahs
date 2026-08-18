/** * PharmacyDispenseTab — Preparation, Batch Fulfillment & Labeling (Volume
2.6) *
============================================================================== *
Standard hospital surface layout: * - Quantity fulfiller with batch lot selector
(FEFO) * - Dosage directions preview & counseling notes textarea * - Print
preview & verification handoff button */

<script setup lang="ts">
import {
  AlertTriangle,
  ArrowRight,
  Boxes,
  Check,
  CheckCircle2,
  FileText,
  Info,
  Pill,
  Printer,
  Sparkles,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
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
import {
  pharmacyStageOf,
  type PharmacyOrder,
  type UsePharmacyOrders,
} from "../composables/usePharmacyOrders";
import { printPharmacyLabel } from "../pharmacyLabelPrint";

const props = defineProps<{
  order: PharmacyOrder;
  pharmacy: UsePharmacyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const quantityToDispense = ref<number>(props.order.quantityPrescribed || 1);
const selectedBatchId = ref<string>("");
const dispensingNotes = ref<string>("");

const batches = computed(() => {
  return (
    props.pharmacy.safetyReview.value?.dispenseInventory?.availableBatches || []
  );
});

watch(
  () => props.order,
  (newOrder) => {
    if (newOrder) {
      quantityToDispense.value = newOrder.quantityPrescribed || 1;
      dispensingNotes.value = newOrder.dispensingNotes || "";
    }
  },
  { immediate: true },
);

/**
 * The tab gate should mean this is never false, but the button carries its own
 * precondition anyway: it is the control that issues stock and prints a label,
 * and it should not depend on a parent having reasoned correctly.
 */
const canDispense = computed(
  () => pharmacyStageOf(props.order) === "ready_for_dispense",
);

async function handleDispense(): Promise<void> {
  const isPartial = quantityToDispense.value < props.order.quantityPrescribed;
  const targetStatus = isPartial ? "partially_dispensed" : "dispensed";

  const success = await props.pharmacy.updateOrderStatus(targetStatus, {
    quantityDispensed: quantityToDispense.value,
    dispensedUnit: props.order.prescribedUnit || "units",
    dispensingNotes: dispensingNotes.value,
    batchId: selectedBatchId.value || undefined,
  });

  if (success) {
    printPharmacyLabel({
      ...props.order,
      quantityDispensed: quantityToDispense.value,
    });
    props.pharmacy.activeTab.value = "verify";
  }
}
</script>

<template>
  <div class="w-full min-w-0 p-4 space-y-4">
    <!-- Dispense Station Card -->
    <div
      class="rounded-lg border border-border bg-surface p-4 space-y-4 shadow-2xs"
    >
      <div
        class="flex items-center justify-between border-b border-border/60 pb-3"
      >
        <div>
          <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
            <Pill class="size-4 text-primary" />
            <span>Medication Fulfillment: {{ order.medicationName }}</span>
          </h3>
          <p class="text-xs text-muted-foreground mt-0.5">
            Select inventory lot, confirm physical count, and provide patient
            counseling remarks.
          </p>
        </div>

        <Badge variant="outline" class="text-xs font-mono">
          Prescribed: {{ order.quantityPrescribed }}
          {{ order.prescribedUnit || "units" }}
        </Badge>
      </div>

      <!-- Form Inputs: Quantity & Batch Selection -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <!-- Quantity Input -->
        <div class="space-y-1.5">
          <Label class="text-xs font-bold text-foreground"
            >Quantity to Dispense *</Label
          >
          <Input
            v-model.number="quantityToDispense"
            type="number"
            min="1"
            :max="order.quantityPrescribed * 2"
            class="h-8.5 text-xs font-medium"
            placeholder="e.g. 10"
          />
          <p class="text-[10.5px] text-muted-foreground font-mono">
            {{
              quantityToDispense < order.quantityPrescribed
                ? "⚠️ Partial fulfillment will be recorded"
                : "✓ Full prescription fulfillment"
            }}
          </p>
        </div>

        <!-- Batch Lot Selector -->
        <div class="space-y-1.5">
          <Label class="text-xs font-bold text-foreground"
            >Inventory Batch / Lot (FEFO Expiry)</Label
          >
          <Select v-if="batches.length > 0" v-model="selectedBatchId">
            <SelectTrigger class="h-8.5 text-xs font-medium">
              <SelectValue placeholder="Select active batch..." />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="batch in batches"
                :key="batch.id || batch.batchNumber"
                :value="batch.id || ''"
                class="text-xs font-mono"
              >
                Lot: {{ batch.batchNumber }} · Exp: {{ batch.expiryDate }} ({{
                  batch.available
                }}
                available)
              </SelectItem>
            </SelectContent>
          </Select>
          <div
            v-else
            class="h-8.5 px-3 py-1.5 border border-border/80 rounded-md bg-muted/30 text-xs text-muted-foreground flex items-center font-mono"
          >
            Main dispensary stock (unbatched)
          </div>
        </div>
      </div>

      <!-- Directions Confirmation Box -->
      <div
        class="rounded-md bg-primary/5 border border-primary/20 p-3 text-xs space-y-1"
      >
        <div class="font-bold text-primary">
          Directions to be Printed on Medication Label:
        </div>
        <div class="text-foreground font-medium">
          {{
            order.dosageInstruction ||
            `${order.doseQuantity || 1} ${order.doseUnit || "unit"} • ${order.frequency || "daily"} for ${order.durationValue || 5} ${order.durationUnit || "days"}`
          }}
        </div>
      </div>

      <!-- Counseling & Dispensing Notes -->
      <div class="space-y-1.5">
        <Label class="text-xs font-bold text-foreground"
          >Pharmacist Dispensing Remarks / Counseling Notes</Label
        >
        <Textarea
          v-model="dispensingNotes"
          rows="2"
          class="text-xs"
          placeholder="e.g. Patient counseled to take with food. Advised to complete full antibiotic course."
        />
      </div>
    </div>

    <!-- Action Bar -->
    <div class="flex items-center justify-between pt-1">
      <Button
        variant="outline"
        size="sm"
        class="h-8 gap-1.5 text-xs cursor-pointer"
        @click="() => printPharmacyLabel(order)"
      >
        <Printer class="size-3.5 text-muted-foreground" />
        <span>Print Label Preview</span>
      </Button>

      <Button
        class="gap-1.5 bg-primary text-primary-foreground hover:bg-primary/90 font-semibold cursor-pointer shadow-xs"
        :disabled="
          !canDispense ||
          pharmacy.isActionLoading.value ||
          quantityToDispense <= 0
        "
        :title="
          canDispense
            ? ''
            : t(
                'pharmacy.locked_dispense',
                'Locked until the prescription has been accepted into preparation',
              )
        "
        @click="handleDispense"
      >
        <Check class="size-4" />
        <span>Complete Dispense & Send for Verification</span>
      </Button>
    </div>
  </div>
</template>
