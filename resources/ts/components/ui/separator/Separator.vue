/**
 * Separator — shadcn-vue component (Volume 1.2 §4.1)
 * =====================================================
 * Thin visual divider between two unrelated content blocks (e.g. two
 * sections sitting side by side in a row). `decorative` defaults on —
 * this codebase's sections already carry their own heading as the real
 * semantic boundary, so the divider itself shouldn't also be announced as
 * a `role="separator"` landmark.
 *
 * `border-t`/`border-l` on a 0-sized box, not a `bg-border` fill on a
 * `h-px`/`w-px` box (direct user feedback: the line flickered in and out
 * while zooming the browser in/out). A background-color fill sized via
 * an explicit `height: 1px`/`width: 1px` can round down to 0 *device*
 * pixels at fractional zoom levels (e.g. 90%/110%) and disappear —
 * browsers instead guarantee a non-zero `border-width` always rasterizes
 * as at least one physical pixel, so drawing the line as a hairline
 * border sidesteps the rounding entirely.
 *
 * `light:border-border/60` (direct user feedback, reception patient-
 * profile redesign): the full-strength `--border` token reads noticeably
 * starker on the near-white light surface than it does on dark/high-
 * contrast/colorblind-safe surfaces — those keep the token at full
 * opacity (high-contrast and the colorblind themes especially must not
 * lose contrast), only literal light theme gets the softer line.
 */

<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core";
import { Separator, type SeparatorProps } from "reka-ui";
import type { HTMLAttributes } from "vue";
import { cn } from "@/lib/utils";

const props = withDefaults(
  defineProps<SeparatorProps & { class?: HTMLAttributes["class"] }>(),
  {
    orientation: "horizontal",
    decorative: true,
  },
);

const delegatedProps = reactiveOmit(props, "class");
</script>

<template>
  <Separator
    data-slot="separator"
    v-bind="delegatedProps"
    :class="
      cn(
        'shrink-0 border-border light:border-border/60 data-[orientation=horizontal]:h-0 data-[orientation=horizontal]:w-full data-[orientation=horizontal]:border-t data-[orientation=vertical]:h-full data-[orientation=vertical]:w-0 data-[orientation=vertical]:border-l',
        props.class,
      )
    "
  />
</template>
