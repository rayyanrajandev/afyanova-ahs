/**
 * PatientRegistrationFields — 2027 Enterprise Registration Fields (Volume 2.1 §6)
 * =========================================================================
 * 2027 Modern Enterprise Health System Upgrades:
 * - Structured clinical card layout (Identity, Contact, Payer & Coverage, Emergency Contact)
 * - Real-time in-line duplicate prevention banner with 1-click "Open Existing"
 * - Bi-directional Age ↔ Date of Birth calculation
 * - Integrated Payer / Insurance selection at front-desk intake
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- v-model="search.searchQuery.value" */
import {
  AlertTriangle,
  Calculator,
  Calendar,
  CreditCard,
  ExternalLink,
  HeartPulse,
  Info,
  Loader2,
  MapPin,
  Phone as PhoneIcon,
  Plus,
  Shield,
  ShieldCheck,
  Sparkles,
  User,
  Users,
} from "lucide-vue-next";
import { useField, useFormContext } from "vee-validate";
import { ref, watch, onBeforeUnmount, onMounted, computed } from "vue";
import { useI18n } from "vue-i18n";
import FormField from "@/components/common/FormField.vue";
import PhoneInput from "@/components/common/PhoneInput.vue";
import SearchableSelect from "@/components/common/SearchableSelect.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DatePicker } from "@/components/ui/date-picker";
import { Input } from "@/components/ui/input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useLocationOptions } from "@/composables/useLocationOptions";
import type { usePatientRegistration } from "@/pages/reception/composables/usePatientRegistration";
import { saveDraft } from "@/pages/reception/registrationDraft";
import { ageFrom, dobFromAge } from "@/pages/reception/registrationSchema";

const { t, locale } = useI18n();
const { values } = useFormContext();

const FIRST_NAME_ID = "patient-registration-first-name";
const todayIso = new Date().toISOString().slice(0, 10);

type ISOString = string;

const props = withDefaults(
  defineProps<{
    autosaveDraft?: boolean;
    registration?: ReturnType<typeof usePatientRegistration>;
  }>(),
  {
    autosaveDraft: true,
    registration: undefined,
  },
);

const emit = defineEmits<{
  "draft-saved": [savedAt: ISOString];
}>();

// ---- Demographics Fields ----
const { value: firstName, errorMessage: firstNameError, handleBlur: firstNameBlur } =
  useField<string>("firstName");
const { value: middleName, errorMessage: middleNameError, handleBlur: middleNameBlur } =
  useField<string>("middleName");
const { value: lastName, errorMessage: lastNameError, handleBlur: lastNameBlur } =
  useField<string>("lastName");
const { value: dateOfBirth, errorMessage: dateOfBirthError, handleBlur: dateOfBirthBlur } =
  useField<string>("dateOfBirth");
const { value: gender, errorMessage: genderError, handleBlur: genderBlur } = useField<
  "male" | "female" | "other" | "unknown"
>("gender");
const { value: nationalId, errorMessage: nationalIdError, handleBlur: nationalIdBlur } =
  useField<string>("nationalId");

// ---- Contact Fields ----
const { value: phone, errorMessage: phoneError, handleBlur: phoneBlur } = useField<string>("phone");
const { value: email, errorMessage: emailError, handleBlur: emailBlur } = useField<string>("email");
const { value: addressLine, errorMessage: addressLineError, handleBlur: addressLineBlur } =
  useField<string>("addressLine");
const { value: region, errorMessage: regionError, handleBlur: regionBlur } = useField<string>("region");
const { value: district, errorMessage: districtError, handleBlur: districtBlur } =
  useField<string>("district");
const { value: countryCode, errorMessage: countryCodeError, handleBlur: countryCodeBlur } =
  useField<string>("countryCode", undefined, { initialValue: "TZ" });

// ---- Financial Coverage / Insurance Fields ----
const { value: coverageType } = useField<"self_pay" | "insurance">("coverageType", undefined, {
  initialValue: "self_pay",
});
const { value: insuranceProvider, errorMessage: insuranceProviderError, handleBlur: insuranceProviderBlur } =
  useField<string>("insuranceProvider");
const { value: memberNumber, errorMessage: memberNumberError, handleBlur: memberNumberBlur } =
  useField<string>("memberNumber");
const { value: policyType, errorMessage: policyTypeError, handleBlur: policyTypeBlur } =
  useField<string>("policyType");

// ---- Emergency Contact Fields ----
const { value: nextOfKinName, errorMessage: nextOfKinNameError, handleBlur: nextOfKinNameBlur } =
  useField<string>("nextOfKinName");
const { value: nextOfKinPhone, errorMessage: nextOfKinPhoneError, handleBlur: nextOfKinPhoneBlur } =
  useField<string>("nextOfKinPhone");

const showEmergencyContact = ref(false);

// ---- Location Helpers ----
const { regionOptions, districtOptionsFor } = useLocationOptions();

watch(region, (newRegion, oldRegion) => {
  if (newRegion === oldRegion) return;
  if (!district.value) return;
  const stillValid = districtOptionsFor(newRegion).some(
    (option) => option.value === district.value,
  );
  if (!stillValid) district.value = "";
});

// ---- Bi-directional Age ↔ DOB Engine with Popover ----
const showAgePopover = ref(false);
const customAge = ref<number | null>(null);

const computedAgeDisplay = computed<string | null>(() => {
  if (!dateOfBirth.value) return null;
  const calculated = ageFrom(dateOfBirth.value);
  return `${calculated} yrs`;
});

let ageDebounce: ReturnType<typeof setTimeout> | undefined;
function onAgeInputChanged() {
  if (ageDebounce) clearTimeout(ageDebounce);
  ageDebounce = setTimeout(() => {
    if (customAge.value != null && customAge.value >= 0 && customAge.value <= 125) {
      dateOfBirth.value = dobFromAge(customAge.value);
    }
  }, 150);
}

function openAgePopover() {
  if (dateOfBirth.value) {
    customAge.value = ageFrom(dateOfBirth.value);
  }
  showAgePopover.value = true;
}

// ---- Real-Time Duplicate Detection Watcher ----
watch(
  [firstName, lastName, dateOfBirth, phone, nationalId],
  ([fName, lName, dob, ph, nid]) => {
    if (!props.registration) return;
    props.registration.checkLiveDuplicates({
      firstName: fName?.trim(),
      lastName: lName?.trim(),
      dateOfBirth: dob?.trim(),
      phone: ph?.trim(),
      nationalId: nid?.trim(),
      gender: gender.value,
    });
  },
  { deep: true },
);

// ---- Draft Autosave ----
const DRAFT_DEBOUNCE_MS = 250;
let draftTimer: ReturnType<typeof setTimeout> | undefined;

function hasMeaningfulDraft(): boolean {
  return Object.entries(values).some(
    ([, v]) => typeof v === "string" && v.trim() !== "",
  );
}

function syncDraft() {
  if (!props.autosaveDraft) return;
  if (draftTimer) clearTimeout(draftTimer);
  if (!hasMeaningfulDraft()) return;
  draftTimer = setTimeout(() => {
    draftTimer = undefined;
    const state = saveDraft({ ...values });
    emit("draft-saved", state.savedAt);
  }, DRAFT_DEBOUNCE_MS);
}

onMounted(() => {
  syncDraft();
  if (props.autosaveDraft) {
    document.getElementById(FIRST_NAME_ID)?.focus();
  }
});

onBeforeUnmount(() => {
  if (draftTimer) clearTimeout(draftTimer);
});

watch(values, syncDraft, { deep: true });
</script>

<template>
  <div class="space-y-3.5">
    <!-- ============================================================
         REAL-TIME IN-LINE DUPLICATE PREVENTION BANNER
         ============================================================ -->
    <div
      v-if="registration?.liveDuplicates.value.duplicates.length"
      class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-warning-foreground shadow-xs animate-in fade-in duration-200"
      role="alert"
    >
      <div class="flex items-start justify-between gap-3">
        <div class="flex items-start gap-2.5">
          <AlertTriangle class="size-4.5 text-warning shrink-0 mt-0.5" aria-hidden="true" />
          <div>
            <h4 class="text-xs font-bold text-foreground">
              Potential Existing Patient Record Detected
            </h4>
            <p class="text-[11px] text-muted-foreground mt-0.5">
              Found {{ registration.liveDuplicates.value.duplicates.length }} existing patient(s) matching entered identification criteria:
            </p>
            <div class="mt-2 space-y-1.5">
              <div
                v-for="dup in registration.liveDuplicates.value.duplicates.slice(0, 3)"
                :key="dup.id ?? ''"
                class="flex flex-wrap items-center justify-between gap-2.5 rounded border border-border/80 bg-surface p-2 text-xs shadow-2xs"
              >
                <div class="flex items-center gap-2">
                  <span class="font-semibold text-foreground">
                    {{ dup.firstName }} {{ dup.lastName }}
                  </span>
                  <span class="font-mono text-muted-foreground text-[11px]">
                    MRN: {{ dup.patientNumber || '—' }}
                  </span>
                  <span v-if="dup.dateOfBirth" class="text-muted-foreground text-[11px]">
                    DOB: {{ dup.dateOfBirth }}
                  </span>
                  <span v-if="dup.phone" class="font-mono text-muted-foreground text-[11px]">
                    📞 {{ dup.phone }}
                  </span>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  class="h-6.5 text-[11px] gap-1 px-2 cursor-pointer font-medium hover:bg-primary hover:text-primary-foreground"
                  @click="registration.openExistingDuplicate(dup.id)"
                >
                  <span>{{ t("registration.open_existing_record") }}</span>
                  <ExternalLink class="size-3" aria-hidden="true" />
                </Button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================
         SECTION 1: PATIENT IDENTITY & DEMOGRAPHICS
         ============================================================ -->
    <div class="rounded-lg border border-border bg-surface p-3.5 sm:p-4 shadow-2xs space-y-3">
      <div class="flex items-center justify-between pb-2 border-b border-border">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
            <User class="size-3.5" aria-hidden="true" />
          </div>
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
              {{ t("patient.section_identity") }}
            </h3>
          </div>
        </div>
        <span class="text-[11px] text-muted-foreground">
          {{ t("registration.basic_demographics") }}
        </span>
      </div>

      <div class="grid grid-cols-1 gap-3 @lg:grid-cols-3">
        <FormField
          :html-for="props.autosaveDraft ? FIRST_NAME_ID : undefined"
          :label="t('patient.first_name')"
          required
          :error="firstNameError"
        >
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="firstName"
              type="text"
              required
              :placeholder="t('patient.first_name')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="firstNameBlur($event, true)"
            />
          </template>
        </FormField>

        <FormField :label="t('patient.middle_name')" :error="middleNameError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="middleName"
              type="text"
              :placeholder="t('patient.middle_name')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="middleNameBlur($event, true)"
            />
          </template>
        </FormField>

        <FormField :label="t('patient.last_name')" required :error="lastNameError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="lastName"
              type="text"
              required
              :placeholder="t('patient.last_name')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="lastNameBlur($event, true)"
            />
          </template>
        </FormField>

        <FormField :label="t('patient.sex')" required :error="genderError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Select v-model="gender">
              <SelectTrigger
                :id="id"
                class="w-full"
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="genderBlur($event, true)"
              >
                <SelectValue :placeholder="t('patient.sex')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="male">{{ t("patient.gender_male") }}</SelectItem>
                <SelectItem value="female">{{ t("patient.gender_female") }}</SelectItem>
                <SelectItem value="other">{{ t("patient.gender_other") }}</SelectItem>
              </SelectContent>
            </Select>
          </template>
        </FormField>

        <!-- DOB & Age Bi-directional Picker with Floating Popover -->
        <div class="@lg:col-span-2 space-y-1">
          <div class="flex items-center justify-between text-xs">
            <span class="font-medium text-foreground flex items-center gap-1.5">
              {{ t('patient.date_of_birth') }}
              <span class="text-xs font-normal text-muted-foreground/70">{{ t('common.required') }}</span>
              <Badge v-if="computedAgeDisplay" variant="secondary" class="ml-1 text-[10px] font-mono px-1.5 py-0">
                {{ computedAgeDisplay }}
              </Badge>
            </span>

            <!-- Age Estimator Floating Popover -->
            <Popover v-model:open="showAgePopover">
              <PopoverTrigger as-child>
                <button
                  type="button"
                  class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline font-medium cursor-pointer"
                  @click="openAgePopover"
                >
                  <Calculator class="size-3" aria-hidden="true" />
                  <span>{{ t("registration.estimate_from_age") }}</span>
                </button>
              </PopoverTrigger>
              <PopoverContent align="end" :side-offset="6" class="w-64 p-3 space-y-2.5 text-xs shadow-elevation-md">
                <div class="flex items-center justify-between font-semibold text-foreground">
                  <span>{{ t("registration.approx_age") }}</span>
                  <span v-if="customAge != null" class="font-mono text-primary font-bold">{{ customAge }} yrs</span>
                </div>
                <div class="flex items-center gap-2">
                  <Input
                    v-model.number="customAge"
                    type="number"
                    min="0"
                    max="125"
                    placeholder="e.g. 34"
                    class="h-8 text-xs font-mono"
                    @input="onAgeInputChanged"
                  />
                  <Button size="sm" class="h-8 text-xs px-2.5" @click="showAgePopover = false">
                    {{ t("common.apply", "Done") }}
                  </Button>
                </div>
                <p class="text-[10.5px] text-muted-foreground leading-tight">
                  {{ t("registration.age_helper_hint") }}
                </p>
              </PopoverContent>
            </Popover>
          </div>

          <FormField :error="dateOfBirthError">
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <DatePicker
                :id="id"
                v-model="dateOfBirth"
                :max-value="todayIso"
                :locale="locale"
                :placeholder="t('patient.date_of_birth')"
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="dateOfBirthBlur($event, true)"
              />
            </template>
          </FormField>
        </div>

        <FormField :label="t('patient.national_id')" :error="nationalIdError" class="@lg:col-span-3">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="nationalId"
              type="text"
              :placeholder="t('patient.national_id_placeholder')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="nationalIdBlur($event, true)"
            />
          </template>
        </FormField>
      </div>
    </div>

    <!-- ============================================================
         SECTION 2: CONTACT & RESIDENCE
         ============================================================ -->
    <div class="rounded-lg border border-border bg-surface p-3.5 sm:p-4 shadow-2xs space-y-3">
      <div class="flex items-center justify-between pb-2 border-b border-border">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
            <PhoneIcon class="size-3.5" aria-hidden="true" />
          </div>
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
              {{ t("patient.section_contact") }} & {{ t("patient.residence") }}
            </h3>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 @lg:grid-cols-3">
        <FormField :label="t('patient.phone')" required :error="phoneError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <PhoneInput
              :id="id"
              v-model="phone"
              required
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="phoneBlur($event, true)"
            />
          </template>
        </FormField>

        <FormField :label="t('patient.email')" :error="emailError" class="@lg:col-span-2">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="email"
              type="email"
              placeholder="name@example.com (Optional)"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="emailBlur($event, true)"
            />
          </template>
        </FormField>

        <div class="@lg:col-span-3">
          <FormField :label="t('patient.address')" required :error="addressLineError">
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <Input
                :id="id"
                v-model="addressLine"
                type="text"
                :placeholder="t('patient.street')"
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="addressLineBlur($event, true)"
              />
            </template>
          </FormField>
        </div>

        <FormField :label="t('patient.region')" required :error="regionError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <SearchableSelect
              :id="id"
              v-model="region"
              :options="regionOptions"
              :placeholder="t('patient.region_placeholder')"
              :empty-text="t('patient.region_empty')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="regionBlur($event, true)"
            />
          </template>
        </FormField>

        <FormField :label="t('patient.district')" required :error="districtError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <SearchableSelect
              :id="id"
              v-model="district"
              :options="districtOptionsFor(region)"
              :disabled="!region"
              :placeholder="t('patient.district_placeholder')"
              :disabled-text="t('patient.district_choose_region_first')"
              :empty-text="t('patient.district_empty')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="districtBlur($event, true)"
            />
          </template>
        </FormField>

        <FormField :label="t('patient.country_code')" required :error="countryCodeError">
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="countryCode"
              type="text"
              maxlength="2"
              :placeholder="t('patient.country_code_placeholder')"
              :aria-describedby="ariaDescribedby"
              :aria-invalid="ariaInvalid"
              @blur="countryCodeBlur($event, true)"
            />
          </template>
        </FormField>
      </div>
    </div>

    <!-- ============================================================
         SECTION 3: FINANCIAL COVERAGE & EMERGENCY CONTACT
         ============================================================ -->
    <div class="rounded-lg border border-border bg-surface p-3.5 sm:p-4 shadow-2xs space-y-4">
      <!-- Payer & Financial Class -->
      <div class="space-y-3">
        <div class="flex items-center justify-between pb-2 border-b border-border flex-wrap gap-2.5">
          <div class="flex items-center gap-2">
            <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
              <ShieldCheck class="size-3.5" aria-hidden="true" />
            </div>
            <div>
              <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                {{ t("insurance.financial_class_and_payer") }}
              </h3>
            </div>
          </div>

          <!-- Payer Type Switcher Segment -->
          <div class="inline-flex rounded-lg bg-muted p-0.5 text-xs font-medium border border-border/60">
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-all cursor-pointer"
              :class="
                coverageType === 'self_pay'
                  ? 'bg-surface text-foreground font-semibold shadow-xs'
                  : 'text-muted-foreground hover:text-foreground'
              "
              @click="coverageType = 'self_pay'"
            >
              <CreditCard class="size-3" aria-hidden="true" />
              <span>{{ t("insurance.cash_self_pay") }}</span>
            </button>
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-all cursor-pointer"
              :class="
                coverageType === 'insurance'
                  ? 'bg-surface text-foreground font-semibold shadow-xs'
                  : 'text-muted-foreground hover:text-foreground'
              "
              @click="coverageType = 'insurance'"
            >
              <Shield class="size-3 text-primary" aria-hidden="true" />
              <span>{{ t("insurance.health_insurance_corporate") }}</span>
            </button>
          </div>
        </div>

        <!-- Inline Insurance Details (when Insurance selected) -->
        <div
          v-if="coverageType === 'insurance'"
          class="grid grid-cols-1 gap-3 @lg:grid-cols-3 animate-in fade-in duration-150"
        >
          <FormField :label="t('insurance.provider_scheme')" required :error="insuranceProviderError">
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <Input
                :id="id"
                v-model="insuranceProvider"
                type="text"
                placeholder="e.g. NHIF, Jubilee, AAR, Strategis"
                required
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="insuranceProviderBlur($event, true)"
              />
            </template>
          </FormField>

          <FormField :label="t('insurance.member_card_number')" required :error="memberNumberError">
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <Input
                :id="id"
                v-model="memberNumber"
                type="text"
                placeholder="Member ID / Policy No."
                required
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="memberNumberBlur($event, true)"
              />
            </template>
          </FormField>

          <FormField :label="t('insurance.policy_plan_type')" :error="policyTypeError">
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <Input
                :id="id"
                v-model="policyType"
                type="text"
                placeholder="e.g. Comprehensive, Corporate Gold"
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="policyTypeBlur($event, true)"
              />
            </template>
          </FormField>
        </div>
      </div>

      <!-- Emergency Contact Section -->
      <div class="pt-2.5 border-t border-border space-y-2.5">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <HeartPulse class="size-3.5 text-primary" aria-hidden="true" />
            <h4 class="text-xs font-bold uppercase tracking-wider text-foreground">
              {{ t("patient.section_emergency_contact") }}
              <span class="text-[11px] font-normal normal-case text-muted-foreground">
                ({{ t("common.optional") }})
              </span>
            </h4>
          </div>

          <button
            v-if="!showEmergencyContact"
            type="button"
            class="text-xs text-primary hover:underline font-medium flex items-center gap-1 cursor-pointer"
            @click="showEmergencyContact = true"
          >
            <Plus class="size-3.5" aria-hidden="true" />
            {{ t("patient.add_emergency_contact") }}
          </button>
          <button
            v-else
            type="button"
            class="text-[11px] text-muted-foreground hover:text-foreground cursor-pointer"
            @click="showEmergencyContact = false"
          >
            {{ t("common.hide", "Hide") }}
          </button>
        </div>

        <div v-if="showEmergencyContact" class="grid grid-cols-1 gap-3 @lg:grid-cols-2 animate-in fade-in duration-150">
          <FormField
            :label="t('patient.next_of_kin_name')"
            :error="nextOfKinNameError"
          >
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <Input
                :id="id"
                v-model="nextOfKinName"
                type="text"
                placeholder="Full Name"
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="nextOfKinNameBlur($event, true)"
              />
            </template>
          </FormField>
          <FormField
            :label="t('patient.next_of_kin_phone')"
            :error="nextOfKinPhoneError"
          >
            <template #default="{ id, ariaDescribedby, ariaInvalid }">
              <PhoneInput
                :id="id"
                v-model="nextOfKinPhone"
                :aria-describedby="ariaDescribedby"
                :aria-invalid="ariaInvalid"
                @blur="nextOfKinPhoneBlur($event, true)"
              />
            </template>
          </FormField>
        </div>
      </div>
    </div>
  </div>
</template>
