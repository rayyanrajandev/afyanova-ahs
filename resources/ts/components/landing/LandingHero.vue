<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  Activity,
  ArrowRight,
  CheckCircle2,
  HeartPulse,
  Layers,
  Pill,
  Sparkles,
  Stethoscope,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";



const { t } = useI18n({ useScope: "global" });
</script>

<template>
  <section
    class="relative pt-12 pb-20 lg:pt-20 lg:pb-28 border-b border-border/40 overflow-hidden"
  >
    <!-- Ambient Backdrop Soft Glow -->
    <div
      class="pointer-events-none absolute -top-48 left-1/2 -z-10 h-[640px] w-[1000px] -translate-x-1/2 rounded-full bg-gradient-to-b from-teal-500/12 via-cyan-500/5 to-transparent blur-3xl"
      aria-hidden="true"
    />

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-16">
      <!-- Hero Narrative Header -->
      <div class="text-center max-w-4xl mx-auto space-y-6">
        <!-- Live Operational Badge -->
        <div
          class="inline-flex items-center gap-2 rounded-full border border-teal-500/30 bg-teal-500/10 px-4 py-1 text-xs font-semibold text-teal-700 dark:text-teal-300 shadow-2xs"
        >
          <Sparkles class="h-3.5 w-3.5 animate-pulse text-teal-600 dark:text-teal-400" />
          <span>{{ t("landing.hero_badge") }}</span>
        </div>

        <!-- Headline -->
        <h1
          class="text-4xl font-extrabold tracking-tight text-foreground sm:text-5xl lg:text-6xl leading-[1.12]"
        >
          <span>{{ t("landing.hero_title_line1") }}</span>
          <span
            class="block bg-gradient-to-r from-teal-600 via-cyan-600 to-emerald-600 bg-clip-text text-transparent dark:from-teal-300 dark:via-cyan-300 dark:to-emerald-400"
          >
            {{ t("landing.hero_title_line2") }}
          </span>
        </h1>

        <!-- Subtitle -->
        <p
          class="mx-auto max-w-3xl text-base text-muted-foreground sm:text-lg lg:text-xl leading-relaxed"
        >
          {{ t("landing.hero_subtitle") }}
        </p>

        <!-- Dual CTAs -->
        <div class="flex flex-wrap items-center justify-center gap-4 pt-2">
          <Link href="/login">
            <Button
              size="lg"
              class="h-12 gap-2 px-7 text-sm font-bold shadow-lg shadow-teal-500/20 cursor-pointer bg-primary text-primary-foreground hover:bg-primary/90"
            >
              <span>{{ t("auth.sign_in") }}</span>
              <ArrowRight class="h-4 w-4" />
            </Button>
          </Link>

          <a href="#workspaces">
            <Button
              variant="outline"
              size="lg"
              class="h-12 gap-2 px-6 text-sm font-semibold cursor-pointer border-border hover:bg-card"
            >
              <Layers class="h-4 w-4 text-primary" />
              <span>{{ t("landing.hero_cta_explore") }}</span>
            </Button>
          </a>
        </div>

        <!-- Trust Indicator & Live Operational Line -->
        <div
          class="flex flex-wrap items-center justify-center gap-2 text-xs text-muted-foreground pt-3"
        >
          <span class="flex h-2 w-2 relative">
            <span
              class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
            />
            <span
              class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"
            />
          </span>
          <span class="font-medium text-foreground">
            {{ t("landing.hero_status_operational") }}
          </span>
        </div>
      </div>

      <!-- High-Fidelity Clinical Command Center Visualization Frame -->
      <div class="relative mx-auto max-w-6xl">
        <!-- Ambient Glow behind application mockup -->
        <div
          class="pointer-events-none absolute -inset-1 rounded-3xl bg-gradient-to-b from-teal-500/20 via-cyan-500/10 to-transparent blur-xl opacity-70"
          aria-hidden="true"
        />

        <!-- Desktop Application Frame -->
        <div
          class="relative rounded-2xl border border-border/80 bg-card shadow-2xl overflow-hidden"
        >
          <!-- Window Chrome Title Bar -->
          <div
            class="flex items-center justify-between border-b border-border/70 bg-muted/40 px-4 py-3"
          >
            <div class="flex items-center gap-2">
              <div class="h-3 w-3 rounded-full bg-rose-500/80" />
              <div class="h-3 w-3 rounded-full bg-amber-500/80" />
              <div class="h-3 w-3 rounded-full bg-emerald-500/80" />
              <span class="ml-2 font-mono text-[11px] text-muted-foreground hidden sm:inline"
                >app.afyanova.co.tz — {{ t("landing.hero_preview_title") }}</span
              >
            </div>

            <div class="flex items-center gap-3 text-xs text-muted-foreground">
              <div class="flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full bg-emerald-500" />
                <span class="font-medium text-foreground text-[11px]"
                  >National Health Grid Connected</span
                >
              </div>
              <span class="hidden md:inline text-border">|</span>
              <span class="hidden md:inline font-mono text-[11px]"
                >NEMLIT 2024 · NHIF Ready</span
              >
            </div>
          </div>

          <!-- Application Layout Showcase Body -->
          <div
            class="p-4 sm:p-6 lg:p-8 space-y-6 bg-gradient-to-b from-background to-muted/20"
          >
            <!-- Department KPI Status Strip -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
              <div
                class="rounded-xl border border-border/70 bg-card p-3 space-y-1"
              >
                <div
                  class="text-[10px] uppercase font-bold text-muted-foreground tracking-wider"
                >
                  {{ t("landing.hero_stat_encounters") }}
                </div>
                <div
                  class="text-xl font-extrabold text-foreground font-mono"
                >
                  148
                </div>
                <div class="text-[10px] text-emerald-600 font-medium">
                  {{ t("landing.hero_stat_encounters_sub") }}
                </div>
              </div>

              <div
                class="rounded-xl border border-border/70 bg-card p-3 space-y-1"
              >
                <div
                  class="text-[10px] uppercase font-bold text-muted-foreground tracking-wider"
                >
                  {{ t("landing.hero_stat_triage_wait") }}
                </div>
                <div
                  class="text-xl font-extrabold text-teal-600 dark:text-teal-400 font-mono"
                >
                  8.4
                  <span class="text-xs font-normal text-muted-foreground"
                    >mins</span
                  >
                </div>
                <div class="text-[10px] text-emerald-600 font-medium">
                  {{ t("landing.hero_stat_triage_wait_sub") }}
                </div>
              </div>

              <div
                class="rounded-xl border border-border/70 bg-card p-3 space-y-1"
              >
                <div
                  class="text-[10px] uppercase font-bold text-muted-foreground tracking-wider"
                >
                  {{ t("landing.hero_stat_fefo") }}
                </div>
                <div
                  class="text-xl font-extrabold text-indigo-600 dark:text-indigo-400 font-mono"
                >
                  99.4%
                </div>
                <div class="text-[10px] text-emerald-600 font-medium">
                  {{ t("landing.hero_stat_fefo_sub") }}
                </div>
              </div>

              <div
                class="rounded-xl border border-border/70 bg-card p-3 space-y-1"
              >
                <div
                  class="text-[10px] uppercase font-bold text-muted-foreground tracking-wider"
                >
                  {{ t("landing.hero_stat_claims") }}
                </div>
                <div
                  class="text-xl font-extrabold text-emerald-600 dark:text-emerald-400 font-mono"
                >
                  100%
                </div>
                <div class="text-[10px] text-emerald-600 font-medium">
                  {{ t("landing.hero_stat_claims_sub") }}
                </div>
              </div>
            </div>

            <!-- Showcase Workstation Main Screen -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
              <!-- Patient Banner & Doctor Encounter Details -->
              <div class="lg:col-span-8 space-y-4">
                <div
                  class="rounded-xl border border-teal-500/30 bg-teal-500/5 p-4 flex flex-wrap items-center justify-between gap-3"
                >
                  <div class="flex items-center gap-3">
                    <div
                      class="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600/20 text-teal-700 dark:text-teal-300 font-bold text-base"
                    >
                      AM
                    </div>
                    <div>
                      <div class="flex items-center gap-2">
                        <span class="text-base font-bold text-foreground"
                          >Amina J. Mbwana</span
                        >
                        <span class="text-xs text-muted-foreground"
                          >32y · Female</span
                        >
                        <Badge
                          variant="outline"
                          class="font-mono text-[10px]"
                          >MRN-88421</Badge
                        >
                      </div>
                      <div
                        class="text-xs text-muted-foreground flex flex-wrap gap-2 pt-0.5"
                      >
                        <span class="text-emerald-600 font-medium"
                          >NHIF Standard Verified</span
                        >
                        <span>·</span>
                        <span>NIN: 19940822-XXXXX</span>
                        <span>·</span>
                        <span class="text-rose-600 font-medium"
                          >Allergy: Penicillin</span
                        >
                      </div>
                    </div>
                  </div>

                  <Badge
                    class="bg-emerald-500/15 text-emerald-600 border-emerald-500/30"
                    >Active Consultation</Badge
                  >
                </div>

                <!-- Clinical Encounter Card -->
                <div
                  class="rounded-xl border border-border/80 bg-card p-4 space-y-3 text-xs"
                >
                  <div
                    class="flex items-center justify-between border-b border-border/50 pb-2"
                  >
                    <div class="flex items-center gap-2">
                      <Stethoscope class="h-4 w-4 text-teal-600" />
                      <span class="font-bold text-foreground"
                        >Doctor Consultation & Diagnosis</span
                      >
                    </div>
                    <span
                      class="text-[10px] font-mono text-muted-foreground"
                      >OPD Desk 1 · Dr. Ndunguru, MD</span
                    >
                  </div>

                  <div
                    class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[11px]"
                  >
                    <div
                      class="p-3 rounded-lg bg-muted/30 border border-border/50 space-y-1"
                    >
                      <div
                        class="text-[10px] font-bold text-muted-foreground uppercase"
                      >
                        Clinical Impression
                      </div>
                      <div class="font-bold text-foreground">
                        Acute Gastroenteritis (ICD-10 A09.0)
                      </div>
                      <div class="text-muted-foreground text-[10px]">
                        Dehydration: Mild · Abdominal exam soft
                      </div>
                    </div>

                    <div
                      class="p-3 rounded-lg bg-muted/30 border border-border/50 space-y-1"
                    >
                      <div
                        class="text-[10px] font-bold text-muted-foreground uppercase"
                      >
                        Point-of-Care Vitals (NEWS2)
                      </div>
                      <div class="font-mono text-foreground font-semibold">
                        BP 118/76 · HR 74 · Temp 37.8°C · SpO2 98%
                      </div>
                      <div class="text-emerald-600 font-bold text-[10px]">
                        NEWS2 Deterioration Score: 1 (Low Risk)
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Right Column: Connected Pharmacy & Billing Widget -->
              <div class="lg:col-span-4 space-y-4">
                <div
                  class="rounded-xl border border-border/80 bg-card p-4 space-y-3 text-xs"
                >
                  <div
                    class="flex items-center justify-between border-b border-border/50 pb-2"
                  >
                    <div class="flex items-center gap-2">
                      <Pill class="h-4 w-4 text-indigo-600" />
                      <span class="font-bold text-foreground"
                        >e-Prescription (NEMLIT)</span
                      >
                    </div>
                    <span class="text-[10px] text-emerald-600 font-bold"
                      >FEFO Batch Match</span
                    >
                  </div>

                  <div class="space-y-2 text-[11px]">
                    <div
                      class="p-2 rounded bg-muted/30 border border-border/40 flex justify-between items-center"
                    >
                      <div>
                        <div class="font-semibold text-foreground">
                          Ciprofloxacin 500mg
                        </div>
                        <div class="text-[10px] text-muted-foreground">
                          1 tab BD x 5 days
                        </div>
                      </div>
                      <span
                        class="text-[10px] font-mono text-emerald-600 font-bold"
                        >In Stock</span
                      >
                    </div>

                    <div
                      class="p-2 rounded bg-muted/30 border border-border/40 flex justify-between items-center"
                    >
                      <div>
                        <div class="font-semibold text-foreground">
                          ORS Sachets (4 pk)
                        </div>
                        <div class="text-[10px] text-muted-foreground">
                          1 sachet / 1L water
                        </div>
                      </div>
                      <span
                        class="text-[10px] font-mono text-emerald-600 font-bold"
                        >In Stock</span
                      >
                    </div>
                  </div>
                </div>

                <div
                  class="rounded-xl border border-border/80 bg-card p-4 space-y-2 text-xs"
                >
                  <div class="flex items-center justify-between">
                    <span class="font-bold text-foreground"
                      >GePG & NHIF Settlement</span
                    >
                    <span
                      class="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-bold text-emerald-600"
                      >Pre-Cleared</span
                    >
                  </div>
                  <p class="text-[11px] text-muted-foreground leading-relaxed">
                    Automated e-claims verification verified against NHIF 2024 benefit tariffs. Zero copay remaining.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
