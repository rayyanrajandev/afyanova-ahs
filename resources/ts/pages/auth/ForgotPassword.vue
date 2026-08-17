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
  CheckCircle2,
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
    <div class="space-y-1">
      <h1 class="text-xl font-semibold tracking-tight text-foreground">
        {{ t('auth.forgot_password_title') }}
      </h1>
      <p class="text-sm text-muted-foreground">
        {{ t('auth.forgot_password_hint') }}
      </p>
    </div>

    <!-- Status Alert -->
    <div
      v-if="props.status"
      class="flex items-center gap-2 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-xs font-medium text-emerald-600 dark:text-emerald-400"
      role="alert"
    >
      <CheckCircle2 class="h-4 w-4 shrink-0" />
      <span>{{ props.status }}</span>
    </div>

    <!-- Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <div class="space-y-1.5">
        <Label for="email" class="text-xs font-medium text-foreground">
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
        <p v-if="form.errors.email" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          {{ form.errors.email }}
        </p>
      </div>

      <Button
        type="submit"
        class="h-10 w-full font-medium transition-all shadow-sm cursor-pointer"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          {{ t('common.loading') }}
        </span>
        <span v-else>{{ t('auth.send_reset_link') }}</span>
      </Button>
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
