<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  Activity,
  HeartPulse,
  Layers,
  ShieldCheck,
} from "lucide-vue-next";

const { t } = useI18n({ useScope: "global" });
</script>

<template>
  <footer class="border-t border-border/60 bg-card/50 pt-16 pb-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-12">
      <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
        <!-- Col 1: Brand & Operational Status -->
        <div class="space-y-4">
          <div class="flex items-center gap-2.5">
            <div
              class="flex h-8 w-8 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-sm"
            >
              <HeartPulse class="h-4 w-4" />
            </div>
            <span class="text-base font-extrabold tracking-tight text-foreground"
              >AFYANOVA AHS</span
            >
          </div>

          <p class="text-xs text-muted-foreground leading-relaxed">
            {{ t("landing.hero_subtitle") }}
          </p>

          <div
            class="flex items-center gap-2 text-xs text-emerald-600 dark:text-emerald-400 pt-1"
          >
            <span class="relative flex h-2 w-2">
              <span
                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
              />
              <span
                class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"
              />
            </span>
            <span class="font-bold"
              >{{ t("landing.footer_link_status") }}: Operational</span
            >
          </div>
        </div>

        <!-- Col 2: Workspaces -->
        <div class="space-y-3">
          <h4
            class="text-xs font-bold text-foreground tracking-wider uppercase"
          >
            {{ t("landing.footer_col_workspaces") }}
          </h4>
          <ul class="space-y-2 text-xs text-muted-foreground">
            <li>
              <Link
                href="/reception"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_reception") }}</Link
              >
            </li>
            <li>
              <Link
                href="/clinician"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_clinician") }}</Link
              >
            </li>
            <li>
              <Link
                href="/nursing"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_nursing") }}</Link
              >
            </li>
            <li>
              <Link
                href="/laboratory"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_lab") }}</Link
              >
            </li>
            <li>
              <Link
                href="/radiology"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_radiology") }}</Link
              >
            </li>
            <li>
              <Link
                href="/pharmacy"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_pharmacy") }}</Link
              >
            </li>
            <li>
              <Link
                href="/cashier"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_cashier") }}</Link
              >
            </li>
            <li>
              <Link
                href="/inventory"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_inventory") }}</Link
              >
            </li>
          </ul>
        </div>

        <!-- Col 3: Architecture & Standards -->
        <div class="space-y-3">
          <h4
            class="text-xs font-bold text-foreground tracking-wider uppercase"
          >
            {{ t("landing.footer_col_architecture") }}
          </h4>
          <ul class="space-y-2 text-xs text-muted-foreground">
            <li>
              <a
                href="#security"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_fhir") }}</a
              >
            </li>
            <li>
              <a
                href="#resilience"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_crdt") }}</a
              >
            </li>
            <li>
              <a
                href="#integrations"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_gepg") }}</a
              >
            </li>
            <li>
              <a
                href="#integrations"
                class="hover:text-foreground transition-colors"
                >{{ t("landing.footer_link_nemlit") }}</a
              >
            </li>
          </ul>
        </div>

        <!-- Col 4: Trust & Compliance -->
        <div class="space-y-3">
          <h4
            class="text-xs font-bold text-foreground tracking-wider uppercase"
          >
            {{ t("landing.footer_col_compliance") }}
          </h4>
          <ul class="space-y-2 text-xs text-muted-foreground">
            <li class="flex items-center gap-1.5">
              <ShieldCheck class="h-3.5 w-3.5 text-primary shrink-0" />
              <span>{{ t("landing.footer_link_phi") }}</span>
            </li>
            <li class="flex items-center gap-1.5">
              <Activity class="h-3.5 w-3.5 text-primary shrink-0" />
              <span>{{ t("landing.footer_link_audit") }}</span>
            </li>
            <li class="flex items-center gap-1.5">
              <Layers class="h-3.5 w-3.5 text-primary shrink-0" />
              <span>{{ t("landing.footer_facility_tag") }}</span>
            </li>
          </ul>
        </div>
      </div>

      <!-- Bottom Bar -->
      <div
        class="border-t border-border/60 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-muted-foreground"
      >
        <div class="flex items-center gap-2">
          <HeartPulse class="h-4 w-4 text-primary" />
          <span>{{ t("landing.footer_rights") }}</span>
        </div>
        <div class="flex items-center gap-3 text-[11px]">
          <span>HL7 FHIR R4</span>
          <span class="text-border">·</span>
          <span>{{ t("landing.footer_facility_tag") }}</span>
          <span class="text-border">·</span>
          <span>{{ t("landing.footer_version") }}</span>
        </div>
      </div>
    </div>
  </footer>
</template>
