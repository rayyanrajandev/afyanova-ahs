/**
 * Auth Store (Volume 1.4 §3.1, Volume 2.2 §13.1)
 * ==============================================
 * Manages the authenticated user, their role, and permissions.
 * Used by all workspaces for RBAC gating (Volume 1.5 §2).
 */

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
    facilityId?: string | null;
    isPlatformAdmin?: boolean;
}

export const useAuthStore = defineStore('auth', () => {
    // ---- State ----
    const user = ref<AuthUser | null>(null);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Getters ----
    const isAuthenticated = computed(() => user.value !== null);
    const isPlatformSuperAdmin = computed(() => user.value?.isPlatformAdmin === true);

    function hasPermission(permission: string): boolean {
        if (!user.value) return false;
        if (user.value.isPlatformAdmin) return true;
        return user.value.permissions.includes(permission);
    }

    function hasRole(role: string): boolean {
        if (!user.value) return false;
        if (user.value.isPlatformAdmin) return true;
        return user.value.roles.includes(role);
    }

    // ---- Actions ----
    function setUser(next: AuthUser | null) {
        user.value = next;
    }

    /** Fetch the current authenticated user (e.g. from /api/user) */
    async function fetchUser(): Promise<AuthUser | null> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/user', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch user');
            user.value = (await res.json()) as AuthUser;
            return user.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch user';
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    function clearUser() {
        user.value = null;
    }

    return {
        user,
        isLoading,
        error,
        isAuthenticated,
        isPlatformSuperAdmin,
        hasPermission,
        hasRole,
        setUser,
        fetchUser,
        clearUser,
    };
});