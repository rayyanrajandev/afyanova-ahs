/**
 * Laboratory Workspace (Volume 2.4)
 * ==================================
 * The primary workstation for Medical Laboratory Scientists, Technologists, and Pathologists.
 * Built on SplitPane architecture with Live Worklist, Specimen Accessioning,
 * Structured Parameter Result Matrix, Critical Panic Value Protocol, and Senior Verification.
 */

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
  ShieldCheck,
  TestTube2,
  Users,
} from "lucide-vue-next";
import { onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import PatientFlowTimeline from "@/components/common/PatientFlowTimeline.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { usePatientFlowLiveSync } from "@/composables/usePatientFlowLiveSync";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

// Laboratory Components & Composable
import LabAuditTab from "./components/LabAuditTab.vue";
import LabOrderHeader from "./components/LabOrderHeader.vue";
import LabQueuePanel from "./components/LabQueuePanel.vue";
import ResultEntryTab from "./components/ResultEntryTab.vue";
import SpecimenAccessioningTab from "./components/SpecimenAccessioningTab.vue";
import VerificationTab from "./components/VerificationTab.vue";
import { useLaboratoryOrders } from "./composables/useLaboratoryOrders";

const { t } = useI18n({ useScope: "global" });

const laboratoryManager = useLaboratoryOrders();

const activeTab = ref<"results" | "accessioning" | "verification" | "audit" | "journey">("results");

onMounted(() => {
  laboratoryManager.fetchOrders();
});

// Laboratory was the only built workspace not listening to the board: a doctor
// ordering a new test, a nurse finishing triage, or reception checking someone
// in changed nothing on this screen until the technician reloaded the page.
usePatientFlowLiveSync({
  onBoardUpdated: () => {
    void laboratoryManager.fetchOrders();
  },
});

// Auto-switch tab based on order status
watch(
  () => laboratoryManager.selectedOrder.value?.status,
  (newStatus) => {
    if (newStatus === "ordered") {
      activeTab.value = "accessioning";
    } else if (newStatus === "sample_collected") {
      activeTab.value = "accessioning";
    } else if (newStatus === "in_progress") {
      activeTab.value = "results";
    } else if (newStatus === "completed") {
      activeTab.value = "verification";
    }
  },
);
</script>

<template>
  <div class="flex h-full w-full flex-col overflow-hidden bg-background">
    <SplitPane persist-key="afyanova:laboratory" :initial-ratio="0.28" :min-size="280">
      
      <!-- 1. Left Context Pane: Worklist & Queue -->
      <template #start>
        <aside class="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface">
          <LabQueuePanel :laboratory="laboratoryManager" />
        </aside>
      </template>

      <!-- 2. Main Pane: Investigation Workstation -->
      <template #end>
        <div class="flex h-full gap-4">
          <main class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface">
            
            <!-- Empty State when no order selected -->
            <div
              v-if="!laboratoryManager.selectedOrder.value"
              class="flex flex-1 items-center justify-center p-6"
            >
              <EmptyState
                illustration="flask"
                :badge="t('laboratory.workspace_badge', 'Laboratory Information System')"
                :title="t('laboratory.no_order_selected', 'Select a Laboratory Order')"
                :description="t('laboratory.no_order_desc', 'Choose an investigation from the worklist to begin specimen accessioning, testing, or result verification.')"
              />
            </div>

            <!-- Main Laboratory Station -->
            <div v-else class="flex flex-1 flex-col overflow-hidden">
              <!-- Order Header Banner -->
              <LabOrderHeader
                :order="laboratoryManager.selectedOrder.value"
                :patient-orders="laboratoryManager.selectedPatientOrders.value"
                :on-select-order="laboratoryManager.selectOrder"
                :is-verifying="laboratoryManager.isVerifying.value"
                :on-verify="() => laboratoryManager.verifyOrder(laboratoryManager.selectedOrder.value!.id)"
              />

              <!-- Station Navigation Tabs -->
              <Tabs v-model="activeTab" class="flex flex-1 flex-col overflow-hidden">
                <div class="shrink-0 border-b border-border bg-surface px-4">
                  <TabsList class="h-9 gap-1 bg-transparent p-0">
                    
                    <!-- Tab 1: Analytical Result Entry Matrix -->
                    <TabsTrigger
                      value="results"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <Activity class="size-3.5 text-primary" />
                      <span>{{ t('laboratory.result_entry') }}</span>
                    </TabsTrigger>

                    <!-- Tab 2: Specimen Accessioning -->
                    <TabsTrigger
                      value="accessioning"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <TestTube2 class="size-3.5 text-blue-600 dark:text-blue-400" />
                      <span>{{ t('laboratory.accessioning') }}</span>
                      <Badge
                        v-if="laboratoryManager.selectedOrder.value.status === 'ordered'"
                        variant="outline"
                        class="text-[9px] font-mono border-amber-500/40 text-amber-600 bg-amber-500/10 px-1 py-0"
                      >
                        {{ t('laboratory.pending') }}
                      </Badge>
                    </TabsTrigger>

                    <!-- Tab 3: Verification & Report -->
                    <TabsTrigger
                      value="verification"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <ShieldCheck class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                      <span>{{ t('laboratory.verification_report') }}</span>
                    </TabsTrigger>

                    <!-- Tab 4: Specimen Chain of Custody / Audit -->
                    <TabsTrigger
                      value="audit"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <Award class="size-3.5 text-purple-600 dark:text-purple-400" />
                      <span>{{ t('laboratory.quality_assurance') }}</span>
                    </TabsTrigger>

                    <!-- Tab 5: Patient Journey Flow -->
                    <TabsTrigger
                      value="journey"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-3 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                    >
                      <History class="size-3.5 text-teal-600 dark:text-teal-400" />
                      <span>{{ t('laboratory.patient_journey') }}</span>
                    </TabsTrigger>
                  </TabsList>
                </div>

                <!-- Tab Contents -->
                <TabsContent value="results" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                  <ResultEntryTab
                    :order="laboratoryManager.selectedOrder.value"
                    :laboratory="laboratoryManager"
                  />
                </TabsContent>

                <TabsContent value="accessioning" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                  <SpecimenAccessioningTab
                    :order="laboratoryManager.selectedOrder.value"
                    :laboratory="laboratoryManager"
                  />
                </TabsContent>

                <TabsContent value="verification" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                  <VerificationTab
                    :order="laboratoryManager.selectedOrder.value"
                    :laboratory="laboratoryManager"
                  />
                </TabsContent>

                <TabsContent value="audit" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                  <LabAuditTab
                    :order="laboratoryManager.selectedOrder.value"
                  />
                </TabsContent>

                <TabsContent value="journey" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                  <PatientFlowTimeline
                    :patient-id="laboratoryManager.selectedOrder.value.patientId"
                    workspace="laboratory"
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
