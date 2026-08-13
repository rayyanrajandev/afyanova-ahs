/**
 * Afyanova AHS — Application Entry (Volume 3.6 §3)
 * =================================================
 * Fresh Inertia + Vue 3 + Pinia + vue-i18n entry point.
 * No old starter imports — the app is built on the codex platform.
 */

import { createInertiaApp } from "@inertiajs/vue3";
import { configureEcho } from "@laravel/echo-vue";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createPinia } from "pinia";
import type { DefineComponent } from "vue";
import { computed, createApp, Fragment, h } from "vue";
import { Toaster } from "vue-sonner";
import "vue-sonner/style.css";
import "../css/globals.css";
import { i18n } from "./i18n";
import { useUiStore } from "./stores/uiStore";

// Echo/Reverb client bootstrap (Volume 2.1 §10.4 real-time queue updates,
// 2026-08-11) — the first real-time frontend wiring in the app; every
// workspace's live-update composable (starting with Reception's
// useReceptionLiveSync.ts) calls useEcho()/useChannel() against this one
// globally-configured client rather than each standing up its own. Values
// come from VITE_REVERB_* (vite.config.ts has no custom envPrefix, so
// Vite's default `VITE_` allowlist already exposes them) — themselves
// `${REVERB_*}`-interpolated from the same REVERB_APP_KEY/HOST/PORT/SCHEME
// the backend's config/reverb.php reads, so client and server can never
// drift out of sync by editing only one side.
configureEcho({
  broadcaster: "reverb",
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? "http") === "https",
  enabledTransports: ["ws", "wss"],
});

createInertiaApp({
  title: (title) => (title ? `${title} — Afyanova AHS` : "Afyanova AHS"),
  resolve: (name) =>
    resolvePageComponent(
      `./pages/${name}.vue`,
      import.meta.glob<DefineComponent>("./pages/**/*.vue"),
    ),
  setup({ el, App, props, plugin }) {
    // Mount vue-sonner's Toaster once, globally — useToast (Volume 1.2 §11)
    // is pure plumbing and renders nothing on its own; without this the
    // registration toast etc. would fire into the void.
    //
    // Bug found and fixed 2026-08-11: the `Toaster` mount above was already
    // correct, but `vue-sonner/style.css` (the `./style.css` export — the
    // library ships its styling as a separate, non-default import, not
    // bundled into the JS) was never imported anywhere, so every toast
    // rendered with zero CSS — `position: static`, no background/shadow,
    // `height: 24px` — sitting unstyled at the bottom of the page's normal
    // document flow instead of floating fixed over it. No script errors,
    // nothing to point at a "toast not showing" bug report — the element
    // was always in the DOM, just imperceptible. Confirmed via a real
    // toast's computed styles before and after this import was added.
    //
    // Second bug, found and fixed same day: the `Toaster` had no `theme`
    // prop at all, so it never followed the user's actual theme choice
    // from the top-bar switcher — toasts just always rendered in
    // vue-sonner's own default palette. vue-sonner only understands
    // `light`/`dark`/`system`, but this app has six themes (Volume 0.2
    // §11), so they're mapped down to the two vue-sonner knows: `dark`
    // and `imaging` (a near-black theme for radiology/imaging rooms,
    // tokens.css) read as dark; `high-contrast`/`deuteranopia`/
    // `tritanopia` are all light-background variants (tokens.css
    // comments confirm — they inherit the light palette and only shift
    // specific accent colors) and read as light, same as `light` itself.
    createApp({
      setup() {
        const uiStore = useUiStore();
        const sonnerTheme = computed<"light" | "dark">(() =>
          uiStore.theme === "dark" || uiStore.theme === "imaging" ? "dark" : "light",
        );
        return { sonnerTheme };
      },
      render() {
        return h(Fragment, null, [
          h(App, props),
          h(Toaster, { position: "bottom-right", theme: this.sonnerTheme }),
        ]);
      },
    })
      .use(plugin)
      .use(createPinia())
      .use(i18n)
      .mount(el);
  },
  progress: {
    // var(--primary) resolves at paint time from tokens.css, so the
    // progress bar always matches the active theme's brand color
    // instead of duplicating it as a second, driftable literal.
    color: "var(--primary)",
  },
});
