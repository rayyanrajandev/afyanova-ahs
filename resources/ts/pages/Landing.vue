/**
 * Afyanova AHS — Enterprise Landing Page (2027 Edition)
 * =======================================================
 * Focused landing: Hero → Workspace showcase → CTA.
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  ArrowRight,
  CheckCircle2,
  HeartPulse,
  Layers,
  Microscope,
  Pill,
  Receipt,
  Scan,
  Sparkles,
  Stethoscope,
  Users,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import LandingLayout from "@/layouts/LandingLayout.vue";

defineOptions({ layout: LandingLayout });

const { t } = useI18n({ useScope: "global" });

const activeTab = ref<string>("clinician");

const workspaces = [
  {
    id: "reception",
    title: "Reception & Triage",
    icon: Users,
    color: "text-blue-500",
    desc: "Rapid patient search, intelligent appointment queues, and instant emergency check-in routing.",
    features: ["NIN auto-lookup", "Smart queue priority", "Emergency fast-path"],
  },
  {
    id: "clinician",
    title: "Clinician (OPD/IPD)",
    icon: Stethoscope,
    color: "text-teal-500",
    desc: "Electronic medical records with ICD-10 coding, e-prescribing, and real-time lab/imaging ordering.",
    features: ["One-click CPOE", "Formulary compliance", "Allergy safety alerts"],
  },
  {
    id: "nursing",
    title: "Nursing & e-MAR",
    icon: HeartPulse,
    color: "text-emerald-500",
    desc: "Touch-optimized vitals entry with NEWS2 scoring, medication administration, and bedside tasks.",
    features: ["NEWS2 deterioration", "5-Rights safety", "Fluid balance"],
  },
  {
    id: "laboratory",
    title: "Laboratory (LIS)",
    icon: Microscope,
    color: "text-amber-500",
    desc: "Specimen barcode tracking, analyzer integration, and critical value clinician alerts.",
    features: ["Barcode accessioning", "Critical result flagging", "Analyzer interop"],
  },
  {
    id: "radiology",
    title: "Radiology (PACS)",
    icon: Scan,
    color: "text-cyan-500",
    desc: "DICOM worklist management, study tracking, and structured radiologist reporting.",
    features: ["Imaging viewer", "Report templates", "Stat priority"],
  },
  {
    id: "pharmacy",
    title: "Pharmacy",
    icon: Pill,
    color: "text-teal-600",
    desc: "Digital prescription fulfillment, batch/expiry tracking, and automated reorder alerts.",
    features: ["Dispense verification", "Stock guard", "Reorder triggers"],
  },
  {
    id: "cashier",
    title: "Cashier & Billing",
    icon: Receipt,
    color: "text-rose-500",
    desc: "NHIF insurance claims, GePG & Mobile Money, and itemized clinical receipts.",
    features: ["NHIF auto-claims", "Payment reconciliation", "Itemized receipts"],
  },
];
</script>

<template>
  <div class="space-y-16 pb-16">
    <!-- Hero -->
    <section class="relative overflow-hidden pt-12 pb-14 lg:pt-20 lg:pb-20 border-b border-border/40">
      <div class="pointer-events-none absolute -top-40 left-1/2 -z-10 h-[500px] w-[800px] -translate-x-1/2 rounded-full bg-gradient-to-b from-cyan-500/12 via-teal-500/8 to-transparent blur-3xl" aria-hidden="true" />

      <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 text-center space-y-6">
        <!-- Badge -->
        <div class="inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
          <Sparkles class="h-3.5 w-3.5" />
          <span>{{ t('landing.hero_badge') }}</span>
        </div>

        <!-- Title -->
        <h1 class="text-4xl font-extrabold tracking-tight text-foreground sm:text-5xl lg:text-6xl leading-[1.1]">
          <span>{{ t('landing.hero_title_prefix') }} </span>
          <span class="bg-gradient-to-r from-cyan-600 via-teal-500 to-emerald-600 bg-clip-text text-transparent dark:from-cyan-400 dark:via-teal-300 dark:to-emerald-400">
            {{ t('landing.hero_title_suffix') }}
          </span>
        </h1>

        <!-- Subtitle -->
        <p class="mx-auto max-w-2xl text-base text-muted-foreground sm:text-lg leading-relaxed">
          {{ t('landing.hero_subtitle') }}
        </p>

        <!-- CTA -->
        <div class="flex items-center justify-center gap-3 pt-2">
          <Link href="/login">
            <Button size="lg" class="h-11 gap-2 px-6 font-medium shadow-md shadow-primary/20 cursor-pointer">
              {{ t('landing.enter_workspace') }}
              <ArrowRight class="h-4 w-4" />
            </Button>
          </Link>
          <a href="#workspaces">
            <Button variant="outline" size="lg" class="h-11 gap-2 px-5 font-medium cursor-pointer">
              <Layers class="h-4 w-4 text-primary" />
              {{ t('landing.workspaces_title') }}
            </Button>
          </a>
        </div>

        <!-- Status -->
        <div class="flex items-center justify-center gap-2 text-xs text-muted-foreground pt-2">
          <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
          </span>
          <span class="font-medium text-foreground">{{ t('landing.live_status_operational') }}</span>
          <span class="text-muted-foreground/60">·</span>
          <span>HL7 FHIR R4 · 7 Workspaces</span>
        </div>
      </div>
    </section>

    <!-- Workspaces Showcase -->
    <section id="workspaces" class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 space-y-8">
      <div class="text-center space-y-2">
        <h2 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
          {{ t('landing.workspaces_title') }}
        </h2>
        <p class="text-sm text-muted-foreground max-w-xl mx-auto">
          {{ t('landing.workspaces_subtitle') }}
        </p>
      </div>

      <!-- Tab selector -->
      <div class="flex flex-wrap items-center justify-center gap-1.5">
        <button
          v-for="ws in workspaces"
          :key="ws.id"
          type="button"
          class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-all cursor-pointer"
          :class="activeTab === ws.id ? 'bg-primary text-primary-foreground shadow-sm' : 'bg-muted/50 text-muted-foreground hover:bg-accent hover:text-foreground'"
          @click="activeTab = ws.id"
        >
          <component :is="ws.icon" class="h-3.5 w-3.5" />
          <span class="hidden sm:inline">{{ ws.title }}</span>
          <span class="sm:hidden">{{ ws.title.split(' ')[0] }}</span>
        </button>
      </div>

      <!-- Active workspace panel -->
      <div
        v-for="ws in workspaces"
        v-show="activeTab === ws.id"
        :key="`panel-${ws.id}`"
        class="rounded-2xl border border-border bg-card p-6 sm:p-8 shadow-sm"
      >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-8">
          <div class="flex-1 space-y-3">
            <div class="flex items-center gap-2">
              <component :is="ws.icon" class="h-5 w-5" :class="ws.color" />
              <h3 class="text-lg font-semibold text-foreground">{{ ws.title }}</h3>
            </div>
            <p class="text-sm text-muted-foreground leading-relaxed">{{ ws.desc }}</p>
          </div>
          <div class="sm:w-56 space-y-2 shrink-0">
            <div
              v-for="(feat, idx) in ws.features"
              :key="idx"
              class="flex items-center gap-2 text-xs text-foreground"
            >
              <CheckCircle2 class="h-3.5 w-3.5 text-emerald-500 shrink-0" />
              <span>{{ feat }}</span>
            </div>
            <Link href="/login" class="block pt-2">
              <Button size="sm" class="w-full gap-1.5 text-xs font-medium cursor-pointer">
                Enter Workspace <ArrowRight class="h-3 w-3" />
              </Button>
            </Link>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA Banner -->
    <section class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
      <div class="relative overflow-hidden rounded-2xl bg-slate-950 p-8 sm:p-10 text-white">
        <div class="pointer-events-none absolute -right-10 -bottom-10 h-56 w-56 rounded-full bg-cyan-500/15 blur-3xl" aria-hidden="true" />
        <div class="relative z-10 flex flex-col items-center text-center gap-4 sm:flex-row sm:text-left sm:justify-between">
          <div>
            <h2 class="text-xl font-bold tracking-tight sm:text-2xl">{{ t('landing.cta_title') }}</h2>
            <p class="mt-1 text-sm text-slate-400">{{ t('landing.cta_subtitle') }}</p>
          </div>
          <Link href="/login" class="shrink-0">
            <Button size="lg" class="h-10 gap-2 bg-gradient-to-r from-cyan-500 to-teal-600 text-white hover:from-cyan-600 hover:to-teal-700 shadow-lg cursor-pointer">
              {{ t('landing.cta_btn') }}
              <ArrowRight class="h-4 w-4" />
            </Button>
          </Link>
        </div>
      </div>
    </section>
  </div>
</template>
