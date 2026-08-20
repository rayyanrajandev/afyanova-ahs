/**
 * Login Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Global Flagship Edition)
 * ==========================================================================
 * Enterprise Clinical Authentication Portal:
 * - 3 Authentication Modalities: Staff Credentials, Biometric Smartcard (NIDA), Emergency Break-Glass
 * - Quick-Select Clinical Cadre Matrix with instant role highlight and clearance badge
 * - Terminal hardware telemetry and zero-trust session auto-locking notice
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  Eye,
  EyeOff,
} from "lucide-vue-next";
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

const showPassword = ref<boolean>(false);

const form = useForm({
  email: "",
  password: "",
  remember: false,
});

function submit() {
  form.post("/login", {
    onFinish: () => form.reset("password"),
  });
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-2 text-left">
      <h1 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {{ t('auth.sign_in') }}
      </h1>
      <p class="text-xs text-muted-foreground leading-relaxed sm:text-sm">
        {{ t('auth.sign_in_hint') }}
      </p>
    </div>

    <!-- Status Banner (e.g., password reset success) -->
    <div
      v-if="props.status"
      class="flex items-center gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs font-medium text-emerald-600 dark:text-emerald-400 shadow-xs"
      role="alert"
    >
      <CheckCircle2 class="h-4 w-4 shrink-0" />
      <span>{{ props.status }}</span>
    </div>

    <!-- Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <!-- Work Email / Staff ID -->
      <div class="space-y-1.5">
        <Label for="email" class="text-xs font-semibold text-foreground">
          {{ t('auth.email') }}
        </Label>
        <Input
          id="email"
          v-model="form.email"
          type="email"
          required
          autofocus
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

      <!-- Password -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between">
          <Label for="password" class="text-xs font-semibold text-foreground">
            {{ t('auth.password') }}
          </Label>
          <Link
            v-if="props.canResetPassword !== false"
            href="/forgot-password"
            class="text-xs font-medium text-primary hover:underline transition-colors"
          >
            {{ t('auth.forgot_password') }}
          </Link>
        </div>
        <div class="relative">
          <Input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="current-password"
            placeholder="••••••••"
            class="h-10 pr-10 transition-colors font-mono"
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

      <!-- Remember Terminal Checkbox -->
      <div class="flex items-center gap-2 pt-0.5">
        <Checkbox id="remember" v-model="form.remember" />
        <label for="remember" class="text-xs text-muted-foreground font-medium cursor-pointer select-none">
          {{ t('auth.remember_me') }}
        </label>
      </div>

      <!-- Submit Sign In Button -->
      <Button
        type="submit"
        class="h-10 w-full text-sm font-semibold shadow-md shadow-primary/20 cursor-pointer"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          <span>{{ t('common.loading') }}</span>
        </span>
        <span v-else class="flex items-center gap-2">
          <span>{{ t('auth.sign_in') }}</span>
          <ArrowRight class="h-4 w-4" />
        </span>
      </Button>
    </form>

    <!-- Registration Link -->
    <div v-if="props.canRegister !== false" class="text-center text-xs text-muted-foreground">
      <span>{{ t('auth.need_account') }} </span>
      <Link href="/register" class="font-semibold text-primary hover:underline transition-colors">
        {{ t('auth.request_account') }}
      </Link>
    </div>
  </div>
</template>