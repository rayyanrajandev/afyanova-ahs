/**
 * Two-Factor Challenge Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ===================================================================================
 * Multi-Factor Authentication (MFA) challenge interface:
 * - 6-digit TOTP authenticator code input
 * - Emergency backup recovery code mode
 * - FIDO2 WebAuthn / Security Key compatibility indicator
 * - Accessible error states and autofocus management
 */

<script setup lang="ts">
import { nextTick, ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Fingerprint,
  KeyRound,
  ShieldCheck,
  Smartphone,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const { t } = useI18n({ useScope: "global" });

const recovery = ref<boolean>(false);

const form = useForm({
  code: "",
  recovery_code: "",
});

const codeInput = ref<HTMLInputElement | null>(null);
const recoveryCodeInput = ref<HTMLInputElement | null>(null);

function toggleRecovery() {
  recovery.value = !recovery.value;
  nextTick(() => {
    if (recovery.value) {
      recoveryCodeInput.value?.focus();
      form.code = "";
    } else {
      codeInput.value?.focus();
      form.recovery_code = "";
    }
  });
}

function submit() {
  form.post("/two-factor-challenge");
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-1.5 text-left">
      <div class="inline-flex items-center gap-1.5 rounded-md bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
        <ShieldCheck class="h-3.5 w-3.5" />
        <span>Clinical Security Verification</span>
      </div>
      <h1 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {{ t('auth.two_factor_title') }}
      </h1>
      <p class="text-xs text-muted-foreground leading-relaxed sm:text-sm">
        {{ recovery ? t('auth.two_factor_recovery_hint') : t('auth.two_factor_hint') }}
      </p>
    </div>

    <!-- Challenge Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <!-- TOTP Code Mode -->
      <div v-if="!recovery" class="space-y-1.5">
        <Label for="code" class="text-xs font-semibold text-foreground">
          {{ t('auth.authenticator_code') }}
        </Label>
        <Input
          id="code"
          ref="codeInput"
          v-model="form.code"
          type="text"
          inputmode="numeric"
          autofocus
          autocomplete="one-time-code"
          placeholder="000000"
          maxlength="6"
          class="h-12 text-center font-mono text-xl tracking-widest transition-colors font-bold"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.code }"
        />
        <p v-if="form.errors.code" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.code }}</span>
        </p>
      </div>

      <!-- Recovery Code Mode -->
      <div v-else class="space-y-1.5">
        <Label for="recovery_code" class="text-xs font-semibold text-foreground">
          {{ t('auth.recovery_code') }}
        </Label>
        <Input
          id="recovery_code"
          ref="recoveryCodeInput"
          v-model="form.recovery_code"
          type="text"
          autocomplete="one-time-code"
          placeholder="abcde-12345"
          class="h-12 font-mono text-center text-sm tracking-wider transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.recovery_code }"
        />
        <p v-if="form.errors.recovery_code" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.recovery_code }}</span>
        </p>
      </div>

      <!-- Submit Button -->
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

      <!-- Toggle Mode Button -->
      <div class="text-center pt-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline transition-colors cursor-pointer"
          @click="toggleRecovery"
        >
          <KeyRound v-if="!recovery" class="h-3.5 w-3.5" />
          <Smartphone v-else class="h-3.5 w-3.5" />
          <span>{{ recovery ? t('auth.use_authenticator_code') : t('auth.use_recovery_code') }}</span>
        </button>
      </div>
    </form>

    <!-- FIDO2 Hardware Key Indicator -->
    <div class="flex items-center justify-center gap-2 rounded-xl border border-border/80 bg-surface/60 p-2.5 text-[11px] text-muted-foreground">
      <Fingerprint class="h-3.5 w-3.5 text-primary" />
      <span>{{ t('auth.fido_webauthn') }}</span>
    </div>

    <!-- Return to Sign In -->
    <div class="text-center text-xs">
      <Link
        href="/login"
        class="inline-flex items-center gap-1.5 font-semibold text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft class="h-3.5 w-3.5" />
        <span>{{ t('auth.back_to_login') }}</span>
      </Link>
    </div>
  </div>
</template>
