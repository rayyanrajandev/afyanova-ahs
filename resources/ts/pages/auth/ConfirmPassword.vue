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
    <div class="space-y-1">
      <h1 class="text-xl font-semibold tracking-tight text-foreground">
        {{ t('auth.confirm_password_title') }}
      </h1>
      <p class="text-sm text-muted-foreground">
        {{ t('auth.confirm_password_hint') }}
      </p>
    </div>

    <!-- Form -->
    <form class="space-y-4" @submit.prevent="submit">
      <div class="space-y-1.5">
        <Label for="password" class="text-xs font-medium text-foreground">
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
            :title="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
          </button>
        </div>
        <p v-if="form.errors.password" class="flex items-center gap-1 text-xs text-destructive" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          {{ form.errors.password }}
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
        <span v-else>{{ t('auth.confirm') }}</span>
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
