/**
 * NursingPatientProfileTab — Patient Demographics, Payer & History in Nursing (Volume 2.3 §4.2)
 * =========================================================================
 * Provides nurses full access to the patient's verified demographics, contact,
 * next-of-kin, insurance coverage, and previous visit records directly inside
 * the clinical workbench.
 */

<script setup lang="ts">
import {
  CalendarClock,
  CircleCheck,
  Contact,
  History,
  Mail,
  MapPin,
  Phone,
  ShieldCheck,
  TriangleAlert,
  Users,
} from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import StatusBadge from "@/components/common/StatusBadge.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Plus, Pencil } from "lucide-vue-next";
import AllergyFormDialog from "@/components/AllergyFormDialog.vue";
import { useAllergyForm } from "@/composables/useAllergyForm";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { usePatientProfile } from "@/pages/reception/composables/usePatientProfile";
import type { Patient } from "@/stores/patientStore";

const props = defineProps<{
  patient: Patient;
  profile: ReturnType<typeof usePatientProfile>;
}>();

const { t } = useI18n();

const allergyForm = useAllergyForm({
  workspace: "nursing",
  onSaved: (patientId) => {
    // Refresh only the profile summary (allergy card) instead of reloading the page.
    props.profile.refreshSummary(patientId);
  },
});

function formatClinicalDate(dateStr: string | null | undefined): string {
  if (!dateStr) return "—";
  try {
    return new Date(dateStr).toLocaleDateString(undefined, {
      day: "numeric",
      month: "short",
      year: "numeric",
    });
  } catch {
    return dateStr;
  }
}
</script>

<template>
  <div class="flex-1 overflow-auto p-3.5 space-y-3 bg-surface">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
      <!-- 1. Contact & Next of Kin Section -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
        <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
          <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
            <Contact class="size-3.5 text-primary" />
            <span>{{ t("patient.section_contact") }} & {{ t("patient.next_of_kin") }}</span>
          </span>
        </div>
        <div class="space-y-1.5 text-xs">
          <div class="flex items-center justify-between py-0.5">
            <span class="text-muted-foreground flex items-center gap-1.5"><Phone class="size-3 text-muted-foreground" /> {{ t("patient.phone") }}</span>
            <span class="font-medium text-foreground">{{ patient.telecom.find((t2) => t2.system === "phone")?.value ?? "—" }}</span>
          </div>
          <div class="flex items-center justify-between py-0.5">
            <span class="text-muted-foreground flex items-center gap-1.5"><Mail class="size-3 text-muted-foreground" /> {{ t("patient.email") }}</span>
            <span class="font-medium text-foreground">{{ profile.profileSummary.value?.contact.email ?? "—" }}</span>
          </div>
          <div class="flex items-center justify-between py-0.5">
            <span class="text-muted-foreground flex items-center gap-1.5"><MapPin class="size-3 text-muted-foreground" /> {{ t("patient.address") }}</span>
            <span class="font-medium text-foreground truncate max-w-[200px]">{{ profile.contactAddress.value ?? "—" }}</span>
          </div>
          <div v-if="profile.profileSummary.value?.contact.nextOfKinName" class="flex items-center justify-between py-0.5">
            <span class="text-muted-foreground flex items-center gap-1.5"><Users class="size-3 text-muted-foreground" /> {{ t("patient.next_of_kin") }}</span>
            <span class="font-medium text-foreground text-right">
              {{ profile.profileSummary.value.contact.nextOfKinName }}
              <span v-if="profile.profileSummary.value.contact.nextOfKinPhone" class="block text-[11px] font-mono text-muted-foreground">
                {{ profile.profileSummary.value.contact.nextOfKinPhone }}
              </span>
            </span>
          </div>
        </div>
      </div>

      <!-- 2. Insurance Coverage Section -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
        <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
          <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
            <ShieldCheck class="size-3.5 text-emerald-500" />
            <span>{{ t("insurance.financial_class_and_payer") }}</span>
          </span>
        </div>
        <div>
          <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
            <div class="h-4 w-32 rounded bg-secondary/80" />
            <div class="h-4 w-40 rounded bg-secondary/60" />
          </div>
          <div v-else-if="!profile.profileSummary.value?.insurance" class="text-xs text-muted-foreground/70 py-3">
            <p>{{ t("patient.no_insurance") }} ({{ t("insurance.cash_self_pay") }})</p>
          </div>
          <div v-else class="space-y-1.5 text-xs">
            <div class="flex items-center justify-between py-0.5">
              <span class="text-muted-foreground">{{ t("patient.insurance_provider") }}</span>
              <span class="font-medium text-foreground">{{ profile.profileSummary.value!.insurance!.insuranceProvider ?? "—" }}</span>
            </div>
            <div class="flex items-center justify-between py-0.5">
              <span class="text-muted-foreground">{{ t("patient.insurance_member_id") }}</span>
              <span class="font-mono font-medium text-foreground">{{ profile.profileSummary.value!.insurance!.memberId ?? "—" }}</span>
            </div>
            <div class="flex items-center justify-between py-0.5">
              <span class="text-muted-foreground">{{ t("insurance.verification_status") }}</span>
              <Badge
                :variant="profile.profileSummary.value!.insurance!.verificationStatus === 'verified' ? 'success' : 'warning'"
                class="text-[11px]"
              >
                {{ profile.profileSummary.value!.insurance!.verificationStatus === 'verified' ? t("insurance.verification_verified") : t("insurance.verification_unverified") }}
              </Badge>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
      <!-- 3. Allergies & Clinical Risk Section -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
        <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
          <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
            <TriangleAlert class="size-3.5 text-amber-500" />
            <span>{{ t("patient.allergies") }}</span>
          </span>
          <Button
            variant="ghost"
            size="sm"
            class="h-6 px-2 text-xs gap-1 text-primary cursor-pointer"
            @click="allergyForm.openAllergyForm(patient.id, null)"
          >
            <Plus class="size-3" />
            {{ t("common.add", "Add") }}
          </Button>
        </div>
        <div>
          <div v-if="profile.isSummaryLoading.value" class="h-6 w-32 rounded bg-secondary/60 animate-pulse" />
          <div v-else-if="(profile.profileSummary.value?.alerts.length ?? 0) > 0" class="flex flex-wrap gap-1.5">
            <Badge
              v-for="allergy in profile.profileSummary.value?.alerts"
              :key="allergy.id"
              :variant="allergy.severity === 'severe' ? 'critical' : 'warning'"
              class="inline-flex items-center gap-1 text-xs"
            >
              <TriangleAlert class="size-3" aria-hidden="true" />
              {{ allergy.substanceName }} ({{ allergy.severity }})
            </Badge>
          </div>
          <div v-else class="flex items-center gap-1.5 py-1">
            <Badge variant="success" class="inline-flex items-center gap-1 text-xs">
              <CircleCheck class="size-3" aria-hidden="true" />
              {{ t("patient.no_allergies") }}
            </Badge>
          </div>
        </div>
      </div>

      <!-- 4. Previous Encounters & Visit History Section -->
      <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
        <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
          <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
            <History class="size-3.5 text-muted-foreground" />
            <span>{{ t("patient.recent_visits") }}</span>
          </span>
        </div>
        <div>
          <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
            <div class="h-10 w-full rounded bg-secondary/60" />
          </div>
          <p v-else-if="!profile.profileSummary.value?.latestEncounter" class="text-xs text-muted-foreground/70 py-2">
            {{ t("patient.no_visits") }}
          </p>
          <div v-else class="p-2.5 rounded-md border border-border/60 bg-surface/50 flex items-center justify-between text-xs">
            <div>
              <p class="font-semibold text-foreground text-xs">
                {{ formatClinicalDate(profile.profileSummary.value!.latestEncounter!.openedAt) }}
              </p>
              <p v-if="profile.profileSummary.value!.latestEncounter!.primaryClinicianName" class="text-xs text-muted-foreground">
                {{ t("appointment.attending") }}: {{ profile.profileSummary.value!.latestEncounter!.primaryClinicianName }}
              </p>
            </div>
            <Badge variant="secondary" class="text-xs">
              {{ profile.profileSummary.value!.latestEncounter!.status || 'Encounter' }}
            </Badge>
          </div>
        </div>
      </div>
    </div>
    
    <AllergyFormDialog :allergy-form="allergyForm" />
  </div>
</template>
