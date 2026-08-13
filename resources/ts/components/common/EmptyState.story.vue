<script setup lang="ts">
import EmptyState from './EmptyState.vue';
</script>

<template>
  <Story
    title="State / EmptyState"
    group="state"
    :layout="{ type: 'grid', width: 400 }"
  >
    <Variant title="Default (inbox)">
      <EmptyState
        title="No patients found"
        description="Try adjusting your search or register a new patient."
        action-label="Register Patient"
      />
    </Variant>

    <Variant title="Search illustration">
      <EmptyState
        title="No results"
        description="No patients match your search criteria."
        illustration="search"
      />
    </Variant>

    <Variant title="Clipboard illustration">
      <EmptyState
        title="No orders"
        description="There are no pending orders for this patient."
        illustration="clipboard"
      />
    </Variant>

    <Variant title="Users illustration">
      <EmptyState
        title="No staff"
        description="No staff members are assigned to this facility."
        illustration="users"
      />
    </Variant>

    <Variant title="No action">
      <EmptyState
        title="No data"
        description="This section is empty."
      />
    </Variant>
  </Story>
</template>