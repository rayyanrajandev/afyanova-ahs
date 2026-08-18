<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import {
  Activity,
  CheckCircle2,
  ChevronRight,
  FlaskConical,
  Pill,
  Receipt,
  Sparkles,
  Stethoscope,
  UserPlus,
} from "lucide-vue-next";

const { t } = useI18n({ useScope: "global" });

const activeStep = ref(1);

const steps = [
  { id: 1, icon: UserPlus, color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-500/10" },
  { id: 2, icon: Activity, color: "text-emerald-600 dark:text-emerald-400", bg: "bg-emerald-500/10" },
  { id: 3, icon: Stethoscope, color: "text-teal-600 dark:text-teal-400", bg: "bg-teal-500/10" },
  { id: 4, icon: FlaskConical, color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-500/10" },
  { id: 5, icon: Pill, color: "text-indigo-600 dark:text-indigo-400", bg: "bg-indigo-500/10" },
  { id: 6, icon: Receipt, color: "text-rose-600 dark:text-rose-400", bg: "bg-rose-500/10" },
];
</script>

<template>
  <section id="journey" class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-12 scroll-mt-24">
    <!-- Header -->
    <div class="text-center space-y-3 max-w-3xl mx-auto">
      <div
        class="inline-flex items-center gap-1.5 rounded-full bg-cyan-500/10 px-3 py-0.5 text-xs font-semibold text-cyan-600 dark:text-cyan-400"
      >
        <Sparkles class="h-3 w-3" />
        <span>{{ t("landing.journey_badge") }}</span>
      </div>
      <h2
        class="text-3xl font-extrabold tracking-tight text-foreground sm:text-4xl"
      >
        {{ t("landing.journey_title") }}
      </h2>
      <p class="text-base text-muted-foreground leading-relaxed">
        {{ t("landing.journey_subtitle") }}
      </p>
    </div>

    <!-- Stepper Grid / Buttons -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
      <button
        v-for="st in steps"
        :key="st.id"
        type="button"
        class="rounded-2xl border p-4 text-left transition-all cursor-pointer flex flex-col justify-between space-y-3 focus-ring"
        :class="
          activeStep === st.id
            ? 'border-primary bg-primary/10 shadow-sm'
            : 'border-border/60 bg-card hover:bg-muted/30 text-muted-foreground'
        "
        @click="activeStep = st.id"
      >
        <div class="flex items-center justify-between w-full">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-xl transition-colors"
            :class="st.bg"
          >
            <component :is="st.icon" class="h-4.5 w-4.5" :class="st.color" />
          </div>
          <CheckCircle2
            v-if="activeStep > st.id"
            class="h-4 w-4 text-emerald-500"
          />
          <span
            v-else-if="activeStep === st.id"
            class="h-2.5 w-2.5 rounded-full bg-primary animate-ping"
          />
        </div>

        <div class="space-y-1">
          <div
            class="text-xs font-bold"
            :class="activeStep === st.id ? 'text-foreground' : 'text-muted-foreground'"
          >
            {{ t(`landing.journey_step_${st.id}_title`) }}
          </div>
          <div class="text-[10px] text-muted-foreground font-medium">
            {{ t(`landing.journey_step_${st.id}_sub`) }}
          </div>
        </div>
      </button>
    </div>

    <!-- Active Step Highlight Banner -->
    <div
      class="rounded-2xl border border-border bg-card p-6 sm:p-8 shadow-xs space-y-4"
    >
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border/60 pb-4">
        <div class="flex items-center gap-3">
          <div
            class="flex h-10 w-10 items-center justify-center rounded-xl"
            :class="steps[activeStep - 1].bg"
          >
            <component
              :is="steps[activeStep - 1].icon"
              class="h-5 w-5"
              :class="steps[activeStep - 1].color"
            />
          </div>
          <div>
            <h3 class="text-lg font-bold text-foreground">
              {{ t(`landing.journey_step_${activeStep}_title`) }}
            </h3>
            <span class="text-xs text-muted-foreground font-medium">
              {{ t(`landing.journey_step_${activeStep}_sub`) }}
            </span>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button
            v-for="s in 6"
            :key="s"
            type="button"
            class="h-2.5 rounded-full transition-all cursor-pointer"
            :class="
              activeStep === s
                ? 'w-7 bg-primary'
                : 'w-2.5 bg-border hover:bg-muted-foreground/40'
            "
            :aria-label="`Go to step ${s}`"
            @click="activeStep = s"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
        <div class="md:col-span-8 space-y-3">
          <p class="text-sm text-muted-foreground leading-relaxed">
            {{ t(`landing.journey_step_${activeStep}_desc`) }}
          </p>
          <div
            class="inline-flex items-center gap-2 text-xs font-semibold text-teal-600 dark:text-teal-400 bg-teal-500/10 px-3 py-1 rounded-lg"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>Zero manual re-entry · Encrypted cross-department sync</span>
          </div>
        </div>

        <div class="md:col-span-4 flex justify-start md:justify-end">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 text-xs font-bold text-primary hover:underline cursor-pointer"
            @click="activeStep = activeStep === 6 ? 1 : activeStep + 1"
          >
            <span>{{ activeStep === 6 ? "Restart Journey" : "Next Stage" }}</span>
            <ChevronRight class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
