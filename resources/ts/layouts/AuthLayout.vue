/**
 * Auth Layout (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ======================================================================
 * Clean dual-pane auth layout. Left: brand testimonial. Right: form slot.
 */

<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { HeartPulse, Moon, Sun } from "lucide-vue-next";
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
  <div class="flex min-h-screen w-full bg-background text-foreground">
    <!-- Left Brand Pane (lg+) -->
    <div
      class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-slate-950 p-10 text-white lg:flex xl:p-14"
    >
      <!-- Subtle glow -->
      <div class="pointer-events-none absolute -top-32 -left-32 h-80 w-80 rounded-full bg-cyan-500/15 blur-3xl" aria-hidden="true" />
      <div class="pointer-events-none absolute -bottom-32 -right-32 h-80 w-80 rounded-full bg-emerald-500/10 blur-3xl" aria-hidden="true" />

      <!-- Brand -->
      <div class="relative z-10">
        <Link href="/" class="inline-flex items-center gap-3 group">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 to-teal-600 text-white shadow-lg shadow-cyan-500/20 transition-transform group-hover:scale-105">
            <HeartPulse class="h-5 w-5" />
          </div>
          <div>
            <span class="text-lg font-bold tracking-tight">AFYANOVA</span>
            <span class="ml-2 rounded-md bg-cyan-500/20 px-1.5 py-0.5 text-[10px] font-semibold tracking-wider text-cyan-300">AHS</span>
          </div>
        </Link>
      </div>

      <!-- Center testimonial -->
      <div class="relative z-10 my-auto max-w-md space-y-6">
        <blockquote class="text-2xl font-semibold leading-snug tracking-tight text-white xl:text-3xl">
          "One system for the entire hospital — from triage to discharge, every department connected in real time."
        </blockquote>
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-full bg-white/10 flex items-center justify-center text-sm font-bold text-cyan-300">
            DM
          </div>
          <div>
            <div class="text-sm font-medium text-white">Dar Main Hospital</div>
            <div class="text-xs text-slate-400">Clinical Operations · Dar es Salaam</div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="relative z-10 flex items-center justify-between text-xs text-slate-500">
        <span>HL7 FHIR R4 · MoH Compliant</span>
        <span>v2027.2 LTS</span>
      </div>
    </div>

    <!-- Right Form Pane -->
    <div class="relative flex w-full flex-col justify-between overflow-y-auto p-6 sm:p-10 lg:w-1/2 lg:p-14 xl:p-20">
      <!-- Top bar -->
      <header class="flex items-center justify-between pb-8">
        <!-- Mobile brand -->
        <div class="flex items-center gap-2 lg:hidden">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <HeartPulse class="h-4 w-4" />
          </div>
          <span class="text-sm font-bold tracking-tight text-foreground">AFYANOVA AHS</span>
        </div>
        <!-- Desktop: facility tag -->
        <div class="hidden lg:block">
          <span class="text-xs text-muted-foreground">Dar Main Hospital</span>
        </div>
        <!-- Controls -->
        <div class="flex items-center gap-2">
          <div class="inline-flex items-center rounded-lg border border-border bg-card p-0.5 text-xs shadow-xs">
            <button
              type="button"
              class="rounded-md px-2 py-1 font-medium transition-colors cursor-pointer"
              :class="locale === 'en' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
              @click="switchLocale('en')"
            >EN</button>
            <button
              type="button"
              class="rounded-md px-2 py-1 font-medium transition-colors cursor-pointer"
              :class="locale === 'sw' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
              @click="switchLocale('sw')"
            >SW</button>
          </div>
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-border bg-card text-muted-foreground hover:bg-accent hover:text-accent-foreground shadow-xs cursor-pointer transition-colors"
            @click="toggleTheme"
          >
            <Sun v-if="isDark" class="h-4 w-4" />
            <Moon v-else class="h-4 w-4" />
          </button>
        </div>
      </header>

      <!-- Form slot -->
      <main class="my-auto mx-auto w-full max-w-sm">
        <slot />
      </main>

      <!-- Footer -->
      <footer class="pt-8 text-center text-[11px] text-muted-foreground">
        <span>© 2027 Afyanova AHS · PHI Protected · Tanzania MoH Compliant</span>
      </footer>
    </div>
  </div>
</template>