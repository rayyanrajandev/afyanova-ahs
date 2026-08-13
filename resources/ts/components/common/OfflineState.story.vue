<script setup lang="ts">
import OfflineState from './OfflineState.vue';
</script>

<template>
  <Story
    title="State / OfflineState"
    group="state"
    :layout="{ type: 'grid', width: 500 }"
  >
    <Variant title="Default (no data)">
      <OfflineState />
    </Variant>

    <Variant title="With stale data">
      <OfflineState
        :last-sync-at="new Date(Date.now() - 2 * 60 * 1000).toISOString()"
        :has-stale-data="true"
      />
    </Variant>

    <Variant title="With queued actions">
      <OfflineState
        :queued-count="3"
        :last-sync-at="new Date(Date.now() - 5 * 60 * 1000).toISOString()"
        :has-stale-data="true"
      />
    </Variant>

    <Variant title="Syncing">
      <OfflineState
        :syncing="true"
        :queued-count="2"
      />
    </Variant>
  </Story>
</template>