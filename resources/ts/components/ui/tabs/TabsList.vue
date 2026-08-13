/**
 * TabsList — overflow safety net added (Reception workspace design audit, 2026-08-11)
 * ==================================================================================
 * Was `overflow: visible` (the browser default) with no fallback: when a
 * narrow container forced the triggers' combined width past the list's
 * own box, the excess simply painted over whatever sat next to it
 * instead of wrapping or scrolling. On the Reception context pane this
 * showed up as the "Schedule" tab silently vanishing under the main
 * pane at narrow SplitPane widths — not hidden by design, just painted
 * over. Added `overflow-x-auto` so a genuinely too-narrow list scrolls
 * instead. The real fix for Reception's specific case is pairing this
 * with `min-w-0` + `truncate` on TabsTrigger (same audit) so triggers
 * shrink and ellipsize before they ever need to scroll — this is the
 * defensive fallback for cases that squeeze past that.
 *
 * Segmented-pill -> underline (2026-08-11, direct user feedback + design
 * research: NN/g "Tabs, Used Right" and uxpatterns.dev both current as of
 * this change — underline is the standard indicator for navigation tabs
 * that switch views; the pill/segmented-control look this replaced is
 * conventionally reserved for filtering/view-toggle controls within one
 * view, e.g. Queue.vue's own Sort control, which correctly still uses a
 * plain-button pill group, not this component). The `bg-muted` track +
 * `rounded-md p-0.75` pill container is gone — TabsTrigger now carries its
 * own bottom-border indicator (see that file), so this list is just a
 * plain row. `justify-start` is explicit, not the default: this used to
 * matter less when every trigger stretched to fill the row (`flex-1`),
 * but natural-width tabs (TabsTrigger's new default) must not fall back
 * to a browser/flex default that could center them.
 */

<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import type { TabsListProps } from "reka-ui"
import { TabsList } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<TabsListProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <TabsList
    data-slot="tabs-list"
    v-bind="delegatedProps"
    :class="cn(
      'text-muted-foreground inline-flex h-[var(--size-control-md)] w-fit items-center justify-start gap-1 overflow-x-auto border-b border-border',
      props.class,
    )"
  >
    <slot />
  </TabsList>
</template>
