<script setup lang="ts">
import { ref, provide } from "vue";
import LandingNav from "@/components/landing/LandingNav.vue";
import LandingFooter from "@/components/landing/LandingFooter.vue";
import DemoRequestModal from "@/components/landing/DemoRequestModal.vue";

const isDemoOpen = ref(false);

function openDemo() {
  isDemoOpen.value = true;
}

provide("openDemoModal", openDemo);
</script>

<template>
  <div
    class="min-h-screen bg-background text-foreground flex flex-col selection:bg-primary/20 selection:text-foreground font-sans antialiased overflow-x-clip"
  >
    <!-- Sticky Glass Navigation -->
    <LandingNav @open-demo="openDemo" />

    <!-- Main Content Slot -->
    <main id="main-content" class="flex-1 focus:outline-none" tabindex="-1">
      <slot />
    </main>

    <!-- Enterprise Multi-Column Footer -->
    <LandingFooter />

    <!-- Demo Request Modal -->
    <DemoRequestModal v-model:open="isDemoOpen" />
  </div>
</template>
