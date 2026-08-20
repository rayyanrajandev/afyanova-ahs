/** * Laboratory Workspace (Volume 2.4) * ================================== *
The primary workstation for Medical Laboratory Scientists, Technologists, and
Pathologists. * Built on SplitPane architecture with Live Worklist, Specimen
Accessioning, * Structured Parameter Result Matrix, Critical Panic Value
Protocol, and Senior Verification. */

<script setup lang="ts">
import {
  Activity,
  Award,
  CheckCircle2,
  FileCheck,
  FileText,
  FlaskConical,
  HeartPulse,
  History,
  Info,
  Lock,
  ShieldCheck,
  TestTube2,
  Users,
} from "lucide-vue-next";
import { onMounted, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import PatientFlowTimeline from "@/components/common/PatientFlowTimeline.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import {
  attachPersistence,
  makeValidator,
} from "@/composables/usePersistedSelection";
import { usePatientFlowLiveSync } from "@/composables/usePatientFlowLiveSync";
import { useLaboratoryLiveSync } from "./composables/useLaboratoryLiveSync";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

// Laboratory Components & Composable
import LabAuditTab from "./components/LabAuditTab.vue";
import LabOrderHeader from "./components/LabOrderHeader.vue";
import LabQueuePanel from "./components/LabQueuePanel.vue";
import LabStageBar from "./components/LabStageBar.vue";
import ResultEntryTab from "./components/ResultEntryTab.vue";
import SpecimenAccessioningTab from "./components/SpecimenAccessioningTab.vue";
import VerificationTab from "./components/VerificationTab.vue";
import {
  LAB_STAGE_TAB,
  isLabTabReachable,
  useLaboratoryOrders,
  type LabTabId,
} from "./composables/useLaboratoryOrders";

const { t } = useI18n({ useScope: "global" });

const laboratoryManager = useLaboratoryOrders();

/**
 * The worklist's shape survives a refresh; the bench tab does not need to,
 * because it is derived from the selected order's stage further down.
 *
 * Laboratory had no persistence of any kind: a technologist who narrowed the
 * worklist to their own discipline, or switched it to the specimen view, got the
 * unfiltered patient view back on every reload.
 */
const LAB_VIEW_MODES = ["patient", "test"] as const;
const LAB_STATUS_FILTERS = [
  "all",
  "ordered",
  "collected",
  "in_progress",
  "completed",
  "critical",
] as const;
const LAB_PRIORITIES = ["all", "routine", "urgent", "stat"] as const;

attachPersistence(
  laboratoryManager.viewMode,
  "afyanova:laboratory:view-mode",
  makeValidator(LAB_VIEW_MODES),
);
attachPersistence(
  laboratoryManager.selectedStatusFilter,
  "afyanova:laboratory:status-filter",
  makeValidator(LAB_STATUS_FILTERS),
);
attachPersistence(
  laboratoryManager.selectedPriorityFilter,
  "afyanova:laboratory:priority-filter",
  makeValidator(LAB_PRIORITIES),
);

// The open order rides the URL rather than storage, so a link to a specimen
// opens that specimen for whoever receives it. fetchOrders() drops the id again
// if that order has since left the worklist.
useWorkspaceUrlSync({
  params: {
    order: {
      ref: laboratoryManager.selectedOrderId as Ref<string>,
      isValid: (value) => value.trim() !== "",
    },
  },
});

const activeTab = ref<LabTabId>("accessioning");

onMounted(() => {
  laboratoryManager.fetchOrders();
});

/**
 * A tab is open only once the bench has reached the step it serves. Result
 * entry on an order whose specimen never arrived, or a release screen for
 * results that were never typed, are not "advanced options" — they are the
 * exact mistakes this workspace used to allow.
 */
function tabReachable(tab: LabTabId): boolean {
  const stage = laboratoryManager.selectedStage.value;

  return stage === null ? false : isLabTabReachable(tab, stage);
}

function tabLockReason(tab: LabTabId): string {
  if (tabReachable(tab)) return "";

  return tab === "results"
    ? t(
        "laboratory.locked_results",
        "Locked until analysis has started on this specimen",
      )
    : t(
        "laboratory.locked_verification",
        "Locked until results have been saved",
      );
}

function selectTab(tab: LabTabId) {
  if (tabReachable(tab)) {
    activeTab.value = tab;
  }
}

// Laboratory was the only built workspace not listening to the board: a doctor
// ordering a new test, a nurse finishing triage, or reception checking someone
// in changed nothing on this screen until the technician reloaded the page.
usePatientFlowLiveSync({
  onBoardUpdated: () => {
    void laboratoryManager.fetchOrders();
  },
});

useLaboratoryLiveSync({
  onQueueUpdated: () => {
    void laboratoryManager.fetchOrders();
  },
});

/**
 * Land on the step that is actually the technician's turn.
 *
 * Keyed on the order id as well as the stage: the old watcher fired only on
 * status *changes*, so selecting a different patient whose order sat at the
 * same status left you on whichever tab you happened to be looking at.
 */
watch(
  () =>
    [
      laboratoryManager.selectedOrderId.value,
      laboratoryManager.selectedStage.value,
    ] as const,
  ([, stage]) => {
    if (stage !== null) {
      activeTab.value = LAB_STAGE_TAB[stage];
    }
  },
  { immediate: true },
);
</script>

<template>
  <div class="flex h-full w-full flex-col overflow-hidden bg-background">
    <SplitPane
      persist-key="afyanova:laboratory"
      :initial-ratio="0.28"
      :min-size="280"
    >
      <!-- 1. Left Context Pane: Worklist & Queue -->
      <template #start>
        <aside
          class="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface"
        >
          <LabQueuePanel :laboratory="laboratoryManager" />
        </aside>
      </template>

      <!-- 2. Main Pane: Investigation Workstation -->
      <template #end>
        <div class="flex h-full w-full min-w-0">
          <main
            class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface w-full min-w-0"
          >
            <!-- Loading Skeleton while orders are loading on initial page mount -->
            <div
              v-if="
                laboratoryManager.isLoadingOrders.value &&
                !laboratoryManager.selectedOrder.value
              "
              class="flex flex-1 flex-col p-6 space-y-4 animate-pulse w-full"
            >
              <div class="h-20 rounded-lg bg-muted/40 w-full" />
              <div class="h-9 rounded-lg bg-muted/30 w-full" />
              <div class="flex-1 rounded-lg bg-muted/20 w-full" />
            </div>

            <!-- Empty State when no order selected after load completes -->
            <div
              v-else-if="!laboratoryManager.selectedOrder.value"
              class="flex flex-1 items-center justify-center p-6"
            >
              <EmptyState
                illustration="flask"
                :badge="
                  t(
                    'laboratory.workspace_badge',
                    'Laboratory Information System',
                  )
                "
                :title="
                  t('laboratory.no_order_selected', 'Select a Laboratory Order')
                "
                :description="
                  t(
                    'laboratory.no_order_desc',
                    'Choose an investigation from the worklist to begin specimen accessioning, testing, or result verification.',
                  )
                "
              />
            </div>

            <!-- Main Laboratory Station -->
            <div v-else class="flex flex-1 flex-col overflow-hidden w-full">
              <!-- Order Header Banner -->
              <LabOrderHeader
                :order="laboratoryManager.selectedOrder.value"
                :patient-orders="laboratoryManager.selectedPatientOrders.value"
                :on-select-order="laboratoryManager.selectOrder"
              />

              <!-- Which of the four bench steps this order is on, and what to do next -->
              <LabStageBar :order="laboratoryManager.selectedOrder.value" />

              <!-- Station Navigation Tabs -->
              <Tabs
                v-model="activeTab"
                class="flex flex-1 flex-col overflow-hidden"
              >
                <!-- Tab Navigation Bar -->
                <div
                  class="border-b border-border bg-surface px-3.5 pt-1 shrink-0"
                >
                  <TabsList
                    class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px"
                  >
                    <!-- Tab 1: Specimen Accessioning (step 1–2) -->
                    <TabsTrigger
                      value="accessioning"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                      @click="selectTab('accessioning')"
                    >
                      <TestTube2
                        class="size-3.5 text-blue-600 dark:text-blue-400"
                      />
                      <span>{{ t("laboratory.accessioning") }}</span>
                      <span
                        v-if="
                          laboratoryManager.selectedOrder.value.status ===
                          'ordered'
                        "
                        class="rounded-full bg-amber-500/15 px-1.5 py-0 text-[10px] font-bold text-amber-600 dark:text-amber-400 font-mono"
                      >
                        {{ t("laboratory.pending") }}
                      </span>
                    </TabsTrigger>

                    <!-- Tab 2: Analytical Result Entry Matrix (step 3) -->
                    <TabsTrigger
                      value="results"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary -mb-px"
                      :class="
                        tabReachable('results')
                          ? 'cursor-pointer'
                          : 'cursor-not-allowed opacity-45'
                      "
                      :disabled="!tabReachable('results')"
                      :title="tabLockReason('results')"
                      @click="selectTab('results')"
                    >
                      <Lock
                        v-if="!tabReachable('results')"
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <Activity
                        v-else
                        class="size-3.5 text-emerald-600 dark:text-emerald-400"
                      />
                      <span>{{ t("laboratory.result_entry") }}</span>
                    </TabsTrigger>

                    <!-- Tab 3: Verification & Report (step 4) -->
                    <TabsTrigger
                      value="verification"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary -mb-px"
                      :class="
                        tabReachable('verification')
                          ? 'cursor-pointer'
                          : 'cursor-not-allowed opacity-45'
                      "
                      :disabled="!tabReachable('verification')"
                      :title="tabLockReason('verification')"
                      @click="selectTab('verification')"
                    >
                      <Lock
                        v-if="!tabReachable('verification')"
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <ShieldCheck
                        v-else
                        class="size-3.5 text-purple-600 dark:text-purple-400"
                      />
                      <span>{{ t("laboratory.verification_report") }}</span>
                    </TabsTrigger>

                    <!-- Tab 4: Specimen Chain of Custody / Audit -->
                    <TabsTrigger
                      value="audit"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <Award
                        class="size-3.5 text-amber-600 dark:text-amber-400"
                      />
                      <span>{{ t("laboratory.quality_assurance") }}</span>
                    </TabsTrigger>

                    <!-- Tab 5: Patient Journey Flow -->
                    <TabsTrigger
                      value="journey"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <History
                        class="size-3.5 text-teal-600 dark:text-teal-400"
                      />
                      <span>{{ t("laboratory.patient_journey") }}</span>
                    </TabsTrigger>
                  </TabsList>
                </div>

                <!-- Tab Contents -->
                <TabsContent
                  value="results"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <ResultEntryTab
                    :order="laboratoryManager.selectedOrder.value"
                    :laboratory="laboratoryManager"
                  />
                </TabsContent>

                <TabsContent
                  value="accessioning"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <SpecimenAccessioningTab
                    :order="laboratoryManager.selectedOrder.value"
                    :laboratory="laboratoryManager"
                  />
                </TabsContent>

                <TabsContent
                  value="verification"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <VerificationTab
                    :order="laboratoryManager.selectedOrder.value"
                    :laboratory="laboratoryManager"
                  />
                </TabsContent>

                <TabsContent
                  value="audit"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <LabAuditTab :order="laboratoryManager.selectedOrder.value" />
                </TabsContent>

                <TabsContent
                  value="journey"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <div class="p-3.5 space-y-3.5">
                    <PatientFlowTimeline
                      :patient-id="
                        laboratoryManager.selectedOrder.value.patientId
                      "
                      workspace="laboratory"
                    />
                  </div>
                </TabsContent>
              </Tabs>
            </div>
          </main>
        </div>
      </template>
    </SplitPane>
  </div>
</template>
