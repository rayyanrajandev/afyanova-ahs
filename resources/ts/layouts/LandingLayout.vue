<script setup lang="ts">

import LandingNav from "@/components/landing/LandingNav.vue";
import LandingFooter from "@/components/landing/LandingFooter.vue";


</script>

<template>
  <div
    class="min-h-screen bg-background text-foreground flex flex-col selection:bg-primary/20 selection:text-foreground font-sans antialiased overflow-x-clip"
  >
    <!-- Sticky Glass Navigation -->
    <LandingNav />

    <!-- Main Content Slot -->
    <main id="main-content" class="flex-1 focus:outline-none" tabindex="-1">
      <slot />
    </main>

    <!-- Enterprise Multi-Column Footer -->
    <LandingFooter />


  </div>
</template>
