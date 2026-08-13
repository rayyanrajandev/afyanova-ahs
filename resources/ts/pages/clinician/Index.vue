/**
 * Clinician Workspace (Volume 2.2)
 * =================================
 * The primary workspace for physicians, clinical officers, and providers.
 * Uses the split-3 layout (context + main + detail) composed entirely from
 * Tier 1 components — no new tokens, primitives, or components.
 *
 * Panes (Volume 2.2 §4):
 *   - Context: patient list (DataTable) or queue (Queue) via tabs
 *   - Main: patient chart with tabs (Summary, Notes, Results, Orders, Timeline)
 *   - Detail: contextual (order entry, result detail)
 *
 * Principles: P1 (safety), P2 (one system), P3 (cognitive load), P4 (interruption),
 * P5 (keyboard), P6 (real-time), P7 (privacy)
 */

<script setup lang="ts">
import { CircleCheck, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Alert from '@/components/common/Alert.vue';
import DataTable, { type DataTableColumn } from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import Queue, { type QueueItem } from '@/components/common/Queue.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import Timeline, { type TimelineEvent } from '@/components/common/Timeline.vue';
import AppShell from '@/components/shell/AppShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useEncounterStore } from '@/stores/encounterStore';
import { useOrdersStore } from '@/stores/ordersStore';
import { useQueueStore } from '@/stores/queueStore';
import { useResultsStore } from '@/stores/resultsStore';

const { t } = useI18n();
const encounterStore = useEncounterStore();
const resultsStore = useResultsStore();
const ordersStore = useOrdersStore();
const queueStore = useQueueStore();

// ---- Types (Volume 2.2 §13) ----
interface Patient {
    id: string;
    name: string;
    mrn: string;
    age: number;
    sex: string;
    allergies: string[];
    status: string;
}

interface Note {
    id: string;
    title: string;
    date: string;
    status: 'draft' | 'signed';
    subjective: string;
    objective: string;
    assessment: string;
    plan: string;
}

interface Result {
    id: string;
    test: string;
    value: string;
    reference: string;
    flag: 'normal' | 'abnormal' | 'critical';
    date: string;
}

interface Order {
    id: string;
    type: 'lab' | 'imaging' | 'medication' | 'referral';
    name: string;
    status: 'pending' | 'in_progress' | 'complete' | 'cancelled';
    date: string;
}

// ---- Context pane: patient list (Volume 2.2 §4.1) ----
const patients: Patient[] = [
    { id: 'p1', name: 'John Mwangi', mrn: 'MRN-1001', age: 45, sex: 'M', allergies: ['Penicillin'], status: 'Admitted' },
    { id: 'p2', name: 'Sarah Joseph', mrn: 'MRN-1002', age: 32, sex: 'F', allergies: [], status: 'Outpatient' },
    { id: 'p3', name: 'Ali Hassan', mrn: 'MRN-1003', age: 58, sex: 'M', allergies: ['Sulfa'], status: 'Critical' },
    { id: 'p4', name: 'Grace Kimaro', mrn: 'MRN-1004', age: 27, sex: 'F', allergies: [], status: 'Outpatient' },
    { id: 'p5', name: 'Peter Mushi', mrn: 'MRN-1005', age: 61, sex: 'M', allergies: [], status: 'Admitted' },
];

const patientColumns: DataTableColumn<Patient>[] = [
    { key: 'name', label: t('patient.name'), accessor: (r) => r.name, sticky: true },
    { key: 'mrn', label: t('patient.mrn'), accessor: (r) => r.mrn, clinical: true },
    { key: 'age', label: t('patient.age'), accessor: (r) => r.age, align: 'right' },
];

const selectedPatient = ref<Patient | null>(null);

function selectPatient(patient: Patient) {
    selectedPatient.value = patient;
}

// ---- Context pane: queue (Volume 2.2 §4.1) — fetched from /clinician/patients + /reception/queue ----
const queue = computed<QueueItem[]>(() =>
    queueStore.tasks.map((task) => ({
        id: task.id,
        name: task.patientName,
        waitTime: task.dueTime,
        waitMinutes: 0,
        priority: task.priority,
        status: task.status === 'complete' ? 'complete' : task.status === 'in_progress' ? 'in_progress' : 'pending',
    })),
);

queueStore.fetchReceptionQueue();

function handleQueueOpen(item: QueueItem) {
    const patient = patients.find((p) => p.name === item.name);
    if (patient) selectPatient(patient);
}

// ---- Main pane: patient chart (Volume 2.2 §4.2) ----
const activeTab = ref<'summary' | 'notes' | 'results' | 'orders' | 'timeline'>('summary');

// Summary data
const activeProblems = ref([
    { name: 'Type 2 Diabetes', type: 'chronic', status: 'active' },
    { name: 'Hypertension', type: 'chronic', status: 'active' },
]);

const activeMedications = ref([
    { name: 'Metformin 500mg', dose: 'BID', status: 'active' },
    { name: 'Amlodipine 5mg', dose: 'OD', status: 'active' },
]);

// Notes (Volume 2.2 §7)
const notes = ref<Note[]>([
    {
        id: 'n1',
        title: 'Initial consultation',
        date: '2026-08-04',
        status: 'signed',
        subjective: 'Patient reports fatigue and increased thirst over the past 2 weeks.',
        objective: 'BP 145/90, HR 78, Temp 36.8C. Random glucose 11.2 mmol/L.',
        assessment: 'Type 2 Diabetes Mellitus, likely new onset. Hypertension.',
        plan: 'Start Metformin 500mg BID. Start Amlodipine 5mg OD. Fasting glucose + HbA1c. Review in 2 weeks.',
    },
    {
        id: 'n2',
        title: 'Follow-up',
        date: '2026-08-08',
        status: 'draft',
        subjective: '',
        objective: '',
        assessment: '',
        plan: '',
    },
]);

const activeNote = ref<Note | null>(null);

function openNote(note: Note) {
    activeNote.value = note;
}

// Results (Volume 2.2 §9) — fetched from GET /clinician/results
const results = computed(() => resultsStore.results);

resultsStore.fetchResults();

const resultColumns: DataTableColumn<Result>[] = [
    { key: 'test', label: t('clinician.test'), accessor: (r) => r.test },
    { key: 'value', label: t('clinician.value'), accessor: (r) => r.value, clinical: true },
    { key: 'reference', label: t('clinician.reference'), accessor: (r) => r.reference, clinical: true },
    { key: 'date', label: t('clinician.date'), accessor: (r) => r.date },
];

// Orders (Volume 2.2 §8) — fetched via /clinician/orders/* (ordersStore)
const orders = computed(() => ordersStore.orders);

const orderColumns: DataTableColumn<Order>[] = [
    { key: 'type', label: t('clinician.type'), accessor: (r) => r.type },
    { key: 'name', label: t('clinician.order_name'), accessor: (r) => r.name },
    { key: 'date', label: t('clinician.date'), accessor: (r) => r.date },
];

// Timeline (Volume 2.2 §4.2)
const timelineEvents = computed<TimelineEvent[]>(() => {
    const events: TimelineEvent[] = [];
    notes.value.forEach((n) => {
        events.push({
            id: `note-${n.id}`,
            type: 'note',
            title: n.title,
            timestamp: `${n.date}T10:00:00`,
            status: n.status === 'signed' ? 'complete' : 'warning',
            summary: n.status === 'signed' ? 'Signed note' : 'Draft — not signed',
        });
    });
    results.value.forEach((r) => {
        events.push({
            id: `result-${r.id}`,
            type: 'lab',
            title: r.test,
            timestamp: `${r.date}T09:00:00`,
            status: r.flag === 'critical' ? 'critical' : r.flag === 'abnormal' ? 'warning' : 'success',
            summary: `${r.value} (${r.reference})`,
        });
    });
    orders.value.forEach((o) => {
        events.push({
            id: `order-${o.id}`,
            type: o.type === 'imaging' ? 'imaging' : o.type === 'medication' ? 'medication' : 'order',
            title: o.name,
            timestamp: `${o.date}T08:00:00`,
            status: o.status === 'complete' ? 'complete' : o.status === 'cancelled' ? 'cancelled' : 'in_progress',
        });
    });
    return events.sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
});

// ---- Detail pane: order entry (Volume 2.2 §4.3) ----
const detailMode = ref<'none' | 'order' | 'result'>('none');
const orderType = ref<'lab' | 'imaging' | 'medication' | 'referral'>('lab');
const orderName = ref('');
const orderPriority = ref<'routine' | 'urgent' | 'stat'>('routine');

function openOrderForm(type: 'lab' | 'imaging' | 'medication' | 'referral') {
    orderType.value = type;
    orderName.value = '';
    orderPriority.value = 'routine';
    detailMode.value = 'order';
}

async function submitOrder() {
    if (!orderName.value.trim() || !selectedPatient.value) return;
    await ordersStore.createOrder(orderType.value, {
        patientId: selectedPatient.value.id,
        name: orderName.value,
        priority: orderPriority.value,
    });
    detailMode.value = 'none';
}

// ---- Keyboard shortcuts (Volume 2.2 §15) ----
// Ctrl+N new encounter, Ctrl+L lab order, Ctrl+I imaging, Ctrl+M medication
// (registered via useShortcuts in a real implementation; here we expose handlers)

// ---- Flag helpers ----
function flagVariant(flag: Result['flag']): 'critical' | 'warning' | 'success' {
    return flag === 'critical' ? 'critical' : flag === 'abnormal' ? 'warning' : 'success';
}
</script>

<template>
    <AppShell>
        <div class="flex h-full gap-4">
            <!-- ============================================================
                 CONTEXT PANE (Volume 2.2 §4.1)
                 ============================================================ -->
            <aside class="flex w-80 flex-col rounded-lg border border-border bg-surface">
                <Tabs default-value="patients" class="flex flex-1 flex-col">
                    <TabsList class="m-2 mb-0 w-auto justify-start">
                        <TabsTrigger value="patients">{{ t('clinician.patients') }}</TabsTrigger>
                        <TabsTrigger value="queue">{{ t('queue.label') }} ({{ queue.length }})</TabsTrigger>
                    </TabsList>

                    <!-- Patients tab -->
                    <TabsContent value="patients" class="flex flex-1 flex-col overflow-hidden">
                        <div class="flex-1 overflow-auto">
                            <DataTable
                                :columns="patientColumns"
                                :rows="patients"
                                :row-key="(r) => r.id"
                                :on-row-click="selectPatient"
                                empty-title="No patients found"
                            />
                        </div>
                    </TabsContent>

                    <!-- Queue tab -->
                    <TabsContent value="queue" class="flex flex-1 flex-col overflow-hidden">
                        <Queue :items="queue" @open="handleQueueOpen" />
                    </TabsContent>
                </Tabs>
            </aside>

            <!-- ============================================================
                 MAIN PANE (Volume 2.2 §4.2)
                 ============================================================ -->
            <main class="flex flex-1 flex-col rounded-lg border border-border bg-surface">
                <!-- No patient selected -->
                <div v-if="!selectedPatient" class="flex flex-1 items-center justify-center">
                    <EmptyState
                        title="Select a patient"
                        description="Choose a patient from the list or queue to open their chart."
                        illustration="users"
                    />
                </div>

                <!-- Patient chart -->
                <template v-else>
                    <!-- Patient banner (Volume 1.1 §7) -->
                    <div class="flex items-center gap-4 border-b border-border px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-foreground">{{ selectedPatient.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ t('patient.mrn') }}: {{ selectedPatient.mrn }}</span>
                            <span class="text-xs text-muted-foreground">{{ selectedPatient.age }}y {{ selectedPatient.sex }}</span>
                        </div>
                        <div v-if="selectedPatient.allergies.length > 0" class="flex items-center gap-1.5">
                            <Badge variant="critical" class="inline-flex items-center gap-1">
                                <TriangleAlert class="h-3 w-3" aria-hidden="true" />
                                {{ t('patient.allergies_count', { count: selectedPatient.allergies.length }) }}
                            </Badge>
                        </div>
                        <div v-else>
                            <Badge variant="success" class="inline-flex items-center gap-1">
                                <CircleCheck class="h-3 w-3" aria-hidden="true" />
                                {{ t('patient.no_allergies') }}
                            </Badge>
                        </div>
                    </div>

                    <!-- Chart tabs -->
                    <Tabs v-model="activeTab" class="flex flex-1 flex-col overflow-hidden">
                        <TabsList class="m-2 mb-0 w-auto justify-start">
                            <TabsTrigger value="summary">{{ t('clinician.summary') }}</TabsTrigger>
                            <TabsTrigger value="notes">{{ t('clinician.notes') }}</TabsTrigger>
                            <TabsTrigger value="results">{{ t('clinician.results') }}</TabsTrigger>
                            <TabsTrigger value="orders">{{ t('clinician.orders') }}</TabsTrigger>
                            <TabsTrigger value="timeline">{{ t('clinician.timeline') }}</TabsTrigger>
                        </TabsList>

                        <!-- Summary tab (Volume 2.2 §6.1) -->
                        <TabsContent value="summary" class="flex-1 overflow-auto p-4">
                            <div class="grid grid-cols-2 gap-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle class="text-sm text-muted-foreground">{{ t('clinician.active_problems') }}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <ul class="space-y-2">
                                            <li v-for="problem in activeProblems" :key="problem.name" class="flex items-center justify-between text-sm">
                                                <span class="text-foreground">{{ problem.name }}</span>
                                                <StatusBadge :status="problem.type === 'chronic' ? 'info' : 'warning'" />
                                            </li>
                                        </ul>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle class="text-sm text-muted-foreground">{{ t('clinician.active_medications') }}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <ul class="space-y-2">
                                            <li v-for="med in activeMedications" :key="med.name" class="flex items-center justify-between text-sm">
                                                <span class="text-foreground">{{ med.name }}</span>
                                                <span class="clinical-value text-muted-foreground">{{ med.dose }}</span>
                                            </li>
                                        </ul>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle class="text-sm text-muted-foreground">{{ t('clinician.allergies') }}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div v-if="selectedPatient.allergies.length > 0">
                                            <Badge
                                                v-for="a in selectedPatient.allergies"
                                                :key="a"
                                                variant="critical"
                                                class="mr-2 mb-2 inline-flex items-center gap-1"
                                            >
                                                <TriangleAlert class="h-3 w-3" aria-hidden="true" />
                                                {{ a }}
                                            </Badge>
                                        </div>
                                        <div v-else>
                                            <Badge variant="success" class="inline-flex items-center gap-1">
                                                <CircleCheck class="h-3 w-3" aria-hidden="true" />
                                                {{ t('patient.no_allergies') }}
                                            </Badge>
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle class="text-sm text-muted-foreground">{{ t('clinician.recent_results') }}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <ul class="space-y-2">
                                            <li v-for="r in results.slice(0, 3)" :key="r.id" class="flex items-center justify-between text-sm">
                                                <span class="text-foreground">{{ r.test }}</span>
                                                <StatusBadge :status="flagVariant(r.flag)" />
                                            </li>
                                        </ul>
                                    </CardContent>
                                </Card>
                            </div>
                        </TabsContent>

                        <!-- Notes tab (Volume 2.2 §7) -->
                        <TabsContent value="notes" class="flex-1 overflow-auto p-4">
                            <div class="mb-4 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-foreground">{{ t('clinician.notes') }}</h3>
                                <Button size="sm" @click="openNote({ id: 'new', title: 'New note', date: new Date().toISOString().slice(0,10), status: 'draft', subjective: '', objective: '', assessment: '', plan: '' })">
                                    {{ t('clinician.new_note') }}
                                </Button>
                            </div>

                            <div v-if="activeNote" class="space-y-4">
                                <Alert v-if="activeNote.status === 'draft'" variant="warning" title="Draft — not signed" />
                                <div class="grid gap-4">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('clinician.subjective') }}</label>
                                        <Textarea v-model="activeNote.subjective" class="min-h-20" :placeholder="t('clinician.subjective_placeholder')" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('clinician.objective') }}</label>
                                        <Textarea v-model="activeNote.objective" class="min-h-20" :placeholder="t('clinician.objective_placeholder')" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('clinician.assessment') }}</label>
                                        <Textarea v-model="activeNote.assessment" class="min-h-20" :placeholder="t('clinician.assessment_placeholder')" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('clinician.plan') }}</label>
                                        <Textarea v-model="activeNote.plan" class="min-h-20" :placeholder="t('clinician.plan_placeholder')" />
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <Button size="sm" @click="activeNote.status = 'signed'">{{ t('clinician.sign_note') }}</Button>
                                    <Button size="sm" variant="secondary" @click="activeNote = null">{{ t('common.cancel') }}</Button>
                                </div>
                            </div>

                            <div v-else class="space-y-2">
                                <button
                                    v-for="note in notes"
                                    :key="note.id"
                                    class="w-full rounded-md border border-border p-3 text-left transition-colors hover:bg-muted"
                                    @click="openNote(note)"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-foreground">{{ note.title }}</span>
                                        <StatusBadge :status="note.status === 'signed' ? 'complete' : 'warning'" />
                                    </div>
                                    <span class="text-xs text-muted-foreground">{{ note.date }}</span>
                                </button>
                            </div>
                        </TabsContent>

                        <!-- Results tab (Volume 2.2 §9) -->
                        <TabsContent value="results" class="flex-1 overflow-auto p-4">
                            <DataTable
                                :columns="resultColumns"
                                :rows="results"
                                :row-key="(r) => r.id"
                                empty-title="No results"
                            >
                                <template #flag="{ row }">
                                    <StatusBadge :status="flagVariant(row.flag)" />
                                </template>
                            </DataTable>
                        </TabsContent>

                        <!-- Orders tab (Volume 2.2 §8) -->
                        <TabsContent value="orders" class="flex-1 overflow-auto p-4">
                            <div class="mb-4 flex flex-wrap gap-2">
                                <Button size="sm" @click="openOrderForm('lab')">{{ t('clinician.new_lab_order') }}</Button>
                                <Button size="sm" variant="secondary" @click="openOrderForm('imaging')">{{ t('clinician.new_imaging_order') }}</Button>
                                <Button size="sm" variant="secondary" @click="openOrderForm('medication')">{{ t('clinician.new_medication_order') }}</Button>
                                <Button size="sm" variant="secondary" @click="openOrderForm('referral')">{{ t('clinician.new_referral') }}</Button>
                            </div>
                            <DataTable
                                :columns="orderColumns"
                                :rows="orders"
                                :row-key="(r) => r.id"
                                empty-title="No orders"
                            />
                        </TabsContent>

                        <!-- Timeline tab (Volume 2.2 §4.2) -->
                        <TabsContent value="timeline" class="flex-1 overflow-auto p-4">
                            <Timeline :events="timelineEvents" />
                        </TabsContent>
                    </Tabs>
                </template>
            </main>

            <!-- ============================================================
                 DETAIL PANE (Volume 2.2 §4.3)
                 ============================================================ -->
            <aside class="flex w-80 flex-col rounded-lg border border-border bg-surface">
                <!-- Order entry form -->
                <div v-if="detailMode === 'order'" class="flex flex-1 flex-col p-4">
                    <h3 class="mb-4 text-sm font-semibold text-foreground">
                        {{ t(`clinician.new_${orderType}_order`) }}
                    </h3>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('clinician.order_name') }}</label>
                            <Input v-model="orderName" type="text" :placeholder="t('clinician.order_name_placeholder')" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('clinician.priority') }}</label>
                            <div class="flex gap-2">
                                <Button
                                    v-for="p in ['routine', 'urgent', 'stat'] as const"
                                    :key="p"
                                    size="sm"
                                    :variant="orderPriority === p ? 'default' : 'outline'"
                                    @click="orderPriority = p"
                                >
                                    {{ t(`clinician.priority_${p}`) }}
                                </Button>
                            </div>
                        </div>
                        <Alert v-if="orderType === 'medication'" variant="warning" title="Allergy check" description="Verify no allergies before prescribing." />
                        <div class="flex gap-3">
                            <Button size="sm" @click="submitOrder">{{ t('common.save') }}</Button>
                            <Button size="sm" variant="secondary" @click="detailMode = 'none'">{{ t('common.cancel') }}</Button>
                        </div>
                    </div>
                </div>

                <!-- Result detail -->
                <div v-else-if="detailMode === 'result'" class="flex flex-1 flex-col p-4">
                    <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('clinician.result_detail') }}</h3>
                    <p class="text-sm text-muted-foreground">{{ t('clinician.select_result_hint') }}</p>
                </div>

                <!-- Empty detail -->
                <div v-else class="flex flex-1 items-center justify-center p-4">
                    <EmptyState
                        title="No detail selected"
                        description="Select an order or result to view details here."
                        illustration="clipboard"
                    />
                </div>
            </aside>
        </div>
    </AppShell>
</template>