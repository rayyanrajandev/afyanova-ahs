<script setup lang="ts">
import Queue, { type QueueItem } from './Queue.vue';

const items: QueueItem[] = [
  { id: 'q1', name: 'John Mwangi', priority: 'critical', waitTime: '45 min', waitMinutes: 45, status: 'pending', category: 'Emergency' },
  { id: 'q2', name: 'Sarah Joseph', priority: 'urgent', waitTime: '25 min', waitMinutes: 25, status: 'pending', category: 'Scheduled' },
  { id: 'q3', name: 'Ali Hassan', priority: 'normal', waitTime: '10 min', waitMinutes: 10, status: 'in_progress', category: 'Walk-in' },
  { id: 'q4', name: 'Grace Kimaro', priority: 'normal', waitTime: '5 min', waitMinutes: 5, status: 'pending', category: 'Walk-in' },
  { id: 'q5', name: 'Peter Mushi', priority: 'urgent', waitTime: '32 min', waitMinutes: 32, status: 'pending', category: 'Scheduled' },
];
</script>

<template>
  <Story
    title="Data / Queue"
    group="data"
    :layout="{ type: 'single', iframe: 500 }"
  >
    <Variant title="Default">
      <Queue :items="items" @open="() => {}" @reorder="() => {}" />
    </Variant>

    <Variant title="Grouped by category, priority chips hidden (Reception)">
      <Queue
        :items="items"
        default-sort="incoming"
        group-by-category
        hide-priority-chips
        @open="() => {}"
        @reorder="() => {}"
      />
    </Variant>

    <Variant title="Loading (skeleton)">
      <Queue :items="[]" loading @open="() => {}" @reorder="() => {}" />
    </Variant>

    <Variant title="Empty">
      <Queue :items="[]" @open="() => {}" @reorder="() => {}" />
    </Variant>

    <Variant title="Error (no data to fall back on)">
      <!-- T5.8, Volume 3.7 §3.6/Phase 5 — blocking error + retry, only
           reached when there's nothing else to show. -->
      <Queue :items="[]" error="Failed to fetch reception queue" @open="() => {}" @reorder="() => {}" @retry="() => {}" />
    </Variant>

    <Variant title="Stale data (error, but real items still shown)">
      <!-- T5.8 — a failed background refresh doesn't blank a working list;
           a non-blocking banner sits above the (still real) rows instead. -->
      <Queue :items="items" error="Failed to fetch reception queue" @open="() => {}" @reorder="() => {}" @retry="() => {}" />
    </Variant>

    <Variant title="Offline">
      <Queue :items="items" offline @open="() => {}" @reorder="() => {}" />
    </Variant>
  </Story>
</template>
