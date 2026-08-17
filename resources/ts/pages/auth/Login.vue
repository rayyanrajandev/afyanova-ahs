/**
 * Login Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ======================================================================
 * Clean login form with staging quick-select (collapsible).
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { AlertCircle, CheckCircle2, ChevronDown, Eye, EyeOff } from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const props = defineProps<{
  canResetPassword?: boolean;
  canRegister?: boolean;
  status?: string;
}>();

const { t } = useI18n({ useScope: "global" });

const showPassword = ref(false);
const showQuickAccounts = ref(false);

const form = useForm({
  email: "",
  password: "",
  remember: false,
});

const stagingAccounts = [
  { label: "Doctor", email: "clinician@local.test" },
  { label: "Nurse", email: "nurse@local.test" },
  { label: "Reception", email: "receptionist@local.test" },
  { label: "Lab Tech", email: "lab@local.test" },
  { label: "Lab Supervisor", email: "lab.supervisor@local.test" },
  { label: "Radiology Tech", email: "radiology@local.test" },
  { label: "Radiologist", email: "radiology.supervisor@local.test" },
  { label: "Pharmacy", email: "pharmacy@local.test" },
  { label: "Cashier", email: "cashier@local.test" },
  { label: "Admin", email: "admin@local.test" },
];

function selectAccount(email: string) {
  form.email = email;
  form.password = "DevPass!2026";
}

function submit() {
  form.post("/login", {
    onFinish: () => form.reset("password"),
  });
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-1">
      <h1 class="text-xl font-semibold tracking-tight text-foreground">
        {{ t('auth.sign_in') }}
      </h1>
      <p class="text-sm text-muted-foreground">
        {{ t('auth.sign_in_hint') }}
      </p>
    </div>

    <!-- Status banner -->
    <div
      v-if="props.status"
      class="flex items-center gap-2 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-2.5 text-xs text-emerald-600 dark:text-emerald-400"
      role="alert"
    >
      <CheckCircle2 class="h-4 w-4 shrink-0" />
      <span>{{ props.status }}</span>
    </div>

    <!-- Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <!-- Email -->
      <div class="space-y-1.5">
        <Label for="email" class="text-xs font-medium">{{ t('auth.email') }}</Label>
        <Input
          id="email"
          v-model="form.email"
          type="email"
          required
          autofocus
          autocomplete="email"
          :placeholder="t('auth.email_placeholder')"
          class="h-9"
          :class="{ 'border-destructive': form.errors.email }"
        />
        <p v-if="form.errors.email" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3 w-3" /> {{ form.errors.email }}
        </p>
      </div>

      <!-- Password -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between">
          <Label for="password" class="text-xs font-medium">{{ t('auth.password') }}</Label>
          <Link
            v-if="props.canResetPassword !== false"
            href="/forgot-password"
            class="text-xs text-primary hover:underline"
          >{{ t('auth.forgot_password') }}</Link>
        </div>
        <div class="relative">
          <Input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="current-password"
            placeholder="••••••••"
            class="h-9 pr-9"
            :class="{ 'border-destructive': form.errors.password }"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-muted-foreground hover:text-foreground cursor-pointer"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-3.5 w-3.5" />
            <Eye v-else class="h-3.5 w-3.5" />
          </button>
        </div>
        <p v-if="form.errors.password" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3 w-3" /> {{ form.errors.password }}
        </p>
      </div>

      <!-- Remember -->
      <div class="flex items-center gap-2">
        <Checkbox id="remember" v-model="form.remember" />
        <label for="remember" class="text-xs text-muted-foreground cursor-pointer">
          {{ t('auth.remember_me') }}
        </label>
      </div>

      <!-- Submit -->
      <Button type="submit" class="h-9 w-full text-sm font-medium cursor-pointer" :disabled="form.processing">
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
          {{ t('common.loading') }}
        </span>
        <span v-else>{{ t('auth.sign_in') }}</span>
      </Button>
    </form>

    <!-- Quick staging accounts (collapsible) -->
    <div class="border-t border-border/50 pt-4">
      <button
        type="button"
        class="flex w-full items-center justify-between text-xs text-muted-foreground hover:text-foreground cursor-pointer transition-colors"
        @click="showQuickAccounts = !showQuickAccounts"
      >
        <span class="font-medium">{{ t('auth.quick_login_title') }}</span>
        <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': showQuickAccounts }" />
      </button>
      <div v-if="showQuickAccounts" class="mt-2 grid grid-cols-4 gap-1.5">
        <button
          v-for="acc in stagingAccounts"
          :key="acc.email"
          type="button"
          class="rounded-md border border-border/60 bg-muted/30 px-2 py-1.5 text-[11px] font-medium text-foreground hover:bg-accent hover:border-primary/40 transition-colors cursor-pointer truncate"
          @click="selectAccount(acc.email)"
        >{{ acc.label }}</button>
      </div>
    </div>

    <!-- Register link -->
    <div v-if="props.canRegister !== false" class="text-center text-xs text-muted-foreground">
      {{ t('auth.need_account') }}
      <Link href="/register" class="font-medium text-primary hover:underline">{{ t('auth.request_account') }}</Link>
    </div>
  </div>
</template>