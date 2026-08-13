/** * PatientRegistrationFields — vee-validate field registration for the *
reception registration form (Volume 2.1 §6). *
============================================================= * The page renders
this inside the Form composite (VeeForm), whose provide * scope is the only
place useField() actually attaches to the form. * Registering the inputs at the
page root instead silently detached them from * every submit, which is why the
"Save" button appeared to do nothing: the * form validated an empty model and
the submit handler never fired. * * The v-model refs and error messages stay
local to this component; the form * collects the validated values and emits them
on submit like any other field.
 *
 * `col-span-2` -> `@lg:col-span-2` (workspace consistency audit, 2026-08-11):
 * both consumers (RegistrationForm.vue, EditDemographicsForm.vue) sit in
 * Reception's resizable main pane and now wrap their grid in `@container` +
 * `grid-cols-1 @lg:grid-cols-2`, matching PatientProfileView.vue's cards —
 * same CSS Grid implicit-column bug that fix already named applies here
 * too: an unconditional `col-span-2` on a grid that's temporarily at 1
 * explicit column doesn't clamp, it creates an implicit 2nd column CSS
 * Grid invents just to satisfy the span. */

<script setup lang="ts">
import { HeartPulse, MapPin, Phone as PhoneIcon, Plus, User } from "lucide-vue-next";
import { useField, useFormContext } from "vee-validate";
import { ref, watch, onBeforeUnmount, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import FormField from "@/components/common/FormField.vue";
import PhoneInput from "@/components/common/PhoneInput.vue";
import SearchableSelect from "@/components/common/SearchableSelect.vue";
import { DatePicker } from "@/components/ui/date-picker";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useLocationOptions } from "@/composables/useLocationOptions";
import { saveDraft } from "@/pages/reception/registrationDraft";

const { t, locale } = useI18n();
const { values } = useFormContext();

/**
 * Fixed (not auto-generated) id, only on the Registration path
 * (`autosaveDraft` is this component's existing signal for "am I
 * Registration or Edit Demographics" — see the prop's own docblock below)
 * — RegistrationForm.vue's autofocus-on-mount (below) needs a stable
 * target to query, and reusing this on Edit Demographics too would mean
 * two mounted instances of this component could theoretically both carry
 * the same DOM id (Registration and Edit Demographics are mutually
 * exclusive `v-if`/`v-else-if` branches today, but there's no reason to
 * couple this id's uniqueness to that staying true forever).
 */
const FIRST_NAME_ID = "patient-registration-first-name";

// DatePicker's calendar popup locale (weekday/month labels only — see
// that component's own docblock for why the trigger label itself stays
// locale-invariant). Also DOB's max-selectable date: a date of birth in
// the future is already rejected server-side (registrationSchema.ts's
// own comment: "dateOfBirth before:today") — this just stops it being
// pickable in the first place instead of round-tripping a guaranteed
// validation error.
const todayIso = new Date().toISOString().slice(0, 10);

type ISOString = string;

const props = withDefaults(
  defineProps<{
    /**
     * The new-patient draft autosave (below) writes to a single fixed
     * localStorage key. Reusing this component for Edit demographics
     * (Volume 2.1 §8.3) with autosave still on would silently overwrite
     * whatever new-patient draft the receptionist has in progress —
     * pass `false` there (Volume 3.7 audit, 2026-08-10).
     */
    autosaveDraft?: boolean;
  }>(),
  {
    autosaveDraft: true,
  },
);

const emit = defineEmits<{
  /** Fired when a field blur lands a draft write (Volume 3.7 T2.3). */
  "draft-saved": [savedAt: ISOString];
}>();

// Field names match the backend StorePatientRequest exactly (Volume 2.1 §6).
//
// `handleBlur` explicitly destructured and wired to every field below
// (bug found 2026-08-12, direct user feedback: "don't rely only on
// REQUIRED... show clear inline validation... immediately after the user
// interacts with it"): this component's OWN docblock already claimed
// "On blur: validation runs on field blur (vee-validate default)", but
// that was never actually true here — only `value`/`errorMessage` were
// destructured, so vee-validate had no `@blur` listener on any real DOM
// element and only ever revalidated as a side effect of the value itself
// changing (typing). Tabbing through an empty required field showed no
// error at all until this was wired up, confirmed live via
// aria-invalid staying null on a pure focus+blur with no typing.
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
const { value: phone, errorMessage: phoneError, handleBlur: phoneBlur } = useField<string>("phone");
const { value: addressLine, errorMessage: addressLineError, handleBlur: addressLineBlur } =
  useField<string>("addressLine");
const { value: region, errorMessage: regionError, handleBlur: regionBlur } = useField<string>("region");
const { value: district, errorMessage: districtError, handleBlur: districtBlur } =
  useField<string>("district");

// Region/District as searchable comboboxes (Patient Registration UX
// direction §2, 2026-08-12) — replaces two disconnected free-text inputs.
// See useLocationOptions.ts's own docblock for where the data comes from.
const { regionOptions, districtOptionsFor } = useLocationOptions();

// Clearing (not just disabling) District on a Region change is the actual
// "prevent invalid Region/District combinations" guarantee — a stale
// district value from the previous region would otherwise sit in the
// field, invisible until submit, potentially still passing validation if
// two regions happen to share a district name.
watch(region, (newRegion, oldRegion) => {
  if (newRegion === oldRegion) return;
  if (!district.value) return;
  const stillValid = districtOptionsFor(newRegion).some(
    (option) => option.value === district.value,
  );
  if (!stillValid) district.value = "";
});
const { value: countryCode, errorMessage: countryCodeError, handleBlur: countryCodeBlur } =
  useField<string>("countryCode");
const { value: nationalId, errorMessage: nationalIdError, handleBlur: nationalIdBlur } =
  useField<string>("nationalId");
const { value: email, errorMessage: emailError, handleBlur: emailBlur } = useField<string>("email");
const { value: nextOfKinName, errorMessage: nextOfKinNameError, handleBlur: nextOfKinNameBlur } =
  useField<string>("nextOfKinName");
const { value: nextOfKinPhone, errorMessage: nextOfKinPhoneError, handleBlur: nextOfKinPhoneBlur } =
  useField<string>("nextOfKinPhone");

// Progressive disclosure (UX feedback, 2026-08-12): Emergency Contact is
// optional, and showing two empty fields for it up front gave it the same
// visual weight as the required identity fields above it. Collapsed by
// default behind an "Add emergency contact" toggle — except on Edit
// Demographics, where a patient can already have real next-of-kin data on
// file; collapsing that by default would hide existing data, the same
// mistake the Region/District casing fix guarded against. Reads the
// field's value once at setup (before any user edit), which is exactly
// "was there already something here" for a freshly-opened form.
const showEmergencyContact = ref(Boolean(nextOfKinName.value || nextOfKinPhone.value));

// ---- Draft autosave (Volume 2.1 §6.2 / Volume 1.2 §7.5, Volume 3.7 T2.3) ----
// A field blur is the trigger: vee-validate keeps `values` in sync, and a
// short debounce collapses mouse-driven focus hops into one write.
const DRAFT_DEBOUNCE_MS = 250;
let draftTimer: ReturnType<typeof setTimeout> | undefined;

/** A form is only worth autosaving once the user has typed something real. */
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

onMounted(syncDraft);
onBeforeUnmount(() => {
  if (draftTimer) clearTimeout(draftTimer);
});

// Autofocus First Name on mount, Registration only (Volume 2.1 §6,
// 2026-08-12 — "Save & Add Another"): RegistrationForm.vue remounts this
// whole component (via its :key bump) after a successful "Save & Add
// Another" specifically so a receptionist registering several patients
// back-to-back can start typing the next one immediately, with no extra
// click. Targets FIRST_NAME_ID (a fixed id, rather than a template ref)
// because Input.vue is a bare <script setup> wrapper with no
// defineExpose — a ref placed on it resolves to an empty component
// proxy, not the underlying <input> DOM node.
onMounted(() => {
  if (!props.autosaveDraft) return;
  document.getElementById(FIRST_NAME_ID)?.focus();
});

watch(values, syncDraft, { deep: true });
</script>

<template>
  <!--
    Grouped sections, not a flat field list (2026-08-12 layout redesign):
    a receptionist scanning 14 ungrouped fields in one visual block has to
    work out which fields belong together herself — this does it for her.
    Four groups, matching what the fields actually are to a receptionist
    (not the database's flat row): who the patient is, how to reach them,
    where they live, who to call if something goes wrong. Emergency
    Contact gets its own bordered/muted box (same `bg-muted` language
    PatientProfileView already uses to mark a card as supplementary,
    not core) specifically because it was reading as part of the
    patient's own profile otherwise — a receptionist skimming shouldn't
    be able to mistake a next-of-kin phone number for the patient's own.
  -->
  <div class="@lg:col-span-3 mb-1">
    <h3 class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
      <User class="h-4 w-4" aria-hidden="true" />
      {{ t("patient.section_identity") }}
    </h3>
  </div>
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
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        @blur="firstNameBlur($event, true)"
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
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        @blur="lastNameBlur($event, true)"
      />
    </template>
  </FormField>
  <FormField :label="t('patient.middle_name')" :error="middleNameError">
    <template #default="{ id, ariaDescribedby, ariaInvalid }">
      <Input
        :id="id"
        v-model="middleName"
        type="text"
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        @blur="middleNameBlur($event, true)"
      />
    </template>
  </FormField>
  <FormField
    :label="t('patient.date_of_birth')"
    required
    :error="dateOfBirthError"
  >
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
          <SelectItem value="female">{{
            t("patient.gender_female")
          }}</SelectItem>
          <SelectItem value="other">{{ t("patient.gender_other") }}</SelectItem>
        </SelectContent>
      </Select>
    </template>
  </FormField>
  <FormField :label="t('patient.national_id')" :error="nationalIdError">
    <template #default="{ id, ariaDescribedby, ariaInvalid }">
      <Input
        :id="id"
        v-model="nationalId"
        @blur="nationalIdBlur($event, true)"
        type="text"
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
      />
    </template>
  </FormField>

  <div class="@lg:col-span-3 mt-3 mb-1">
    <h3 class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
      <PhoneIcon class="h-4 w-4" aria-hidden="true" />
      {{ t("patient.section_contact") }}
    </h3>
  </div>
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
  <FormField :label="t('patient.email')" :error="emailError">
    <template #default="{ id, ariaDescribedby, ariaInvalid }">
      <Input
        :id="id"
        v-model="email"
        type="email"
        :aria-describedby="ariaDescribedby"
        :aria-invalid="ariaInvalid"
        @blur="emailBlur($event, true)"
      />
    </template>
  </FormField>

  <div class="@lg:col-span-3 mt-3 mb-1">
    <h3 class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
      <MapPin class="h-4 w-4" aria-hidden="true" />
      {{ t("patient.section_address") }}
    </h3>
  </div>
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
  <FormField
    :label="t('patient.country_code')"
    required
    :error="countryCodeError"
  >
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

  <div class="@lg:col-span-3 mt-3 overflow-hidden rounded-md border border-border bg-muted/40">
    <button
      v-if="!showEmergencyContact"
      type="button"
      class="flex w-full items-center justify-between gap-2 p-3 text-left transition-colors hover:bg-muted"
      @click="showEmergencyContact = true"
    >
      <span class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
        <HeartPulse class="h-4 w-4" aria-hidden="true" />
        {{ t("patient.section_emergency_contact") }}
        <span class="text-xs font-normal normal-case text-muted-foreground/70">
          ({{ t("common.optional") }})
        </span>
      </span>
      <span class="flex items-center gap-1 text-xs font-medium text-primary">
        <Plus class="h-3.5 w-3.5" aria-hidden="true" />
        {{ t("patient.add_emergency_contact") }}
      </span>
    </button>
    <div v-else class="p-3">
      <h3 class="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wide text-muted-foreground">
        <HeartPulse class="h-4 w-4" aria-hidden="true" />
        {{ t("patient.section_emergency_contact") }}
        <span class="text-xs font-normal normal-case text-muted-foreground/70">
          ({{ t("common.optional") }})
        </span>
      </h3>
      <div class="mt-2 grid grid-cols-1 gap-3 @lg:grid-cols-2">
        <FormField
          :label="t('patient.next_of_kin_name')"
          :error="nextOfKinNameError"
        >
          <template #default="{ id, ariaDescribedby, ariaInvalid }">
            <Input
              :id="id"
              v-model="nextOfKinName"
              type="text"
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
</template>
