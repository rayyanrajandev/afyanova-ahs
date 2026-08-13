/**
 * Nursing Workspace (Volume 2.3)
 * ===============================
 * The workspace for nurses to record vitals, perform assessments,
 * administer medications (MAR), and manage their task list.
 *
 * Uses the split-2 layout (context + main) with an optional detail pane
 * for MAR. Composed entirely from Tier 1 components — no new tokens,
 * primitives, or components.
 *
 * Principles: P1 (safety), P2 (one system), P3 (cognitive load),
 * P4 (interruption), P5 (keyboard), P8 (offline)
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
import AppShell from '@/components/shell/AppShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useMedicationStore } from '@/stores/medicationStore';
import { useQueueStore } from '@/stores/queueStore';

const { t } = useI18n();
const medicationStore = useMedicationStore();
const queueStore = useQueueStore();

// ---- Types (Volume 2.3 §12) ----
interface Patient {
    id: string;
    name: string;
    mrn: string;
    age: number;
    sex: string;
    ward: string;
    bed: string;
    allergies: string[];
}

interface Vital {
    id: string;
    patientId: string;
    temperature: number;
    heartRate: number;
    respiratoryRate: number;
    sbp: number;
    dbp: number;
    spo2: number;
    painScore: number;
    weight: number;
    recordedAt: string;
}

interface MarMedication {
    id: string;
    name: string;
    dose: string;
    route: string;
    dueTime: string;
    status: 'due' | 'given' | 'missed' | 'omitted' | 'held' | 'refused' | 'overdue';
}

interface Task {
    id: string;
    description: string;
    patientName: string;
    dueTime: string;
    priority: 'critical' | 'urgent' | 'normal';
    status: 'pending' | 'in_progress' | 'complete';
}

// ---- Context pane: patient list (Volume 2.3 §4.1) ----
const patients: Patient[] = [
    { id: 'p1', name: 'John Mwangi', mrn: 'MRN-1001', age: 45, sex: 'M', ward: 'Ward A', bed: 'A-101', allergies: ['Penicillin'] },
    { id: 'p2', name: 'Sarah Joseph', mrn: 'MRN-1002', age: 32, sex: 'F', ward: 'Ward A', bed: 'A-102', allergies: [] },
    { id: 'p3', name: 'Ali Hassan', mrn: 'MRN-1003', age: 58, sex: 'M', ward: 'Ward B', bed: 'B-201', allergies: ['Sulfa'] },
    { id: 'p4', name: 'Grace Kimaro', mrn: 'MRN-1004', age: 27, sex: 'F', ward: 'Ward B', bed: 'B-202', allergies: [] },
    { id: 'p5', name: 'Peter Mushi', mrn: 'MRN-1005', age: 61, sex: 'M', ward: 'Ward A', bed: 'A-103', allergies: [] },
];

const patientColumns: DataTableColumn<Patient>[] = [
    { key: 'name', label: t('patient.name'), accessor: (r) => r.name, sticky: true },
    { key: 'mrn', label: t('patient.mrn'), accessor: (r) => r.mrn, clinical: true },
    { key: 'ward', label: t('nursing.ward'), accessor: (r) => r.ward },
    { key: 'bed', label: t('nursing.bed'), accessor: (r) => r.bed },
];

const selectedPatient = ref<Patient | null>(null);

function selectPatient(patient: Patient) {
    selectedPatient.value = patient;
}

// ---- Context pane: task list (Volume 2.3 §4.1, §9) — fetched from GET /nursing/tasks ----
const tasks = computed(() => queueStore.tasks);

queueStore.fetchTasks();

const taskQueue = computed<QueueItem[]>(() =>
    tasks.value.map((task) => ({
        id: task.id,
        name: task.description,
        waitTime: task.dueTime,
        waitMinutes: 0,
        priority: task.priority,
        status: task.status === 'complete' ? 'complete' : task.status === 'in_progress' ? 'in_progress' : 'pending',
        category: task.patientName,
    })),
);

function handleTaskOpen(item: QueueItem) {
    queueStore.markInProgress(item.id);
}

// ---- Main pane: vitals collection (Volume 2.3 §7) ----
const mainView = ref<'vitals' | 'assessment' | 'notes' | 'none'>('none');

const vitals = ref<Vital[]>([
    {
        id: 'v1',
        patientId: 'p1',
        temperature: 36.8,
        heartRate: 78,
        respiratoryRate: 16,
        sbp: 145,
        dbp: 90,
        spo2: 97,
        painScore: 2,
        weight: 72,
        recordedAt: '2026-08-08T08:00:00',
    },
]);

// Vitals form state
const vitalForm = ref({
    temperature: 36.5,
    heartRate: 75,
    respiratoryRate: 16,
    sbp: 120,
    dbp: 80,
    spo2: 98,
    painScore: 0,
    weight: 70,
});

// Out-of-range flags (Volume 2.3 §7.2 — icon + label, never color alone)
// Only returns a status for out-of-range values; normal values return null (no badge).
function vitalFlag(vital: keyof typeof vitalForm.value, value: number): 'warning' | 'critical' | null {
    switch (vital) {
        case 'temperature':
            if (value < 35 || value > 38.5) return 'critical';
            if (value < 36.1 || value > 37.2) return 'warning';
            return null;
        case 'heartRate':
            if (value < 40 || value > 130) return 'critical';
            if (value < 60 || value > 100) return 'warning';
            return null;
        case 'respiratoryRate':
            if (value < 8 || value > 30) return 'critical';
            if (value < 12 || value > 20) return 'warning';
            return null;
        case 'sbp':
            if (value < 80 || value > 180) return 'critical';
            if (value < 90 || value > 120) return 'warning';
            return null;
        case 'dbp':
            if (value < 50 || value > 110) return 'critical';
            if (value < 60 || value > 80) return 'warning';
            return null;
        case 'spo2':
            if (value < 90) return 'critical';
            if (value < 95) return 'warning';
            return null;
        case 'painScore':
            if (value >= 7) return 'critical';
            if (value >= 4) return 'warning';
            return null;
        default:
            return null;
    }
}

function openVitals() {
    mainView.value = 'vitals';
}

function saveVitals() {
    if (!selectedPatient.value) return;
    vitals.value.push({
        id: `v${Date.now()}`,
        patientId: selectedPatient.value.id,
        ...vitalForm.value,
        recordedAt: new Date().toISOString(),
    });
    mainView.value = 'none';
}

// ---- MAR (Volume 2.3 §8) — fetched from GET /nursing/mar ----
const mar = computed(() => medicationStore.mar);

function loadMar() {
    if (selectedPatient.value) {
        medicationStore.fetchMar(selectedPatient.value.id);
    }
}

const showMar = ref(false);

function marStatusVariant(status: MarMedication['status']): 'critical' | 'warning' | 'success' | 'info' {
    switch (status) {
        case 'given': return 'success';
        case 'overdue': return 'critical';
        case 'missed': return 'warning';
        case 'held': return 'warning';
        case 'due': return 'warning';
        case 'omitted': return 'info';
        case 'refused': return 'info';
    }
}

async function administerMedication(med: MarMedication) {
    // Volume 2.3 §8.2 — 5 Rights verification
    // In production: scan patient wristband + medication barcode
    await medicationStore.administerMedication(med.id, {
        rightPatient: true,
        rightMedication: true,
        rightDose: true,
        rightRoute: true,
        rightTime: true,
    });
}

// ---- Nursing notes (Volume 2.3 §10) ----
const noteForm = ref({
    situation: '',
    background: '',
    assessment: '',
    recommendation: '',
});

const notes = ref<{ id: string; patientId: string; title: string; date: string; status: 'draft' | 'signed' }[]>([
    { id: 'n1', patientId: 'p1', title: 'Shift note', date: '2026-08-08', status: 'signed' },
]);

function openNotes() {
    mainView.value = 'notes';
}

function saveNote() {
    if (!selectedPatient.value) return;
    notes.value.push({
        id: `n${Date.now()}`,
        patientId: selectedPatient.value.id,
        title: 'Shift note',
        date: new Date().toISOString().slice(0, 10),
        status: 'draft',
    });
    noteForm.value = { situation: '', background: '', assessment: '', recommendation: '' };
    mainView.value = 'none';
}

// ---- Assessment (Volume 2.3 §6) ----
const assessmentForm = ref({
    type: 'shift' as 'admission' | 'shift' | 'focused',
    reason: '',
    findings: '',
    riskNotes: '',
});

function openAssessment() {
    mainView.value = 'assessment';
}

function saveAssessment() {
    mainView.value = 'none';
    assessmentForm.value = { type: 'shift', reason: '', findings: '', riskNotes: '' };
}
</script>

<template>
    <AppShell>
        <div class="flex h-full gap-4">
            <!-- ============================================================
                 CONTEXT PANE (Volume 2.3 §4.1)
                 ============================================================ -->
            <aside class="flex w-80 flex-col rounded-lg border border-border bg-surface">
                <Tabs default-value="patients" class="flex flex-1 flex-col">
                    <TabsList class="m-2 mb-0 w-auto justify-start">
                        <TabsTrigger value="patients">{{ t('clinician.patients') }}</TabsTrigger>
                        <TabsTrigger value="tasks">{{ t('nursing.tasks') }} ({{ tasks.length }})</TabsTrigger>
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

                    <!-- Tasks tab -->
                    <TabsContent value="tasks" class="flex flex-1 flex-col overflow-hidden">
                        <Queue :items="taskQueue" @open="handleTaskOpen" />
                    </TabsContent>
                </Tabs>
            </aside>

            <!-- ============================================================
                 MAIN PANE (Volume 2.3 §4.2)
                 ============================================================ -->
            <main class="flex flex-1 flex-col rounded-lg border border-border bg-surface">
                <!-- No patient selected -->
                <div v-if="!selectedPatient" class="flex flex-1 items-center justify-center">
                    <EmptyState
                        title="Select a patient"
                        description="Choose a patient from the list or tasks to begin."
                        illustration="users"
                    />
                </div>

                <!-- Patient selected -->
                <template v-else>
                    <!-- Patient banner (Volume 1.1 §7) -->
                    <div class="flex items-center gap-4 border-b border-border px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-foreground">{{ selectedPatient.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ t('patient.mrn') }}: {{ selectedPatient.mrn }}</span>
                            <span class="text-xs text-muted-foreground">{{ selectedPatient.ward }} · {{ selectedPatient.bed }}</span>
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

                    <!-- Action bar -->
                    <div class="flex flex-wrap gap-2 border-b border-border p-3">
                        <Button size="sm" @click="openVitals">{{ t('nursing.record_vitals') }}</Button>
                        <Button size="sm" variant="secondary" @click="openAssessment">{{ t('nursing.new_assessment') }}</Button>
                        <Button size="sm" variant="secondary" @click="openNotes">{{ t('nursing.new_note') }}</Button>
                        <Button size="sm" variant="secondary" @click="showMar = !showMar">{{ t('nursing.mar') }}</Button>
                    </div>

                    <!-- Vitals collection (Volume 2.3 §7) -->
                    <div v-if="mainView === 'vitals'" class="flex-1 overflow-auto p-4">
                        <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('nursing.record_vitals') }}</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div v-for="(vital, key) in vitalForm" :key="key" class="space-y-1">
                                <label class="block text-xs font-medium text-muted-foreground">
                                    {{ t(`nursing.vital_${key}`) }}
                                </label>
                                <div class="flex items-center gap-2">
                                    <Input v-model.number="vitalForm[key as keyof typeof vitalForm]" type="number" class="clinical-value" />
                                    <span v-if="vitalFlag(key as keyof typeof vitalForm, vital) as string" class="shrink-0">
                                        <StatusBadge :status="(vitalFlag(key as keyof typeof vitalForm, vital) ?? 'warning') as 'warning' | 'critical'" />
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 flex gap-3">
                            <Button size="sm" @click="saveVitals">{{ t('common.save') }}</Button>
                            <Button size="sm" variant="secondary" @click="mainView = 'none'">{{ t('common.cancel') }}</Button>
                        </div>
                    </div>

                    <!-- Assessment (Volume 2.3 §6) -->
                    <div v-else-if="mainView === 'assessment'" class="flex-1 overflow-auto p-4">
                        <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('nursing.new_assessment') }}</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.assessment_type') }}</label>
                                <div class="flex gap-2">
                                    <Button
                                        v-for="type in ['admission', 'shift', 'focused'] as const"
                                        :key="type"
                                        size="sm"
                                        :variant="assessmentForm.type === type ? 'default' : 'outline'"
                                        @click="assessmentForm.type = type"
                                    >
                                        {{ t(`nursing.assessment_${type}`) }}
                                    </Button>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.reason') }}</label>
                                <Textarea v-model="assessmentForm.reason" class="min-h-16" :placeholder="t('nursing.reason_placeholder')" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.findings') }}</label>
                                <Textarea v-model="assessmentForm.findings" class="min-h-24" :placeholder="t('nursing.findings_placeholder')" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.risk_notes') }}</label>
                                <Textarea v-model="assessmentForm.riskNotes" class="min-h-16" :placeholder="t('nursing.risk_notes_placeholder')" />
                            </div>
                            <div class="flex gap-3">
                                <Button size="sm" @click="saveAssessment">{{ t('common.save') }}</Button>
                                <Button size="sm" variant="secondary" @click="mainView = 'none'">{{ t('common.cancel') }}</Button>
                            </div>
                        </div>
                    </div>

                    <!-- Nursing notes (Volume 2.3 §10) -->
                    <div v-else-if="mainView === 'notes'" class="flex-1 overflow-auto p-4">
                        <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('nursing.new_note') }}</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.situation') }}</label>
                                <Textarea v-model="noteForm.situation" class="min-h-16" :placeholder="t('nursing.situation_placeholder')" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.background') }}</label>
                                <Textarea v-model="noteForm.background" class="min-h-16" :placeholder="t('nursing.background_placeholder')" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.assessment') }}</label>
                                <Textarea v-model="noteForm.assessment" class="min-h-16" :placeholder="t('nursing.assessment_placeholder')" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ t('nursing.recommendation') }}</label>
                                <Textarea v-model="noteForm.recommendation" class="min-h-16" :placeholder="t('nursing.recommendation_placeholder')" />
                            </div>
                            <div class="flex gap-3">
                                <Button size="sm" @click="saveNote">{{ t('common.save') }}</Button>
                                <Button size="sm" variant="secondary" @click="mainView = 'none'">{{ t('common.cancel') }}</Button>
                            </div>
                        </div>
                    </div>

                    <!-- Default: recent vitals -->
                    <div v-else class="flex-1 overflow-auto p-4">
                        <h3 class="mb-4 text-sm font-semibold text-foreground">{{ t('nursing.recent_vitals') }}</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <Card v-for="v in vitals.filter((v) => v.patientId === selectedPatient?.id).slice(-1)" :key="v.id">
                                <CardHeader>
                                    <CardTitle class="text-sm text-muted-foreground">{{ t('nursing.last_vitals') }}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <dl class="space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ t('nursing.vital_temperature') }}</dt>
                                            <dd class="clinical-value font-medium text-foreground">{{ v.temperature }} °C</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ t('nursing.vital_heartRate') }}</dt>
                                            <dd class="clinical-value font-medium text-foreground">{{ v.heartRate }} bpm</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ t('nursing.vital_respiratoryRate') }}</dt>
                                            <dd class="clinical-value font-medium text-foreground">{{ v.respiratoryRate }}/min</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ t('nursing.vital_sbp') }}/{{ t('nursing.vital_dbp') }}</dt>
                                            <dd class="clinical-value font-medium text-foreground">{{ v.sbp }}/{{ v.dbp }} mmHg</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ t('nursing.vital_spo2') }}</dt>
                                            <dd class="clinical-value font-medium text-foreground">{{ v.spo2 }}%</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ t('nursing.vital_painScore') }}</dt>
                                            <dd class="clinical-value font-medium text-foreground">{{ v.painScore }}/10</dd>
                                        </div>
                                    </dl>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </template>
            </main>

            <!-- ============================================================
                 DETAIL PANE — MAR (Volume 2.3 §4.3, §8)
                 ============================================================ -->
            <aside v-if="showMar" class="flex w-96 flex-col rounded-lg border border-border bg-surface">
                <div class="flex items-center justify-between border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold text-foreground">{{ t('nursing.mar') }}</h3>
                    <Button size="sm" variant="ghost" @click="showMar = false">{{ t('common.close') }}</Button>
                </div>
                <div class="flex-1 overflow-auto p-4">
                    <div class="space-y-2">
                        <div v-for="med in mar" :key="med.id" class="rounded-md border border-border p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-foreground">{{ med.name }}</span>
                                <StatusBadge :status="marStatusVariant(med.status)" />
                            </div>
                            <div class="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                <span class="clinical-value">{{ med.dose }}</span>
                                <span>{{ med.route }}</span>
                                <span>Due {{ med.dueTime }}</span>
                            </div>
                            <Button
                                v-if="med.status === 'due' || med.status === 'overdue'"
                                size="sm"
                                class="mt-2 w-full"
                                @click="administerMedication(med)"
                            >
                                {{ t('nursing.administer') }}
                            </Button>
                        </div>
                    </div>
                    <Alert v-if="mar.some((m) => m.status === 'overdue')" variant="critical" title="Overdue medications" description="Some medications are past their due window." class="mt-4" />
                </div>
            </aside>
        </div>
    </AppShell>
</template>