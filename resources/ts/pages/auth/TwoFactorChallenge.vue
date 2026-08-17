/**
 * Two-Factor Challenge Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ===================================================================================
 * MFA challenge interface supporting both 6-digit TOTP app codes and backup recovery codes.
 */

<script setup lang="ts">
import { nextTick, ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  ArrowLeft,
  KeyRound,
  Smartphone,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const { t } = useI18n({ useScope: "global" });

const recovery = ref(false);

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
    <div class="space-y-1">
      <h1 class="text-xl font-semibold tracking-tight text-foreground">
        {{ t('auth.two_factor_title') }}
      </h1>
      <p class="text-sm text-muted-foreground">
        {{ recovery ? t('auth.two_factor_recovery_hint') : t('auth.two_factor_hint') }}
      </p>
    </div>

    <!-- Challenge Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <!-- TOTP Code Mode -->
      <div v-if="!recovery" class="space-y-1.5">
        <Label for="code" class="text-xs font-medium text-foreground">
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
          placeholder="123456"
          maxlength="6"
          class="h-11 text-center font-mono text-lg tracking-widest transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.code }"
        />
        <p v-if="form.errors.code" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          {{ form.errors.code }}
        </p>
      </div>

      <!-- Recovery Code Mode -->
      <div v-else class="space-y-1.5">
        <Label for="recovery_code" class="text-xs font-medium text-foreground">
          {{ t('auth.recovery_code') }}
        </Label>
        <Input
          id="recovery_code"
          ref="recoveryCodeInput"
          v-model="form.recovery_code"
          type="text"
          autocomplete="one-time-code"
          placeholder="abcde-12345"
          class="h-11 font-mono text-center transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.recovery_code }"
        />
        <p v-if="form.errors.recovery_code" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          {{ form.errors.recovery_code }}
        </p>
      </div>

      <!-- Submit Button -->
      <Button
        type="submit"
        class="h-10 w-full font-medium transition-all shadow-sm cursor-pointer"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          {{ t('common.loading') }}
        </span>
        <span v-else>{{ t('auth.sign_in') }}</span>
      </Button>

      <!-- Toggle Mode Button -->
      <div class="text-center pt-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline hover:text-primary/80 transition-colors cursor-pointer"
          @click="toggleRecovery"
        >
          <KeyRound v-if="!recovery" class="h-3.5 w-3.5" />
          <Smartphone v-else class="h-3.5 w-3.5" />
          {{ recovery ? t('auth.use_authenticator_code') : t('auth.use_recovery_code') }}
        </button>
      </div>
    </form>

    <!-- Return to Sign In -->
    <div class="text-center text-xs">
      <Link
        href="/login"
        class="inline-flex items-center gap-1.5 font-medium text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft class="h-3.5 w-3.5" />
        {{ t('auth.back_to_login') }}
      </Link>
    </div>
  </div>
</template>
