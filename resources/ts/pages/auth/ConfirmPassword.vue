/**
 * Confirm Password Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ==============================================================================
 * Elevated security confirmation gate for high-privilege hospital administrative actions.
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Eye,
  EyeOff,
  ShieldAlert,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const { t } = useI18n({ useScope: "global" });

const showPassword = ref<boolean>(false);

const form = useForm({
  password: "",
});

function submit() {
  form.post("/user/confirm-password", {
    onFinish: () => form.reset("password"),
  });
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-1.5 text-left">
      <div class="inline-flex items-center gap-1.5 rounded-md bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
        <ShieldAlert class="h-3.5 w-3.5" />
        <span>Elevated Privileges Required</span>
      </div>
      <h1 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {{ t('auth.confirm_password_title') }}
      </h1>
      <p class="text-xs text-muted-foreground leading-relaxed sm:text-sm">
        {{ t('auth.confirm_password_hint') }}
      </p>
    </div>

    <!-- Form -->
    <form class="space-y-4" @submit.prevent="submit">
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
            autofocus
            autocomplete="current-password"
            placeholder="••••••••"
            class="h-10 pr-10 transition-colors"
            :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.password }"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
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
          <span>{{ t('auth.confirm') }}</span>
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
