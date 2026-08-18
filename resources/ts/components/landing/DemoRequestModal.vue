<script setup lang="ts">
import { ref, reactive } from "vue";
import { useI18n } from "vue-i18n";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Building2,
  CheckCircle2,
  HeartPulse,
  Mail,
  Phone,
  Send,
  Sparkles,
  Stethoscope,
  X,
} from "lucide-vue-next";

const props = defineProps<{
  open: boolean;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
}>();

const { t } = useI18n({ useScope: "global" });

const isSubmitting = ref(false);
const isSuccess = ref(false);

const form = reactive({
  name: "",
  email: "",
  phone: "",
  facilityName: "",
  facilityType: "private_hospital",
  facilitySize: "50_200",
  notes: "",
  workflows: ["clinical", "pharmacy", "billing"] as string[],
});

const errors = reactive({
  name: "",
  email: "",
  phone: "",
  facilityName: "",
});

function toggleWorkflow(key: string) {
  const idx = form.workflows.indexOf(key);
  if (idx >= 0) {
    form.workflows.splice(idx, 1);
  } else {
    form.workflows.push(key);
  }
}

function validate() {
  let valid = true;
  errors.name = "";
  errors.email = "";
  errors.phone = "";
  errors.facilityName = "";

  if (!form.name.trim()) {
    errors.name = "Name is required";
    valid = false;
  }
  if (!form.email.trim() || !form.email.includes("@")) {
    errors.email = "Valid work email is required";
    valid = false;
  }
  if (!form.phone.trim()) {
    errors.phone = "Phone number is required";
    valid = false;
  }
  if (!form.facilityName.trim()) {
    errors.facilityName = "Facility name is required";
    valid = false;
  }
  return valid;
}

function handleSubmit() {
  if (!validate()) return;

  isSubmitting.value = true;
  // Simulate prompt presentation booking response
  setTimeout(() => {
    isSubmitting.value = false;
    isSuccess.value = true;
  }, 900);
}

function handleClose() {
  emit("update:open", false);
  // Reset success state after closing animation
  setTimeout(() => {
    if (isSuccess.value) {
      isSuccess.value = false;
      form.name = "";
      form.email = "";
      form.phone = "";
      form.facilityName = "";
      form.notes = "";
    }
  }, 300);
}
</script>

<template>
  <Dialog :open="open" @update:open="(val) => emit('update:open', val)">
    <DialogContent
      class="max-w-xl p-0 overflow-hidden border-border/80 bg-background shadow-2xl rounded-2xl sm:rounded-2xl"
    >
      <!-- Top banner / Accent -->
      <div
        class="relative bg-gradient-to-r from-teal-900/40 via-cyan-900/30 to-background p-6 border-b border-border/60"
      >
        <div class="flex items-center gap-3">
          <div
            class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-md"
          >
            <HeartPulse class="h-5 w-5" />
          </div>
          <div>
            <div
              class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary mb-1"
            >
              <Sparkles class="h-3 w-3" />
              <span>Enterprise Demonstration</span>
            </div>
            <DialogTitle
              class="text-lg font-bold tracking-tight text-foreground"
            >
              {{ t("landing.demo_modal_title") }}
            </DialogTitle>
          </div>
        </div>
        <p class="text-xs text-muted-foreground pt-2 leading-relaxed">
          {{ t("landing.demo_modal_subtitle") }}
        </p>
      </div>

      <!-- Success State -->
      <div v-if="isSuccess" class="p-8 text-center space-y-4">
        <div
          class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
        >
          <CheckCircle2 class="h-8 w-8" />
        </div>
        <div class="space-y-2">
          <h3 class="text-lg font-bold text-foreground">
            {{ t("landing.demo_success_title") }}
          </h3>
          <p
            class="text-xs text-muted-foreground max-w-md mx-auto leading-relaxed"
          >
            {{ t("landing.demo_success_desc") }}
          </p>
        </div>
        <div
          class="rounded-xl border border-border/60 bg-muted/30 p-3 text-xs text-left max-w-md mx-auto space-y-1"
        >
          <div class="font-semibold text-foreground">
            Facility: {{ form.facilityName }}
          </div>
          <div class="text-muted-foreground">
            Contact: {{ form.name }} ({{ form.email }})
          </div>
          <div class="text-muted-foreground">Phone: {{ form.phone }}</div>
        </div>
        <div class="pt-4">
          <Button @click="handleClose" class="cursor-pointer px-6">
            {{ t("landing.demo_btn_close") }}
          </Button>
        </div>
      </div>

      <!-- Form State -->
      <form
        v-else
        @submit.prevent="handleSubmit"
        class="p-6 space-y-4 max-h-[75vh] overflow-y-auto"
      >
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Full Name -->
          <div class="space-y-1.5">
            <Label for="demo-name" class="text-xs font-semibold">
              {{ t("landing.demo_field_name") }}
              <span class="text-destructive">*</span>
            </Label>
            <Input
              id="demo-name"
              v-model="form.name"
              :placeholder="t('landing.demo_field_name_placeholder')"
              class="h-9 text-xs"
              :class="{ 'border-destructive': errors.name }"
            />
            <span v-if="errors.name" class="text-[10px] text-destructive">{{
              errors.name
            }}</span>
          </div>

          <!-- Work Email -->
          <div class="space-y-1.5">
            <Label for="demo-email" class="text-xs font-semibold">
              {{ t("landing.demo_field_email") }}
              <span class="text-destructive">*</span>
            </Label>
            <Input
              id="demo-email"
              type="email"
              v-model="form.email"
              :placeholder="t('landing.demo_field_email_placeholder')"
              class="h-9 text-xs"
              :class="{ 'border-destructive': errors.email }"
            />
            <span v-if="errors.email" class="text-[10px] text-destructive">{{
              errors.email
            }}</span>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Phone Number -->
          <div class="space-y-1.5">
            <Label for="demo-phone" class="text-xs font-semibold">
              {{ t("landing.demo_field_phone") }}
              <span class="text-destructive">*</span>
            </Label>
            <Input
              id="demo-phone"
              v-model="form.phone"
              :placeholder="t('landing.demo_field_phone_placeholder')"
              class="h-9 text-xs"
              :class="{ 'border-destructive': errors.phone }"
            />
            <span v-if="errors.phone" class="text-[10px] text-destructive">{{
              errors.phone
            }}</span>
          </div>

          <!-- Facility Name -->
          <div class="space-y-1.5">
            <Label for="demo-facility" class="text-xs font-semibold">
              {{ t("landing.demo_field_facility_name") }}
              <span class="text-destructive">*</span>
            </Label>
            <Input
              id="demo-facility"
              v-model="form.facilityName"
              :placeholder="
                t('landing.demo_field_facility_name_placeholder')
              "
              class="h-9 text-xs"
              :class="{ 'border-destructive': errors.facilityName }"
            />
            <span
              v-if="errors.facilityName"
              class="text-[10px] text-destructive"
              >{{ errors.facilityName }}</span
            >
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Facility Type -->
          <div class="space-y-1.5">
            <Label for="demo-type" class="text-xs font-semibold">
              {{ t("landing.demo_field_facility_type") }}
            </Label>
            <select
              id="demo-type"
              v-model="form.facilityType"
              class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-xs shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              <option value="private_hospital">
                Private Hospital (50+ Beds)
              </option>
              <option value="polyclinic">
                Polyclinic / Medical Center (10-50 Beds)
              </option>
              <option value="specialty_clinic">
                Specialty Clinic / Outpatient
              </option>
              <option value="group_chain">
                Hospital Group / Multi-branch Network
              </option>
              <option value="diagnostic_pharmacy">
                Diagnostic & Pharmacy Chain
              </option>
            </select>
          </div>

          <!-- Facility Size -->
          <div class="space-y-1.5">
            <Label for="demo-size" class="text-xs font-semibold">
              {{ t("landing.demo_field_facility_size") }}
            </Label>
            <select
              id="demo-size"
              v-model="form.facilitySize"
              class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-xs shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              <option value="small">&lt; 50 Encounters/Day</option>
              <option value="50_200">50 – 200 Encounters/Day</option>
              <option value="200_500">200 – 500 Encounters/Day</option>
              <option value="large">500+ Encounters/Day (Enterprise)</option>
            </select>
          </div>
        </div>

        <!-- Workflows of interest -->
        <div class="space-y-2">
          <Label class="text-xs font-semibold"
            >{{ t("landing.demo_field_workflows") }}</Label
          >
          <div class="flex flex-wrap gap-1.5 text-xs">
            <button
              v-for="wf in [
                { id: 'clinical', label: 'Clinician CPOE & EMR' },
                { id: 'pharmacy', label: 'Pharmacy FEFO & Store' },
                { id: 'billing', label: 'NHIF & GePG Billing' },
                { id: 'queue', label: 'Patient Queue & Triage' },
                { id: 'lab', label: 'Laboratory Analyzers' },
                { id: 'multi', label: 'Multi-Facility Scale' },
              ]"
              :key="wf.id"
              type="button"
              class="rounded-lg border px-2.5 py-1 text-xs transition-all cursor-pointer"
              :class="
                form.workflows.includes(wf.id)
                  ? 'border-primary bg-primary/10 text-primary font-semibold'
                  : 'border-border/60 bg-muted/40 text-muted-foreground hover:text-foreground'
              "
              @click="toggleWorkflow(wf.id)"
            >
              {{ wf.label }}
            </button>
          </div>
        </div>

        <!-- Notes -->
        <div class="space-y-1.5">
          <Label for="demo-notes" class="text-xs font-semibold">
            {{ t("landing.demo_field_notes") }}
          </Label>
          <Textarea
            id="demo-notes"
            v-model="form.notes"
            :placeholder="t('landing.demo_field_notes_placeholder')"
            rows="2"
            class="text-xs"
          />
        </div>

        <!-- Action buttons -->
        <div
          class="flex items-center justify-end gap-2 pt-3 border-t border-border/60"
        >
          <Button
            type="button"
            variant="ghost"
            size="sm"
            class="text-xs cursor-pointer"
            @click="handleClose"
          >
            Cancel
          </Button>
          <Button
            type="submit"
            size="sm"
            class="h-9 gap-1.5 px-4 text-xs font-semibold cursor-pointer shadow-sm"
            :disabled="isSubmitting"
          >
            <Send v-if="!isSubmitting" class="h-3.5 w-3.5" />
            <span>{{
              isSubmitting
                ? t("landing.demo_btn_submitting")
                : t("landing.demo_btn_submit")
            }}</span>
          </Button>
        </div>
      </form>
    </DialogContent>
  </Dialog>
</template>
