<script setup lang="ts">
import SplitPane from './SplitPane.vue';
</script>

<template>
  <Story
    title="Shell / SplitPane"
    group="shell"
    :layout="{ type: 'single', iframe: 400 }"
  >
    <Variant title="Horizontal (side-by-side)">
      <div class="h-[300px]">
        <SplitPane direction="horizontal" :initial-ratio="0.3">
          <template #start>
            <div class="h-full bg-muted p-4">
              <p>Context pane</p>
            </div>
          </template>
          <template #end>
            <div class="h-full p-4">
              <p>Main pane</p>
            </div>
          </template>
        </SplitPane>
      </div>
    </Variant>

    <Variant title="Vertical (stacked)">
      <div class="h-[300px]">
        <SplitPane direction="vertical" :initial-ratio="0.5">
          <template #start>
            <div class="h-full bg-muted p-4">
              <p>Top pane</p>
            </div>
          </template>
          <template #end>
            <div class="h-full p-4">
              <p>Bottom pane</p>
            </div>
          </template>
        </SplitPane>
      </div>
    </Variant>

    <Variant title="Persisted ratio">
      <div class="h-[300px]">
        <SplitPane direction="horizontal" :initial-ratio="0.4" persist-key="story-split">
          <template #start>
            <div class="h-full bg-muted p-4">
              <p>Context pane (ratio persisted)</p>
            </div>
          </template>
          <template #end>
            <div class="h-full p-4">
              <p>Main pane</p>
            </div>
          </template>
        </SplitPane>
      </div>
    </Variant>
  </Story>
</template>