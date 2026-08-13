/**
 * OfflineState — composite component (Volume 1.2 §4.2, §17)
 * ===========================================================
 * Shown when the network is unavailable. Anatomy (Volume 1.2 §17.1):
 *   WifiOff (lucide-vue-next, Volume 0.5 §2)
 *   You are offline.
 *   Data shown is from your last sync (2 minutes ago).
 *   3 actions queued
 *   [ Sync Now ] (disabled)
 *
 * Rules (§17.2):
 *   - Shows offline status + last sync time.
 *   - Shows the count of queued actions (writes pending sync).
 *   - "Sync Now" is disabled while offline; activates on reconnection.
 *   - If stale data is available, show it WITH the offline indicator — not an
 *     empty state (P8). This component is a banner/inline indicator, not a
 *     full-screen replacement. Clinical work continues (P8).
 *   - role="status" on the offline indicator.
 */

<script setup lang="ts">
import { WifiOff } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useI18nSafe } from '@/composables/useI18nSafe';

const props = withDefaults(
    defineProps<{
        /** ISO timestamp of the last successful sync. */
        lastSyncAt?: string | number | Date | null;
        /** Number of writes queued locally, pending sync. */
        queuedCount?: number;
        /** Whether a sync is currently in progress. */
        syncing?: boolean;
        /** Whether stale local data is available to show. */
        hasStaleData?: boolean;
    }>(),
    {
        lastSyncAt: null,
        queuedCount: 0,
        syncing: false,
        hasStaleData: false,
    },
);

const emit = defineEmits<{
    sync: [];
}>();

const { t } = useI18nSafe();

// Relative "last sync" label — e.g. "2 minutes ago"
const lastSyncLabel = computed(() => {
    if (!props.lastSyncAt) return null;
    const then = new Date(props.lastSyncAt).getTime();
    const seconds = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (seconds < 60) return t('offline.just_now');
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return t('offline.minutes_ago', { count: minutes });
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return t('offline.hours_ago', { count: hours });
    const days = Math.floor(hours / 24);
    return t('offline.days_ago', { count: days });
});
</script>

<template>
    <div
        class="flex items-center gap-3 rounded-md border border-warning/25 bg-warning/5 px-4 py-3 text-sm"
        role="status"
    >
        <!-- Offline icon -->
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning">
            <WifiOff class="h-4 w-4" aria-hidden="true" />
        </div>

        <div class="min-w-0 flex-1">
            <p class="font-medium text-foreground">{{ t('offline.title') }}</p>
            <p class="text-xs text-muted-foreground">
                <!-- Stale data is shown WITH the offline indicator (P8, §17.2) -->
                <template v-if="hasStaleData && lastSyncLabel">
                    {{ t('offline.stale_data', { time: lastSyncLabel }) }}
                </template>
                <template v-else>
                    {{ t('offline.no_data') }}
                </template>
                <template v-if="queuedCount > 0">
                    · {{ t('offline.queued_actions', { count: queuedCount }) }}
                </template>
            </p>
        </div>

        <!-- Sync Now — disabled while offline; activates on reconnection (§17.2) -->
        <Button
            variant="outline"
            size="sm"
            :disabled="true"
            :aria-disabled="true"
            :title="t('offline.sync_disabled')"
        >
            <span v-if="syncing" class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
            {{ t('offline.sync_now') }}
        </Button>
    </div>
</template>