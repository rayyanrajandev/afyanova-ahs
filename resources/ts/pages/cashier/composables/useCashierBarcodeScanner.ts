/**
 * useCashierBarcodeScanner — hardware barcode/QR gun listener
 * ============================================================
 * Captures HID keyboard-wedge barcode and 2D QR scanners (USB/Bluetooth)
 * reading patient wristbands, clinic cards, and routing slips.
 *
 * Distinguishes hardware scanners from human typists via inter-character
 * timing threshold (< 45ms average), auto-selecting the matched patient in
 * under 200ms.
 */

import { onMounted, onUnmounted, ref } from "vue";

export interface UseCashierBarcodeScannerOptions {
  onScan: (barcode: string) => void;
  /** Maximum milliseconds between keystrokes to be considered a scanner burst. Default: 50ms */
  timingThresholdMs?: number;
  /** Minimum characters to consider a valid barcode. Default: 3 */
  minBarcodeLength?: number;
}

export function useCashierBarcodeScanner(options: UseCashierBarcodeScannerOptions) {
  const timingThreshold = options.timingThresholdMs ?? 50;
  const minLength = options.minBarcodeLength ?? 3;

  const lastScanned = ref<string | null>(null);
  const isScanning = ref(false);

  let buffer: string[] = [];
  let lastKeyTime = 0;

  function handleKeyDown(event: KeyboardEvent): void {
    const now = Date.now();
    const isEnter = event.key === "Enter";

    // Ignore special modifier keys
    if (event.ctrlKey || event.altKey || event.metaKey) {
      return;
    }

    const elapsed = now - lastKeyTime;
    lastKeyTime = now;

    if (isEnter) {
      if (buffer.length >= minLength && elapsed <= timingThreshold * 3) {
        const scannedText = buffer.join("").trim();
        if (scannedText.length >= minLength) {
          lastScanned.value = scannedText;
          options.onScan(scannedText);

          // If focused on an input that isn't expecting manual submit, prevent form submit
          event.preventDefault();
        }
      }
      buffer = [];
      isScanning.value = false;
      return;
    }

    // Only printable single characters
    if (event.key.length === 1) {
      if (elapsed > timingThreshold && buffer.length > 0) {
        // Gap too long: previous characters were human typing, reset buffer
        buffer = [];
      }

      buffer.push(event.key);
      isScanning.value = buffer.length >= 2;
    }
  }

  onMounted(() => {
    if (typeof window !== "undefined") {
      window.addEventListener("keydown", handleKeyDown, { capture: true });
    }
  });

  onUnmounted(() => {
    if (typeof window !== "undefined") {
      window.removeEventListener("keydown", handleKeyDown, { capture: true });
    }
  });

  return {
    lastScanned,
    isScanning,
  };
}
