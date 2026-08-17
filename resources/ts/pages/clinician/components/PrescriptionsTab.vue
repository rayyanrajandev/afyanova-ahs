/**
 * PrescriptionsTab — Medication Order Entry & Pharmacy E-Prescribing (Volume 2.2 §8)
 * =================================================================================
 * 2027 Modern Enterprise Clinical Workstation Edition:
 * - High-Density Drug Formulary Search with real-time price & route badges
 * - Quick-pick formulary pills for high-frequency clinical prescribing
 * - Precision Prescription Grid (Dosage, Route, Frequency, Duration, Quantity, Line-Item Total)
 * - Real-time Estimated Order Total summary banner & batch submission
 * - Placed Prescriptions Lifecycle Tracker (Pending, Dispensed, Cancelled)
 */

<script setup lang="ts">
import {
  AlertTriangle,
  Check,
  CheckCircle2,
  Clock,
  DollarSign,
  Eye,
  Loader2,
  Pill,
  Plus,
  Search,
  Send,
  Sparkles,
  Trash2,
  XCircle,
} from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  type MedicationCatalogItem,
  type PlacedClinicalOrder,
  STANDARD_DRUG_CATALOG,
  useClinicianOrders,
} from "../composables/useClinicianOrders";

const props = withDefaults(
  defineProps<{
    encounterId: string | null;
    patientId: string | null;
    orders: ReturnType<typeof useClinicianOrders>;
    clinicalMode?: "active" | "awaiting_start" | "triage_pending" | "read_only" | "completed";
  }>(),
  {
    clinicalMode: "active",
  }
);

const { t } = useI18n({ useScope: "global" });

const drugSearchQuery = ref("");
const showDrugDropdown = ref(false);
const searchContainerRef = ref<HTMLElement | null>(null);
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

function handleClickOutside(event: MouseEvent) {
  if (searchContainerRef.value && !searchContainerRef.value.contains(event.target as Node)) {
    showDrugDropdown.value = false;
  }
}

onMounted(() => {
  document.addEventListener("pointerdown", handleClickOutside);
  if (props.orders.medicationCatalog.value.length === 0) {
    props.orders.searchMedicationCatalog();
  }
});

onUnmounted(() => {
  document.removeEventListener("pointerdown", handleClickOutside);
  if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
});

watch(drugSearchQuery, (newVal) => {
  if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(() => {
    props.orders.searchMedicationCatalog(newVal);
  }, 250);
});

const displayDrugs = computed(() => {
  const q = drugSearchQuery.value.trim().toLowerCase();
  const source = props.orders.medicationCatalog.value.length > 0
    ? props.orders.medicationCatalog.value
    : STANDARD_DRUG_CATALOG;
  if (!q) return source;
  return source.filter(
    (item) =>
      item.name.toLowerCase().includes(q) ||
      (item.genericName && item.genericName.toLowerCase().includes(q)) ||
      (item.strength && item.strength.toLowerCase().includes(q)) ||
      (item.code && item.code.toLowerCase().includes(q))
  );
});

const commonSuggestedDrugs = computed(() => {
  const commonCodes = ["MED-PARA-500TAB", "MED-ALBEN-200TAB", "MED-ACECL-100TAB", "MED-ACICV-200TAB", "MED-ADREN-1ML"];
  if (props.orders.medicationCatalog.value.length > 0) {
    const list = props.orders.medicationCatalog.value.filter((d) => commonCodes.includes(d.code));
    if (list.length > 0) return list;
  }
  return STANDARD_DRUG_CATALOG.slice(0, 5);
});

const placedMedications = computed(() =>
  props.orders.activeOrders.value.filter((o) => o.type === "medication")
);

const totalDraftCost = computed(() =>
  props.orders.prescriptionDrafts.value.reduce((acc, cur) => {
    const qty = Number(cur.quantityPrescribed);
    if (qty && qty > 0) {
      return acc + (cur.unitPrice || 0) * qty;
    }
    return acc;
  }, 0)
);

// Order Cancellation State
const orderToCancel = ref<PlacedClinicalOrder | null>(null);
const cancelReason = ref("Ordered in error");
const isCancelling = ref(false);

function openCancelDialog(order: PlacedClinicalOrder) {
  orderToCancel.value = order;
  cancelReason.value = "Ordered in error";
}

async function confirmCancelOrder() {
  if (!orderToCancel.value) return;
  isCancelling.value = true;
  try {
    await props.orders.cancelOrder(orderToCancel.value, cancelReason.value);
    orderToCancel.value = null;
  } finally {
    isCancelling.value = false;
  }
}

function selectDrug(drug: MedicationCatalogItem) {
  props.orders.addPrescriptionItem(drug);
  drugSearchQuery.value = "";
  showDrugDropdown.value = false;
}

async function handleSubmitPrescriptions() {
  if (!props.encounterId || !props.patientId) return;
  await props.orders.submitPrescriptions(props.encounterId, props.patientId);
}
</script>

<template>
  <div class="space-y-3 p-3.5">
    <!-- Clinical Gating / Operating Mode Banner -->
    <!--
      Queued for a doctor, nobody has started. Read-only until the consultation
      is actually opened — documenting or ordering here would create a record of
      a consultation that never happened.
    -->
    <div
      v-if="props.clinicalMode === 'awaiting_start'"
      class="rounded-md border border-primary/30 bg-primary/10 px-3 py-2 text-xs text-foreground flex items-center gap-2"
    >
      <div class="flex items-center gap-2">
        <Clock class="size-4 shrink-0 text-primary" />
        <span class="text-xs leading-tight">
          <strong class="font-semibold">{{ t("clinician.awaiting_start_title") }}:</strong>
          <span class="ml-1 text-muted-foreground">{{ t("clinician.awaiting_start_desc") }}</span>
        </span>
      </div>
    </div>

    <div
      v-else-if="props.clinicalMode === 'triage_pending'"
      class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200 flex items-center gap-2"
    >
      <div class="flex items-center gap-2">
        <Clock class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
        <span class="text-xs leading-tight">
          <strong class="font-semibold">{{ t("clinician.triage_pending_title") }}:</strong>
          <span class="text-amber-800/90 dark:text-amber-300/90 ml-1">{{ t("clinician.prescriptions_gated_triage") }}</span>
        </span>
      </div>
    </div>

    <div
      v-else-if="props.clinicalMode === 'read_only'"
      class="rounded-md border border-border/80 bg-muted/40 px-3 py-2 text-xs text-muted-foreground flex items-center gap-2"
    >
      <Eye class="size-4 shrink-0 text-primary" />
      <span class="text-xs leading-tight">
        <strong class="font-semibold text-foreground">{{ t("clinician.read_only_review_title") }}:</strong>
        <span class="ml-1">{{ t("clinician.prescriptions_gated_not_checked_in") }}</span>
      </span>
    </div>

    <div
      v-else-if="props.clinicalMode === 'completed'"
      class="rounded-md border border-border bg-secondary/40 px-3 py-2 text-xs text-muted-foreground flex items-center gap-2"
    >
      <CheckCircle2 class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
      <span class="text-xs leading-tight">
        <strong class="font-semibold text-foreground">{{ t("clinician.encounter_completed_title") }}:</strong>
        <span class="ml-1">{{ t("clinician.encounter_completed_desc") }}</span>
      </span>
    </div>

    <!-- ============================================================
         CARD 1: FORMULARY SEARCH & PRESCRIPTION DRAFTING
         ============================================================ -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
            <Pill class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>{{ t("clinician.prescriptions") }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">E-Prescribe</Badge>
          </h3>
        </div>

        <div class="flex items-center gap-2 text-xs">
          <span class="text-xs text-muted-foreground font-mono">
            {{ placedMedications.length }} {{ t("clinician.active_prescriptions", "Prescribed") }}
          </span>
        </div>
      </div>

      <div class="space-y-3 text-xs">
        <!-- Drug Search Bar -->
        <div class="space-y-1.5">
          <Label class="text-xs font-semibold text-foreground">
            {{ t("clinician.drug_name") }} (Hospital Formulary Search)
          </Label>

          <div ref="searchContainerRef" class="relative">
            <Search v-if="!props.orders.isSearchingMedications.value" class="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
            <Loader2 v-else class="absolute left-2.5 top-2.5 size-3.5 text-primary animate-spin" />
            <Input
              v-model="drugSearchQuery"
              type="search"
              class="pl-8 h-8 text-xs font-medium disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="props.clinicalMode !== 'active'"
              :placeholder="t('clinician.search_drug')"
              @focus="showDrugDropdown = true"
            />

            <!-- Autocomplete Dropdown -->
            <div
              v-if="showDrugDropdown && displayDrugs.length > 0 && props.clinicalMode === 'active'"
              class="absolute z-50 mt-1 w-full rounded-md border border-border bg-popover p-1 shadow-lg max-h-56 overflow-y-auto"
            >
              <div
                v-for="drug in displayDrugs"
                :key="drug.id"
                class="flex items-center justify-between gap-2 rounded px-2.5 py-1.5 text-xs hover:bg-accent cursor-pointer transition-colors"
                @mousedown.prevent="selectDrug(drug)"
              >
                <div class="min-w-0">
                  <span class="font-bold text-foreground text-[12px]">{{ drug.name }} <span v-if="drug.strength && !drug.name.includes(drug.strength)">({{ drug.strength }})</span></span>
                  <span v-if="drug.genericName || drug.form" class="text-[10.5px] text-muted-foreground ml-2 font-mono">
                    {{ [drug.genericName, drug.form].filter(Boolean).join(" · ") }}
                  </span>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                  <span v-if="drug.price" class="text-[10.5px] font-mono font-semibold text-emerald-700 dark:text-emerald-400">
                    TZS {{ drug.price.toLocaleString() }}
                  </span>
                  <Badge v-if="drug.defaultRoute" variant="secondary" class="text-[9.5px] uppercase font-mono px-1 py-0">
                    {{ drug.defaultRoute }}
                  </Badge>
                </div>
              </div>
            </div>
          </div>

          <!-- Suggested Quick-Picks -->
          <div class="flex items-center gap-1.5 flex-wrap pt-0.5">
            <span class="text-[10.5px] text-muted-foreground font-semibold uppercase tracking-wider mr-1">
              {{ t("common.suggested", "Common:") }}
            </span>
            <button
              v-for="drug in commonSuggestedDrugs"
              :key="drug.id"
              type="button"
              class="inline-flex items-center gap-1 rounded-full border border-border bg-secondary/50 px-2 py-0.5 text-[11px] text-foreground transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              :class="props.clinicalMode === 'active' ? 'hover:border-primary/40 hover:bg-secondary cursor-pointer' : ''"
              :disabled="props.clinicalMode !== 'active'"
              @click="props.orders.addPrescriptionItem(drug)"
            >
              <Plus class="size-2.5 text-primary" />
              <span>{{ drug.name }} ({{ drug.strength }})</span>
              <span v-if="drug.price" class="text-[9.5px] font-mono font-bold text-emerald-600 dark:text-emerald-400 ml-0.5">
                {{ drug.price.toLocaleString() }}
              </span>
            </button>
          </div>
        </div>

        <!-- Prescriptions Drafting Table -->
        <div class="rounded-lg border border-border bg-surface overflow-hidden">
          <div v-if="orders.prescriptionDrafts.value.length === 0" class="p-3 text-center text-xs text-muted-foreground italic">
            {{ t("clinician.no_prescriptions_added") }}
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-xs table-fixed">
              <thead class="border-b border-border/70 bg-muted/30 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">
                <tr>
                  <th class="p-2 pl-3 w-[24%]">{{ t("clinician.drug_name") }}</th>
                  <th class="p-2 w-[10%]">{{ t("clinician.dosage") }}</th>
                  <th class="p-2 w-[10%]">{{ t("clinician.route") }}</th>
                  <th class="p-2 w-[10%]">{{ t("clinician.frequency") }}</th>
                  <th class="p-2 w-[8%] text-center">{{ t("clinician.duration") }}</th>
                  <th class="p-2 w-[8%] text-center">{{ t("clinician.quantity", "Qty") }}</th>
                  <th class="p-2 w-[11%] text-right">{{ t("clinician.price", "Price") }}</th>
                  <th class="p-2 w-[15%]">{{ t("clinician.instructions") }}</th>
                  <th class="p-2 w-[4%] text-right pr-3"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="(item, idx) in orders.prescriptionDrafts.value" :key="idx" class="hover:bg-muted/15">
                  <td class="p-2 pl-3 font-semibold text-foreground text-[11.5px] truncate" :title="item.drugName">
                    {{ item.drugName }}
                  </td>
                  <td class="p-2">
                    <Input
                      v-model="item.dosage"
                      type="text"
                      class="h-7 text-xs px-1.5 w-full font-medium disabled:opacity-60 disabled:cursor-not-allowed"
                      :disabled="props.clinicalMode !== 'active'"
                    />
                  </td>
                  <td class="p-2">
                    <select
                      v-model="item.route"
                      class="h-7 w-full rounded border border-border bg-background px-1 text-xs disabled:opacity-60 disabled:cursor-not-allowed"
                      :disabled="props.clinicalMode !== 'active'"
                    >
                      <option value="Oral">Oral</option>
                      <option value="IV">IV</option>
                      <option value="IM">IM</option>
                      <option value="Topical">Topical</option>
                      <option value="Inhaled">Inhaled</option>
                      <option value="Sublingual">Sublingual</option>
                    </select>
                  </td>
                  <td class="p-2">
                    <select
                      v-model="item.frequency"
                      class="h-7 w-full rounded border border-border bg-background px-1 text-xs font-mono font-medium disabled:opacity-60 disabled:cursor-not-allowed"
                      :disabled="props.clinicalMode !== 'active'"
                    >
                      <option value="OD">OD</option>
                      <option value="BID">BID</option>
                      <option value="TID">TID</option>
                      <option value="QID">QID</option>
                      <option value="PRN">PRN</option>
                      <option value="STAT">STAT</option>
                    </select>
                  </td>
                  <td class="p-2">
                    <div class="flex items-center gap-0.5 w-full">
                      <Input
                        v-model.number="item.durationDays"
                        type="number"
                        min="1"
                        max="90"
                        placeholder="5"
                        class="h-7 text-xs px-1 w-full font-mono text-center disabled:opacity-60 disabled:cursor-not-allowed"
                        :disabled="props.clinicalMode !== 'active'"
                      />
                      <span class="text-[10px] text-muted-foreground font-mono">d</span>
                    </div>
                  </td>
                  <td class="p-2">
                    <Input
                      v-model.number="item.quantityPrescribed"
                      type="number"
                      min="1"
                      placeholder="—"
                      class="h-7 text-xs px-1 w-full font-mono font-semibold text-center text-primary disabled:opacity-60 disabled:cursor-not-allowed"
                      :disabled="props.clinicalMode !== 'active'"
                    />
                  </td>
                  <td class="p-2 text-right">
                    <template v-if="item.unitPrice">
                      <template v-if="item.quantityPrescribed && item.quantityPrescribed > 0">
                        <span class="font-mono font-bold text-emerald-700 dark:text-emerald-400 text-[11px] whitespace-nowrap block">
                          TZS {{ ((item.unitPrice || 0) * item.quantityPrescribed).toLocaleString() }}
                        </span>
                        <span v-if="item.quantityPrescribed > 1" class="text-[9px] text-muted-foreground font-mono block">
                          @ {{ item.unitPrice.toLocaleString() }}/ea
                        </span>
                      </template>
                      <span v-else class="text-muted-foreground text-[10.5px] font-mono block">
                        TZS {{ item.unitPrice.toLocaleString() }}/ea
                      </span>
                    </template>
                    <span v-else class="text-muted-foreground text-[10px]">-</span>
                  </td>
                  <td class="p-2">
                    <Input
                      v-model="item.instructions"
                      type="text"
                      class="h-7 text-xs px-1.5 w-full disabled:opacity-60 disabled:cursor-not-allowed"
                      :disabled="props.clinicalMode !== 'active'"
                      placeholder="e.g. Take with food"
                    />
                  </td>
                  <td class="p-2 pr-3 text-right">
                    <button
                      v-if="props.clinicalMode === 'active'"
                      type="button"
                      class="p-1 text-muted-foreground hover:text-critical transition-colors cursor-pointer"
                      @click="orders.removePrescriptionItem(idx)"
                    >
                      <Trash2 class="size-3.5" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Action / Submit Prescription Banner -->
        <div
          v-if="orders.prescriptionDrafts.value.length > 0"
          class="flex flex-col sm:flex-row items-center justify-between gap-3 p-3 rounded-lg border border-primary/20 bg-primary/5"
        >
          <div class="flex items-center gap-3">
            <div class="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
              <Send class="size-3.5" />
            </div>
            <div>
              <p class="text-xs font-bold text-foreground">
                {{ orders.prescriptionDrafts.value.length }} {{ orders.prescriptionDrafts.value.length === 1 ? 'Medication' : 'Medications' }} ready to prescribe
              </p>
              <p v-if="totalDraftCost > 0" class="text-[11px] text-emerald-700 dark:text-emerald-400 font-mono font-semibold">
                Est. Total: TZS {{ totalDraftCost.toLocaleString() }}
              </p>
            </div>
          </div>

          <Button
            size="sm"
            class="h-8 text-xs font-semibold gap-1.5 px-4 shadow-xs cursor-pointer w-full sm:w-auto"
            :disabled="orders.isPlacingOrder.value || props.clinicalMode !== 'active'"
            @click="handleSubmitPrescriptions"
          >
            <Loader2 v-if="orders.isPlacingOrder.value" class="size-3.5 animate-spin" />
            <Send v-else class="size-3.5" />
            <span>{{ orders.isPlacingOrder.value ? t("common.submitting", "Sending...") : t("clinician.send_to_pharmacy") }}</span>
          </Button>
        </div>
      </div>
    </section>

    <!-- ============================================================
         CARD 2: PLACED ACTIVE MEDICATIONS TRACKER
         ============================================================ -->
    <section v-if="placedMedications.length > 0" class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-2.5">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <h4 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
          <span>{{ t("clinician.placed_prescriptions", "Prescribed Medications") }}</span>
          <Badge variant="secondary" class="text-[9px] font-mono px-1.5 py-0">{{ placedMedications.length }}</Badge>
        </h4>
      </div>

      <div class="rounded-lg border border-border bg-surface overflow-hidden">
        <table class="w-full text-left text-xs table-fixed">
          <thead class="border-b border-border/70 bg-muted/30 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">
            <tr>
              <th class="p-2 pl-3 w-[30%]">{{ t("clinician.drug_name") }}</th>
              <th class="p-2 w-[25%]">{{ t("clinician.dosage") }} & Regimen</th>
              <th class="p-2 w-[15%] text-right">{{ t("clinician.price", "Price") }}</th>
              <th class="p-2 w-[15%] text-center">{{ t("common.status", "Status") }}</th>
              <th class="p-2 w-[15%] text-right pr-3">{{ t("common.actions", "Actions") }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="order in placedMedications" :key="order.id" class="hover:bg-muted/15">
              <td class="p-2 pl-3 font-semibold text-foreground text-[11.5px] truncate">
                {{ order.name }}
              </td>
              <td class="p-2 text-muted-foreground text-[11px] truncate">
                {{ [order.dosage, order.frequency, order.route].filter(Boolean).join(" · ") }}
              </td>
              <td class="p-2 text-right font-mono text-[11px] font-semibold text-foreground">
                {{ order.price ? `TZS ${order.price.toLocaleString()}` : '-' }}
              </td>
              <td class="p-2 text-center">
                <Badge
                  variant="outline"
                  class="text-[9.5px] font-mono uppercase px-1.5 py-0"
                  :class="{
                    'border-emerald-500/40 text-emerald-600 bg-emerald-500/10': order.status === 'dispensed' || order.status === 'completed',
                    'border-amber-500/40 text-amber-600 bg-amber-500/10': order.status === 'pending' || order.status === 'in_progress',
                    'border-rose-500/40 text-rose-600 bg-rose-500/10': order.status === 'cancelled',
                  }"
                >
                  {{ order.status }}
                </Badge>
              </td>
              <td class="p-2 pr-3 text-right">
                <Button
                  v-if="order.status !== 'cancelled' && order.status !== 'dispensed' && props.clinicalMode === 'active'"
                  variant="ghost"
                  size="sm"
                  class="h-6 px-1.5 text-[10.5px] text-muted-foreground hover:text-critical cursor-pointer"
                  @click="openCancelDialog(order)"
                >
                  <XCircle class="size-3 mr-1" />
                  {{ t("common.cancel") }}
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Cancel Order Confirmation Dialog -->
    <Dialog :open="orderToCancel !== null" @update:open="(val) => { if (!val) orderToCancel = null; }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ t("clinician.cancel_order_title", "Cancel Prescription") }}</DialogTitle>
          <DialogDescription>
            {{ t("clinician.cancel_order_confirm", "Are you sure you want to cancel this prescription?") }}
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-2 py-2">
          <Label class="text-xs font-semibold">{{ t("clinician.cancellation_reason", "Reason for cancellation") }}</Label>
          <Input v-model="cancelReason" class="h-8 text-xs" />
        </div>

        <DialogFooter>
          <Button variant="secondary" size="sm" @click="orderToCancel = null">{{ t("common.back", "Back") }}</Button>
          <Button variant="destructive" size="sm" :disabled="isCancelling" @click="confirmCancelOrder">
            <Loader2 v-if="isCancelling" class="size-3.5 animate-spin mr-1" />
            {{ t("common.confirm_cancel", "Confirm Cancel") }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
