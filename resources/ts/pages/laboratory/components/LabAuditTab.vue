/** * LabAuditTab — Laboratory Forensic Sample Audit & Quality Assurance Stream
(Volume 2.4 §12) *
=========================================================================================
* 2027 ISO 15189 Quality Assurance & Custody Audit Trail: * - Specimen
Collection & Barcode Registration * - Instrument Analysis Start & Result Entry
Checkpoints * - Critical Value Physician Notification Timestamp * - Verifying
Senior Scientist Sign-off * - Full Internationalization (i18n) Support */

<script setup lang="ts">
import {
  Activity,
  Award,
  CheckCircle2,
  Clock,
  FileCheck,
  FlaskConical,
  History,
  PhoneCall,
  ShieldCheck,
  TestTube2,
  User,
  UserCheck,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import type { LaboratoryOrder } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
}>();

const { t } = useI18n({ useScope: "global" });

function formatFullDate(dateStr?: string | null): string {
  if (!dateStr) return t("laboratory.awaiting_sample", "Pending");
  const d = new Date(dateStr);
  return `${d.toLocaleDateString([], { day: "numeric", month: "short", year: "numeric" })} at ${d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    <!-- Header -->
    <div
      class="flex items-center justify-between border-b border-border/80 pb-2"
    >
      <div class="flex items-center gap-2">
        <div
          class="flex size-6 items-center justify-center rounded-md bg-teal-500/10 text-teal-600"
        >
          <History class="size-3.5" />
        </div>
        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
          {{
            t(
              "laboratory.audit_title",
              "Specimen Chain of Custody & Analytical Audit Trail",
            )
          }}
        </h3>
      </div>
      <Badge
        variant="outline"
        class="text-[9px] font-mono uppercase px-1.5 py-0"
      >
        {{ t("laboratory.traceability_id", "Traceability ID:") }}
        {{ order.orderNumber }}
      </Badge>
    </div>

    <!-- Timeline Trail -->
    <ol
      class="relative border-l border-border/80 ml-3.5 space-y-4 py-2 text-xs"
    >
      <!-- 1. Order Placed -->
      <li class="relative pl-6">
        <div
          class="absolute -left-3 top-1 flex size-6 items-center justify-center rounded-full border-2 border-primary bg-primary/10 text-primary"
        >
          <FlaskConical class="size-3" />
        </div>
        <div
          class="rounded-lg border border-border bg-surface p-3 space-y-1 shadow-2xs"
        >
          <div class="flex items-center justify-between">
            <span class="font-bold text-foreground">{{
              t("laboratory.audit_ordered", "Laboratory Investigation Ordered")
            }}</span>
            <span class="font-mono text-[11px] text-muted-foreground">{{
              formatFullDate(order.createdAt)
            }}</span>
          </div>
          <p class="text-muted-foreground">
            {{
              t("laboratory.audit_ordered_desc", {
                doctor: order.orderingClinician,
                indication:
                  order.clinicalIndication ||
                  t("clinician.clinical_indication", "Routine evaluation"),
              })
            }}
          </p>
        </div>
      </li>

      <!-- 2. Sample Accessioning -->
      <li class="relative pl-6">
        <div
          class="absolute -left-3 top-1 flex size-6 items-center justify-center rounded-full border-2 bg-surface"
          :class="
            order.collectedAt
              ? 'border-blue-500 bg-blue-500/10 text-blue-600'
              : 'border-border text-muted-foreground'
          "
        >
          <TestTube2 class="size-3" />
        </div>
        <div
          class="rounded-lg border border-border bg-surface p-3 space-y-1 shadow-2xs"
        >
          <div class="flex items-center justify-between">
            <span class="font-bold text-foreground">{{
              t(
                "laboratory.audit_accessioned",
                "Specimen Received & Accessioned",
              )
            }}</span>
            <span class="font-mono text-[11px] text-muted-foreground">{{
              formatFullDate(order.collectedAt)
            }}</span>
          </div>
          <p class="text-muted-foreground">
            {{
              t("laboratory.audit_accessioned_desc", {
                medium: order.sampleType,
                tube: order.tubeType || "Standard",
                quality:
                  order.specimenIntegrity ||
                  t("laboratory.quality_adequate", "Adequate"),
              })
            }}
          </p>
        </div>
      </li>

      <!-- 3. Critical Value Notification (if logged) -->
      <li v-if="order.criticalNotifiedAt" class="relative pl-6">
        <div
          class="absolute -left-3 top-1 flex size-6 items-center justify-center rounded-full border-2 border-rose-500 bg-rose-500/15 text-rose-600 animate-pulse"
        >
          <PhoneCall class="size-3" />
        </div>
        <div
          class="rounded-lg border border-rose-500/30 bg-rose-500/5 p-3 space-y-1 shadow-2xs"
        >
          <div class="flex items-center justify-between">
            <span class="font-bold text-rose-700 dark:text-rose-400">{{
              t(
                "laboratory.audit_critical",
                "Critical Panic Value Communicated",
              )
            }}</span>
            <span class="font-mono text-[11px] text-rose-600">{{
              formatFullDate(order.criticalNotifiedAt)
            }}</span>
          </div>
          <p class="text-muted-foreground">
            {{
              t("laboratory.audit_critical_desc", {
                doctor: order.criticalNotifiedTo,
              })
            }}
          </p>
        </div>
      </li>

      <!-- 4. Senior Verification -->
      <li class="relative pl-6">
        <div
          class="absolute -left-3 top-1 flex size-6 items-center justify-center rounded-full border-2 bg-surface"
          :class="
            order.verifiedAt
              ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600'
              : 'border-border text-muted-foreground'
          "
        >
          <ShieldCheck class="size-3" />
        </div>
        <div
          class="rounded-lg border border-border bg-surface p-3 space-y-1 shadow-2xs"
        >
          <div class="flex items-center justify-between">
            <span class="font-bold text-foreground">{{
              t(
                "laboratory.audit_verified",
                "Results Verified & Released to EMR",
              )
            }}</span>
            <span class="font-mono text-[11px] text-muted-foreground">{{
              formatFullDate(order.verifiedAt)
            }}</span>
          </div>
          <p class="text-muted-foreground">
            {{
              t("laboratory.audit_verified_desc", {
                user:
                  order.verifiedBy ||
                  t("laboratory.awaiting_sample", "Pending"),
              })
            }}
          </p>
        </div>
      </li>
    </ol>
  </div>
</template>
