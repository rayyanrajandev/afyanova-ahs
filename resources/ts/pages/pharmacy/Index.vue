/** * Pharmacy Workspace (Volume 2.6) * ================================= * The
primary workstation for Pharmacists, Pharmacy Technicians, and Dispensary Staff.
* Built on SplitPane architecture matching Laboratory and Radiology
workstations. */

<script setup lang="ts">
import {
  Activity,
  Award,
  CheckCircle2,
  FileCheck,
  FileText,
  HeartPulse,
  History,
  Info,
  Lock,
  Pill,
  ShieldCheck,
  Users,
} from "lucide-vue-next";
import { onMounted, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import PatientFlowTimeline from "@/components/common/PatientFlowTimeline.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { TooltipProvider } from "@/components/ui/tooltip";
import {
  attachPersistence,
  makeValidator,
} from "@/composables/usePersistedSelection";
import { usePatientFlowLiveSync } from "@/composables/usePatientFlowLiveSync";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

// Pharmacy Components & Composable
import PharmacyDispenseTab from "./components/PharmacyDispenseTab.vue";
import PharmacyOrderHeader from "./components/PharmacyOrderHeader.vue";
import PharmacyQueuePanel from "./components/PharmacyQueuePanel.vue";
import PharmacyReviewTab from "./components/PharmacyReviewTab.vue";
import PharmacyStageBar from "./components/PharmacyStageBar.vue";
import PharmacyVerifyTab from "./components/PharmacyVerifyTab.vue";
import {
  isPharmacyTabReachable,
  PHARMACY_STAGE_TAB,
  pharmacyStageOf,
  usePharmacyOrders,
  type PharmacyTabId,
} from "./composables/usePharmacyOrders";

const { t } = useI18n({ useScope: "global" });

const pharmacyManager = usePharmacyOrders();
const activeTab = pharmacyManager.activeTab;

const PHARMACY_VIEW_MODES = ["patient", "prescription"] as const;
const PHARMACY_STATUS_FILTERS = [
  "all",
  "pending",
  "in_preparation",
  "partially_dispensed",
  "dispensed",
] as const;

attachPersistence(
  pharmacyManager.viewMode,
  "afyanova:pharmacy:view-mode",
  makeValidator(PHARMACY_VIEW_MODES),
);
attachPersistence(
  pharmacyManager.selectedStatusFilter,
  "afyanova:pharmacy:status-filter",
  makeValidator(PHARMACY_STATUS_FILTERS),
);

useWorkspaceUrlSync({
  params: {
    order: {
      ref: pharmacyManager.selectedOrderId as Ref<string>,
      isValid: (value) => value.trim() !== "",
    },
    tab: {
      ref: pharmacyManager.activeTab as Ref<string>,
      isValid: (value) =>
        ["review", "dispense", "verify", "audit"].includes(value),
    },
  },
});

usePatientFlowLiveSync({
  onBoardUpdated: () => {
    void pharmacyManager.fetchOrders(true);
  },
});

/**
 * The step gate, mirroring the laboratory bench. An unreachable tab is shown
 * locked rather than hidden, so the sequence itself stays legible: a
 * pharmacist can see that Sign & Release exists and that it opens once the
 * medicine has actually been handed over.
 */
function currentStage() {
  const order = pharmacyManager.selectedOrder.value;

  return order ? pharmacyStageOf(order) : null;
}

function tabReachable(tab: PharmacyTabId): boolean {
  const stage = currentStage();

  return stage === null ? false : isPharmacyTabReachable(tab, stage);
}

function tabLockReason(tab: PharmacyTabId): string {
  if (tabReachable(tab)) return "";

  if (currentStage() === "cancelled") {
    return t(
      "pharmacy.locked_cancelled",
      "This order was cancelled — there is nothing left to fill or sign off",
    );
  }

  return tab === "dispense"
    ? t(
        "pharmacy.locked_dispense",
        "Locked until the prescription has been accepted into preparation",
      )
    : t(
        "pharmacy.locked_verify",
        "Locked until the medicine has been dispensed",
      );
}

function selectTab(tab: PharmacyTabId) {
  if (tabReachable(tab)) {
    activeTab.value = tab;
  }
}

/**
 * A tab can go out of reach without anyone touching it — a background poll
 * cancels the order, or a colleague reverses a step. Fall back to the tab the
 * stage actually serves rather than leave a locked one rendered.
 */
watch(
  () => currentStage(),
  (stage) => {
    if (stage !== null && !isPharmacyTabReachable(activeTab.value, stage)) {
      activeTab.value = PHARMACY_STAGE_TAB[stage];
    }
  },
);

onMounted(() => {
  void pharmacyManager.fetchOrders();
});
</script>

<template>
  <TooltipProvider>
    <SplitPane
      persist-key="afyanova:pharmacy"
      :initial-ratio="0.28"
      :min-size="280"
    >
      <!-- 1. Left Context Pane: Worklist & Queue (slot: start) -->
      <template #start>
        <aside
          class="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface"
        >
          <PharmacyQueuePanel :pharmacy="pharmacyManager" />
        </aside>
      </template>

      <!-- 2. Main Pane: Investigation & Dispensing Workstation (slot: end) -->
      <template #end>
        <div class="flex h-full w-full min-w-0">
          <main
            class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface w-full min-w-0"
          >
            <!-- Loading Skeleton while orders are loading on initial page mount -->
            <div
              v-if="
                pharmacyManager.isLoadingOrders.value &&
                !pharmacyManager.selectedOrder.value
              "
              class="flex flex-1 flex-col p-6 space-y-4 animate-pulse w-full"
            >
              <div class="h-20 rounded-lg bg-muted/40 w-full" />
              <div class="h-9 rounded-lg bg-muted/30 w-full" />
              <div class="flex-1 rounded-lg bg-muted/20 w-full" />
            </div>

            <!-- Empty State when no prescription selected -->
            <div
              v-else-if="!pharmacyManager.selectedOrder.value"
              class="flex flex-1 items-center justify-center p-6"
            >
              <EmptyState
                illustration="pill"
                badge="Pharmacy Information System"
                title="Select a Medication Order"
                description="Choose a prescription or patient from the worklist to review safety, fulfill inventory batch, or verify dispensing."
              />
            </div>

            <!-- Main Pharmacy Station -->
            <div v-else class="flex flex-1 flex-col overflow-hidden">
              <!-- Patient & Encounter Banner -->
              <PharmacyOrderHeader
                :order="pharmacyManager.selectedOrder.value"
                :patient-orders="pharmacyManager.selectedPatientOrders.value"
                :pharmacy="pharmacyManager"
              />

              <!-- 3-Step Dispensing Stage Tracker -->
              <PharmacyStageBar :order="pharmacyManager.selectedOrder.value" />

              <!-- Workspace Tabs -->
              <Tabs
                v-model="activeTab"
                class="flex flex-1 flex-col overflow-hidden"
              >
                <div class="shrink-0 border-b border-border bg-surface px-4">
                  <TabsList class="h-9 gap-1 bg-transparent p-0">
                    <!-- Tab 1: Safety Review -->
                    <TabsTrigger
                      value="review"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <Activity class="size-3.5 text-primary" />
                      <span>1. Safety & Stock Review</span>
                    </TabsTrigger>

                    <!-- Tab 2: Batch Dispense & Fulfillment -->
                    <TabsTrigger
                      value="dispense"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary -mb-px"
                      :class="
                        tabReachable('dispense')
                          ? 'cursor-pointer'
                          : 'cursor-not-allowed opacity-45'
                      "
                      :disabled="!tabReachable('dispense')"
                      :title="tabLockReason('dispense')"
                      @click="selectTab('dispense')"
                    >
                      <Lock
                        v-if="!tabReachable('dispense')"
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <Pill
                        v-else
                        class="size-3.5 text-blue-600 dark:text-blue-400"
                      />
                      <span>2. Fill & Label</span>
                    </TabsTrigger>

                    <!-- Tab 3: Verification & Release -->
                    <TabsTrigger
                      value="verify"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary -mb-px"
                      :class="
                        tabReachable('verify')
                          ? 'cursor-pointer'
                          : 'cursor-not-allowed opacity-45'
                      "
                      :disabled="!tabReachable('verify')"
                      :title="tabLockReason('verify')"
                      @click="selectTab('verify')"
                    >
                      <Lock
                        v-if="!tabReachable('verify')"
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <ShieldCheck
                        v-else
                        class="size-3.5 text-emerald-600 dark:text-emerald-400"
                      />
                      <span>3. Sign & Release</span>
                    </TabsTrigger>

                    <!-- Tab 4: Patient Journey Flow -->
                    <TabsTrigger
                      value="audit"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <History
                        class="size-3.5 text-teal-600 dark:text-teal-400"
                      />
                      <span>Patient Journey</span>
                    </TabsTrigger>
                  </TabsList>
                </div>

                <!-- Tab Contents -->
                <TabsContent
                  value="review"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <PharmacyReviewTab
                    :order="pharmacyManager.selectedOrder.value"
                    :pharmacy="pharmacyManager"
                  />
                </TabsContent>

                <TabsContent
                  value="dispense"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <PharmacyDispenseTab
                    :order="pharmacyManager.selectedOrder.value"
                    :pharmacy="pharmacyManager"
                  />
                </TabsContent>

                <TabsContent
                  value="verify"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <PharmacyVerifyTab
                    :order="pharmacyManager.selectedOrder.value"
                    :patient-orders="
                      pharmacyManager.selectedPatientOrders.value
                    "
                    :pharmacy="pharmacyManager"
                  />
                </TabsContent>

                <TabsContent
                  value="audit"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <div class="w-full min-w-0 p-4 space-y-4">
                    <div
                      class="rounded-lg border border-border bg-surface p-4 space-y-3 w-full"
                    >
                      <h3
                        class="text-sm font-bold flex items-center gap-2 text-foreground"
                      >
                        <History class="size-4 text-primary" />
                        <span>Patient Encounter Journey & Audit Trail</span>
                      </h3>
                      <PatientFlowTimeline
                        :patient-id="
                          pharmacyManager.selectedOrder.value.patientId
                        "
                        workspace="pharmacy"
                      />
                    </div>
                  </div>
                </TabsContent>
              </Tabs>
            </div>
          </main>
        </div>
      </template>
    </SplitPane>
  </TooltipProvider>
</template>
