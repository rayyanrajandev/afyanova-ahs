/**
 * Register Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ======================================================================
 * Clinical staff onboarding request page:
 * - Professional council credential capture (MCT, TNMC, PC, MLST)
 * - Localized clinical department assignment
 * - Password compliance validation with visibility toggles
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  ArrowRight,
  Eye,
  EyeOff,
  ShieldCheck,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const { t } = useI18n({ useScope: "global" });

const showPassword = ref<boolean>(false);

const form = useForm({
  name: "",
  email: "",
  department: "general_practice",
  license_number: "",
  password: "",
  password_confirmation: "",
});

const departmentOptions = [
  { value: "general_practice", key: "auth.dept_opd" },
  { value: "emergency_triage", key: "auth.dept_emergency" },
  { value: "nursing_inpatient", key: "auth.dept_nursing" },
  { value: "laboratory_lis", key: "auth.dept_lab" },
  { value: "radiology_pacs", key: "auth.dept_radiology" },
  { value: "pharmacy_dispensary", key: "auth.dept_pharmacy" },
  { value: "billing_insurance", key: "auth.dept_cashier" },
  { value: "hospital_admin", key: "auth.dept_admin" },
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
    <div class="space-y-1.5 text-left">
      <h1 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {{ t('auth.register_title') }}
      </h1>
      <p class="text-xs text-muted-foreground leading-relaxed sm:text-sm">
        {{ t('auth.register_hint') }}
      </p>
    </div>

    <!-- Council Credential Advisory Banner -->
    <div class="flex items-start gap-2.5 rounded-xl border border-primary/20 bg-primary/5 p-3 text-xs text-muted-foreground shadow-xs">
      <ShieldCheck class="h-4 w-4 text-primary shrink-0 mt-0.5" />
      <span class="leading-tight">All clinical registrations undergo verified credential auditing against the National Medical Council registry.</span>
    </div>

    <!-- Registration Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <!-- Full Name -->
      <div class="space-y-1.5">
        <Label for="name" class="text-xs font-semibold text-foreground">
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
          class="h-10 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.name }"
        />
        <p v-if="form.errors.name" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.name }}</span>
        </p>
      </div>

      <!-- Email -->
      <div class="space-y-1.5">
        <Label for="email" class="text-xs font-semibold text-foreground">
          {{ t('auth.email') }}
        </Label>
        <Input
          id="email"
          v-model="form.email"
          type="email"
          required
          autocomplete="email"
          :placeholder="t('auth.email_placeholder')"
          class="h-10 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.email }"
        />
        <p v-if="form.errors.email" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.email }}</span>
        </p>
      </div>

      <!-- Department & Council License No (2 Cols on sm+) -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <!-- Department -->
        <div class="space-y-1.5">
          <Label for="department" class="text-xs font-semibold text-foreground">
            {{ t('auth.department') }}
          </Label>
          <select
            id="department"
            v-model="form.department"
            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-xs shadow-xs transition-colors focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring"
          >
            <option v-for="dept in departmentOptions" :key="dept.value" :value="dept.value">
              {{ t(dept.key) }}
            </option>
          </select>
        </div>

        <!-- License / Council Registration No. -->
        <div class="space-y-1.5">
          <Label for="license" class="text-xs font-semibold text-foreground">
            {{ t('auth.council_registration') }}
          </Label>
          <Input
            id="license"
            v-model="form.license_number"
            type="text"
            :placeholder="t('auth.council_registration_placeholder')"
            class="h-10 transition-colors"
          />
        </div>
      </div>

      <!-- Password -->
      <div class="space-y-1.5">
        <Label for="password" class="text-xs font-semibold text-foreground">
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
            class="h-10 pr-10 transition-colors"
            :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.password }"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground cursor-pointer transition-colors"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
          </button>
        </div>
        <p v-if="form.errors.password" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.password }}</span>
        </p>
      </div>

      <!-- Password Confirmation -->
      <div class="space-y-1.5">
        <Label for="password_confirmation" class="text-xs font-semibold text-foreground">
          {{ t('auth.password_confirmation') }}
        </Label>
        <Input
          id="password_confirmation"
          v-model="form.password_confirmation"
          :type="showPassword ? 'text' : 'password'"
          required
          autocomplete="new-password"
          placeholder="••••••••"
          class="h-10 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.password_confirmation }"
        />
        <p v-if="form.errors.password_confirmation" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.password_confirmation }}</span>
        </p>
      </div>

      <!-- Submit Button -->
      <Button
        type="submit"
        class="h-10 w-full text-sm font-semibold shadow-md shadow-primary/20 cursor-pointer pt-1"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          <span>{{ t('common.loading') }}</span>
        </span>
        <span v-else class="flex items-center gap-2">
          <span>{{ t('auth.request_account') }}</span>
          <ArrowRight class="h-4 w-4" />
        </span>
      </Button>
    </form>

    <!-- Sign In Link -->
    <div class="text-center text-xs text-muted-foreground">
      <span>{{ t('auth.already_registered') }} </span>
      <Link href="/login" class="font-semibold text-primary hover:underline transition-colors">
        {{ t('auth.sign_in') }}
      </Link>
    </div>
  </div>
</template>
