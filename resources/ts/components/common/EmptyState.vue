/**
 * EmptyState — composite component (Volume 1.2 §4.2, §14)
 * =========================================================
 * Modern 2027 Enterprise clinical empty state with contextual
 * illustrations, status pill badges, and dual action triggers.
 */

<script setup lang="ts">
import {
    Activity,
    Calendar,
    ClipboardList,
    FlaskConical,
    Inbox,
    Package,
    Pill,
    Receipt,
    Search,
    Stethoscope,
    Users,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';

export type EmptyStateIllustration =
    | 'users'
    | 'stethoscope'
    | 'clipboard'
    | 'flask'
    | 'activity'
    | 'pill'
    | 'receipt'
    | 'package'
    | 'search'
    | 'inbox'
    | 'calendar';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        badge?: string;
        badgeDotColor?: string;
        actionLabel?: string;
        actionIcon?: Component;
        actionVariant?: 'default' | 'outline' | 'secondary' | 'ghost';
        secondaryActionLabel?: string;
        secondaryActionIcon?: Component;
        secondaryActionVariant?: 'default' | 'outline' | 'secondary' | 'ghost';
        illustration?: EmptyStateIllustration;
        compact?: boolean;
    }>(),
    {
        description: undefined,
        badge: undefined,
        badgeDotColor: 'bg-emerald-500',
        actionLabel: undefined,
        actionIcon: undefined,
        actionVariant: 'default',
        secondaryActionLabel: undefined,
        secondaryActionIcon: undefined,
        secondaryActionVariant: 'outline',
        illustration: 'inbox',
        compact: false,
    },
);

const emit = defineEmits<{
    action: [];
    secondaryAction: [];
}>();
</script>

<template>
    <div
        class="flex h-full w-full flex-col items-center justify-center text-center select-none transition-all duration-300"
        :class="compact ? 'gap-2.5 px-3 py-6' : 'gap-3 px-6 py-12'"
        role="status"
        :aria-label="title"
    >
        <!-- Optional contextual badge pill -->
        <slot name="badge">
            <div
                v-if="badge"
                class="inline-flex items-center gap-1.5 rounded-full border border-border/80 bg-muted/60 px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground shadow-2xs backdrop-blur-xs"
            >
                <span
                    class="size-1.5 rounded-full animate-pulse shrink-0"
                    :class="badgeDotColor"
                    aria-hidden="true"
                />
                <span class="truncate">{{ badge }}</span>
            </div>
        </slot>

        <!-- Illustration container with layered glassmorphism glow -->
        <slot name="icon">
            <div
                class="relative flex items-center justify-center rounded-2xl border border-primary/20 bg-linear-to-b from-primary/10 via-primary/5 to-muted/40 shadow-xs ring-1 ring-border/50 text-primary transition-transform duration-300 hover:scale-105"
                :class="compact ? 'h-10 w-10 p-2' : 'h-14 w-14 p-3.5'"
            >
                <!-- Subtle radial ambient glow behind the icon -->
                <div
                    class="absolute inset-0 -z-10 rounded-2xl bg-primary/10 blur-md"
                    aria-hidden="true"
                />

                <Stethoscope
                    v-if="illustration === 'stethoscope'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Users
                    v-else-if="illustration === 'users'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <ClipboardList
                    v-else-if="illustration === 'clipboard'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <FlaskConical
                    v-else-if="illustration === 'flask'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Activity
                    v-else-if="illustration === 'activity'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Pill
                    v-else-if="illustration === 'pill'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Receipt
                    v-else-if="illustration === 'receipt'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Package
                    v-else-if="illustration === 'package'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Search
                    v-else-if="illustration === 'search'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Calendar
                    v-else-if="illustration === 'calendar'"
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
                <Inbox
                    v-else
                    :class="compact ? 'size-5' : 'size-7'"
                    aria-hidden="true"
                />
            </div>
        </slot>

        <!-- Typography Hierarchy -->
        <div class="space-y-1 max-w-sm">
            <h3
                class="font-semibold tracking-tight text-foreground"
                :class="compact ? 'text-xs sm:text-sm' : 'text-sm sm:text-base'"
            >
                {{ title }}
            </h3>

            <p
                v-if="description"
                class="text-muted-foreground leading-relaxed text-balance"
                :class="compact ? 'text-[11.5px] max-w-[260px]' : 'text-xs sm:text-sm max-w-xs sm:max-w-sm'"
            >
                {{ description }}
            </p>
        </div>

        <!-- Action Triggers -->
        <slot name="actions">
            <slot name="action">
                <div
                    v-if="actionLabel || secondaryActionLabel"
                    class="mt-1 flex flex-wrap items-center justify-center gap-2"
                >
                    <Button
                        v-if="actionLabel"
                        :variant="actionVariant"
                        :size="compact ? 'xs' : 'sm'"
                        class="gap-1.5 cursor-pointer font-medium shadow-2xs"
                        @click="emit('action')"
                    ><component
                            :is="actionIcon"
                            v-if="actionIcon"
                            class="size-3.5"
                            aria-hidden="true"
                        />{{ actionLabel }}</Button>

                    <Button
                        v-if="secondaryActionLabel"
                        :variant="secondaryActionVariant"
                        :size="compact ? 'xs' : 'sm'"
                        class="gap-1.5 cursor-pointer font-medium"
                        @click="emit('secondaryAction')"
                    ><component
                            :is="secondaryActionIcon"
                            v-if="secondaryActionIcon"
                            class="size-3.5"
                            aria-hidden="true"
                        />{{ secondaryActionLabel }}</Button>
                </div>
            </slot>
        </slot>

        <!-- Optional custom footer slot -->
        <slot name="extra" />
    </div>
</template>