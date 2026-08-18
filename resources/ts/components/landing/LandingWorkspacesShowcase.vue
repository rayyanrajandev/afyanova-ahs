<script setup lang="ts">
import { ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  ArrowRight,
  Boxes,
  CheckCircle2,
  HeartPulse,
  Microscope,
  Pill,
  Receipt,
  Scan,
  Sparkles,
  Stethoscope,
  Users,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";

const { t, tm } = useI18n({ useScope: "global" });

const activeTab = ref<string>("clinician");

interface WorkspaceMeta {
  id: string;
  icon: any;
  route: string;
  colorClass: string;
  bgLightClass: string;
}

const workspaceList: WorkspaceMeta[] = [
  {
    id: "reception",
    icon: Users,
    route: "/reception",
    colorClass: "text-blue-600 dark:text-blue-400",
    bgLightClass: "bg-blue-500/10",
  },
  {
    id: "clinician",
    icon: Stethoscope,
    route: "/clinician",
    colorClass: "text-teal-600 dark:text-teal-400",
    bgLightClass: "bg-teal-500/10",
  },
  {
    id: "nursing",
    icon: HeartPulse,
    route: "/nursing",
    colorClass: "text-emerald-600 dark:text-emerald-400",
    bgLightClass: "bg-emerald-500/10",
  },
  {
    id: "laboratory",
    icon: Microscope,
    route: "/laboratory",
    colorClass: "text-amber-600 dark:text-amber-400",
    bgLightClass: "bg-amber-500/10",
  },
  {
    id: "radiology",
    icon: Scan,
    route: "/radiology",
    colorClass: "text-cyan-600 dark:text-cyan-400",
    bgLightClass: "bg-cyan-500/10",
  },
  {
    id: "pharmacy",
    icon: Pill,
    route: "/pharmacy",
    colorClass: "text-indigo-600 dark:text-indigo-400",
    bgLightClass: "bg-indigo-500/10",
  },
  {
    id: "cashier",
    icon: Receipt,
    route: "/cashier",
    colorClass: "text-rose-600 dark:text-rose-400",
    bgLightClass: "bg-rose-500/10",
  },
  {
    id: "inventory",
    icon: Boxes,
    route: "/inventory",
    colorClass: "text-purple-600 dark:text-purple-400",
    bgLightClass: "bg-purple-500/10",
  },
];

function getFeatures(wsId: string): string[] {
  const feats = tm(`landing.workspaces.${wsId}.features`);
  if (Array.isArray(feats)) return feats as string[];
  return [];
}

function handleTabKeydown(e: KeyboardEvent, currentIndex: number) {
  const count = workspaceList.length;
  let nextIndex = currentIndex;

  if (e.key === "ArrowRight" || e.key === "ArrowDown") {
    e.preventDefault();
    nextIndex = (currentIndex + 1) % count;
  } else if (e.key === "ArrowLeft" || e.key === "ArrowUp") {
    e.preventDefault();
    nextIndex = (currentIndex - 1 + count) % count;
  } else if (e.key === "Home") {
    e.preventDefault();
    nextIndex = 0;
  } else if (e.key === "End") {
    e.preventDefault();
    nextIndex = count - 1;
  }

  if (nextIndex !== currentIndex) {
    activeTab.value = workspaceList[nextIndex].id;
    const tabEl = document.getElementById(`tab-${workspaceList[nextIndex].id}`);
    tabEl?.focus();
  }
}
</script>

<template>
  <section id="workspaces" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-10 scroll-mt-24">
    <!-- Header -->
    <div class="text-center space-y-3 max-w-3xl mx-auto">
      <div
        class="inline-flex items-center gap-1.5 rounded-full bg-teal-500/10 px-3 py-0.5 text-xs font-semibold text-teal-600 dark:text-teal-400"
      >
        <Sparkles class="h-3 w-3" />
        <span>{{ t("landing.workspaces_badge") }}</span>
      </div>
      <h2
        class="text-3xl font-extrabold tracking-tight text-foreground sm:text-4xl"
      >
        {{ t("landing.workspaces_title") }}
      </h2>
      <p class="text-base text-muted-foreground leading-relaxed">
        {{ t("landing.workspaces_subtitle") }}
      </p>
    </div>

    <!-- Tab Buttons Grid -->
    <div
      role="tablist"
      aria-label="Clinical Workspaces"
      class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2"
    >
      <button
        v-for="(ws, idx) in workspaceList"
        :id="`tab-${ws.id}`"
        :key="ws.id"
        type="button"
        role="tab"
        :aria-selected="activeTab === ws.id"
        :aria-controls="`panel-${ws.id}`"
        :tabindex="activeTab === ws.id ? 0 : -1"
        class="flex flex-col items-center gap-2 rounded-xl border p-3 text-center transition-all cursor-pointer focus-ring"
        :class="
          activeTab === ws.id
            ? 'border-primary bg-primary/10 shadow-sm'
            : 'border-border/60 bg-card hover:bg-muted/40 text-muted-foreground'
        "
        @click="activeTab = ws.id"
        @keydown="handleTabKeydown($event, idx)"
      >
        <div
          class="flex h-8 w-8 items-center justify-center rounded-lg transition-transform"
          :class="[ws.bgLightClass, activeTab === ws.id ? 'scale-110' : '']"
        >
          <component :is="ws.icon" class="h-4 w-4" :class="ws.colorClass" />
        </div>
        <span
          class="text-[11px] font-bold tracking-tight capitalize"
          :class="activeTab === ws.id ? 'text-foreground font-extrabold' : 'text-muted-foreground'"
        >
          {{ ws.id }}
        </span>
      </button>
    </div>

    <!-- Active Workspace Preview Card Panel -->
    <div
      v-for="ws in workspaceList"
      v-show="activeTab === ws.id"
      :id="`panel-${ws.id}`"
      :key="ws.id"
      role="tabpanel"
      :aria-labelledby="`tab-${ws.id}`"
      tabindex="0"
      class="rounded-2xl border border-border bg-card p-6 sm:p-8 shadow-sm transition-all focus:outline-none"
    >
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
        <!-- Left details -->
        <div class="lg:col-span-6 space-y-5">
          <div class="space-y-2">
            <div class="flex items-center gap-2">
              <span
                class="rounded-md px-2.5 py-0.5 text-xs font-semibold"
                :class="[ws.bgLightClass, ws.colorClass]"
              >
                {{ t(`landing.workspaces.${ws.id}.tag`) }}
              </span>
              <span class="text-xs text-muted-foreground font-mono">RBAC Gated</span>
            </div>
            <h3 class="text-2xl font-bold tracking-tight text-foreground">
              {{ t(`landing.workspaces.${ws.id}.title`) }}
            </h3>
            <p class="text-sm text-muted-foreground leading-relaxed">
              {{ t(`landing.workspaces.${ws.id}.desc`) }}
            </p>
          </div>

          <!-- Feature check list -->
          <div class="space-y-2.5 pt-1">
            <div
              v-for="(feat, fIdx) in getFeatures(ws.id)"
              :key="fIdx"
              class="flex items-center gap-2.5 text-xs font-medium text-foreground"
            >
              <div
                class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 shrink-0"
              >
                <CheckCircle2 class="h-3.5 w-3.5" />
              </div>
              <span>{{ feat }}</span>
            </div>
          </div>

          <!-- Launch Button -->
          <div class="pt-2">
            <Link :href="ws.route">
              <Button class="gap-2 text-xs font-bold px-5 h-10 cursor-pointer">
                <span>{{
                  t("landing.workspaces_enter", {
                    name: t(`landing.workspaces.${ws.id}.title`),
                  })
                }}</span>
                <ArrowRight class="h-3.5 w-3.5" />
              </Button>
            </Link>
          </div>
        </div>

        <!-- Right Realistic UI Mockup Card -->
        <div class="lg:col-span-6">
          <div
            class="rounded-xl border border-border/80 bg-surface p-5 space-y-4 shadow-inner"
          >
            <!-- Header strip -->
            <div class="flex items-center justify-between border-b border-border/60 pb-3">
              <div class="flex items-center gap-2">
                <div
                  class="flex h-7 w-7 items-center justify-center rounded-lg"
                  :class="[ws.bgLightClass, ws.colorClass]"
                >
                  <component :is="ws.icon" class="h-3.5 w-3.5" />
                </div>
                <span class="font-bold text-xs text-foreground"
                  >{{ t(`landing.workspaces.${ws.id}.title`) }} View</span
                >
              </div>
              <span
                class="rounded bg-emerald-500/10 px-2 py-0.5 text-[10px] font-mono font-bold text-emerald-600"
                >Active Session</span
              >
            </div>

            <!-- Workspace Snapshot Simulation Elements -->
            <div class="space-y-2.5 text-xs">
              <div
                class="rounded-lg border border-border/60 bg-card p-3 flex items-center justify-between"
              >
                <div>
                  <div class="font-bold text-foreground text-[11px]">
                    Current Queue / Worklist Items
                  </div>
                  <div class="text-[10px] text-muted-foreground">
                    Auto-synchronized with facility patient flow bus
                  </div>
                </div>
                <span
                  class="text-xs font-mono font-extrabold text-foreground px-2 py-1 bg-muted/40 rounded border border-border/50"
                  >12 Active</span
                >
              </div>

              <div
                class="rounded-lg border border-border/60 bg-card p-3 space-y-1.5"
              >
                <div class="flex items-center justify-between text-[11px]">
                  <span class="font-bold text-foreground"
                    >Patient Record Link</span
                  >
                  <span class="font-mono text-[10px] text-muted-foreground"
                    >MRN-88421</span
                  >
                </div>
                <div
                  class="text-[10px] font-mono text-teal-600 dark:text-teal-400 bg-teal-500/5 p-2 rounded border border-teal-500/20"
                >
                  Action: {{ t(`landing.workspaces.${ws.id}.tag`) }} verified.
                  Realtime updates streaming.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
