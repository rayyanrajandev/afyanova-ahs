/** * Radiology Workspace — the imaging bench *
============================================================================= *
Workspace 5, built on the same spine as Laboratory: a SplitPane with the *
worklist on the left and the study being worked on to the right. * * The bench
runs in one direction and the tabs say so: * * ordered -> scheduled ->
in_progress -> completed -> verified * Schedule Perform Report Verify * * Two
deliberate differences from Laboratory, both from the domain rather than *
taste: * * - **Scheduling is a real step.** A study is booked against a modality
and a * time before anyone touches the patient, so the first tab is a scheduler.
* The lab has no equivalent — a specimen simply arrives. * * - **Reporting and
releasing are two acts by two people.** The backend * enforces a two-person
rule, so whoever reported a study cannot verify it. * The UI states that up
front rather than letting a radiographer discover it * as a failed request. */

<script setup lang="ts">
import {
  Activity,
  FileCheck,
  History,
  ScanLine,
  ShieldCheck,
} from "lucide-vue-next";
import { onMounted, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import PatientFlowTimeline from "@/components/common/PatientFlowTimeline.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { usePatientFlowLiveSync } from "@/composables/usePatientFlowLiveSync";
import {
  attachPersistence,
  makeValidator,
} from "@/composables/usePersistedSelection";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";

import RadiologyOrderHeader from "./components/RadiologyOrderHeader.vue";
import RadiologyQueuePanel from "./components/RadiologyQueuePanel.vue";
import RadiologyStageBar from "./components/RadiologyStageBar.vue";
import ReportEntryTab from "./components/ReportEntryTab.vue";
import SchedulingTab from "./components/SchedulingTab.vue";
import VerificationTab from "./components/VerificationTab.vue";
import { useRadiologyOrders } from "./composables/useRadiologyOrders";

const { t } = useI18n({ useScope: "global" });

const radiology = useRadiologyOrders();

const RAD_VIEW_MODES = ["patient", "study"] as const;
const RAD_STATUS_FILTERS = [
  "all",
  "ordered",
  "scheduled",
  "in_progress",
  "completed",
] as const;

attachPersistence(
  radiology.viewMode,
  "afyanova:radiology:view-mode",
  makeValidator(RAD_VIEW_MODES),
);
attachPersistence(
  radiology.selectedStatusFilter,
  "afyanova:radiology:status-filter",
  makeValidator(RAD_STATUS_FILTERS),
);

useWorkspaceUrlSync({
  params: {
    order: {
      ref: radiology.selectedOrderId as Ref<string>,
      isValid: (value) => value.trim() !== "",
    },
  },
});

type RadiologyTab = "scheduling" | "report" | "verification" | "journey";

const activeTab = ref<RadiologyTab>("scheduling");

onMounted(() => {
  void radiology.fetchOrders();
});

// The imaging worklist changes because of what other people do — a doctor
// ordering a study, a nurse finishing triage. Without this the radiographer
// would only find out by reloading.
usePatientFlowLiveSync({
  onBoardUpdated: () => {
    void radiology.fetchOrders();
  },
});

/**
 * Follow the study to the step it is actually at, so selecting a booked study
 * opens the console rather than the scheduler it has already been through.
 * Only on a change of *study* — never while someone is working, or the tab
 * would jump out from under them mid-report.
 */
watch(
  () => radiology.selectedOrder.value?.id,
  () => {
    const status = radiology.selectedOrder.value?.status;
    if (!status) return;

    activeTab.value =
      status === "ordered"
        ? "scheduling"
        : status === "scheduled" || status === "in_progress"
          ? "report"
          : "verification";
  },
);
</script>

<template>
  <div class="flex h-full w-full flex-col overflow-hidden bg-background">
    <SplitPane
      persist-key="afyanova:radiology"
      :initial-ratio="0.28"
      :min-size="280"
    >
      <!-- Worklist -->
      <template #start>
        <aside
          class="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface"
        >
          <RadiologyQueuePanel :radiology="radiology" />
        </aside>
      </template>

      <!-- The study being worked on -->
      <template #end>
        <div class="flex h-full w-full min-w-0">
          <main
            class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface w-full min-w-0"
          >
            <!-- Loading Skeleton while orders are loading on initial page mount -->
            <div
              v-if="
                radiology.isLoadingOrders.value &&
                !radiology.selectedOrder.value
              "
              class="flex flex-1 flex-col p-6 space-y-4 animate-pulse w-full"
            >
              <div class="h-20 rounded-lg bg-muted/40 w-full" />
              <div class="h-9 rounded-lg bg-muted/30 w-full" />
              <div class="flex-1 rounded-lg bg-muted/20 w-full" />
            </div>

            <div
              v-else-if="!radiology.selectedOrder.value"
              class="flex flex-1 items-center justify-center p-6"
            >
              <EmptyState
                illustration="flask"
                :badge="t('radiology.workspace_badge')"
                :title="t('radiology.no_study_selected')"
                :description="t('radiology.no_study_selected_desc')"
              />
            </div>

            <div v-else class="flex flex-1 flex-col overflow-hidden w-full">
              <RadiologyOrderHeader
                :order="radiology.selectedOrder.value"
                :patient-orders="radiology.selectedPatientOrders.value"
                :on-select-order="radiology.selectOrder"
                :radiology="radiology"
                @start-study="activeTab = 'report'"
              />

              <RadiologyStageBar :order="radiology.selectedOrder.value" />

              <Tabs
                v-model="activeTab"
                class="flex flex-1 flex-col overflow-hidden"
              >
                <div class="shrink-0 border-b border-border bg-surface px-4">
                  <TabsList class="h-9 gap-1 bg-transparent p-0">
                    <TabsTrigger
                      value="scheduling"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <Activity class="size-3.5 text-primary" />
                      <span>{{ t("radiology.tab_scheduling") }}</span>
                    </TabsTrigger>

                    <TabsTrigger
                      value="report"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <ScanLine
                        class="size-3.5 text-blue-600 dark:text-blue-400"
                      />
                      <span>{{ t("radiology.tab_report") }}</span>
                    </TabsTrigger>

                    <TabsTrigger
                      value="verification"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <ShieldCheck
                        class="size-3.5 text-emerald-600 dark:text-emerald-400"
                      />
                      <span>{{ t("radiology.tab_verification") }}</span>
                    </TabsTrigger>

                    <TabsTrigger
                      value="journey"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <History
                        class="size-3.5 text-teal-600 dark:text-teal-400"
                      />
                      <span>{{ t("radiology.tab_journey") }}</span>
                    </TabsTrigger>
                  </TabsList>
                </div>

                <TabsContent
                  value="scheduling"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <SchedulingTab
                    :order="radiology.selectedOrder.value"
                    :radiology="radiology"
                    @start-study="activeTab = 'report'"
                  />
                </TabsContent>

                <TabsContent
                  value="report"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <ReportEntryTab
                    :order="radiology.selectedOrder.value"
                    :radiology="radiology"
                  />
                </TabsContent>

                <TabsContent
                  value="verification"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <VerificationTab
                    :order="radiology.selectedOrder.value"
                    :radiology="radiology"
                  />
                </TabsContent>

                <TabsContent
                  value="journey"
                  class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden"
                >
                  <PatientFlowTimeline
                    :patient-id="radiology.selectedOrder.value.patientId"
                    workspace="radiology"
                  />
                </TabsContent>
              </Tabs>
            </div>
          </main>
        </div>
      </template>
    </SplitPane>
  </div>
</template>
