<script setup lang="ts">

import LandingLayout from "@/layouts/LandingLayout.vue";
import LandingHero from "@/components/landing/LandingHero.vue";
import LandingStats from "@/components/landing/LandingStats.vue";
import LandingWorkspacesShowcase from "@/components/landing/LandingWorkspacesShowcase.vue";
import LandingPatientJourney from "@/components/landing/LandingPatientJourney.vue";
import LandingOfflineArchitecture from "@/components/landing/LandingOfflineArchitecture.vue";
import LandingRegionalIntegrations from "@/components/landing/LandingRegionalIntegrations.vue";
import LandingSecurityCompliance from "@/components/landing/LandingSecurityCompliance.vue";
import LandingCtaSection from "@/components/landing/LandingCtaSection.vue";

defineOptions({ layout: LandingLayout });

</script>

<template>
  <div class="space-y-20 sm:space-y-28 pb-20 overflow-x-clip">
    <!-- 1. Editorial Hero & Clinical Command Center Mockup -->
    <LandingHero />

    <!-- 2. Enterprise Performance & Availability KPI Grid -->
    <LandingStats />

    <!-- 3. Specialized Role-Gated Workspaces Tabbed Explorer -->
    <LandingWorkspacesShowcase />

    <!-- 4. End-to-End Connected Patient Flow Journey -->
    <LandingPatientJourney />

    <!-- 5. Offline-First CRDT Edge Resilience Engine -->
    <LandingOfflineArchitecture />

    <!-- 6. National Healthcare Ecosystem & Regional Standards -->
    <LandingRegionalIntegrations />

    <!-- 7. Institutional Trust & Security Architecture -->
    <LandingSecurityCompliance />

    <!-- 8. Conversion Closing Call to Action -->
    <LandingCtaSection />
  </div>
</template>
