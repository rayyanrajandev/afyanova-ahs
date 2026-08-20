<!--
  CustomerDisplayView — Secondary Monitor / CFD View
  ====================================================
  A high-contrast, patient-facing view designed for dual-screen monitors
  or bedside/countertop tablets.

  Synchronizes with the cashier counter over BroadcastChannel with zero
  network overhead.
-->
<script setup lang="ts">
import {
  CheckCircle2,
  CreditCard,
  HeartPulse,
  QrCode,
  Smartphone,
  Wallet,
} from "lucide-vue-next";
import { formatMoney } from "./cashierFormatters";
import { useCashierCustomerDisplayReceiver } from "./composables/useCashierCustomerDisplay";

const { display } = useCashierCustomerDisplayReceiver();
</script>

<template>
  <div class="flex h-screen w-screen flex-col bg-background text-foreground antialiased selection:bg-primary/20">
    <!-- Header -->
    <header class="flex shrink-0 items-center justify-between border-b border-border/80 bg-surface px-8 py-4">
      <div class="flex items-center gap-3">
        <div class="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm">
          <HeartPulse class="size-6" />
        </div>
        <div>
          <h1 class="text-lg font-bold tracking-tight">AFYANOVA HEALTH SYSTEM</h1>
          <p class="text-xs text-muted-foreground">Self-Pay Patient Counter</p>
        </div>
      </div>
      <div class="flex items-center gap-4 text-xs text-muted-foreground font-medium">
        <span class="flex items-center gap-1.5"><Wallet class="size-3.5" /> Fedha Taslimu</span>
        <span class="flex items-center gap-1.5"><Smartphone class="size-3.5 text-primary" /> Lipa Namba</span>
        <span class="flex items-center gap-1.5"><CreditCard class="size-3.5" /> Kadi / Benki</span>
      </div>
    </header>

    <!-- Content Area -->
    <main class="flex flex-1 items-center justify-center p-8">
      <!-- 1. IDLE STATE -->
      <div v-if="display.state === 'idle'" class="flex max-w-md flex-col items-center text-center">
        <div class="mb-4 flex size-20 items-center justify-center rounded-2xl bg-primary/10 text-primary">
          <HeartPulse class="size-10" />
        </div>
        <h2 class="text-2xl font-bold">Karibu AfyaNova</h2>
        <p class="mt-2 text-sm text-muted-foreground">
          Welcome to AfyaNova. Please present your clinic card or routing slip to the cashier.
        </p>
      </div>

      <!-- 2. BASKET ACTIVE / CHARGES DISPLAY -->
      <div v-else-if="display.state === 'basket_active'" class="flex h-full w-full max-w-4xl flex-col gap-6">
        <!-- Patient Banner -->
        <div class="flex items-center justify-between rounded-xl border border-border bg-surface p-4 shadow-2xs">
          <div>
            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Patient</span>
            <p class="text-xl font-bold text-foreground">{{ display.patientName ?? "Patient" }}</p>
          </div>
          <div class="text-right">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">MRN</span>
            <p class="text-sm font-mono font-medium text-foreground">{{ display.patientNumber ?? "—" }}</p>
          </div>
        </div>

        <!-- Charges Table -->
        <div class="flex-1 overflow-hidden rounded-xl border border-border bg-surface shadow-2xs">
          <div class="border-b border-border bg-muted/40 px-6 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Service Items / Huduma Zilizotolewa
          </div>
          <div class="max-h-[50vh] overflow-y-auto p-4">
            <ul class="flex flex-col gap-2">
              <li
                v-for="(item, idx) in display.charges"
                :key="idx"
                class="flex items-center justify-between rounded-lg border border-border/60 bg-card p-3"
              >
                <div>
                  <p class="text-sm font-medium">{{ item.description }}</p>
                  <p v-if="item.quantity !== 1" class="text-xs text-muted-foreground">Quantity: x{{ item.quantity }}</p>
                </div>
                <p class="text-base font-semibold tabular-nums">
                  {{ formatMoney(item.amount, display.currencyCode ?? "TZS") }}
                </p>
              </li>
            </ul>
          </div>
        </div>

        <!-- Total Footer -->
        <div class="flex items-center justify-between rounded-xl bg-primary/10 border border-primary/20 p-6">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-primary">Total Amount Due</p>
            <p class="text-xs text-muted-foreground">Jumla ya Malipo</p>
          </div>
          <p class="text-3xl font-extrabold text-primary tabular-nums">
            {{ formatMoney(display.totalDue ?? "0.00", display.currencyCode ?? "TZS") }}
          </p>
        </div>
      </div>

      <!-- 3. PAYMENT PROMPT / LIPA NAMBA & QR CODE -->
      <div v-else-if="display.state === 'payment_prompt'" class="flex max-w-xl flex-col items-center text-center rounded-2xl border border-border bg-surface p-8 shadow-sm">
        <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
          Lipa kwa Simu / Scan to Pay
        </span>
        <h2 class="mt-2 text-2xl font-bold">Lipa kwa M-Pesa / Tigo / Airtel</h2>

        <!-- Prominent Lipa Namba Banner -->
        <div class="my-4 w-full rounded-xl border border-primary/30 bg-primary/5 p-4 text-center">
          <p class="text-xs font-semibold uppercase text-primary">LIPA NAMBA (MERCHANT CODE)</p>
          <p class="text-3xl font-extrabold font-mono text-foreground tracking-widest my-1">5421098</p>
          <p class="text-xs font-medium text-muted-foreground">JINA: AFYANOVA HEALTH SYSTEM</p>
        </div>

        <div class="grid grid-cols-2 gap-2 text-left w-full text-xs text-muted-foreground bg-muted/40 p-3 rounded-lg">
          <div><span class="font-bold text-foreground">Vodacom M-Pesa:</span> *150*00#</div>
          <div><span class="font-bold text-foreground">Tigo Pesa:</span> *150*01#</div>
          <div><span class="font-bold text-foreground">Airtel Money:</span> *150*60#</div>
          <div><span class="font-bold text-foreground">Halopesa:</span> *150*88#</div>
        </div>

        <div class="w-full rounded-xl bg-muted/60 p-4 mt-4">
          <p class="text-xs text-muted-foreground uppercase font-semibold">Total to Pay / Kiasi cha Kulipa</p>
          <p class="text-3xl font-extrabold text-primary tabular-nums">
            {{ formatMoney(display.totalDue ?? "0.00", display.currencyCode ?? "TZS") }}
          </p>
        </div>
      </div>

      <!-- 4. PAYMENT SUCCESS -->
      <div v-else-if="display.state === 'payment_success'" class="flex max-w-md flex-col items-center text-center">
        <div class="mb-4 flex size-20 items-center justify-center rounded-full bg-success/15 text-success">
          <CheckCircle2 class="size-12" />
        </div>
        <h2 class="text-2xl font-extrabold text-foreground">Malipo Yamekamilika!</h2>
        <p class="text-sm font-medium text-success">Payment Received Successfully</p>
        <p class="mt-2 text-xs text-muted-foreground">
          Receipt Number: <span class="font-mono font-bold text-foreground">{{ display.receiptNumber }}</span>
        </p>

        <div class="mt-6 w-full rounded-xl border border-success/20 bg-success/5 p-4">
          <p class="text-xs text-muted-foreground">Total Paid</p>
          <p class="text-2xl font-extrabold text-foreground tabular-nums">
            {{ formatMoney(display.totalDue ?? "0.00", display.currencyCode ?? "TZS") }}
          </p>
        </div>

        <p class="mt-6 text-xs text-muted-foreground">
          Please collect your receipt from the cashier. Asante sana!
        </p>
      </div>
    </main>
  </div>
</template>
