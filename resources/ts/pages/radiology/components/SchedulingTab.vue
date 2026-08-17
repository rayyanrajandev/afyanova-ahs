/**
 * SchedulingTab — Modality Booking & Pre-Exam Preparation (2027 Standard)
 * =========================================================================
 * - Quick Time Slot Presets (30m, 2h, Tomorrow Morning, Tomorrow Afternoon)
 * - Examination Suite & Modality Equipment Assignment
 * - Pre-Procedure Patient Preparation Checklist (Fasting, Hydration, Contrast)
 * - Reschedule and Safe Cancellation Workflows
 */

<script setup lang="ts">
import {
  AlertCircle,
  Calendar,
  CalendarClock,
  CheckCircle2,
  Clock,
  Droplet,
  Info,
  Layers,
  Sparkles,
  UtensilsCrossed,
  XCircle,
  Zap,
} from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { RadiologyOrder, UseRadiologyOrders } from "../composables/useRadiologyOrders";

const props = defineProps<{
  order: RadiologyOrder;
  radiology: UseRadiologyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const slot = ref("");
const cancelReason = ref("");
const showCancel = ref(false);
const selectedSuite = ref("Suite 1");

/** Reset the form when a different study is selected. */
watch(
  () => props.order.id,
  () => {
    slot.value = toLocalInput(props.order.scheduledFor);
    cancelReason.value = "";
    showCancel.value = false;
  },
  { immediate: true },
);

function toLocalInput(value: string | null | undefined): string {
  if (!value) return "";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";

  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const isBookable = computed(() => props.order.status === "ordered");
const isBooked = computed(() => props.order.status === "scheduled");
const canCancel = computed(() => ["ordered", "scheduled"].includes(props.order.status));

function applyPreset(minutesFromNow: number) {
  const d = new Date(Date.now() + minutesFromNow * 60 * 1000);
  slot.value = toLocalInput(d.toISOString());
}

function applyTomorrowPreset(hour: number) {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  d.setHours(hour, 0, 0, 0);
  slot.value = toLocalInput(d.toISOString());
}

async function book() {
  if (!slot.value) return;
  await props.radiology.scheduleStudy(props.order.id, new Date(slot.value).toISOString());
}

async function cancel() {
  if (!cancelReason.value.trim()) return;
  await props.radiology.cancelStudy(props.order.id, cancelReason.value.trim());
  showCancel.value = false;
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    <!-- Header Section -->
    <div class="flex items-center justify-between border-b border-border pb-3">
      <div class="flex items-center gap-2">
        <CalendarClock class="size-4 text-primary" />
        <h3 class="text-sm font-bold text-foreground">
          {{ t('radiology.scheduling_title', 'Modality Scheduling & Patient Preparation') }}
        </h3>
      </div>
      <Badge
        variant="outline"
        class="text-[10px] font-mono uppercase px-2 py-0.5"
        :class="isBooked ? 'border-emerald-500 text-emerald-600 bg-emerald-500/10' : 'border-amber-500 text-amber-600 bg-amber-500/10'"
      >
        {{ isBooked ? t('radiology.slot_booked', 'Booked & Reserved') : t('radiology.walk_in_eligible', 'Walk-in / Unscheduled') }}
      </Badge>
    </div>

    <!-- Active Booked Slot Banner -->
    <div
      v-if="isBooked"
      class="flex items-center justify-between rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3.5"
    >
      <div class="flex items-center gap-3">
        <CheckCircle2 class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
        <div>
          <p class="font-bold text-xs text-foreground">
            {{ t('radiology.scheduled_banner', 'Examination Slot Confirmed') }}
          </p>
          <p class="text-xs text-muted-foreground mt-0.5 font-mono">
            {{ props.order.scheduledFor ? new Date(props.order.scheduledFor).toLocaleString([], { dateStyle: 'full', timeStyle: 'short' }) : t('radiology.no_slot_recorded', 'No slot set') }}
          </p>
        </div>
      </div>

      <Button
        type="button"
        size="sm"
        variant="outline"
        class="h-7 text-xs font-semibold gap-1.5 px-3 border-emerald-500/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/15 cursor-pointer shrink-0"
        :disabled="props.radiology.isUpdatingOrder.value"
        @click="props.radiology.startStudy(props.order.id)"
      >
        <Zap class="size-3.5" />
        <span>{{ t('radiology.call_patient_start', 'Call Patient & Start Scan') }}</span>
      </Button>
    </div>

    <!-- Walk-In Quick Callout -->
    <div
      v-else-if="isBookable"
      class="flex items-center justify-between rounded-lg border border-primary/30 bg-primary/5 p-3"
    >
      <div class="flex items-center gap-2.5">
        <Info class="size-4 text-primary shrink-0" />
        <div class="text-xs">
          <span class="font-semibold text-foreground">{{ t('radiology.walk_in_notice_title', 'Walk-in Study:') }}</span>
          <span class="text-muted-foreground ml-1">{{ t('radiology.walk_in_notice_desc', 'If the patient is already at the department, you can proceed directly to the Examination tab without scheduling.') }}</span>
        </div>
      </div>
      <Button
        type="button"
        size="sm"
        class="h-7 text-xs font-semibold gap-1 px-3 cursor-pointer shrink-0"
        :disabled="props.radiology.isUpdatingOrder.value"
        @click="props.radiology.startStudy(props.order.id)"
      >
        <Zap class="size-3.5" />
        <span>{{ t('radiology.start_now', 'Start Now') }}</span>
      </Button>
    </div>

    <!-- Booking Form & Presets Grid -->
    <div v-if="isBookable || isBooked" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Left: Slot Picker & Quick Presets -->
      <section class="rounded-lg border border-border bg-surface p-4 space-y-3.5 shadow-2xs">
        <div class="flex items-center justify-between">
          <Label class="text-xs font-bold text-foreground">
            {{ t('radiology.select_slot', 'Select Appointment Slot') }}
          </Label>
          <span class="text-[10px] text-muted-foreground font-mono">{{ t('radiology.standard_slot_hint', 'Standard 30m slot') }}</span>
        </div>

        <Input
          v-model="slot"
          type="datetime-local"
          class="h-8 text-xs font-mono bg-background"
        />

        <!-- Quick 1-Click Presets -->
        <div class="space-y-1.5 pt-1">
          <span class="text-[10.5px] font-semibold text-muted-foreground block">
            {{ t('radiology.quick_presets', 'Quick Presets') }}:
          </span>
          <div class="grid grid-cols-2 gap-1.5">
            <Button
              type="button"
              variant="outline"
              size="sm"
              class="h-7 text-[11px] font-medium justify-start px-2 hover:bg-muted cursor-pointer"
              @click="applyPreset(30)"
            >
              <Clock class="size-3 text-primary mr-1 shrink-0" />
              <span>{{ t('radiology.preset_today_30m', 'Today +30 mins') }}</span>
            </Button>

            <Button
              type="button"
              variant="outline"
              size="sm"
              class="h-7 text-[11px] font-medium justify-start px-2 hover:bg-muted cursor-pointer"
              @click="applyPreset(120)"
            >
              <Clock class="size-3 text-primary mr-1 shrink-0" />
              <span>{{ t('radiology.preset_today_2h', 'Today +2 hours') }}</span>
            </Button>

            <Button
              type="button"
              variant="outline"
              size="sm"
              class="h-7 text-[11px] font-medium justify-start px-2 hover:bg-muted cursor-pointer"
              @click="applyTomorrowPreset(9)"
            >
              <Calendar class="size-3 text-primary mr-1 shrink-0" />
              <span>{{ t('radiology.preset_tomorrow_morning', 'Tomorrow 09:00 AM') }}</span>
            </Button>

            <Button
              type="button"
              variant="outline"
              size="sm"
              class="h-7 text-[11px] font-medium justify-start px-2 hover:bg-muted cursor-pointer"
              @click="applyTomorrowPreset(14)"
            >
              <Calendar class="size-3 text-primary mr-1 shrink-0" />
              <span>{{ t('radiology.preset_tomorrow_afternoon', 'Tomorrow 02:00 PM') }}</span>
            </Button>
          </div>
        </div>

        <Button
          type="button"
          size="sm"
          class="w-full h-8 text-xs font-semibold gap-1.5 cursor-pointer disabled:opacity-60 mt-2"
          :disabled="!slot || props.radiology.isUpdatingOrder.value"
          @click="book"
        >
          <CalendarClock class="size-3.5" />
          <span>{{ isBooked ? t('radiology.reschedule_action', 'Update Reserved Slot') : t('radiology.schedule_action', 'Confirm Booking') }}</span>
        </Button>
      </section>

      <!-- Right: Preparation Checklist & Clinical Indication -->
      <section class="rounded-lg border border-border bg-surface p-4 space-y-3.5 shadow-2xs">
        <div>
          <h4 class="text-xs font-bold text-foreground">{{ t('radiology.prep_guidelines', 'Patient Preparation Guidelines') }}</h4>
          <p class="text-[11px] text-muted-foreground mt-0.5">{{ t('radiology.prep_guidelines_desc', { study: props.order.studyDescription }) }}</p>
        </div>

        <div class="space-y-2 text-xs">
          <div class="flex items-start gap-2 p-2 rounded-md bg-muted/30 border border-border/60">
            <Droplet class="size-4 text-sky-600 shrink-0 mt-0.5" />
            <div>
              <span class="font-bold text-foreground">{{ t('radiology.prep_hydration_title', 'Hydration & Bladder:') }}</span>
              <p class="text-[11px] text-muted-foreground mt-0.5">{{ t('radiology.prep_hydration_desc', 'For pelvic and lower abdominal ultrasound, ensure full bladder (drink 750ml water 1 hr prior).') }}</p>
            </div>
          </div>

          <div class="flex items-start gap-2 p-2 rounded-md bg-muted/30 border border-border/60">
            <UtensilsCrossed class="size-4 text-amber-600 shrink-0 mt-0.5" />
            <div>
              <span class="font-bold text-foreground">{{ t('radiology.prep_fasting_title', 'Fasting Protocol:') }}</span>
              <p class="text-[11px] text-muted-foreground mt-0.5">{{ t('radiology.prep_fasting_desc', 'NPO for 6 hours prior to hepatobiliary ultrasound or IV contrast studies.') }}</p>
            </div>
          </div>
        </div>

        <!-- Clinical Indication Callout -->
        <div v-if="props.order.clinicalIndication" class="border-t border-border/60 pt-2.5">
          <span class="text-[10.5px] font-bold text-foreground uppercase tracking-wider block mb-1">
            {{ t('radiology.clinical_indication', 'Doctor Clinical Indication') }}
          </span>
          <p class="rounded-md border border-border/70 bg-muted/20 p-2 text-xs text-foreground font-medium">
            {{ props.order.clinicalIndication }}
          </p>
        </div>
      </section>
    </div>

    <!-- Safe Cancellation Accordion / Action -->
    <div v-if="canCancel" class="border-t border-border/70 pt-3">
      <div v-if="!showCancel">
        <Button
          type="button"
          size="sm"
          variant="ghost"
          class="h-7 gap-1 px-2.5 text-xs text-rose-600 hover:bg-rose-500/10 cursor-pointer"
          @click="showCancel = true"
        >
          <XCircle class="size-3.5" />
          <span>{{ t('radiology.cancel_study', 'Cancel Imaging Order') }}</span>
        </Button>
      </div>

      <div v-else class="p-3.5 rounded-lg border border-rose-500/30 bg-rose-500/5 space-y-2.5 max-w-lg">
        <div class="flex items-center gap-2 text-rose-700 dark:text-rose-400 font-bold text-xs">
          <AlertCircle class="size-4" />
          <span>{{ t('radiology.cancel_reason_title', 'Reason for Cancellation') }}</span>
        </div>

        <Textarea
          v-model="cancelReason"
          rows="2"
          class="text-xs resize-none bg-background"
          :placeholder="t('radiology.cancel_reason_placeholder', 'e.g. Patient declined, duplicate request, contraindicated...')"
        />

        <div class="flex items-center gap-2">
          <Button
            type="button"
            size="sm"
            class="h-7 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white cursor-pointer"
            :disabled="!cancelReason.trim() || props.radiology.isUpdatingOrder.value"
            @click="cancel"
          >
            {{ t('radiology.confirm_cancel', 'Confirm Cancellation') }}
          </Button>

          <Button
            type="button"
            size="sm"
            variant="ghost"
            class="h-7 text-xs cursor-pointer"
            @click="showCancel = false"
          >
            {{ t('common.cancel', 'Back') }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
