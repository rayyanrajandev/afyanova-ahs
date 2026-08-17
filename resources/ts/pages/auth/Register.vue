/**
 * Register Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ======================================================================
 * Clinical staff onboarding request page. Collects professional council credentials,
 * department, and secure authentication tokens.
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  Eye,
  EyeOff,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const { t } = useI18n({ useScope: "global" });

const showPassword = ref(false);

const form = useForm({
  name: "",
  email: "",
  department: "general_practice",
  license_number: "",
  password: "",
  password_confirmation: "",
});

const departments = [
  { value: "general_practice", label: "Outpatient & Clinicians (OPD)" },
  { value: "emergency_triage", label: "Emergency & Critical Care" },
  { value: "nursing_inpatient", label: "Nursing & Inpatient Wards" },
  { value: "laboratory_lis", label: "Diagnostic Laboratory (LIS)" },
  { value: "radiology_pacs", label: "Radiology & Imaging (PACS)" },
  { value: "pharmacy_dispensary", label: "Pharmacy & Formulary" },
  { value: "billing_insurance", label: "Revenue & NHIF Claims" },
  { value: "hospital_admin", label: "Health Information & Admin" },
];

function submit() {
  form.post("/register", {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-1">
      <h1 class="text-xl font-semibold tracking-tight text-foreground">
        {{ t('auth.register_title') }}
      </h1>
      <p class="text-sm text-muted-foreground">
        {{ t('auth.register_hint') }}
      </p>
    </div>

    <!-- Registration Form -->
    <form class="space-y-3.5" @submit.prevent="submit">
      <!-- Full Name -->
      <div class="space-y-1">
        <Label for="name" class="text-xs font-medium text-foreground">
          {{ t('auth.full_name') }}
        </Label>
        <Input
          id="name"
          v-model="form.name"
          type="text"
          required
          autofocus
          autocomplete="name"
          :placeholder="t('auth.full_name_placeholder')"
          class="h-9 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.name }"
        />
        <p v-if="form.errors.name" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3 w-3" />
          {{ form.errors.name }}
        </p>
      </div>

      <!-- Email -->
      <div class="space-y-1">
        <Label for="email" class="text-xs font-medium text-foreground">
          {{ t('auth.email') }}
        </Label>
        <Input
          id="email"
          v-model="form.email"
          type="email"
          required
          autocomplete="email"
          :placeholder="t('auth.email_placeholder')"
          class="h-9 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.email }"
        />
        <p v-if="form.errors.email" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3 w-3" />
          {{ form.errors.email }}
        </p>
      </div>

      <!-- Department & License No in 2 Columns -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <!-- Department -->
        <div class="space-y-1">
          <Label for="department" class="text-xs font-medium text-foreground">
            {{ t('auth.department') }}
          </Label>
          <select
            id="department"
            v-model="form.department"
            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-xs shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring"
          >
            <option v-for="dept in departments" :key="dept.value" :value="dept.value">
              {{ dept.label }}
            </option>
          </select>
        </div>

        <!-- License / Council No. -->
        <div class="space-y-1">
          <Label for="license" class="text-xs font-medium text-foreground">
            {{ t('auth.council_registration') }}
          </Label>
          <Input
            id="license"
            v-model="form.license_number"
            type="text"
            :placeholder="t('auth.council_registration_placeholder')"
            class="h-9 transition-colors"
          />
        </div>
      </div>

      <!-- Password -->
      <div class="space-y-1">
        <Label for="password" class="text-xs font-medium text-foreground">
          {{ t('auth.password') }}
        </Label>
        <div class="relative">
          <Input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="new-password"
            placeholder="••••••••"
            class="h-9 pr-10 transition-colors"
            :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.password }"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            :title="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
          </button>
        </div>
        <p v-if="form.errors.password" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3 w-3" />
          {{ form.errors.password }}
        </p>
      </div>

      <!-- Password Confirmation -->
      <div class="space-y-1">
        <Label for="password_confirmation" class="text-xs font-medium text-foreground">
          {{ t('auth.password_confirmation') }}
        </Label>
        <Input
          id="password_confirmation"
          v-model="form.password_confirmation"
          :type="showPassword ? 'text' : 'password'"
          required
          autocomplete="new-password"
          placeholder="••••••••"
          class="h-9 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.password_confirmation }"
        />
        <p v-if="form.errors.password_confirmation" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3 w-3" />
          {{ form.errors.password_confirmation }}
        </p>
      </div>

      <!-- Submit Button -->
      <Button
        type="submit"
        class="h-10 w-full font-medium transition-all shadow-sm cursor-pointer mt-2"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          {{ t('common.loading') }}
        </span>
        <span v-else>{{ t('auth.register_title') }}</span>
      </Button>
    </form>

    <!-- Sign In Link -->
    <div class="text-center text-xs text-muted-foreground pt-2">
      <span>{{ t('auth.already_registered') }} </span>
      <Link href="/login" class="font-medium text-primary hover:underline hover:text-primary/80 transition-colors">
        {{ t('auth.sign_in') }}
      </Link>
    </div>
  </div>
</template>
