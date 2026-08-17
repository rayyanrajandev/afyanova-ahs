/**
 * Verify Email Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Enterprise Edition)
 * ==========================================================================
 * Email address confirmation gate for newly registered clinical accounts.
 */

<script setup lang="ts">
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  CheckCircle2,
  LogOut,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const props = defineProps<{
  status?: string;
}>();

const { t } = useI18n({ useScope: "global" });

const form = useForm({});

const verificationLinkSent = computed(
  () => props.status === "verification-link-sent",
);

function submit() {
  form.post("/email/verification-notification");
}

function logout() {
  form.post("/logout");
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="space-y-1">
      <h1 class="text-xl font-semibold tracking-tight text-foreground">
        {{ t('auth.verify_email_title') }}
      </h1>
      <p class="text-sm text-muted-foreground leading-relaxed">
        {{ t('auth.verify_email_hint') }}
      </p>
    </div>

    <!-- Status Alert -->
    <div
      v-if="verificationLinkSent"
      class="flex items-center gap-2 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-xs font-medium text-emerald-600 dark:text-emerald-400"
      role="alert"
    >
      <CheckCircle2 class="h-4 w-4 shrink-0" />
      <span>{{ t('auth.verification_link_sent') }}</span>
    </div>

    <!-- Actions -->
    <div class="space-y-3 pt-2">
      <form @submit.prevent="submit">
        <Button
          type="submit"
          class="h-10 w-full font-medium transition-all shadow-sm cursor-pointer"
          :disabled="form.processing"
        >
          <span v-if="form.processing" class="flex items-center gap-2">
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
            {{ t('common.loading') }}
          </span>
          <span v-else>{{ t('auth.resend_verification') }}</span>
        </Button>
      </form>

      <div class="text-center">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
          @click="logout"
        >
          <LogOut class="h-3.5 w-3.5" />
          {{ t('auth.sign_out') }}
        </button>
      </div>
    </div>
  </div>
</template>
