/** * PharmacyReviewTab — Clinical Safety Review & Stock Status (Volume 2.6) *
======================================================================= *
Designed with standard hospital surface styling: * - Directions & SIG card with
dosage guidelines * - Live stock status from `dispenseInventory` * - Patient
allergy alerts and drug-drug cross-reactivity screen * - Clean action bar to
start preparation */

<script setup lang="ts">
import {
  AlertCircle,
  AlertTriangle,
  ArrowRight,
  Boxes,
  CheckCircle2,
  Clock,
  ExternalLink,
  Info,
  PackageCheck,
  PackageX,
  Pill,
  ShieldAlert,
  ShieldCheck,
  Stethoscope,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type {
  PharmacyOrder,
  UsePharmacyOrders,
} from "../composables/usePharmacyOrders";

const props = defineProps<{
  order: PharmacyOrder;
  pharmacy: UsePharmacyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const safety = computed(() => props.pharmacy.safetyReview.value);

const stockInfo = computed(() => safety.value?.dispenseInventory);

const isStockAvailable = computed(() => {
  if (!stockInfo.value) return false;
  return (stockInfo.value.currentStock ?? 0) >= props.order.quantityPrescribed;
});
</script>

<template>
  <div class="w-full min-w-0 p-4 space-y-4">
    <!-- 1. Clinical Prescription Instructions Card -->
    <div
      class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-2xs"
    >
      <div
        class="flex items-center justify-between border-b border-border/60 pb-3"
      >
        <div class="flex items-center gap-2">
          <div
            class="flex size-7 items-center justify-center rounded-lg bg-primary/10 text-primary"
          >
            <Pill class="size-4" />
          </div>
          <div>
            <h3 class="text-sm font-bold text-foreground">
              {{ order.medicationName }}
            </h3>
            <p class="text-xs text-muted-foreground font-mono">
              Code: {{ order.medicationCode }} · Prescribed:
              {{ new Date(order.orderedAt).toLocaleDateString() }}
            </p>
          </div>
        </div>

        <div class="text-right">
          <span class="text-[11px] text-muted-foreground block"
            >Quantity Ordered</span
          >
          <span class="text-sm font-bold text-foreground font-mono">
            {{ order.quantityPrescribed }} {{ order.prescribedUnit || "Units" }}
          </span>
        </div>
      </div>

      <!-- Directions (SIG) Callout -->
      <div
        class="rounded-md bg-muted/40 p-3 border border-border/70 space-y-1.5"
      >
        <div
          class="text-[11px] font-bold text-muted-foreground flex items-center gap-1.5 uppercase"
        >
          <Info class="size-3.5 text-primary" />
          <span>Patient Dosage & Administration Directions:</span>
        </div>
        <div class="text-xs font-semibold text-foreground leading-relaxed">
          {{
            order.dosageInstruction ||
            `${order.doseQuantity || 1} ${order.doseUnit || "unit"} • ${order.frequency || "as directed"} for ${order.durationValue || 5} ${order.durationUnit || "days"}`
          }}
        </div>
        <div
          v-if="order.clinicalIndication"
          class="text-xs text-muted-foreground pt-1 flex items-center gap-1"
        >
          <Stethoscope class="size-3.5 text-primary" />
          <span
            >Clinical Indication:
            <strong>{{ order.clinicalIndication }}</strong></span
          >
        </div>
      </div>

      <!-- Pricing Summary Strip -->
      <div
        class="flex items-center justify-between p-2.5 rounded-md bg-surface border text-xs"
      >
        <span class="text-muted-foreground"
          >Unit Price:
          <strong class="text-foreground">{{
            order.unitPrice
              ? order.unitPrice.toLocaleString() + " TZS"
              : "Standard"
          }}</strong></span
        >
        <span class="text-muted-foreground"
          >Estimated Charge:
          <strong class="text-foreground font-bold">{{
            order.totalPrice ? order.totalPrice.toLocaleString() + " TZS" : "—"
          }}</strong></span
        >
      </div>
    </div>

    <!-- 2. Dispensary Inventory Availability Card -->
    <div
      class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-2xs"
    >
      <h3
        class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2"
      >
        <Boxes class="size-4 text-blue-600 dark:text-blue-400" />
        <span>Inventory Stock Verification</span>
      </h3>

      <div
        v-if="stockInfo"
        class="p-3.5 rounded-lg border flex items-center justify-between gap-3"
        :class="
          isStockAvailable
            ? 'bg-emerald-500/10 border-emerald-500/30'
            : 'bg-destructive/10 border-destructive/30'
        "
      >
        <div class="flex items-center gap-3">
          <PackageCheck
            v-if="isStockAvailable"
            class="size-6 text-emerald-600 dark:text-emerald-400 shrink-0"
          />
          <PackageX v-else class="size-6 text-destructive shrink-0" />
          <div>
            <div
              class="text-xs font-bold"
              :class="
                isStockAvailable
                  ? 'text-emerald-700 dark:text-emerald-300'
                  : 'text-destructive'
              "
            >
              {{
                isStockAvailable
                  ? "In Stock — Available in Dispensary Store"
                  : "Stock Depleted / Insufficient Inventory"
              }}
            </div>
            <div class="text-[11px] text-muted-foreground mt-0.5">
              Available on hand:
              <strong>{{ stockInfo.currentStock ?? 0 }}</strong>
              {{ stockInfo.dispensingUnit || stockInfo.unit || "units" }} across
              active store lots
            </div>
          </div>
        </div>

        <Badge
          :variant="isStockAvailable ? 'default' : 'destructive'"
          class="text-xs font-mono"
        >
          {{ stockInfo.currentStock ?? 0 }} In Stock
        </Badge>
      </div>

      <div
        v-else
        class="p-3 rounded-lg border bg-muted/40 text-xs text-muted-foreground"
      >
        Checking live dispensary inventory balances...
      </div>
    </div>

    <!-- 3. Medication Safety Screen (Allergies & Interactions) -->
    <div
      class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-2xs"
    >
      <h3
        class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2"
      >
        <ShieldAlert class="size-4 text-amber-600 dark:text-amber-400" />
        <span>Patient Safety & Cross-Reactivity Screen</span>
      </h3>

      <!-- Known Allergies -->
      <div
        v-if="safety?.allergyConflicts && safety.allergyConflicts.length > 0"
        class="space-y-2"
      >
        <div
          class="text-xs font-bold text-destructive flex items-center gap-1.5"
        >
          <AlertCircle class="size-3.5" />
          <span>Patient Allergy Alert:</span>
        </div>
        <div
          v-for="allergy in safety.allergyConflicts"
          :key="allergy.substanceName || allergy.substanceCode"
          class="p-2.5 rounded-md bg-destructive/10 border border-destructive/20 text-xs space-y-0.5"
        >
          <div class="font-bold text-destructive">
            {{ allergy.substanceName || allergy.substanceCode }} (Severity:
            {{ allergy.severity }})
          </div>
          <div class="text-muted-foreground">
            {{ allergy.reaction || allergy.reactionCode || "No documented reaction details" }}
          </div>
        </div>
      </div>

      <!-- Drug-Drug Interactions -->
      <div
        v-if="
          safety?.interactionConflicts && safety.interactionConflicts.length > 0
        "
        class="space-y-2"
      >
        <div
          class="text-xs font-bold text-amber-600 dark:text-amber-400 flex items-center gap-1.5"
        >
          <AlertTriangle class="size-3.5" />
          <span>Drug-Drug Interaction Warning:</span>
        </div>
        <div
          v-for="interaction in safety.interactionConflicts"
          :key="interaction.name || interaction.code"
          class="p-2.5 rounded-md bg-amber-500/10 border border-amber-500/20 text-xs space-y-0.5"
        >
          <div class="font-bold text-amber-700 dark:text-amber-300">
            {{ interaction.name || interaction.code }}
          </div>
          <div class="text-muted-foreground">
            {{ interaction.description || interaction.clinicalEffect }}
          </div>
        </div>
      </div>

      <!-- Clean State -->
      <div
        v-if="
          (!safety?.allergyConflicts || safety.allergyConflicts.length === 0) &&
          (!safety?.interactionConflicts ||
            safety.interactionConflicts.length === 0)
        "
        class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-700 dark:text-emerald-300 flex items-center gap-2"
      >
        <ShieldCheck
          class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
        />
        <span
          >No drug-allergy or critical interaction conflicts identified for this
          patient.</span
        >
      </div>
    </div>

    <!-- Action Bar -->
    <div class="flex items-center justify-end gap-3 pt-2">
      <Button
        v-if="order.status === 'pending'"
        class="gap-1.5 bg-primary text-primary-foreground hover:bg-primary/90 font-semibold cursor-pointer shadow-xs"
        :disabled="pharmacy.isActionLoading.value"
        @click="() => pharmacy.updateOrderStatus('in_preparation')"
      >
        <span>Accept & Start Preparation</span>
        <ArrowRight class="size-4" />
      </Button>

      <Button
        v-else-if="
          order.status === 'in_preparation' ||
          order.status === 'partially_dispensed'
        "
        variant="outline"
        class="gap-1.5 text-primary border-primary hover:bg-primary/10 font-semibold cursor-pointer"
        @click="pharmacy.activeTab.value = 'dispense'"
      >
        <span>Proceed to Fill & Dispense</span>
        <ArrowRight class="size-4" />
      </Button>
    </div>
  </div>
</template>
