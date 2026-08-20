import { describe, expect, it, vi } from "vitest";
import { useCashierBarcodeScanner } from "../composables/useCashierBarcodeScanner";

describe("useCashierBarcodeScanner", () => {
  it("initializes reactive state cleanly", () => {
    const onScan = vi.fn();
    const { lastScanned, isScanning } = useCashierBarcodeScanner({ onScan });

    expect(lastScanned.value).toBeNull();
    expect(isScanning.value).toBe(false);
  });
});
