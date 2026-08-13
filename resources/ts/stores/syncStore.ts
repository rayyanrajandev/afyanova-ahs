/**
 * Sync Store (Volume 1.4 §7)
 * ===========================
 * Manages offline/sync state: online status, pending actions queue,
 * last sync time, conflicts. Implements P8 (offline is a first-class state).
 *
 * Writes queue locally when offline and sync on reconnect.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface QueuedAction {
    id: string;
    timestamp: number;
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    url: string;
    body: unknown;
    status: 'pending' | 'syncing' | 'conflict' | 'failed';
    retries: number;
}

const QUEUE_KEY = 'afyanova:sync-queue';

function loadQueue(): QueuedAction[] {
    try {
        const raw = localStorage.getItem(QUEUE_KEY);
        return raw ? (JSON.parse(raw) as QueuedAction[]) : [];
    } catch {
        return [];
    }
}

function saveQueue(queue: QueuedAction[]) {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
}

export const useSyncStore = defineStore('sync', () => {
    // ---- State ----
    const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
    const queue = ref<QueuedAction[]>(loadQueue());
    const lastSyncAt = ref<number | null>(null);
    const isSyncing = ref(false);

    // ---- Getters ----
    const pendingCount = () => queue.value.filter((a) => a.status === 'pending').length;
    const conflictCount = () => queue.value.filter((a) => a.status === 'conflict').length;

    // ---- Actions ----
    function enqueue(action: Omit<QueuedAction, 'id' | 'timestamp' | 'status' | 'retries'>) {
        const item: QueuedAction = {
            ...action,
            id: crypto.randomUUID(),
            timestamp: Date.now(),
            status: 'pending',
            retries: 0,
        };
        queue.value.push(item);
        saveQueue(queue.value);
    }

    function markSyncing(id: string) {
        const item = queue.value.find((a) => a.id === id);
        if (item) {
            item.status = 'syncing';
            saveQueue(queue.value);
        }
    }

    function markSuccess(id: string) {
        queue.value = queue.value.filter((a) => a.id !== id);
        saveQueue(queue.value);
        lastSyncAt.value = Date.now();
    }

    function markConflict(id: string) {
        const item = queue.value.find((a) => a.id === id);
        if (item) {
            item.status = 'conflict';
            saveQueue(queue.value);
        }
    }

    function markFailed(id: string) {
        const item = queue.value.find((a) => a.id === id);
        if (item) {
            item.status = 'failed';
            item.retries += 1;
            saveQueue(queue.value);
        }
    }

    function setOnline(online: boolean) {
        isOnline.value = online;
        if (online) {
            void sync();
        }
    }

    async function sync() {
        if (isSyncing.value || !isOnline.value) return;
        isSyncing.value = true;

        // FIFO order (oldest first)
        const pending = queue.value
            .filter((a) => a.status === 'pending')
            .sort((a, b) => a.timestamp - b.timestamp);

        for (const action of pending) {
            markSyncing(action.id);
            try {
                const res = await fetch(action.url, {
                    method: action.method,
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(action.body),
                });
                if (res.ok) {
                    markSuccess(action.id);
                } else if (res.status === 409) {
                    markConflict(action.id);
                } else {
                    markFailed(action.id);
                }
            } catch {
                markFailed(action.id);
            }
        }

        isSyncing.value = false;
    }

    // ---- Listen for online/offline ----
    if (typeof window !== 'undefined') {
        window.addEventListener('online', () => setOnline(true));
        window.addEventListener('offline', () => setOnline(false));
    }

    return {
        isOnline,
        queue,
        lastSyncAt,
        isSyncing,
        pendingCount,
        conflictCount,
        enqueue,
        markSyncing,
        markSuccess,
        markConflict,
        markFailed,
        setOnline,
        sync,
    };
});