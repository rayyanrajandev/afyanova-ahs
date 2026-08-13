/**
 * Auth Layout (Volume 2.9 §5, Volume 3.6 §3)
 * ===========================================
 * Fresh token-driven auth layout. Center-card design on bg-background.
 * Uses the shadcn-vue Card primitive (Volume 1.2 §4.1).
 */

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Card } from '@/components/ui/card';

const { t } = useI18n();
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-background p-4 text-foreground">
        <!-- Brand -->
        <div class="mb-6 flex items-center gap-2">
            <div
                class="flex h-10 w-10 items-center justify-center rounded-md bg-primary text-sm font-bold text-primary-foreground"
                aria-hidden="true"
            >
                A
            </div>
            <span class="text-lg font-semibold tracking-tight">
                {{ t('shell.facility') }}
            </span>
        </div>

        <!-- Card (shadcn-vue primitive, Volume 1.2 §4.1) -->
        <Card class="w-full max-w-md p-6">
            <slot />
        </Card>

        <p class="mt-6 text-xs text-muted-foreground">
            {{ t('shell.facility') }} · {{ t('auth.tagline') }}
        </p>
    </div>
</template>