/**
 * useLaboratoryOrders — Laboratory Orders & LIS Composable (Volume 2.4)
 * =========================================================================
 * 2027 Modern Enterprise Hospital LIS Management:
 * - Dual-View Architecture: Group by Patient vs By Specimen/Test
 * - Patient multi-test batch accessioning & verification
 * - Live lab worklist with status counts & discipline filtering
 * - Specimen accessioning & integrity verification (Hemolysis, Clotted, QNS)
 * - Multi-parameter structured result matrix with automated clinical range checking
 * - Senior Technologist verification & electronic EMR release
 * - Critical value panic alert notification pipeline
 */

import { computed, ref, watch } from "vue";
import { useToast } from "@/composables/useToast";

export interface LabTestParameter {
  key: string;
  name: string;
  value: string | number | null;
  unit: string;
  referenceRange: string;
  minNormal?: number;
  maxNormal?: number;
  minCritical?: number;
  maxCritical?: number;
  flag: "normal" | "abnormal" | "critical_low" | "critical_high";
  previousValue?: string | number | null;
  previousDate?: string | null;
  /**
   * Where this parameter belongs on a sectioned result sheet (e.g. "Dipstick"
   * or "Microscopy"). Undefined for the flat legacy panels.
   */
  section?: string;
  /**
   * The catalog template's field type, when the parameter was derived from the
   * backend's sectioned `resultTemplate` ("select", "number", "positive-negative",
   * "text", "multiselect", "not-done"). Lets the result sheet render the right
   * input instead of always a free-text box.
   */
  fieldType?: string;
  /** Candidate choices for a `select`/`multiselect` field from the template. */
  options?: string[];
  /** Placeholder hint for `text` fields, from the template. */
  placeholder?: string;
}

/**
 * The backend's own vocabulary, used verbatim. This workspace used to rename
 * `collected` to `sample_collected` on read and rename it back on write, so any
 * code that compared a status against the real API value was silently false.
 */
export type LaboratoryOrderStatus =
  | "ordered"
  | "collected"
  | "in_progress"
  | "completed"
  | "cancelled";

export interface LaboratoryOrder {
  id: string;
  orderNumber?: string;
  patientId: string;
  patientName?: string;
  patientMrn?: string;
  patientGender?: string;
  patientAge?: number | string;
  patientDob?: string;
  testCode: string;
  testName: string;
  department: string;
  sampleType: string;
  tubeType?: string;
  priority: "routine" | "urgent" | "stat";
  status: LaboratoryOrderStatus;
  clinicalIndication?: string;
  orderingClinician?: string;
  createdAt: string;
  collectedAt?: string | null;
  collectedBy?: string | null;
  barcode?: string;
  specimenIntegrity?:
    | "adequate"
    | "hemolyzed"
    | "clotted"
    | "lipemic"
    | "insufficient";
  rejectionReason?: string | null;
  parameters: LabTestParameter[];
  resultValue?: string | number | null;
  unit?: string | null;
  referenceRange?: string | null;
  flag?: "normal" | "abnormal" | "critical" | null;
  technicianNotes?: string | null;
  interpretation?: string | null;
  verifiedAt?: string | null;
  verifiedBy?: string | null;
  criticalNotifiedAt?: string | null;
  criticalNotifiedTo?: string | null;
  price?: number;
  /**
   * Where the patient stands in the whole visit, resolved server-side by the
   * same code every other board uses (ClinicalOrderVisitStageEnricher). Null
   * for a direct-service order, which has no appointment and so no visit stage.
   */
  visitStage?: string | null;
}

export interface PatientLabGroup {
  patientId: string;
  patientName: string;
  patientMrn: string;
  patientGender: string;
  patientAge: string | number;
  patientDob?: string;
  orders: LaboratoryOrder[];
  totalTests: number;
  pendingCount: number;
  collectedCount: number;
  inProgressCount: number;
  completedCount: number;
  highestPriority: "stat" | "urgent" | "routine";
  latestCreatedAt: string;
  orderingClinician?: string;
  clinicalIndication?: string;
}

/**
 * The bench stage — the one thing this workspace never had.
 * ==========================================================
 * `status` alone could not say where an order stood, because `completed` means
 * two different things: results are typed but unreleased, and results are
 * released to the chart. Every screen therefore invented its own rule and they
 * disagreed, which is how a "Verify & Release" button ended up on an order
 * whose specimen had not arrived.
 *
 * Stage is derived, never stored: it is `status` plus `verifiedAt`, resolved in
 * exactly one place. Every button, tab and banner reads from here.
 */
export type LabStage =
  | "awaiting_specimen"
  | "ready_for_analysis"
  | "in_analysis"
  | "awaiting_release"
  | "released"
  | "rejected";

export type LabTabId =
  | "results"
  | "accessioning"
  | "verification"
  | "audit"
  | "journey";

/** The four steps a technician actually walks. `released`/`rejected` are outcomes. */
export type LabBenchStep = Exclude<LabStage, "released" | "rejected">;

export const LAB_STAGE_SEQUENCE: readonly LabBenchStep[] = [
  "awaiting_specimen",
  "ready_for_analysis",
  "in_analysis",
  "awaiting_release",
];

/** Position of a stage on the bench, or -1 for the terminal outcomes. */
export function benchStepIndex(stage: LabStage): number {
  return (LAB_STAGE_SEQUENCE as readonly LabStage[]).indexOf(stage);
}

export function labStageOf(
  order: Pick<LaboratoryOrder, "status" | "verifiedAt">,
): LabStage {
  if (order.status === "cancelled") return "rejected";
  if (order.status === "completed")
    return order.verifiedAt ? "released" : "awaiting_release";
  if (order.status === "in_progress") return "in_analysis";
  if (order.status === "collected") return "ready_for_analysis";

  return "awaiting_specimen";
}

/** Which tab is the technician's actual workstation at this stage. */
export const LAB_STAGE_TAB: Record<LabStage, LabTabId> = {
  awaiting_specimen: "accessioning",
  ready_for_analysis: "accessioning",
  in_analysis: "results",
  awaiting_release: "verification",
  released: "verification",
  rejected: "accessioning",
};

/**
 * A tab is reachable once the order has reached the stage that tab serves.
 * Reading back is always allowed; jumping ahead of the bench is not.
 */
export function isLabTabReachable(tab: LabTabId, stage: LabStage): boolean {
  if (tab === "audit" || tab === "journey") return true;
  if (tab === "accessioning") return true;
  // A rejected order keeps its result sheet readable but never opens a release.
  if (stage === "rejected") return tab === "results";

  // `released` sits past the last bench step, so treat it as fully advanced.
  const reached = benchStepIndex(stage);
  const stageIndex = reached === -1 ? LAB_STAGE_SEQUENCE.length : reached;

  if (tab === "results") return stageIndex >= benchStepIndex("in_analysis");

  return stageIndex >= benchStepIndex("awaiting_release");
}

/** Parameters the technician still has to fill before results can be saved. */
export function missingParameters(order: LaboratoryOrder): LabTestParameter[] {
  return order.parameters.filter(
    (p) =>
      p.value === null ||
      p.value === undefined ||
      String(p.value).trim() === "",
  );
}

export function hasCompleteResults(order: LaboratoryOrder): boolean {
  return order.parameters.length > 0 && missingParameters(order).length === 0;
}

/**
 * Where a second pair of eyes actually changes the outcome (ISO 15189 §7.4).
 * Deliberately narrow: a blanket second-review prompt on every release becomes
 * a rubber stamp within a week, which is worse than not asking.
 */
const SECOND_REVIEW_TEST_CODES = new Set(["LAB-SER-HIV-RDT", "LAB-BB-ABO-RH"]);
const SECOND_REVIEW_DEPARTMENTS = new Set(["Blood Bank"]);

export function secondReviewReason(order: LaboratoryOrder): string | null {
  if (
    order.parameters.some(
      (p) => p.flag === "critical_low" || p.flag === "critical_high",
    )
  ) {
    return "critical";
  }
  if (
    SECOND_REVIEW_TEST_CODES.has(order.testCode) ||
    SECOND_REVIEW_DEPARTMENTS.has(order.department)
  ) {
    return "high_stakes";
  }

  return null;
}

// Built-in Standard Clinical Lab Panel Profiles (2027 CLSI / WHO Guidelines)
export const LAB_PANEL_TEMPLATES: Record<string, LabTestParameter[]> = {
  "LAB-HEM-HB": [
    {
      key: "hb",
      name: "Hemoglobin (Hb)",
      value: null,
      unit: "g/dL",
      referenceRange: "12.0 – 17.5",
      minNormal: 12.0,
      maxNormal: 17.5,
      minCritical: 7.0,
      maxCritical: 20.0,
      flag: "normal",
      previousValue: "13.2",
    },
  ],
  "LAB-PAR-MRDT": [
    {
      key: "mrdt",
      name: "Malaria Pf/Pan Antigen",
      value: null,
      unit: "result",
      referenceRange: "Negative",
      flag: "normal",
    },
    {
      key: "density",
      name: "Parasite Density (if +ve)",
      value: null,
      unit: "parasites/µL",
      referenceRange: "0",
      flag: "normal",
    },
  ],
  "LAB-BIO-GLUCOSE-RBG": [
    {
      key: "rbg",
      name: "Random Blood Glucose",
      value: null,
      unit: "mmol/L",
      referenceRange: "4.0 – 7.8",
      minNormal: 4.0,
      maxNormal: 7.8,
      minCritical: 2.8,
      maxCritical: 25.0,
      flag: "normal",
      previousValue: "5.4",
    },
  ],
  "LAB-SER-HIV-RDT": [
    {
      key: "hiv_sd",
      name: "HIV 1/2 Screening (Determine)",
      value: null,
      unit: "result",
      referenceRange: "Non-Reactive",
      flag: "normal",
    },
    {
      key: "hiv_conf",
      name: "HIV 1/2 Confirmatory (Uni-Gold)",
      value: null,
      unit: "result",
      referenceRange: "Non-Reactive",
      flag: "normal",
    },
  ],
  "LAB-URI-ROUTINE": [
    // Physical Examination (2 parameters)
    {
      key: "color",
      name: "Color",
      value: null,
      unit: "",
      referenceRange: "Pale Yellow / Yellow",
      flag: "normal",
      section: "Physical Examination",
      fieldType: "select",
      options: [
        "Pale Yellow",
        "Yellow",
        "Dark Yellow",
        "Amber",
        "Red",
        "Brown",
        "Colourless",
        "Cloudy",
      ],
    },
    {
      key: "appearance",
      name: "Appearance",
      value: null,
      unit: "",
      referenceRange: "Clear",
      flag: "normal",
      section: "Physical Examination",
      fieldType: "select",
      options: ["Clear", "Slightly Cloudy", "Cloudy", "Turbid"],
    },
    // Dipstick / Chemical Examination (10 parameters)
    {
      key: "specific_gravity",
      name: "Specific Gravity (SG)",
      value: null,
      unit: "",
      referenceRange: "1.005 – 1.030",
      flag: "normal",
      section: "Dipstick",
      fieldType: "text",
      placeholder: "e.g. 1.015",
    },
    {
      key: "ph",
      name: "Urine pH",
      value: null,
      unit: "pH",
      referenceRange: "5.0 – 7.5",
      minNormal: 5.0,
      maxNormal: 7.5,
      flag: "normal",
      section: "Dipstick",
      fieldType: "number",
      placeholder: "e.g. 6.0",
    },
    {
      key: "protein",
      name: "Protein (Albumin)",
      value: null,
      unit: "mg/dL",
      referenceRange: "Negative (<15)",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Negative", "Trace", "+", "++", "+++"],
    },
    {
      key: "glucose",
      name: "Glucose (Glycosuria)",
      value: null,
      unit: "mmol/L",
      referenceRange: "Negative",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Negative", "Trace", "+", "++", "+++"],
    },
    {
      key: "ketones",
      name: "Ketones",
      value: null,
      unit: "mmol/L",
      referenceRange: "Negative",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Negative", "Trace", "+", "++", "+++"],
    },
    {
      key: "bilirubin",
      name: "Bilirubin",
      value: null,
      unit: "",
      referenceRange: "Negative",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Negative", "+", "++", "+++"],
    },
    {
      key: "urobilinogen",
      name: "Urobilinogen",
      value: null,
      unit: "",
      referenceRange: "Normal",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Normal", "+", "++", "+++"],
    },
    {
      key: "nitrites",
      name: "Nitrite",
      value: null,
      unit: "result",
      referenceRange: "Negative",
      flag: "normal",
      section: "Dipstick",
      fieldType: "positive-negative",
      options: ["Negative", "Positive"],
    },
    {
      key: "blood",
      name: "Blood / Hemoglobin",
      value: null,
      unit: "Ery/µL",
      referenceRange: "Negative",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Negative", "Trace", "+", "++", "+++"],
    },
    {
      key: "leukocytes",
      name: "Leukocyte Esterase",
      value: null,
      unit: "cells/µL",
      referenceRange: "Negative",
      flag: "normal",
      section: "Dipstick",
      fieldType: "select",
      options: ["Negative", "Trace", "+", "++", "+++"],
    },
    // Microscopy (Core 7 parameters)
    {
      key: "wbc",
      name: "White Blood Cells (WBC)",
      value: null,
      unit: "",
      referenceRange: "0 – 5/HPF",
      flag: "normal",
      section: "Microscopy",
      fieldType: "text",
      placeholder: "e.g. 0–5/HPF",
    },
    {
      key: "rbc",
      name: "Red Blood Cells (RBC)",
      value: null,
      unit: "",
      referenceRange: "0 – 3/HPF",
      flag: "normal",
      section: "Microscopy",
      fieldType: "text",
      placeholder: "e.g. 0–3/HPF",
    },
    {
      key: "epithelial_cells",
      name: "Epithelial Cells",
      value: null,
      unit: "",
      referenceRange: "Few / Moderate / Many",
      flag: "normal",
      section: "Microscopy",
      fieldType: "text",
      placeholder: "e.g. Few, Moderate, Many",
    },
    {
      key: "casts",
      name: "Casts",
      value: null,
      unit: "",
      referenceRange: "None Seen",
      flag: "normal",
      section: "Microscopy",
      fieldType: "select",
      options: ["None Seen", "Hyaline", "Granular", "Cellular", "Waxy"],
    },
    {
      key: "crystals",
      name: "Crystals",
      value: null,
      unit: "",
      referenceRange: "None Seen",
      flag: "normal",
      section: "Microscopy",
      fieldType: "select",
      options: [
        "None Seen",
        "Calcium Oxalate",
        "Uric Acid",
        "Triple Phosphate",
        "Amorphous",
      ],
    },
    {
      key: "bacteria",
      name: "Bacteria",
      value: null,
      unit: "",
      referenceRange: "None Seen",
      flag: "normal",
      section: "Microscopy",
      fieldType: "select",
      options: ["None Seen", "Few", "Moderate", "Many"],
    },
    {
      key: "yeast",
      name: "Yeast Cells",
      value: null,
      unit: "",
      referenceRange: "None Seen",
      flag: "normal",
      section: "Microscopy",
      fieldType: "select",
      options: ["None Seen", "Few", "Moderate"],
    },
    // Tanzania MoH Parasites in urine (e.g. Schistosoma haematobium)
    {
      key: "parasites",
      name: "Parasites (e.g. S. haematobium)",
      value: null,
      unit: "",
      referenceRange: "None Seen",
      flag: "normal",
      section: "Microscopy",
      fieldType: "select",
      options: [
        "None Seen",
        "Schistosoma haematobium ova seen",
        "Trichomonas vaginalis seen",
        "Other",
      ],
    },
  ],
  "LAB-BIO-LIPID-CHO": [
    {
      key: "chol_total",
      name: "Total Cholesterol",
      value: null,
      unit: "mmol/L",
      referenceRange: "< 5.2",
      maxNormal: 5.2,
      maxCritical: 10.0,
      flag: "normal",
    },
    {
      key: "triglycerides",
      name: "Triglycerides",
      value: null,
      unit: "mmol/L",
      referenceRange: "< 1.7",
      maxNormal: 1.7,
      flag: "normal",
    },
    {
      key: "hdl",
      name: "HDL ('Good') Cholesterol",
      value: null,
      unit: "mmol/L",
      referenceRange: "> 1.0",
      minNormal: 1.0,
      flag: "normal",
    },
    {
      key: "ldl",
      name: "LDL ('Bad') Cholesterol",
      value: null,
      unit: "mmol/L",
      referenceRange: "< 3.0",
      maxNormal: 3.0,
      flag: "normal",
    },
  ],
  "LAB-BIO-RENAL-URIC": [
    {
      key: "creatinine",
      name: "Serum Creatinine",
      value: null,
      unit: "µmol/L",
      referenceRange: "60 – 110",
      minNormal: 60,
      maxNormal: 110,
      maxCritical: 450,
      flag: "normal",
      previousValue: "88",
    },
    {
      key: "urea",
      name: "Blood Urea Nitrogen (BUN)",
      value: null,
      unit: "mmol/L",
      referenceRange: "2.5 – 7.1",
      minNormal: 2.5,
      maxNormal: 7.1,
      maxCritical: 30.0,
      flag: "normal",
    },
    {
      key: "uric_acid",
      name: "Uric Acid",
      value: null,
      unit: "µmol/L",
      referenceRange: "200 – 420",
      minNormal: 200,
      maxNormal: 420,
      flag: "normal",
    },
    {
      key: "egfr",
      name: "Estimated GFR (CKD-EPI)",
      value: null,
      unit: "mL/min/1.73m²",
      referenceRange: "> 90",
      minNormal: 90,
      minCritical: 15,
      flag: "normal",
    },
  ],
  "LAB-HEM-ESR": [
    {
      key: "esr",
      name: "ESR (Westergren)",
      value: null,
      unit: "mm/hr",
      referenceRange: "0 – 20",
      maxNormal: 20,
      maxCritical: 100,
      flag: "normal",
    },
  ],
  "LAB-BB-ABO-RH": [
    {
      key: "abo",
      name: "ABO Blood Group",
      value: null,
      unit: "group",
      referenceRange: "A / B / AB / O",
      flag: "normal",
    },
    {
      key: "rh",
      name: "Rhesus (Rh D) Factor",
      value: null,
      unit: "result",
      referenceRange: "Positive / Negative",
      flag: "normal",
    },
  ],
};

/**
 * Build the result-entry parameter list from the backend's sectioned catalog
 * template (`metadata.resultTemplate`), flattened into a single sheet of rows.
 *
 * The catalog is the single source of truth for which parameters a test has —
 * e.g. Urinalysis (LAB-URI-ROUTINE) defines Physical Examination, Dipstick and
 * Microscopy sections. Only flattening these fields surfaces the Microscopy
 * parameters; the legacy client-side `LAB_PANEL_TEMPLATES` map predates the
 * sectioned templates and omits them.
 */
function parametersFromResultTemplate(
  resultTemplate: any,
  persistedResults: any[] | null,
): LabTestParameter[] | null {
  const sections = resultTemplate?.sections;
  if (!Array.isArray(sections) || sections.length === 0) return null;

  // Map a previously saved value onto the right template field by code, so a
  // reopened order shows what was entered rather than blank rows.
  const savedByCode = new Map<string, any>();
  if (Array.isArray(persistedResults)) {
    for (const rp of persistedResults) {
      savedByCode.set(rp.code ?? rp.key, rp);
    }
  }

  const params: LabTestParameter[] = [];
  for (const section of sections) {
    const fields = section?.fields;
    if (!Array.isArray(fields)) continue;
    for (const field of fields) {
      const code = field?.code || field?.key;
      if (!code) continue;
      const saved = savedByCode.get(code);
      params.push({
        key: code,
        name: field?.label || code,
        value: saved?.value ?? null,
        unit: saved?.unit || "",
        referenceRange: saved?.referenceRange || "Normal",
        flag:
          saved?.flag === "critical"
            ? "critical_high"
            : saved?.flag === "abnormal"
              ? "abnormal"
              : "normal",
        section: section?.label,
        fieldType: field?.type,
        options: Array.isArray(field?.options) ? field.options : undefined,
        placeholder: field?.placeholder,
      });
    }
  }

  return params.length > 0 ? params : null;
}

export function inferLaboratoryDepartment(
  testCode?: string,
  testName?: string,
  explicitDept?: string,
): string {
  if (
    explicitDept &&
    explicitDept !== "General Lab" &&
    explicitDept !== "Laboratory" &&
    explicitDept !== "General"
  ) {
    return explicitDept;
  }
  const code = (testCode || "").toUpperCase();
  const name = (testName || "").toLowerCase();

  if (
    code.includes("-HEM-") ||
    code.includes("CBC") ||
    code.includes("FBC") ||
    code.includes("ESR") ||
    code.includes("HB") ||
    name.includes("blood count") ||
    name.includes("hemoglobin") ||
    name.includes("esr")
  ) {
    return "Hematology";
  }
  if (
    code.includes("-BIO-") ||
    code.includes("GLUCOSE") ||
    code.includes("RBG") ||
    code.includes("FBS") ||
    code.includes("LIPID") ||
    code.includes("RENAL") ||
    code.includes("LFT") ||
    code.includes("KFT") ||
    name.includes("glucose") ||
    name.includes("lipid") ||
    name.includes("renal") ||
    name.includes("creatinine") ||
    name.includes("liver") ||
    name.includes("cholesterol") ||
    name.includes("uric")
  ) {
    return "Biochemistry";
  }
  if (
    code.includes("-PAR-") ||
    code.includes("MAL") ||
    code.includes("MRDT") ||
    code.includes("STOOL") ||
    name.includes("malaria") ||
    name.includes("parasite") ||
    name.includes("stool")
  ) {
    return "Parasitology";
  }
  if (
    code.includes("-SER-") ||
    code.includes("HIV") ||
    code.includes("VDRL") ||
    code.includes("RPR") ||
    code.includes("HEPB") ||
    code.includes("HEPC") ||
    code.includes("HBSAG") ||
    code.includes("HCV") ||
    code.includes("WIDAL") ||
    code.includes("HPYLORI") ||
    code.includes("UPT") ||
    name.includes("hiv") ||
    name.includes("serology") ||
    name.includes("hepatitis") ||
    name.includes("syphilis") ||
    name.includes("widal") ||
    name.includes("typhoid") ||
    name.includes("pylori") ||
    name.includes("pregnancy")
  ) {
    return "Serology";
  }
  if (
    code.includes("-URI-") ||
    code.includes("DIP") ||
    name.includes("urinalysis") ||
    name.includes("urine")
  ) {
    return "Urinalysis";
  }
  if (
    code.includes("-BB-") ||
    code.includes("ABO") ||
    name.includes("blood group") ||
    name.includes("crossmatch")
  ) {
    return "Blood Bank";
  }
  if (
    code.includes("-MIC-") ||
    name.includes("culture") ||
    name.includes("gram stain") ||
    name.includes("swab") ||
    name.includes("sensitivity")
  ) {
    return "Microbiology";
  }

  return "Hematology";
}

export function useLaboratoryOrders() {
  const toast = useToast();

  const orders = ref<LaboratoryOrder[]>([]);
  const selectedOrderId = ref<string | null>(null);
  const selectedPatientId = ref<string | null>(null);
  /**
   * A page of the worklist, asked for explicitly. Large enough for a real
   * bench list, and visible here rather than buried in a query string.
   */
  const WORKLIST_PAGE_SIZE = 200;

  const viewMode = ref<"patient" | "test">("patient"); // default to 2027 Patient-Centric Console

  const isLoadingOrders = ref(true);
  /** A load that failed is not a load that returned nothing. */
  const loadFailed = ref(false);
  const isUpdatingOrder = ref(false);
  const isSavingResults = ref(false);
  const isVerifying = ref(false);

  // Status counts
  const statusCounts = ref({
    all: 0,
    ordered: 0,
    collected: 0,
    in_progress: 0,
    completed: 0,
    critical: 0,
  });

  // Filters
  const searchQuery = ref("");
  const selectedStatusFilter = ref<string>("all");
  const selectedPriorityFilter = ref<string>("all");

  const selectedOrder = computed(() => {
    if (!selectedOrderId.value) return null;
    return orders.value.find((o) => o.id === selectedOrderId.value) ?? null;
  });

  /** The one stage value the whole workspace renders from. */
  const selectedStage = computed<LabStage | null>(() =>
    selectedOrder.value ? labStageOf(selectedOrder.value) : null,
  );

  // Patient Groups Computed
  const patientGroups = computed<PatientLabGroup[]>(() => {
    const map = new Map<string, PatientLabGroup>();

    for (const order of orders.value) {
      const pid = order.patientId || order.patientMrn || "unknown-pat";
      if (!map.has(pid)) {
        map.set(pid, {
          patientId: order.patientId || pid,
          patientName: order.patientName || "Patient",
          patientMrn: order.patientMrn || "MRN-0000",
          patientGender: order.patientGender || "—",
          patientAge: order.patientAge || "—",
          patientDob: order.patientDob,
          orders: [],
          totalTests: 0,
          pendingCount: 0,
          collectedCount: 0,
          inProgressCount: 0,
          completedCount: 0,
          highestPriority: "routine",
          latestCreatedAt: order.createdAt,
          orderingClinician: order.orderingClinician,
          clinicalIndication: order.clinicalIndication,
        });
      }

      const group = map.get(pid)!;
      group.orders.push(order);
      group.totalTests++;

      if (order.status === "ordered") group.pendingCount++;
      else if (order.status === "collected") group.collectedCount++;
      else if (order.status === "in_progress") group.inProgressCount++;
      else if (order.status === "completed") group.completedCount++;

      // Evaluate highest priority
      if (order.priority === "stat") {
        group.highestPriority = "stat";
      } else if (
        order.priority === "urgent" &&
        group.highestPriority !== "stat"
      ) {
        group.highestPriority = "urgent";
      }
    }

    return Array.from(map.values());
  });

  const selectedPatientGroup = computed(() => {
    if (!selectedPatientId.value) return null;
    return (
      patientGroups.value.find(
        (g) => g.patientId === selectedPatientId.value,
      ) ?? null
    );
  });

  const selectedPatientOrders = computed(() => {
    if (!selectedPatientId.value) {
      return selectedOrder.value ? [selectedOrder.value] : [];
    }
    return orders.value.filter(
      (o) =>
        (o.patientId || o.patientMrn || "unknown-pat") ===
        selectedPatientId.value,
    );
  });

  function selectPatient(patientId: string) {
    selectedPatientId.value = patientId;
    const group = patientGroups.value.find((g) => g.patientId === patientId);
    if (group && group.orders.length > 0) {
      selectedOrderId.value = group.orders[0].id;
    }
  }

  function selectOrder(orderId: string) {
    selectedOrderId.value = orderId;
    const order = orders.value.find((o) => o.id === orderId);
    if (order) {
      selectedPatientId.value =
        order.patientId || order.patientMrn || "unknown-pat";
    }
  }

  // Auto-evaluate parameter flag based on CLSI thresholds & qualitative findings
  function evaluateParameterFlag(
    param: LabTestParameter,
  ): LabTestParameter["flag"] {
    const num =
      typeof param.value === "number"
        ? param.value
        : parseFloat(String(param.value || ""));
    if (isNaN(num)) {
      if (typeof param.value === "string") {
        const val = param.value.trim().toLowerCase();
        const ref = (param.referenceRange || "").toLowerCase();

        // Obvious abnormal qualitative indicators
        if (
          val.includes("positive") ||
          val.includes("reactive") ||
          val.includes("detected") ||
          val.includes("ova seen") ||
          val.includes("schistosoma") ||
          val.includes("trichomonas") ||
          val.includes("turbid") ||
          val.includes("cloudy") ||
          val.includes("many") ||
          val.includes("moderate") ||
          val === "+" ||
          val === "++" ||
          val === "+++" ||
          val === "++++" ||
          val.includes("trace")
        ) {
          if (
            ref.includes("negative") ||
            ref.includes("non-reactive") ||
            ref.includes("none seen") ||
            ref.includes("clear") ||
            ref.includes("normal")
          ) {
            return "abnormal";
          }
        }
      }
      return "normal";
    }

    if (param.minCritical !== undefined && num <= param.minCritical)
      return "critical_low";
    if (param.maxCritical !== undefined && num >= param.maxCritical)
      return "critical_high";
    if (param.minNormal !== undefined && num < param.minNormal)
      return "abnormal";
    if (param.maxNormal !== undefined && num > param.maxNormal)
      return "abnormal";
    return "normal";
  }

  // Load orders from API
  async function fetchOrders() {
    isLoadingOrders.value = true;
    try {
      // The worklist is a query. This asked for a flat `?perPage=50` and then
      // did status, discipline and priority filtering in the browser, so a lab
      // with more than 50 open orders silently lost the rest and every filter
      // was applied to a truncated set.
      const params = new URLSearchParams({
        perPage: String(WORKLIST_PAGE_SIZE),
      });
      if (
        selectedStatusFilter.value !== "all" &&
        selectedStatusFilter.value !== "critical"
      ) {
        params.set("status", selectedStatusFilter.value);
      }
      if (selectedPriorityFilter.value !== "all") {
        params.set("priority", selectedPriorityFilter.value);
      }
      const search = searchQuery.value.trim();
      if (search !== "") params.set("q", search);

      const res = await fetch(
        `/api/v1/laboratory/orders?${params.toString()}`,
        {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        },
      );
      if (!res.ok) throw new Error("Failed to fetch lab orders");
      const json = await res.json();
      const rawOrders = (json.data ?? []) as any[];

      orders.value = rawOrders.map((raw: any) => {
        const testCode = raw.testCode || raw.code || "LAB-GEN";
        const testName = raw.testName || "Laboratory Investigation";
        // Server-supplied, from the catalog item's own category. The local
        // inference stays only as a fallback for an order whose catalog entry
        // has no category recorded.
        const department =
          raw.department || inferLaboratoryDepartment(testCode, testName);
        const defaultParams = LAB_PANEL_TEMPLATES[testCode]
          ? JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES[testCode]))
          : [
              {
                key: "result",
                name: testName,
                value: raw.resultValue ?? null,
                unit: raw.unit ?? "",
                referenceRange: raw.referenceRange ?? "Normal",
                flag:
                  raw.flag === "critical"
                    ? "critical_high"
                    : raw.flag === "abnormal"
                      ? "abnormal"
                      : "normal",
              },
            ];

        const patientObj = raw.patient || {};
        const pFirst = patientObj.firstName || raw.patientFirstName || "";
        const pMiddle = patientObj.middleName || "";
        const pLast = patientObj.lastName || raw.patientLastName || "";
        const pFullName = [pFirst, pMiddle, pLast].filter(Boolean).join(" ");

        const patientName =
          patientObj.name ||
          (pFullName.length > 0 ? pFullName : raw.patientName || "Patient");
        const patientMrn =
          patientObj.mrn ||
          patientObj.patientNumber ||
          raw.patientMrn ||
          raw.patientNumber ||
          "MRN-0000";
        const patientGender = patientObj.gender || raw.patientGender || "—";
        const patientAge =
          patientObj.age ||
          raw.patientAge ||
          (patientObj.dateOfBirth
            ? `${new Date().getFullYear() - new Date(patientObj.dateOfBirth).getFullYear()} yrs`
            : "—");

        const status = (raw.status || "ordered") as LaboratoryOrderStatus;

        const persistedResults = Array.isArray(raw.resultParameters)
          ? raw.resultParameters
          : null;

        // The catalog's sectioned template is the source of truth for which
        // parameters a test carries (it includes sections such as Microscopy
        // that the legacy flat map below omits). It is merged with any already
        // saved values so reopened orders keep their entered results.
        const templateParams = parametersFromResultTemplate(
          raw.catalogResultTemplate,
          persistedResults,
        );

        let parameters: LabTestParameter[];
        if (templateParams) {
          parameters = templateParams;
        } else if (persistedResults && persistedResults.length > 0) {
          parameters = persistedResults.map((rp: any) => ({
            key: rp.code || rp.key || "res",
            name: rp.name || "Parameter",
            value: rp.value ?? null,
            unit: rp.unit || "",
            referenceRange: rp.referenceRange || "Normal",
            flag:
              rp.flag === "critical"
                ? "critical_high"
                : rp.flag === "abnormal"
                  ? "abnormal"
                  : "normal",
          }));
        } else {
          parameters = defaultParams;
        }

        return {
          id: raw.id,
          orderNumber:
            raw.orderNumber || `LAB-${raw.id.slice(0, 8).toUpperCase()}`,
          patientId: raw.patientId,
          patientName,
          patientMrn,
          patientGender,
          patientAge,
          patientDob:
            patientObj.dateOfBirth || patientObj.dob || raw.patientDob,
          testCode,
          testName,
          department,
          sampleType: raw.sampleType || "Blood / Serum",
          tubeType: raw.tubeType || "Standard Tube",
          priority: raw.priority || "routine",
          status,
          clinicalIndication:
            raw.clinicalIndication ||
            raw.indication ||
            "Routine diagnostic evaluation",
          // ClinicalOrderUserSummaryEnricher attaches `orderedBy: {id, name}`.
          // Reading `orderingClinician` — a key the API never sends — meant the
          // header always fell through to the placeholder, so every order looked
          // as though nobody in particular had prescribed it.
          orderingClinician:
            raw.orderedBy?.name ||
            raw.orderingClinician ||
            "Attending Clinician",
          createdAt: raw.createdAt || new Date().toISOString(),
          collectedAt: raw.collectedAt,
          collectedBy: raw.collectedBy,
          barcode: raw.barcode || `*${raw.id.slice(0, 8).toUpperCase()}*`,
          specimenIntegrity: raw.specimenIntegrity || "adequate",
          parameters,
          resultValue: raw.resultValue || raw.resultSummary,
          unit: raw.unit,
          referenceRange: raw.referenceRange,
          flag: raw.flag,
          technicianNotes: raw.technicianNotes,
          interpretation: raw.interpretation || raw.verificationNote,
          verifiedAt: raw.verifiedAt,
          verifiedBy: raw.verifiedBy,
          criticalNotifiedAt: raw.criticalNotifiedAt,
          criticalNotifiedTo: raw.criticalNotifiedTo,
          price: raw.price,
          visitStage: raw.visitStage ?? null,
        };
      });

      void fetchStatusCounts();

      loadFailed.value = false;

      // Auto-select first patient in queue if nothing is selected or previous
      // selection is no longer in list.
      if (
        !selectedOrderId.value ||
        !orders.value.some((o) => o.id === selectedOrderId.value)
      ) {
        if (
          patientGroups.value.length > 0 &&
          patientGroups.value[0].orders.length > 0
        ) {
          selectOrder(patientGroups.value[0].orders[0].id);
        } else if (orders.value.length > 0) {
          selectOrder(orders.value[0].id);
        } else {
          selectedOrderId.value = null;
          selectedPatientId.value = null;
        }
      }
    } catch (err) {
      // This used to swap in a hardcoded demo dataset — six invented patients,
      // MRNs and all — whenever the worklist came back empty *or* the request
      // failed. A filter with no matches showed fabricated orders under it, and
      // so did a 500. Nothing in a clinical worklist may be made up: an empty
      // result is empty, and a failed load says so.
      console.error("Failed to fetch laboratory orders", err);
      loadFailed.value = true;
    } finally {
      isLoadingOrders.value = false;
    }
  }

  /**
   * Totals from the server, across the whole worklist.
   *
   * `laboratory/orders/status-counts` has existed all along and was called zero
   * times; the counts were summed from the fetched page instead, so the tabs
   * agreed with a truncated list.
   */
  async function fetchStatusCounts() {
    try {
      const params = new URLSearchParams();
      if (selectedPriorityFilter.value !== "all") {
        params.set("priority", selectedPriorityFilter.value);
      }
      const search = searchQuery.value.trim();
      if (search !== "") params.set("q", search);

      const res = await fetch(
        `/api/v1/laboratory/orders/status-counts?${params.toString()}`,
        {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        },
      );
      if (!res.ok) return;

      const body = await res.json();
      const counts = body.data ?? {};
      statusCounts.value = {
        all: Object.values(counts).reduce(
          (sum: number, n) => sum + Number(n || 0),
          0,
        ),
        ordered: Number(counts.ordered ?? 0),
        collected: Number(counts.collected ?? 0),
        in_progress: Number(counts.in_progress ?? 0),
        completed: Number(counts.completed ?? 0),
        // Critical is a property of entered results, not a stored status, so it
        // stays derived from the loaded page.
        critical: orders.value.filter(
          (o) =>
            o.flag === "critical" ||
            o.parameters.some((p) => p.flag.startsWith("critical")),
        ).length,
      };
    } catch {
      // Keep the last known totals rather than blanking the tabs.
    }
  }

  function updateLocalStatusCounts() {
    statusCounts.value = {
      all: orders.value.length,
      ordered: orders.value.filter((o) => o.status === "ordered").length,
      collected: orders.value.filter((o) => o.status === "collected").length,
      in_progress: orders.value.filter((o) => o.status === "in_progress")
        .length,
      completed: orders.value.filter((o) => o.status === "completed").length,
      critical: orders.value.filter(
        (o) =>
          o.flag === "critical" ||
          o.parameters.some((p) => p.flag.startsWith("critical")),
      ).length,
    };
  }

  /**
   * Pull the real reason a write was refused out of the response.
   *
   * This workspace used to swallow every rejection: `fetch` does not throw on
   * 4xx, nothing checked `res.ok`, and the `catch` branch reported *success*
   * anyway. A technician saw "verified and published" while the server had
   * refused the transition, and the next live-sync refresh silently undid the
   * screen. Everything below exists so that can never happen again.
   */
  async function readWriteError(
    res: Response,
    fallback: string,
  ): Promise<string> {
    try {
      const json = await res.json();
      if (typeof json?.message === "string" && json.message.trim() !== "") {
        return json.message;
      }
      const first = Object.values(json?.errors ?? {}).flat()[0];
      if (typeof first === "string") return first;
    } catch {
      // Non-JSON body (HTML error page, empty 500) — fall through.
    }

    if (res.status === 403) {
      return "You do not have permission to perform this step.";
    }
    if (res.status === 404) {
      return "This laboratory order no longer exists. Refresh the worklist.";
    }

    return fallback;
  }

  /**
   * Apply an optimistic change, then keep it only if the server agreed.
   * On refusal the exact fields we touched are restored, so the screen can
   * never show a stage the backend did not accept.
   */
  async function runOrderMutation(
    orderId: string,
    optimistic: Partial<LaboratoryOrder>,
    request: () => Promise<Response>,
    fallbackError: string,
  ): Promise<boolean> {
    const order = orders.value.find((o) => o.id === orderId);
    if (!order) return false;

    const rollback = Object.fromEntries(
      Object.keys(optimistic).map((key) => [
        key,
        order[key as keyof LaboratoryOrder],
      ]),
    );

    Object.assign(order, optimistic);
    updateLocalStatusCounts();

    try {
      const res = await request();

      if (!res.ok) {
        Object.assign(order, rollback);
        updateLocalStatusCounts();
        toast.error(await readWriteError(res, fallbackError));
        return false;
      }

      // Trust the server's echo over our guess, so status and verifiedAt are
      // whatever the database actually holds.
      const serverOrder = (await res.json().catch(() => null))?.data;
      if (serverOrder?.status) {
        order.status = serverOrder.status as LaboratoryOrderStatus;
        order.verifiedAt = serverOrder.verifiedAt ?? order.verifiedAt;
        order.resultValue = serverOrder.resultSummary ?? order.resultValue;
      }

      updateLocalStatusCounts();
      return true;
    } catch {
      Object.assign(order, rollback);
      updateLocalStatusCounts();
      toast.error(fallbackError, {
        description:
          "The laboratory server could not be reached. Nothing was saved.",
      });
      return false;
    }
  }

  function patchStatus(
    orderId: string,
    body: Record<string, unknown>,
  ): Promise<Response> {
    return fetch(`/api/v1/laboratory/orders/${orderId}/status`, {
      method: "PATCH",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify(body),
    });
  }

  // Step 1 — Receive the specimen at the bench (ordered → collected)
  async function acceptSpecimen(
    orderId: string,
    specimenNotes?: string,
  ): Promise<boolean> {
    isUpdatingOrder.value = true;
    try {
      const ok = await runOrderMutation(
        orderId,
        { status: "collected", specimenIntegrity: "adequate" },
        () =>
          patchStatus(orderId, {
            status: "collected",
            reason:
              specimenNotes?.trim() ||
              "Sample received and integrity validated",
          }),
        "Could not accession this specimen.",
      );

      if (ok) toast.success("Specimen received. Next step: start analysis.");
      return ok;
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  // Off-ramp — Reject the specimen (only before analysis begins)
  async function rejectSpecimen(
    orderId: string,
    reason: string,
  ): Promise<boolean> {
    if (!reason.trim()) {
      toast.error("Please specify a reason for specimen rejection");
      return false;
    }

    isUpdatingOrder.value = true;
    try {
      const ok = await runOrderMutation(
        orderId,
        {
          status: "cancelled",
          specimenIntegrity: "insufficient",
          rejectionReason: reason,
        },
        () => patchStatus(orderId, { status: "cancelled", reason }),
        "Could not reject this specimen.",
      );

      if (ok)
        toast.info(
          "Specimen rejected. The ordering clinician has been notified.",
        );
      return ok;
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  // Step 2 — Begin analysis (collected → in_progress)
  async function startAnalysis(orderId: string): Promise<boolean> {
    isUpdatingOrder.value = true;
    try {
      const ok = await runOrderMutation(
        orderId,
        { status: "in_progress" },
        () =>
          patchStatus(orderId, {
            status: "in_progress",
            reason: "Analytical testing initiated",
          }),
        "Could not start analysis on this order.",
      );

      if (ok) toast.info("Analysis started. Next step: enter results.");
      return ok;
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  // One-Click Normal Defaults Filler
  function fillNormalDefaults(orderId: string) {
    const order = orders.value.find((o) => o.id === orderId);
    if (!order) return;

    for (const p of order.parameters) {
      const key = (p.key || "").toLowerCase();
      if (key === "color") {
        p.value = p.options?.includes("Yellow") ? "Yellow" : "Pale Yellow";
      } else if (key === "appearance") {
        p.value = "Clear";
      } else if (key === "specific_gravity" || key === "sg") {
        p.value = "1.015";
      } else if (key === "ph") {
        p.value = p.fieldType === "number" ? 6.0 : "6.0";
      } else if (key === "wbc" || key === "pus_cells") {
        p.value = "0–2/HPF";
      } else if (key === "rbc") {
        p.value = "0–1/HPF";
      } else if (key === "epithelial_cells") {
        p.value = "Few";
      } else if (p.options?.includes("None Seen")) {
        p.value = "None Seen";
      } else if (p.options?.includes("Negative")) {
        p.value = "Negative";
      } else if (p.options?.includes("Non-Reactive")) {
        p.value = "Non-Reactive";
      } else if (p.options?.includes("Normal")) {
        p.value = "Normal";
      } else if (
        p.unit === "result" ||
        p.fieldType === "positive-negative" ||
        p.referenceRange?.includes("Negative") ||
        p.referenceRange?.includes("Non-Reactive")
      ) {
        p.value = p.referenceRange?.includes("Non-Reactive")
          ? "Non-Reactive"
          : "Negative";
      } else if (p.minNormal !== undefined && p.maxNormal !== undefined) {
        p.value = Number(((p.minNormal + p.maxNormal) / 2).toFixed(1));
      } else if (p.maxNormal !== undefined) {
        p.value = Number((p.maxNormal * 0.7).toFixed(1));
      } else if (p.minNormal !== undefined) {
        p.value = Number((p.minNormal * 1.2).toFixed(1));
      }
      p.flag = evaluateParameterFlag(p);
    }
    toast.info("Normal reference values populated");
  }

  function overallFlagOf(
    order: LaboratoryOrder,
  ): "critical" | "abnormal" | "normal" {
    if (
      order.parameters.some(
        (p) => p.flag === "critical_low" || p.flag === "critical_high",
      )
    ) {
      return "critical";
    }

    return order.parameters.some((p) => p.flag === "abnormal")
      ? "abnormal"
      : "normal";
  }

  /**
   * Step 3 — Save the results to the order (in_progress → completed).
   *
   * Saving is NOT releasing. This writes the numbers and stops; the report is
   * still a draft that no clinician can see. Refusing to fill a parameter used
   * to publish "Hemoglobin: — g/dL" straight to the patient chart, because the
   * old one-click path substituted an em dash for every empty field.
   */
  async function saveResults(orderId: string): Promise<boolean> {
    const order = orders.value.find((o) => o.id === orderId);
    if (!order) return false;

    const missing = missingParameters(order);
    if (missing.length > 0) {
      toast.error(
        "Every parameter needs a value before results can be saved.",
        {
          description: `Still empty: ${missing.map((p) => p.name).join(", ")}`,
        },
      );
      return false;
    }

    const resultSummary = [
      order.parameters
        .map((p) => `${p.name}: ${p.value} ${p.unit}`.trim())
        .join(", "),
      // The backend decides a result is critical by looking for this exact
      // phrase (VerifyLaboratoryOrderResultUseCase::isCriticalResultSummary),
      // which then makes the verification note mandatory. Emit it so a critical
      // panel cannot be released with an empty note.
      overallFlagOf(order) === "critical" ? "Result flag: critical" : null,
    ]
      .filter(Boolean)
      .join(" | ");

    const resultParameters = order.parameters.map((p) => ({
      code: p.key,
      name: p.name,
      value: String(p.value),
      unit: p.unit || "",
      flag:
        p.flag === "critical_high" || p.flag === "critical_low"
          ? "critical"
          : p.flag === "abnormal"
            ? "abnormal"
            : "normal",
      referenceRange: p.referenceRange || "",
    }));

    isSavingResults.value = true;
    try {
      const ok = await runOrderMutation(
        orderId,
        {
          status: "completed",
          flag: overallFlagOf(order),
          resultValue: resultSummary,
        },
        () =>
          patchStatus(orderId, {
            status: "completed",
            resultSummary,
            resultParameters,
          }),
        "Could not save these results.",
      );

      if (ok) {
        toast.success("Results saved as a draft report.", {
          description:
            "Nothing has reached the patient chart yet — review and release it next.",
        });
      }
      return ok;
    } finally {
      isSavingResults.value = false;
    }
  }

  /**
   * Step 4 — Release the saved report to the patient chart.
   *
   * Separate call, separate button, separate tab. This is the only path that
   * makes a result visible to a clinician, and it is reachable only from
   * `awaiting_release`.
   */
  async function releaseResults(
    orderId: string,
    verificationNote: string,
    selfVerified = false,
  ): Promise<boolean> {
    const order = orders.value.find((o) => o.id === orderId);
    if (!order) return false;

    if (labStageOf(order) !== "awaiting_release") {
      toast.error("Save the results before releasing this report.");
      return false;
    }

    const note = verificationNote.trim();
    if (note === "") {
      toast.error(
        "A release note is required before this report goes to the chart.",
      );
      return false;
    }

    isVerifying.value = true;
    try {
      const ok = await runOrderMutation(
        orderId,
        { verifiedAt: new Date().toISOString(), interpretation: note },
        () =>
          fetch(`/api/v1/laboratory/orders/${orderId}/verify`, {
            method: "PATCH",
            headers: {
              "Content-Type": "application/json",
              Accept: "application/json",
              "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({
              verificationNote: selfVerified ? `[Self-verified] ${note}` : note,
            }),
          }),
        "Could not release this report.",
      );

      if (ok) {
        toast.success("Report released to the patient chart.");
        // The release is what hands the patient back to the clinician
        // (RecordLaboratoryFlowTransitionService), so re-read the worklist to
        // pick up the new visit stage rather than guessing at it here.
        void fetchOrders();
      }
      return ok;
    } finally {
      isVerifying.value = false;
    }
  }

  // Panic Critical Result Call Logger
  function logCriticalNotification(orderId: string, clinicianName: string) {
    const order = orders.value.find((o) => o.id === orderId);
    if (order) {
      order.criticalNotifiedAt = new Date().toISOString();
      order.criticalNotifiedTo = clinicianName;
      toast.success(
        `Critical value telephone read-back logged with ${clinicianName}`,
      );
    }
  }

  /**
   * The server already returned this exact slice.
   *
   * Status, discipline, priority and search are query parameters now, so
   * re-applying them here would filter an already-filtered set — and worse,
   * would look like it worked while quietly hiding rows the server had chosen.
   * Only `critical` stays local: it is a property of entered results, not a
   * stored status the API can filter on.
   */
  const filteredOrders = computed(() => {
    if (selectedStatusFilter.value === "critical") {
      return orders.value.filter(
        (o) =>
          o.flag === "critical" ||
          o.parameters.some((p) => p.flag.startsWith("critical")),
      );
    }

    return orders.value;
  });

  /** Patient view of the same server-selected slice. */
  const filteredPatientGroups = computed(() => {
    if (selectedStatusFilter.value !== "critical") return patientGroups.value;

    const criticalIds = new Set(filteredOrders.value.map((o) => o.id));

    return patientGroups.value.filter((g) =>
      g.orders.some((o) => criticalIds.has(o.id)),
    );
  });

  // The filters drive the query, so every change re-asks the server — the same
  // rule reception's queue needed: a selection that changes what is shown has to
  // change what is fetched.
  watch([selectedStatusFilter, selectedPriorityFilter], () => {
    void fetchOrders();
  });

  /**
   * Search re-queries too, debounced so a typed word is one request rather than
   * one per keystroke. It was a client-side `.filter()` over the loaded page,
   * which searched only what had already been fetched.
   */
  let searchDebounce: ReturnType<typeof setTimeout> | undefined;
  watch(searchQuery, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      void fetchOrders();
    }, 300);
  });

  return {
    orders,
    fetchStatusCounts,
    selectedOrderId,
    selectedPatientId,
    viewMode,
    selectedOrder,
    selectedPatientGroup,
    selectedPatientOrders,
    patientGroups,
    filteredPatientGroups,
    isLoadingOrders,
    loadFailed,
    isUpdatingOrder,
    isSavingResults,
    isVerifying,
    selectedStage,
    statusCounts,
    searchQuery,
    selectedStatusFilter,
    selectedPriorityFilter,
    filteredOrders,
    fetchOrders,
    selectPatient,
    selectOrder,
    acceptSpecimen,
    rejectSpecimen,
    startAnalysis,
    fillNormalDefaults,
    saveResults,
    releaseResults,
    logCriticalNotification,
    evaluateParameterFlag,
  };
}

export type UseLaboratoryOrders = ReturnType<typeof useLaboratoryOrders>;
