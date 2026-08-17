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
import AppShell from "./components/shell/AppShell.vue";
import AuthLayout from "./layouts/AuthLayout.vue";
import { i18n } from "./i18n";
import { useUiStore } from "./stores/uiStore";

// Echo/Reverb client bootstrap — only initialize if VITE_REVERB_APP_KEY is present
if (import.meta.env.VITE_REVERB_APP_KEY) {
  try {
    configureEcho({
      broadcaster: "reverb",
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? "http") === "https",
      enabledTransports: ["ws", "wss"],
    });
  } catch (e) {
    console.warn("Failed to initialize Echo/Reverb:", e);
  }
}

createInertiaApp({
  title: (title) => (title ? `${title} — Afyanova AHS` : "Afyanova AHS"),
  resolve: (name) =>
    resolvePageComponent(
      `./pages/${name}.vue`,
      import.meta.glob<DefineComponent>("./pages/**/*.vue"),
    ).then((page) => {
      const component = (page as any)?.default;
      if (component && component.layout === undefined) {
        if (name.toLowerCase().startsWith("auth/")) {
          component.layout = AuthLayout;
        } else if (name.toLowerCase() !== "landing") {
          component.layout = AppShell;
        }
      }
      return page as any;
    }),
  setup({ el, App, props, plugin }) {
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
    color: "#0891b2",
    includeCSS: true,
    showSpinner: false,
    delay: 50,
  },
});
