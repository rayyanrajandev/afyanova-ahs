/**
 * Auth Layout (Volume 2.9 §5, Volume 3.6 §3 — 2027 Global Enterprise Edition)
 * ============================================================================
 * State-of-the-art clinical authentication portal layout:
 * - Left Pane: Live Hospital Mesh Node Radar, Cryptographic Security HUD & Testimonial
 * - Right Pane: Multi-modal authentication container with terminal telemetry & PHI guardrails
 */

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  Activity,
  Cpu,
  Database,
  HeartPulse,
  Lock,
  Moon,
  Radio,
  Server,
  ShieldCheck,
  Sparkles,
  Sun,
  Wifi,
} from "lucide-vue-next";
import { setLocale } from "@/i18n";
import { useUiStore } from "@/stores/uiStore";

const { t, locale } = useI18n({ useScope: "global" });
const uiStore = useUiStore();

const isDark = computed(() => uiStore.theme === "dark");

// Simulated Live Hospital Mesh Nodes
const hospitalNodes = [
  { name: "OPD Clinician Hub", status: "Active", ping: "8.4ms", load: "14 Encounters" },
  { name: "Emergency Trauma Bay", status: "STAT Priority", ping: "6.2ms", load: "3 Active Triage" },
  { name: "Diagnostic Lab (LIS)", status: "Analyzers Connected", ping: "11.1ms", load: "48 Tests/hr" },
  { name: "Central Pharmacy", status: "FEFO Guard Active", ping: "9.5ms", load: "128 Batches" },
];

const activeNodeIndex = ref<number>(0);
let nodeInterval: any = null;

onMounted(() => {
  nodeInterval = setInterval(() => {
    activeNodeIndex.value = (activeNodeIndex.value + 1) % hospitalNodes.length;
  }, 3500);
});

onUnmounted(() => {
  if (nodeInterval) clearInterval(nodeInterval);
});

function toggleTheme() {
  uiStore.setTheme(isDark.value ? "light" : "dark");
}

function switchLocale(next: "en" | "sw") {
  locale.value = next;
  setLocale(next);
  uiStore.setLocale(next);
}
</script>

<template>
  <div class="flex min-h-screen w-full bg-background text-foreground selection:bg-primary/20 selection:text-foreground">
    <!-- Skip to main content -->
    <a
      href="#auth-content"
      class="sr-only-focusable fixed top-3 left-3 z-[100] rounded-md bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground shadow-lg ring-2 ring-ring focus:not-sr-only"
    >
      {{ t('landing.skip_to_content') }}
    </a>

    <!-- Left Brand & Hospital Mesh Radar Pane (lg+) -->
    <div
      class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-slate-950 p-10 text-white lg:flex xl:p-14 border-r border-border/20"
    >
      <!-- Cyber-Clinical Grid & Radial Glows -->
      <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(#1e293b_1px,transparent_1px)] [background-size:24px_24px] opacity-40" aria-hidden="true" />
      <div class="pointer-events-none absolute -top-40 -left-40 h-96 w-96 rounded-full bg-cyan-500/15 blur-3xl" aria-hidden="true" />
      <div class="pointer-events-none absolute -bottom-40 -right-40 h-96 w-96 rounded-full bg-teal-500/12 blur-3xl" aria-hidden="true" />

      <!-- Brand Header -->
      <div class="relative z-10 flex items-center justify-between">
        <Link href="/" class="inline-flex items-center gap-3 group">
          <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 via-teal-500 to-emerald-600 text-white shadow-lg shadow-cyan-500/25 transition-transform group-hover:scale-105">
            <HeartPulse class="h-6 w-6" />
          </div>
          <div>
            <div class="flex items-baseline gap-2">
              <span class="text-xl font-extrabold tracking-tight text-white font-mono">AFYANOVA</span>
              <span class="rounded bg-cyan-500/20 px-1.5 py-0.5 text-[10px] font-bold tracking-wider text-cyan-300">AHS 2027</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium">Enterprise Clinical Operating System</p>
          </div>
        </Link>

        <!-- Live Node Heartbeat -->
        <div class="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs text-emerald-400 font-mono">
          <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
          </span>
          <span>99.99% Operational</span>
        </div>
      </div>

      <!-- Center: Hospital Mesh Node Radar & Testimonial -->
      <div class="relative z-10 my-auto max-w-lg space-y-7">
        <!-- Testimonial Quote -->
        <div class="space-y-3.5">
          <blockquote class="text-xl font-semibold leading-relaxed tracking-tight text-white xl:text-2xl">
            "{{ t('auth.testimonial_quote') }}"
          </blockquote>
          <div class="flex items-center gap-3 pt-1">
            <div class="h-10 w-10 rounded-full bg-cyan-500/20 border border-cyan-500/40 flex items-center justify-center text-sm font-bold text-cyan-300">
              DM
            </div>
            <div>
              <div class="text-sm font-semibold text-white">{{ t('auth.testimonial_author') }}</div>
              <div class="text-xs text-slate-400">{{ t('auth.testimonial_role') }}</div>
            </div>
          </div>
        </div>

        <!-- Live Hospital Mesh Node Telemetry Card -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-4.5 backdrop-blur-xl shadow-2xl space-y-3.5">
          <div class="flex items-center justify-between text-xs border-b border-white/10 pb-2.5">
            <div class="flex items-center gap-2">
              <Radio class="h-3.5 w-3.5 text-cyan-400 animate-pulse" />
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-200">Hospital Mesh Network Radar</span>
            </div>
            <span class="text-[10px] font-mono text-cyan-400">Node Cluster: Dar-Main-04</span>
          </div>

          <!-- Active Node Readout -->
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div
              v-for="(node, idx) in hospitalNodes"
              :key="node.name"
              class="rounded-xl border p-2.5 transition-all duration-300"
              :class="activeNodeIndex === idx ? 'border-cyan-500/60 bg-cyan-500/10 shadow-sm' : 'border-white/5 bg-white/5 opacity-70'"
            >
              <div class="flex items-center justify-between text-[11px] font-bold">
                <span :class="activeNodeIndex === idx ? 'text-cyan-300' : 'text-slate-300'">{{ node.name }}</span>
                <span class="text-[10px] font-mono text-emerald-400">{{ node.ping }}</span>
              </div>
              <div class="text-[10px] text-slate-400 pt-1 flex items-center justify-between">
                <span>{{ node.status }}</span>
                <span class="font-mono text-slate-500">{{ node.load }}</span>
              </div>
            </div>
          </div>

          <!-- Cryptographic & Security Trust Badges -->
          <div class="grid grid-cols-2 gap-2 pt-1 text-[11px] text-slate-300 border-t border-white/10">
            <div class="flex items-center gap-2">
              <Lock class="h-3.5 w-3.5 text-cyan-400 shrink-0" />
              <span>{{ t('auth.security_aes') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <ShieldCheck class="h-3.5 w-3.5 text-teal-400 shrink-0" />
              <span>{{ t('auth.security_rbac') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <Activity class="h-3.5 w-3.5 text-emerald-400 shrink-0" />
              <span>{{ t('auth.security_audit') }}</span>
            </div>
            <div class="flex items-center gap-2">
              <Database class="h-3.5 w-3.5 text-cyan-400 shrink-0" />
              <span>{{ t('auth.security_fhir') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Left Pane Footer -->
      <div class="relative z-10 flex items-center justify-between text-xs text-slate-500 pt-4 border-t border-white/10 font-mono">
        <span>Tanzania MoH · HL7 FHIR R4 · NEMLIT 2024</span>
        <span>v2027.2 Enterprise LTS</span>
      </div>
    </div>

    <!-- Right Form Pane -->
    <div class="relative flex w-full flex-col justify-between overflow-y-auto p-6 sm:p-10 lg:w-1/2 lg:p-12 xl:p-16">
      <!-- Top Controls Bar -->
      <header class="flex items-center justify-between pb-6">
        <!-- Mobile Brand -->
        <Link href="/" class="flex items-center gap-2 lg:hidden">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-500 to-teal-600 text-white shadow-xs">
            <HeartPulse class="h-4 w-4" />
          </div>
          <div class="flex items-baseline gap-1">
            <span class="text-sm font-bold tracking-tight text-foreground font-mono">AFYANOVA</span>
            <span class="rounded bg-primary/10 px-1 text-[9px] font-semibold text-primary">AHS</span>
          </div>
        </Link>

        <!-- Desktop Facility Label & Terminal Status -->
        <div class="hidden lg:flex items-center gap-2 text-xs text-muted-foreground font-medium">
          <span class="h-2 w-2 rounded-full bg-emerald-500" />
          <span>{{ t('auth.facility_default') }}</span>
        </div>

        <!-- Controls: Language & Theme -->
        <div class="flex items-center gap-2">
          <div class="inline-flex items-center rounded-lg border border-border bg-card p-0.5 text-xs shadow-xs" role="group" aria-label="Language Selector">
            <button
              type="button"
              class="rounded-md px-2.5 py-1 font-semibold transition-colors cursor-pointer"
              :class="locale === 'en' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
              @click="switchLocale('en')"
            >EN</button>
            <button
              type="button"
              class="rounded-md px-2.5 py-1 font-semibold transition-colors cursor-pointer"
              :class="locale === 'sw' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
              @click="switchLocale('sw')"
            >SW</button>
          </div>
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-card text-muted-foreground hover:bg-accent hover:text-accent-foreground shadow-xs cursor-pointer transition-colors focus-ring"
            :title="isDark ? 'Switch to Light Theme' : 'Switch to Dark Theme'"
            :aria-label="isDark ? 'Switch to Light Theme' : 'Switch to Dark Theme'"
            @click="toggleTheme"
          >
            <Sun v-if="isDark" class="h-4 w-4" />
            <Moon v-else class="h-4 w-4" />
          </button>
        </div>
      </header>

      <!-- Form Slot -->
      <main id="auth-content" class="my-auto mx-auto w-full max-w-md focus:outline-none" tabindex="-1">
        <slot />
      </main>

      <!-- Right Footer: PHI Protection Notice -->
      <footer class="pt-8 text-center text-[11px] text-muted-foreground space-y-1">
        <div class="flex items-center justify-center gap-1.5 text-primary">
          <ShieldCheck class="h-3.5 w-3.5" />
          <span class="font-medium">{{ t('auth.compliance_notice') }}</span>
        </div>
        <p class="font-mono text-[10px] text-muted-foreground/80">© 2027 Afyanova Advanced Health System. Institutional Node Zero-Trust Enforced.</p>
      </footer>
    </div>
  </div>
</template>