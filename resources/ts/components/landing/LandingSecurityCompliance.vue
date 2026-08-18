<script setup lang="ts">
import { useI18n } from "vue-i18n";
import {
  Database,
  FileCheck,
  Lock,
  Network,
  ShieldCheck,
  Sparkles,
} from "lucide-vue-next";

const { t } = useI18n({ useScope: "global" });

const pillars = [
  {
    key: "fhir",
    icon: Network,
    color: "text-cyan-600 dark:text-cyan-400",
    bg: "bg-cyan-500/10",
  },
  {
    key: "rls",
    icon: Database,
    color: "text-teal-600 dark:text-teal-400",
    bg: "bg-teal-500/10",
  },
  {
    key: "audit",
    icon: FileCheck,
    color: "text-emerald-600 dark:text-emerald-400",
    bg: "bg-emerald-500/10",
  },
  {
    key: "crypto",
    icon: Lock,
    color: "text-indigo-600 dark:text-indigo-400",
    bg: "bg-indigo-500/10",
  },
];
</script>

<template>
  <section id="security" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-12 scroll-mt-24">
    <!-- Header -->
    <div class="text-center space-y-3 max-w-3xl mx-auto">
      <div
        class="inline-flex items-center gap-1.5 rounded-full bg-indigo-500/10 px-3 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400"
      >
        <Sparkles class="h-3 w-3" />
        <span>{{ t("landing.security_badge") }}</span>
      </div>
      <h2
        class="text-3xl font-extrabold tracking-tight text-foreground sm:text-4xl"
      >
        {{ t("landing.security_title") }}
      </h2>
      <p class="text-base text-muted-foreground leading-relaxed">
        {{ t("landing.security_subtitle") }}
      </p>
    </div>

    <!-- Security Matrix Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div
        v-for="p in pillars"
        :key="p.key"
        class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-2xs space-y-4 hover:border-primary/40 transition-colors"
      >
        <div class="flex items-center gap-3.5">
          <div
            class="flex h-11 w-11 items-center justify-center rounded-xl shrink-0"
            :class="p.bg"
          >
            <component :is="p.icon" class="h-5 w-5" :class="p.color" />
          </div>
          <div>
            <h3 class="text-lg font-bold text-foreground">
              {{ t(`landing.security_${p.key}_title`) }}
            </h3>
            <span class="text-[11px] font-mono text-muted-foreground"
              >Institutional Healthcare Compliance</span
            >
          </div>
        </div>

        <p class="text-xs sm:text-sm text-muted-foreground leading-relaxed">
          {{ t(`landing.security_${p.key}_desc`) }}
        </p>
      </div>
    </div>
  </section>
</template>
