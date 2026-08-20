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
  HeartPulse,
  Moon,
  Sun,
} from "lucide-vue-next";
import { setLocale } from "@/i18n";
import { useUiStore } from "@/stores/uiStore";

const { t, locale } = useI18n({ useScope: "global" });
const uiStore = useUiStore();

const isDark = computed(() => uiStore.theme === "dark");



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
            </div>
            <p class="text-[11px] text-slate-400 font-medium">Enterprise Clinical Operating System</p>
          </div>
        </Link>
      </div>

      <!-- Center: Testimonial -->
      <div class="relative z-10 my-auto max-w-lg space-y-7">
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
          </div>
        </Link>

        <!-- Controls: Language & Theme -->
        <div class="flex items-center gap-2 ml-auto">
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
    </div>
  </div>
</template>