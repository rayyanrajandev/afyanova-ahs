/**
 * TabsTrigger — underline indicator (2026-08-11, direct user feedback +
 * design research: NN/g "Tabs, Used Right" and uxpatterns.dev — see
 * TabsList's docblock for the full research summary and why this only
 * applies to navigation tabs, not Queue.vue's own filter pill group).
 * Replaces the previous `bg-surface`+`border` pill/box active state
 * (2026-08-11, same day — that fix corrected the pill's *color* without
 * questioning whether a pill was the right pattern at all) with a bottom
 * border: `border-b-2 border-primary` when active, transparent at rest.
 * Per NN/g's "use at least two indicators" guidance, paired with a
 * font-weight + color change (`font-semibold text-foreground` active vs.
 * `font-medium text-muted-foreground` at rest), not the underline alone.
 *
 * `justify-center` -> `justify-start` (direct user question: "why are
 * tabs centered?"). Root cause was never the alignment property in
 * isolation — `flex-1` (every trigger stretched to an equal-width share
 * of the row) forced each button wider than its own label, and
 * `justify-center` then centered that label inside the extra space,
 * which reads as a segmented-control convention. `flex-1` -> `flex-
 * initial` here fixes the actual cause: a trigger is now only as wide as
 * its content, so there's no leftover space left to center *into* —
 * `justify-start` is kept explicit anyway (see TabsList's docblock) as a
 * defensive default, not the fix itself. Consumers that still want equal-
 * width stretched tabs can pass `flex-1` as an override class same as
 * before; Reception/Clinician/Nursing's own context-pane tabs (2026-08-11)
 * all moved to natural width instead, which is the conventional pairing
 * with an underline indicator (equal-width tabs with left-aligned labels
 * of different lengths reads unevenly — the empty trailing space per tab
 * isn't visually balanced the way centered-in-a-pill was).
 *
 * Also added `min-w-0`: flex items default to `min-width: auto`, which
 * refuses to shrink below the label's natural width — combined with
 * `whitespace-nowrap` that's exactly what let triggers push the whole
 * TabsList past its box in a narrow pane. Pairs with the
 * `overflow-x-auto` added to TabsList (same audit) as a defensive
 * fallback beyond this.
 *
 * The label is wrapped in its own inner span rather than putting
 * `truncate` on this flex root directly: `text-overflow: ellipsis`
 * doesn't reliably render on a flex container with `justify-center` (the
 * original reason this span was added, pre-underline-redesign) — a
 * block-level child is what `text-overflow` actually expects.
 *
 * That inner span no longer carries `truncate` itself, though (bug
 * found & fixed same day as the underline redesign, once Reception's
 * count badges made it show up): `overflow: hidden` on a flex item
 * — even one with real positive free space in its row, nowhere near
 * needing to shrink — measurably under-reports its own natural
 * (max-content) width once it contains a trailing atomic inline-block
 * child. Live-measured: a trigger's own on-screen box was wide enough,
 * but its *inner truncating span* rendered ~1-2px narrower than
 * "label + badge" actually needed, which `text-overflow: ellipsis`
 * doesn't clip pixel-by-pixel — it hides the entire un-splittable
 * trailing child, so the whole count badge silently vanished behind an
 * ellipsis while the label read as merely "Patients…". Isolated by
 * testing `min-w-0` and `truncate` independently: `min-w-0` was never
 * the problem (removing only it made things worse); `overflow-hidden`
 * specifically was. Replaced with plain `whitespace-nowrap` (still
 * prevents the label wrapping to a second line) — `min-w-0` stays, it's
 * harmless and still lets this span shrink in a genuinely too-narrow
 * container. The real fallback for that case is TabsList's own
 * `overflow-x-auto` (horizontal scroll of the whole row) — coarser, but
 * it can't silently eat content the way a mis-measured ellipsis did.
 */

<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import type { TabsTriggerProps } from "reka-ui"
import { TabsTrigger, useForwardProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<TabsTriggerProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TabsTrigger
    data-slot="tabs-trigger"
    :class="cn(
      `data-[state=active]:text-foreground data-[state=active]:border-primary data-[state=active]:font-semibold focus-visible:ring-ring/50 text-muted-foreground hover:text-foreground inline-flex h-full min-w-0 flex-initial items-center justify-start gap-1.5 border-b-2 border-transparent px-2.5 py-1.5 text-xs font-medium transition-all focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50 -mb-px cursor-pointer [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-3.5`,
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <span class="inline-flex items-center gap-1.5 min-w-0 whitespace-nowrap">
      <slot />
    </span>
  </TabsTrigger>
</template>
