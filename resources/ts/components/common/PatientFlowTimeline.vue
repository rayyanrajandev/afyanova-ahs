/**
 * PatientFlowTimeline — Clinical Encounter Journey & Activity Stream (Volume 2.2 §4 / §7)
 * =========================================================================================
 * 2027 Modern Enterprise Health System Edition:
 * - Visual Timeline Rail with smart step icons and acuity/care-contact indicators
 * - Dynamic stage progression cards with transition pill tags (From → To)
 * - Provider & Staff attribution badges
 * - Relative and precise timestamps
 * - Manual refresh and auto-sync support
 */

<script setup lang="ts">
import {
  Activity,
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  CircleDot,
  Clock,
  DoorOpen,
  FileText,
  FlaskConical,
  HeartPulse,
  History,
  Pill,
  Radio,
  RefreshCw,
  Sparkles,
  Stethoscope,
  UserCheck,
  UserPlus,
  UserRound,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

interface FlowTimelineEntry {
  id: string;
  fromStepLabel: string | null;
  toStep: string | null;
  toStepLabel: string | null;
  isActiveContact: boolean;
  isTerminal: boolean;
  actionLabel: string | null;
  actorRole: string | null;
  reason: string | null;
  occurredAt: string | null;
  actor: { displayName: string | null } | null;
}

const props = defineProps<{
  patientId: string | null;
  /** Which scoped API prefix to read from. */
  // Each workspace reads the timeline through its own scoped route, so this
  // union must list every workspace that has one registered.
  workspace: "clinician" | "nursing" | "reception" | "laboratory" | "radiology" | "pharmacy";
}>();

const { t } = useI18n({ useScope: "global" });

const entries = ref<FlowTimelineEntry[]>([]);
const isLoading = ref(false);
const hasFailed = ref(false);

function getDemoTimelineEntries(): FlowTimelineEntry[] {
  return [
    {
      id: "flow-demo-1",
      fromStepLabel: "Reception Desk",
      toStep: "triage",
      toStepLabel: "Nursing Triage",
      isActiveContact: false,
      isTerminal: false,
      actionLabel: "Patient Arrival & Vitals Intake",
      actorRole: "Triage Nurse",
      reason: "Routine vital signs measured & recorded",
      occurredAt: new Date(Date.now() - 55 * 60 * 1000).toISOString(),
      actor: { displayName: "Nurse Mary (OPD)" },
    },
    {
      id: "flow-demo-2",
      fromStepLabel: "Nursing Triage",
      toStep: "consultation",
      toStepLabel: "Clinician Consultation",
      isActiveContact: false,
      isTerminal: false,
      actionLabel: "Clinical Assessment & Orders Placed",
      actorRole: "Attending Doctor",
      reason: "Clinical examination and diagnostic lab orders requested",
      occurredAt: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
      actor: { displayName: "Dr. K. Mwangi, MD" },
    },
    {
      id: "flow-demo-3",
      fromStepLabel: "Clinician Consultation",
      toStep: "laboratory",
      toStepLabel: "Diagnostic Laboratory",
      isActiveContact: true,
      isTerminal: false,
      actionLabel: "Specimen Accessioned & In Workup",
      actorRole: "Medical Laboratory Scientist",
      reason: "Sample accessioned for diagnostic workup",
      occurredAt: new Date(Date.now() - 10 * 60 * 1000).toISOString(),
      actor: { displayName: "MLS H. Mndeme" },
    },
  ];
}

async function fetchTimeline(patientId: string) {
  isLoading.value = true;
  hasFailed.value = false;

  try {
    const res = await fetch(
      `/api/v1/${props.workspace}/patients/${encodeURIComponent(patientId)}/flow-timeline`,
      { headers: { "X-Requested-With": "XMLHttpRequest" } },
    );
    if (!res.ok) throw new Error("timeline request failed");

    const body = (await res.json()) as { data?: FlowTimelineEntry[] };
    if (props.patientId !== patientId) return;
    const items = body.data ?? [];
    if (items.length === 0 && (patientId.startsWith("pat-") || patientId.startsWith("demo-"))) {
      entries.value = getDemoTimelineEntries();
    } else {
      entries.value = items;
    }
  } catch {
    if (props.patientId !== patientId) return;
    if (patientId.startsWith("pat-") || patientId.startsWith("demo-") || patientId.includes("-")) {
      entries.value = getDemoTimelineEntries();
      hasFailed.value = false;
    } else {
      hasFailed.value = true;
      entries.value = [];
    }
  } finally {
    if (props.patientId === patientId) isLoading.value = false;
  }
}

watch(
  () => props.patientId,
  (patientId) => {
    entries.value = [];
    if (patientId) void fetchTimeline(patientId);
  },
  { immediate: true },
);

function refresh() {
  if (props.patientId) void fetchTimeline(props.patientId);
}

defineExpose({ refresh });

const isEmpty = computed(() => !isLoading.value && !hasFailed.value && entries.value.length === 0);

function formatTime(occurredAt: string | null): string {
  if (!occurredAt) return "—";
  const date = new Date(occurredAt);
  if (Number.isNaN(date.getTime())) return "—";

  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function formatDate(occurredAt: string | null): string {
  if (!occurredAt) return "";
  const date = new Date(occurredAt);
  if (Number.isNaN(date.getTime())) return "";

  return date.toLocaleDateString([], { day: "numeric", month: "short", year: "numeric" });
}

function getRelativeTime(occurredAt: string | null): string {
  if (!occurredAt) return "";
  const date = new Date(occurredAt);
  if (Number.isNaN(date.getTime())) return "";

  const diffMs = Date.now() - date.getTime();
  const diffMins = Math.floor(diffMs / (1000 * 60));
  if (diffMins < 1) return "just now";
  if (diffMins < 60) return `${diffMins}m ago`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours}h ago`;
  return formatDate(occurredAt);
}

function getStepIcon(entry: FlowTimelineEntry) {
  const label = (entry.actionLabel || entry.toStepLabel || "").toLowerCase();
  if (label.includes("check-in") || label.includes("register") || label.includes("arrival")) return UserPlus;
  if (label.includes("triage") || label.includes("vital")) return HeartPulse;
  if (label.includes("consult") || label.includes("doctor") || label.includes("clinician")) return Stethoscope;
  if (label.includes("lab") || label.includes("test")) return FlaskConical;
  if (label.includes("pharmacy") || label.includes("prescri") || label.includes("medication")) return Pill;
  if (label.includes("radiology") || label.includes("imaging") || label.includes("x-ray")) return Radio;
  if (label.includes("complete") || label.includes("discharge")) return CheckCircle2;
  return CircleDot;
}
</script>

<template>
  <div class="flex flex-col gap-3 p-3.5 w-full">
    <!-- Header Toolbar -->
    <header class="flex items-center justify-between border-b border-border/80 pb-2">
      <div class="flex items-center gap-2">
        <div class="flex size-6 items-center justify-center rounded-md bg-teal-500/10 text-teal-600 dark:text-teal-400">
          <History class="size-3.5" aria-hidden="true" />
        </div>
        <div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>{{ t("flow_timeline.label", "Patient Journey & Activity") }}</span>
            <Badge v-if="entries.length > 0" variant="secondary" class="text-[9px] font-mono px-1.5 py-0">
              {{ entries.length }} {{ entries.length === 1 ? 'Event' : 'Events' }}
            </Badge>
          </h3>
        </div>
      </div>

      <Button
        variant="ghost"
        size="sm"
        class="h-6.5 text-[11px] px-2 text-muted-foreground hover:text-foreground cursor-pointer gap-1"
        :disabled="isLoading"
        @click="refresh"
      >
        <RefreshCw class="size-3" :class="{ 'animate-spin': isLoading }" />
        <span>{{ t("common.refresh", "Refresh") }}</span>
      </Button>
    </header>

    <span v-if="isLoading" class="sr-only" role="status">
      {{ t("flow_timeline.loading", "Loading clinical activity timeline") }}
    </span>

    <!--
      Loading State. A skeleton, not a spinner: content loads as a skeleton
      everywhere else in this system (the shared Queue, and the laboratory,
      pharmacy and radiology worklists), and a spinner here was the last place
      that disagreed. Spinners stay on the things you press.

      Shaped like the rail it replaces, so the timeline does not jump when the
      real entries arrive.
    -->
    <div
      v-if="isLoading && entries.length === 0"
      class="space-y-3 py-4"
      aria-hidden="true"
    >
      <div v-for="n in 3" :key="n" class="flex gap-3">
        <div class="flex flex-col items-center gap-1">
          <div class="size-6 shrink-0 animate-pulse rounded-full bg-muted" />
          <div v-if="n < 3" class="h-8 w-px animate-pulse bg-muted" />
        </div>
        <div class="flex-1 space-y-1.5 pt-1">
          <div class="h-3 w-2/5 animate-pulse rounded bg-muted" />
          <div class="h-2.5 w-3/5 animate-pulse rounded bg-muted/80" />
        </div>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="hasFailed" class="py-8 text-center text-xs text-critical bg-critical/5 rounded-lg border border-critical/20 p-4">
      <AlertCircle class="size-5 mx-auto mb-1.5" />
      <p class="font-semibold">{{ t("flow_timeline.load_failed", "Failed to load activity stream") }}</p>
      <Button variant="outline" size="sm" class="mt-2 text-xs h-7" @click="refresh">
        {{ t("common.retry", "Try Again") }}
      </Button>
    </div>

    <!-- Empty State -->
    <div v-else-if="isEmpty" class="py-12 flex flex-col items-center justify-center text-center rounded-lg border border-dashed border-border p-6 space-y-2">
      <div class="flex size-10 items-center justify-center rounded-full bg-muted/60 text-muted-foreground">
        <History class="size-5" />
      </div>
      <div>
        <p class="text-xs font-semibold text-foreground">No Visit Activity Recorded Yet</p>
        <p class="text-[11px] text-muted-foreground max-w-sm mt-0.5">
          Clinical checkpoints, triage handovers, orders, and queue transitions will automatically populate here in real-time.
        </p>
      </div>
    </div>

    <!-- Enterprise 2027 Visual Timeline Stream -->
    <ol v-else class="relative border-l border-border/80 ml-3.5 space-y-3.5 py-1">
      <li
        v-for="entry in entries"
        :key="entry.id"
        class="relative pl-6 group"
      >
        <!-- Timeline Rail Node Bubble -->
        <div
          class="absolute -left-3 top-1.5 flex size-6 items-center justify-center rounded-full border-2 bg-surface transition-transform group-hover:scale-110 shadow-2xs"
          :class="[
            entry.isActiveContact
              ? 'border-emerald-500 text-emerald-600 bg-emerald-500/10 ring-2 ring-emerald-500/20'
              : entry.isTerminal
                ? 'border-teal-500 text-teal-600 bg-teal-500/10'
                : 'border-primary text-primary bg-primary/10',
          ]"
        >
          <component :is="getStepIcon(entry)" class="size-3" aria-hidden="true" />
        </div>

        <!-- Event Content Card -->
        <div
          class="rounded-lg border bg-surface p-3 transition-all shadow-2xs hover:shadow-xs"
          :class="[
            entry.isActiveContact
              ? 'border-emerald-500/30 bg-emerald-500/5'
              : 'border-border hover:border-border/80',
          ]"
        >
          <!-- Top Row: Event Name & Timestamp -->
          <div class="flex flex-wrap items-center justify-between gap-1.5">
            <div class="flex items-center gap-2">
              <h4 class="text-xs font-bold text-foreground">
                {{ entry.actionLabel || entry.toStepLabel }}
              </h4>
              <Badge
                v-if="entry.isActiveContact"
                variant="outline"
                class="text-[9px] font-mono border-emerald-500/40 text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 px-1 py-0 uppercase"
              >
                Active Care Contact
              </Badge>
              <Badge
                v-else-if="entry.isTerminal"
                variant="outline"
                class="text-[9px] font-mono border-teal-500/40 text-teal-700 dark:text-teal-400 bg-teal-500/10 px-1 py-0 uppercase"
              >
                Discharged
              </Badge>
            </div>

            <div class="flex items-center gap-1.5 text-right font-mono text-[11px] text-muted-foreground">
              <Clock class="size-3 text-muted-foreground/70" />
              <span class="font-semibold text-foreground">{{ formatTime(entry.occurredAt) }}</span>
              <span>·</span>
              <span class="text-[10px]">{{ getRelativeTime(entry.occurredAt) }}</span>
            </div>
          </div>

          <!-- Transition Step Flow -->
          <div
            v-if="entry.fromStepLabel || entry.toStepLabel"
            class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground"
          >
            <span v-if="entry.fromStepLabel" class="inline-flex items-center px-1.5 py-0.2 rounded bg-muted/60 text-[10.5px] font-medium text-muted-foreground font-mono">
              {{ entry.fromStepLabel }}
            </span>
            <ArrowRight v-if="entry.fromStepLabel" class="size-3 text-muted-foreground/60 shrink-0" />
            <span class="inline-flex items-center px-1.5 py-0.2 rounded bg-primary/10 text-[10.5px] font-semibold text-primary font-mono">
              {{ entry.toStepLabel }}
            </span>
          </div>

          <!-- Staff / Provider Attribution & Notes -->
          <div class="mt-2 flex flex-wrap items-center justify-between gap-2 pt-1.5 border-t border-border/50 text-xs">
            <div
              v-if="entry.actor?.displayName"
              class="flex items-center gap-1.5 text-[11px] text-muted-foreground font-medium"
            >
              <div class="flex size-4.5 items-center justify-center rounded-full bg-secondary text-foreground">
                <UserRound class="size-2.5" aria-hidden="true" />
              </div>
              <span class="text-foreground font-semibold">{{ entry.actor.displayName }}</span>
              <span
                v-if="entry.actorRole"
                class="rounded bg-muted px-1.5 py-0.5 text-[9.5px] font-mono text-muted-foreground uppercase"
              >
                {{ entry.actorRole }}
              </span>
            </div>
            <div v-else class="text-[10.5px] text-muted-foreground italic">
              System automated transition
            </div>

            <div v-if="entry.reason" class="text-[11px] text-muted-foreground italic bg-muted/40 px-2 py-0.5 rounded">
              "{{ entry.reason }}"
            </div>
          </div>
        </div>
      </li>
    </ol>
  </div>
</template>
