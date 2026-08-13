<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import Drawer from './Drawer.vue';

const openSm = ref(false);
const openMd = ref(false);
const openLg = ref(false);
const openStart = ref(false);
</script>

<template>
  <Story
    title="Shell / Drawer"
    group="shell"
    :layout="{ type: 'single', iframe: 500 }"
  >
    <Variant title="Small (320px)">
      <div class="p-4">
        <Button @click="openSm = true">Open small drawer</Button>
        <Drawer v-model:open="openSm" title="Quick form" size="sm">
          <p>Small drawer content — quick forms, filters.</p>
        </Drawer>
      </div>
    </Variant>

    <Variant title="Medium (480px)">
      <div class="p-4">
        <Button @click="openMd = true">Open medium drawer</Button>
        <Drawer v-model:open="openMd" title="Order entry" size="md">
          <p>Medium drawer content — order entry, result detail.</p>
        </Drawer>
      </div>
    </Variant>

    <Variant title="Large (640px)">
      <div class="p-4">
        <Button @click="openLg = true">Open large drawer</Button>
        <Drawer v-model:open="openLg" title="Multi-section form" size="lg">
          <p>Large drawer content — multi-section forms.</p>
        </Drawer>
      </div>
    </Variant>

    <Variant title="Start edge (context panel)">
      <div class="p-4">
        <Button @click="openStart = true">Open start-edge drawer</Button>
        <Drawer v-model:open="openStart" title="Context panel" side="start">
          <p>Start-edge drawer — used for context panels.</p>
        </Drawer>
      </div>
    </Variant>
  </Story>
</template>