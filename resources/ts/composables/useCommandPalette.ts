/**
 * useCommandPalette (Volume 1.1 §6)
 * =================================
 * The command palette is the keyboard front door to the entire system (P5).
 * Shell-owned; workspaces register commands. Triggered by Cmd/Ctrl+K.
 *
 * Command types (Volume 1.1 §6.3):
 *   navigation, patient, encounter, action, setting
 */

import { computed, ref, type Component } from 'vue';
import { useUiStore } from '../stores/uiStore';

export interface Command {
    id: string;
    label: string;
    /** lucide-vue-next icon component (Vol 0.5 §4) or patient-initial string (Vol 0.5 §8). */
    icon?: Component | string;
    keywords?: string[];
    type: 'navigation' | 'patient' | 'encounter' | 'action' | 'setting';
    action: () => void;
}

const globalCommands = ref<Command[]>([]);
const workspaceCommands = ref<Command[]>([]);
const searchQuery = ref('');

export function useCommandPalette() {
    const uiStore = useUiStore();

    function registerCommands(commands: Command[], scope: 'global' | 'workspace' = 'workspace') {
        const target = scope === 'global' ? globalCommands : workspaceCommands;
        target.value = [...target.value, ...commands];
    }

    function unregisterCommands(ids: string[]) {
        globalCommands.value = globalCommands.value.filter((c) => !ids.includes(c.id));
        workspaceCommands.value = workspaceCommands.value.filter((c) => !ids.includes(c.id));
    }

    // Filtered commands by search query
    const filteredCommands = computed(() => {
        const all = [...globalCommands.value, ...workspaceCommands.value];
        const q = searchQuery.value.trim().toLowerCase();
        if (!q) return all;
        return all.filter(
            (c) =>
                c.label.toLowerCase().includes(q) ||
                (c.keywords ?? []).some((k) => k.toLowerCase().includes(q)),
        );
    });

    function open() {
        searchQuery.value = '';
        uiStore.openCommandPalette();
    }

    function close() {
        uiStore.closeCommandPalette();
    }

    function run(command: Command) {
        close();
        command.action();
    }

    return {
        isOpen: computed(() => uiStore.commandPaletteOpen),
        searchQuery,
        filteredCommands,
        registerCommands,
        unregisterCommands,
        open,
        close,
        run,
    };
}