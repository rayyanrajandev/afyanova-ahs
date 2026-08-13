<script setup lang="ts">
import ErrorState from './ErrorState.vue';
</script>

<template>
  <Story
    title="State / ErrorState"
    group="state"
    :layout="{ type: 'grid', width: 400 }"
  >
    <Variant title="Default">
      <ErrorState
        title="Failed to load results"
        description="The server returned an error. Please try again."
      />
    </Variant>

    <Variant title="Critical (with Contact IT)">
      <ErrorState
        title="Data loss risk"
        description="A critical error occurred. Contact IT immediately."
        critical
      />
    </Variant>

    <Variant title="With debug details">
      <ErrorState
        title="Failed to load"
        description="An unexpected error occurred."
        details="TypeError: Cannot read properties of undefined (reading 'map')"
      />
    </Variant>

    <Variant title="No retry">
      <ErrorState
        title="Blocked"
        description="This action is not permitted."
        :show-retry="false"
      />
    </Variant>
  </Story>
</template>