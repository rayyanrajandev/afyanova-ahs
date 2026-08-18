<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import {
  CheckCircle2,
  Database,
  RefreshCw,
  Sparkles,
  Wifi,
  WifiOff,
} from "lucide-vue-next";

const { t } = useI18n({ useScope: "global" });

const isOffline = ref(false);
const syncFeedback = ref<string | null>(null);

function toggleMode(offline: boolean) {
  isOffline.value = offline;
  if (!offline) {
    syncFeedback.value = t("landing.resilience_feedback");
    setTimeout(() => {
      syncFeedback.value = null;
    }, 4000);
  }
}
</script>

<template>
  <section id="resilience" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8 scroll-mt-24">
    <div
      class="rounded-3xl border border-border bg-gradient-to-b from-card to-muted/20 p-6 sm:p-10 shadow-sm space-y-8 relative overflow-hidden"
    >
      <!-- Background Ambient -->
      <div
        class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-teal-500/10 blur-3xl"
        aria-hidden="true"
      />

      <!-- Header & Interactive Toggle -->
      <div
        class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between border-b border-border/60 pb-6"
      >
        <div class="space-y-2 max-w-2xl">
          <div
            class="inline-flex items-center gap-1.5 rounded-full bg-teal-500/10 px-3 py-0.5 text-xs font-semibold text-teal-600 dark:text-teal-400"
          >
            <Sparkles class="h-3 w-3" />
            <span>{{ t("landing.resilience_badge") }}</span>
          </div>
          <h2
            class="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl"
          >
            {{ t("landing.resilience_title") }}
          </h2>
          <p class="text-sm text-muted-foreground leading-relaxed">
            {{ t("landing.resilience_subtitle") }}
          </p>
        </div>

        <!-- Online / Offline Interactive Simulator Switch -->
        <div
          class="inline-flex items-center rounded-2xl border border-border/80 bg-surface p-1.5 shadow-xs shrink-0 self-start lg:self-center"
        >
          <button
            type="button"
            class="flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition-all cursor-pointer"
            :class="
              !isOffline
                ? 'bg-emerald-600 text-white shadow-sm'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="toggleMode(false)"
          >
            <Wifi class="h-4 w-4" />
            <span>{{ t("landing.resilience_toggle_online") }}</span>
          </button>
          <button
            type="button"
            class="flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition-all cursor-pointer"
            :class="
              isOffline
                ? 'bg-amber-600 text-white shadow-sm'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="toggleMode(true)"
          >
            <WifiOff class="h-4 w-4" />
            <span>{{ t("landing.resilience_toggle_offline") }}</span>
          </button>
        </div>
      </div>

      <!-- Simulator Interactive Readout Cards -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <!-- Status Card -->
        <div
          class="rounded-2xl border p-5 space-y-2.5 transition-all"
          :class="
            isOffline
              ? 'border-amber-500/40 bg-amber-500/5'
              : 'border-emerald-500/30 bg-emerald-500/5'
          "
        >
          <div class="flex items-center justify-between text-xs font-bold">
            <span class="text-foreground">{{
              t("landing.resilience_card_network")
            }}</span>
            <span
              class="rounded-full px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide"
              :class="
                isOffline
                  ? 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                  : 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
              "
            >
              {{ isOffline ? "Offline Outage" : "Online Grid" }}
            </span>
          </div>
          <p class="text-xs text-muted-foreground leading-relaxed">
            {{
              isOffline
                ? t("landing.resilience_card_network_offline")
                : t("landing.resilience_card_network_online")
            }}
          </p>
        </div>

        <!-- Engine Card -->
        <div
          class="rounded-2xl border border-border/80 bg-card p-5 space-y-2.5 shadow-2xs"
        >
          <div class="flex items-center justify-between text-xs font-bold">
            <div class="flex items-center gap-2 text-foreground">
              <Database class="h-4 w-4 text-primary" />
              <span>{{ t("landing.resilience_card_engine") }}</span>
            </div>
            <span
              class="rounded bg-primary/10 px-2 py-0.5 text-[10px] font-mono font-semibold text-primary"
              >CRDT + SQLite</span
            >
          </div>
          <p class="text-xs text-muted-foreground leading-relaxed">
            {{ t("landing.resilience_card_engine_desc") }}
          </p>
        </div>

        <!-- Sync SLA Card -->
        <div
          class="rounded-2xl border border-border/80 bg-card p-5 space-y-2.5 shadow-2xs"
        >
          <div class="flex items-center justify-between text-xs font-bold">
            <div class="flex items-center gap-2 text-foreground">
              <RefreshCw class="h-4 w-4 text-teal-600 dark:text-teal-400" />
              <span>{{ t("landing.resilience_card_sync") }}</span>
            </div>
            <span
              class="rounded bg-teal-500/10 px-2 py-0.5 text-[10px] font-mono font-semibold text-teal-600 dark:text-teal-400"
              >Zero-Loss</span
            >
          </div>
          <p class="text-xs text-muted-foreground leading-relaxed">
            <span
              v-if="syncFeedback"
              class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1.5"
            >
              <CheckCircle2 class="h-4 w-4" />
              {{ syncFeedback }}
            </span>
            <span v-else>
              {{ t("landing.resilience_card_sync_desc") }}
            </span>
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
