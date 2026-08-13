/**
 * useShortcuts (Volume 1.6 §3)
 * =============================
 * Keyboard shortcut system. Shortcuts are registered, not hardcoded.
 * Scoped: global shortcuts work everywhere; workspace shortcuts only
 * in the active workspace. Implements P5 (keyboard is the scalpel).
 */

import { onBeforeUnmount, onMounted } from 'vue';

export interface Shortcut {
    key: string; // e.g. 'ctrl+k', 'alt+1', 'f6'
    action: string;
    label: string;
    scope: 'global' | 'workspace';
    handler: () => void;
}

type ShortcutMap = Map<string, Shortcut>;

const globalShortcuts: ShortcutMap = new Map();
const workspaceShortcuts: ShortcutMap = new Map();

function normalizeKey(e: KeyboardEvent): string {
    const parts: string[] = [];
    if (e.ctrlKey || e.metaKey) parts.push('ctrl');
    if (e.altKey) parts.push('alt');
    if (e.shiftKey) parts.push('shift');
    parts.push(e.key.toLowerCase());
    return parts.join('+');
}

function isEditableTarget(e: KeyboardEvent): boolean {
    const target = e.target as HTMLElement | null;
    if (!target) return false;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
}

function handleKeydown(e: KeyboardEvent) {
    const key = normalizeKey(e);

    // Single-key shortcuts (no modifier) are only active when not in an input (Volume 1.6 §3.4)
    const hasModifier = e.ctrlKey || e.metaKey || e.altKey;
    if (!hasModifier && isEditableTarget(e)) return;

    // Workspace shortcuts take priority, then global (Volume 1.6 §3.4)
    const workspace = workspaceShortcuts.get(key);
    if (workspace) {
        e.preventDefault();
        workspace.handler();
        return;
    }

    const global = globalShortcuts.get(key);
    if (global) {
        e.preventDefault();
        global.handler();
    }
}

export function useShortcuts() {
    function registerShortcuts(shortcuts: Shortcut[]) {
        for (const shortcut of shortcuts) {
            const map = shortcut.scope === 'global' ? globalShortcuts : workspaceShortcuts;
            map.set(shortcut.key, shortcut);
        }
    }

    function unregisterShortcuts(keys: string[]) {
        for (const key of keys) {
            globalShortcuts.delete(key);
            workspaceShortcuts.delete(key);
        }
    }

    onMounted(() => {
        window.addEventListener('keydown', handleKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', handleKeydown);
    });

    return { registerShortcuts, unregisterShortcuts };
}