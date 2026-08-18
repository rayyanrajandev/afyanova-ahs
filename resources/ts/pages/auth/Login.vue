/**
 * Login Page (Volume 2.9 §5, Volume 3.6 §3 — 2027 Global Flagship Edition)
 * ==========================================================================
 * Enterprise Clinical Authentication Portal:
 * - 3 Authentication Modalities: Staff Credentials, Biometric Smartcard (NIDA), Emergency Break-Glass
 * - Quick-Select Clinical Cadre Matrix with instant role highlight and clearance badge
 * - Terminal hardware telemetry and zero-trust session auto-locking notice
 */

<script setup lang="ts">
import { ref } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import {
  AlertCircle,
  AlertTriangle,
  ArrowRight,
  BadgeCheck,
  CheckCircle2,
  Cpu,
  Eye,
  EyeOff,
  Fingerprint,
  HeartPulse,
  Info,
  KeyRound,
  Lock,
  Microscope,
  Pill,
  Radio,
  Receipt,
  Scan,
  ShieldAlert,
  ShieldCheck,
  Smartphone,
  Sparkles,
  Stethoscope,
  Users,
} from "lucide-vue-next";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import AuthLayout from "@/layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });

const props = defineProps<{
  canResetPassword?: boolean;
  canRegister?: boolean;
  status?: string;
}>();

const { t } = useI18n({ useScope: "global" });

// Auth Mode Tabs: 'credentials' | 'biometric' | 'breakglass'
const authMode = ref<"credentials" | "biometric" | "breakglass">("credentials");

const showPassword = ref<boolean>(false);
const activeCadreId = ref<string>("clinician");

const form = useForm({
  email: "clinician@local.test",
  password: "DevPass!2026",
  remember: false,
  breakglass_reason: "",
});

// Biometric Simulation State
const isScanningBio = ref<boolean>(false);
const bioScanSuccess = ref<boolean>(false);

interface StagingCadre {
  id: string;
  name: string;
  cadreKey: string;
  clearance: string;
  email: string;
  icon: any;
  colorClass: string;
  bgClass: string;
}

const stagingCadres: StagingCadre[] = [
  { id: "clinician", name: "Clinician", cadreKey: "landing.workspaces.clinician.title", clearance: "Level 4 (CPOE & Prescribing)", email: "clinician@local.test", icon: Stethoscope, colorClass: "text-teal-600 dark:text-teal-400", bgClass: "bg-teal-500/10 border-teal-500/30" },
  { id: "nursing", name: "Nursing", cadreKey: "landing.workspaces.nursing.title", clearance: "Level 3 (e-MAR & Bedside Vitals)", email: "nurse@local.test", icon: HeartPulse, colorClass: "text-emerald-600 dark:text-emerald-400", bgClass: "bg-emerald-500/10 border-emerald-500/30" },
  { id: "reception", name: "Reception", cadreKey: "landing.workspaces.reception.title", clearance: "Level 2 (Triage & Check-in)", email: "receptionist@local.test", icon: Users, colorClass: "text-blue-600 dark:text-blue-400", bgClass: "bg-blue-500/10 border-blue-500/30" },
  { id: "laboratory", name: "Laboratory", cadreKey: "landing.workspaces.laboratory.title", clearance: "Level 3 (LIS Analyzers & Release)", email: "lab@local.test", icon: Microscope, colorClass: "text-amber-600 dark:text-amber-400", bgClass: "bg-amber-500/10 border-amber-500/30" },
  { id: "radiology", name: "Radiology", cadreKey: "landing.workspaces.radiology.title", clearance: "Level 3 (DICOM PACS Worklist)", email: "radiology@local.test", icon: Scan, colorClass: "text-cyan-600 dark:text-cyan-400", bgClass: "bg-cyan-500/10 border-cyan-500/30" },
  { id: "pharmacy", name: "Pharmacy", cadreKey: "landing.workspaces.pharmacy.title", clearance: "Level 3 (5-Rights Dispense & FEFO)", email: "pharmacy@local.test", icon: Pill, colorClass: "text-indigo-600 dark:text-indigo-400", bgClass: "bg-indigo-500/10 border-indigo-500/30" },
  { id: "cashier", name: "Cashier", cadreKey: "landing.workspaces.cashier.title", clearance: "Level 2 (NHIF & GePG Revenue)", email: "cashier@local.test", icon: Receipt, colorClass: "text-rose-600 dark:text-rose-400", bgClass: "bg-rose-500/10 border-rose-500/30" },
  { id: "admin", name: "Admin", cadreKey: "auth.dept_admin", clearance: "Level 5 (Full Hospital Tenancy)", email: "admin@local.test", icon: ShieldCheck, colorClass: "text-purple-600 dark:text-purple-400", bgClass: "bg-purple-500/10 border-purple-500/30" },
];

function selectCadre(cadre: StagingCadre) {
  activeCadreId.value = cadre.id;
  form.email = cadre.email;
  form.password = "DevPass!2026";
}

function triggerBiometricScan() {
  isScanningBio.value = true;
  bioScanSuccess.value = false;
  setTimeout(() => {
    isScanningBio.value = false;
    bioScanSuccess.value = true;
    form.email = "clinician@local.test";
    form.password = "DevPass!2026";
    setTimeout(() => {
      submit();
    }, 900);
  }, 1600);
}

function submit() {
  form.post("/login", {
    onFinish: () => form.reset("password"),
  });
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header & Terminal Telemetry Pill -->
    <div class="space-y-2 text-left">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold text-primary">
          <Cpu class="h-3 w-3" />
          <span>{{ t('auth.terminal_info') }}</span>
        </div>
        <span class="text-[10px] font-mono text-muted-foreground">Session TTL: 15m</span>
      </div>

      <h1 class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {{ t('auth.sign_in') }}
      </h1>
      <p class="text-xs text-muted-foreground leading-relaxed sm:text-sm">
        {{ t('auth.sign_in_hint') }}
      </p>
    </div>

    <!-- Status Banner (e.g., password reset success) -->
    <div
      v-if="props.status"
      class="flex items-center gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs font-medium text-emerald-600 dark:text-emerald-400 shadow-xs"
      role="alert"
    >
      <CheckCircle2 class="h-4 w-4 shrink-0" />
      <span>{{ props.status }}</span>
    </div>

    <!-- Quick Clinical Cadre Staging Matrix -->
    <div class="space-y-2 rounded-2xl border border-border/80 bg-card p-3 shadow-xs">
      <div class="flex items-center justify-between text-[11px] font-semibold text-foreground px-1">
        <span class="flex items-center gap-1.5">
          <Sparkles class="h-3.5 w-3.5 text-primary" />
          <span>1-Click Role Switcher (Staging Cadres)</span>
        </span>
        <span class="text-[10px] text-muted-foreground font-mono">Dev Mode</span>
      </div>

      <!-- Cadre Button Row -->
      <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-4">
        <button
          v-for="cadre in stagingCadres"
          :key="cadre.id"
          type="button"
          class="flex flex-col items-center justify-center rounded-xl border p-2 text-center transition-all cursor-pointer focus-ring"
          :class="activeCadreId === cadre.id ? `${cadre.bgClass} shadow-xs font-bold` : 'border-border/60 bg-surface/60 hover:bg-surface text-muted-foreground'"
          @click="selectCadre(cadre)"
        >
          <component :is="cadre.icon" class="h-4 w-4 shrink-0" :class="cadre.colorClass" />
          <span class="text-[10px] truncate max-w-full pt-1" :class="activeCadreId === cadre.id ? 'text-foreground font-semibold' : ''">{{ cadre.name }}</span>
        </button>
      </div>

      <!-- Active Cadre Clearance Strip -->
      <div class="flex items-center justify-between rounded-lg bg-surface px-2.5 py-1.5 text-[10px] text-muted-foreground border border-border/50">
        <span class="font-mono">Account: <strong class="text-foreground">{{ form.email }}</strong></span>
        <span class="text-primary font-medium">Clearance: {{ stagingCadres.find(c => c.id === activeCadreId)?.clearance }}</span>
      </div>
    </div>

    <!-- 3-Modality Authentication Tabs -->
    <div class="flex items-center rounded-xl border border-border bg-surface p-1 shadow-xs text-xs font-semibold" role="tablist">
      <button
        type="button"
        role="tab"
        :aria-selected="authMode === 'credentials'"
        class="flex-1 rounded-lg py-1.5 text-center transition-all cursor-pointer"
        :class="authMode === 'credentials' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
        @click="authMode = 'credentials'"
      >
        {{ t('auth.auth_tab_credentials') }}
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="authMode === 'biometric'"
        class="flex-1 rounded-lg py-1.5 text-center transition-all cursor-pointer flex items-center justify-center gap-1.5"
        :class="authMode === 'biometric' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
        @click="authMode = 'biometric'"
      >
        <Fingerprint class="h-3.5 w-3.5 text-primary" />
        <span>{{ t('auth.auth_tab_biometric') }}</span>
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="authMode === 'breakglass'"
        class="flex-1 rounded-lg py-1.5 text-center transition-all cursor-pointer flex items-center justify-center gap-1.5"
        :class="authMode === 'breakglass' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
        @click="authMode = 'breakglass'"
      >
        <ShieldAlert class="h-3.5 w-3.5 text-rose-500" />
        <span>STAT</span>
      </button>
    </div>

    <!-- ==================== MODE 1: STAFF CREDENTIALS ==================== -->
    <form v-if="authMode === 'credentials'" class="space-y-4" @submit.prevent="submit">
      <!-- Work Email / Staff ID -->
      <div class="space-y-1.5">
        <Label for="email" class="text-xs font-semibold text-foreground">
          {{ t('auth.email') }}
        </Label>
        <Input
          id="email"
          v-model="form.email"
          type="email"
          required
          autofocus
          autocomplete="email"
          :placeholder="t('auth.email_placeholder')"
          class="h-10 transition-colors"
          :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.email }"
        />
        <p v-if="form.errors.email" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.email }}</span>
        </p>
      </div>

      <!-- Password -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between">
          <Label for="password" class="text-xs font-semibold text-foreground">
            {{ t('auth.password') }}
          </Label>
          <Link
            v-if="props.canResetPassword !== false"
            href="/forgot-password"
            class="text-xs font-medium text-primary hover:underline transition-colors"
          >
            {{ t('auth.forgot_password') }}
          </Link>
        </div>
        <div class="relative">
          <Input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="current-password"
            placeholder="••••••••"
            class="h-10 pr-10 transition-colors font-mono"
            :class="{ 'border-destructive focus-visible:ring-destructive': form.errors.password }"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground cursor-pointer transition-colors"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" class="h-4 w-4" />
            <Eye v-else class="h-4 w-4" />
          </button>
        </div>
        <p v-if="form.errors.password" class="flex items-center gap-1 text-xs text-destructive pt-0.5" role="alert">
          <AlertCircle class="h-3.5 w-3.5" />
          <span>{{ form.errors.password }}</span>
        </p>
      </div>

      <!-- Remember Terminal Checkbox -->
      <div class="flex items-center gap-2 pt-0.5">
        <Checkbox id="remember" v-model="form.remember" />
        <label for="remember" class="text-xs text-muted-foreground font-medium cursor-pointer select-none">
          {{ t('auth.remember_me') }}
        </label>
      </div>

      <!-- Submit Sign In Button -->
      <Button
        type="submit"
        class="h-10 w-full text-sm font-semibold shadow-md shadow-primary/20 cursor-pointer"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          <span>{{ t('common.loading') }}</span>
        </span>
        <span v-else class="flex items-center gap-2">
          <span>{{ t('auth.sign_in') }}</span>
          <ArrowRight class="h-4 w-4" />
        </span>
      </Button>
    </form>

    <!-- ==================== MODE 2: BIOMETRIC / SMARTCARD (TAP & GO) ==================== -->
    <div v-else-if="authMode === 'biometric'" class="space-y-4 rounded-2xl border border-primary/20 bg-card p-6 text-center shadow-xs">
      <div class="relative mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 border border-primary/30">
        <Fingerprint class="h-10 w-10 text-primary" :class="{ 'animate-pulse text-cyan-400': isScanningBio }" />
        <span v-if="isScanningBio" class="absolute inset-0 rounded-full border-2 border-primary animate-ping" />
      </div>

      <div class="space-y-1">
        <h3 class="text-base font-bold text-foreground">{{ t('auth.biometric_prompt_title') }}</h3>
        <p class="text-xs text-muted-foreground leading-relaxed">{{ t('auth.biometric_prompt_desc') }}</p>
      </div>

      <div v-if="bioScanSuccess" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs font-semibold text-emerald-600 dark:text-emerald-400 flex items-center justify-center gap-2">
        <CheckCircle2 class="h-4 w-4" />
        <span>{{ t('auth.biometric_success') }}</span>
      </div>

      <Button
        type="button"
        size="lg"
        class="h-11 w-full text-sm font-semibold shadow-md shadow-primary/20 cursor-pointer"
        :disabled="isScanningBio"
        @click="triggerBiometricScan"
      >
        <span v-if="isScanningBio" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          <span>{{ t('auth.biometric_reading') }}</span>
        </span>
        <span v-else class="flex items-center gap-2">
          <Fingerprint class="h-4 w-4" />
          <span>{{ t('auth.biometric_scan_btn') }}</span>
        </span>
      </Button>
    </div>

    <!-- ==================== MODE 3: EMERGENCY BREAK-GLASS (STAT) ==================== -->
    <form v-else class="space-y-4 rounded-2xl border border-rose-500/30 bg-rose-500/5 p-5 shadow-xs" @submit.prevent="submit">
      <div class="flex items-start gap-3">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-500/20 text-rose-600 dark:text-rose-400 shrink-0">
          <AlertTriangle class="h-5 w-5" />
        </div>
        <div class="space-y-1 text-left">
          <h3 class="text-sm font-bold text-rose-700 dark:text-rose-400">{{ t('auth.breakglass_title') }}</h3>
          <p class="text-[11px] text-muted-foreground leading-relaxed">{{ t('auth.breakglass_desc') }}</p>
        </div>
      </div>

      <div class="space-y-1.5 text-left">
        <Label for="breakglass-reason" class="text-xs font-semibold text-foreground">
          {{ t('auth.breakglass_reason') }}
        </Label>
        <Input
          id="breakglass-reason"
          v-model="form.breakglass_reason"
          type="text"
          required
          autofocus
          :placeholder="t('auth.breakglass_reason_placeholder')"
          class="h-10 border-rose-500/40 bg-card transition-colors focus-visible:ring-rose-500"
        />
      </div>

      <Button
        type="submit"
        variant="destructive"
        class="h-11 w-full text-sm font-bold shadow-lg shadow-rose-500/20 cursor-pointer"
        :disabled="form.processing"
      >
        <span v-if="form.processing" class="flex items-center gap-2">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
          <span>Authorizing STAT Emergency Access...</span>
        </span>
        <span v-else class="flex items-center gap-2">
          <ShieldAlert class="h-4 w-4" />
          <span>{{ t('auth.breakglass_btn') }}</span>
        </span>
      </Button>
    </form>

    <!-- Registration Link -->
    <div v-if="props.canRegister !== false" class="text-center text-xs text-muted-foreground">
      <span>{{ t('auth.need_account') }} </span>
      <Link href="/register" class="font-semibold text-primary hover:underline transition-colors">
        {{ t('auth.request_account') }}
      </Link>
    </div>
  </div>
</template>