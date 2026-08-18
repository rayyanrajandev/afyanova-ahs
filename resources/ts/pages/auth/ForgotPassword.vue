/**
 * Forgot Password Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ==============================================================================
 * Self-service password recovery flow triggering Fortify password reset notification.
 */

<script setup lang="ts">
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  KeyRound,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const props = defineProps<{
  status?: string;
}>();

const { t } = useI18n({ useScope: "global" });

const form = useForm({
  email: "",
});

function submit() {
  form.post("/forgot-password");
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-1.5 text-left">
      <div class="inline-flex items-center gap-1.5 rounded-md bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
        <KeyRound class="h-3.5 w-3.5" />
        <span>Self-Service Recovery</span>
      </div>
      <h1 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {{ t('auth.forgot_password_title') }}
      </h1>
      <p class="text-xs text-muted-foreground leading-relaxed sm:text-sm">
        {{ t('auth.forgot_password_hint') }}
      </p>
    </div>

    <!-- Status Alert -->
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
          <span>{{ t('auth.send_reset_link') }}</span>
          <ArrowRight class="h-4 w-4" />
        </span>
      </Button>
    </form>

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
