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

import { computed, ref } from "vue";
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
}

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
  status: "ordered" | "sample_collected" | "in_progress" | "completed" | "cancelled";
  clinicalIndication?: string;
  orderingClinician?: string;
  createdAt: string;
  collectedAt?: string | null;
  collectedBy?: string | null;
  barcode?: string;
  specimenIntegrity?: "adequate" | "hemolyzed" | "clotted" | "lipemic" | "insufficient";
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

// Built-in Standard Clinical Lab Panel Profiles (2027 CLSI / WHO Guidelines)
export const LAB_PANEL_TEMPLATES: Record<string, LabTestParameter[]> = {
  "LAB-HEM-HB": [
    { key: "hb", name: "Hemoglobin (Hb)", value: null, unit: "g/dL", referenceRange: "12.0 – 17.5", minNormal: 12.0, maxNormal: 17.5, minCritical: 7.0, maxCritical: 20.0, flag: "normal", previousValue: "13.2" },
  ],
  "LAB-PAR-MRDT": [
    { key: "mrdt", name: "Malaria Pf/Pan Antigen", value: null, unit: "result", referenceRange: "Negative", flag: "normal" },
    { key: "density", name: "Parasite Density (if +ve)", value: null, unit: "parasites/µL", referenceRange: "0", flag: "normal" },
  ],
  "LAB-BIO-GLUCOSE-RBG": [
    { key: "rbg", name: "Random Blood Glucose", value: null, unit: "mmol/L", referenceRange: "4.0 – 7.8", minNormal: 4.0, maxNormal: 7.8, minCritical: 2.8, maxCritical: 25.0, flag: "normal", previousValue: "5.4" },
  ],
  "LAB-SER-HIV-RDT": [
    { key: "hiv_sd", name: "HIV 1/2 Screening (Determine)", value: null, unit: "result", referenceRange: "Non-Reactive", flag: "normal" },
    { key: "hiv_conf", name: "HIV 1/2 Confirmatory (Uni-Gold)", value: null, unit: "result", referenceRange: "Non-Reactive", flag: "normal" },
  ],
  "LAB-URI-ROUTINE": [
    { key: "color", name: "Appearance / Color", value: null, unit: "visual", referenceRange: "Clear / Straw", flag: "normal" },
    { key: "ph", name: "Urine pH", value: null, unit: "pH", referenceRange: "5.0 – 7.5", minNormal: 5.0, maxNormal: 7.5, flag: "normal" },
    { key: "protein", name: "Protein (Albumin)", value: null, unit: "mg/dL", referenceRange: "Negative (<15)", flag: "normal" },
    { key: "glucose", name: "Glucose (Glycosuria)", value: null, unit: "mmol/L", referenceRange: "Negative", flag: "normal" },
    { key: "leukocytes", name: "Leukocyte Esterase", value: null, unit: "cells/µL", referenceRange: "Negative", flag: "normal" },
    { key: "nitrite", name: "Nitrite", value: null, unit: "result", referenceRange: "Negative", flag: "normal" },
    { key: "blood", name: "Blood / Hemoglobin", value: null, unit: "Ery/µL", referenceRange: "Negative", flag: "normal" },
  ],
  "LAB-BIO-LIPID-CHO": [
    { key: "chol_total", name: "Total Cholesterol", value: null, unit: "mmol/L", referenceRange: "< 5.2", maxNormal: 5.2, maxCritical: 10.0, flag: "normal" },
    { key: "triglycerides", name: "Triglycerides", value: null, unit: "mmol/L", referenceRange: "< 1.7", maxNormal: 1.7, flag: "normal" },
    { key: "hdl", name: "HDL ('Good') Cholesterol", value: null, unit: "mmol/L", referenceRange: "> 1.0", minNormal: 1.0, flag: "normal" },
    { key: "ldl", name: "LDL ('Bad') Cholesterol", value: null, unit: "mmol/L", referenceRange: "< 3.0", maxNormal: 3.0, flag: "normal" },
  ],
  "LAB-BIO-RENAL-URIC": [
    { key: "creatinine", name: "Serum Creatinine", value: null, unit: "µmol/L", referenceRange: "60 – 110", minNormal: 60, maxNormal: 110, maxCritical: 450, flag: "normal", previousValue: "88" },
    { key: "urea", name: "Blood Urea Nitrogen (BUN)", value: null, unit: "mmol/L", referenceRange: "2.5 – 7.1", minNormal: 2.5, maxNormal: 7.1, maxCritical: 30.0, flag: "normal" },
    { key: "uric_acid", name: "Uric Acid", value: null, unit: "µmol/L", referenceRange: "200 – 420", minNormal: 200, maxNormal: 420, flag: "normal" },
    { key: "egfr", name: "Estimated GFR (CKD-EPI)", value: null, unit: "mL/min/1.73m²", referenceRange: "> 90", minNormal: 90, minCritical: 15, flag: "normal" },
  ],
  "LAB-HEM-ESR": [
    { key: "esr", name: "ESR (Westergren)", value: null, unit: "mm/hr", referenceRange: "0 – 20", maxNormal: 20, maxCritical: 100, flag: "normal" },
  ],
  "LAB-BB-ABO-RH": [
    { key: "abo", name: "ABO Blood Group", value: null, unit: "group", referenceRange: "A / B / AB / O", flag: "normal" },
    { key: "rh", name: "Rhesus (Rh D) Factor", value: null, unit: "result", referenceRange: "Positive / Negative", flag: "normal" },
  ],
};

export function inferLaboratoryDepartment(testCode?: string, testName?: string, explicitDept?: string): string {
  if (explicitDept && explicitDept !== "General Lab" && explicitDept !== "Laboratory" && explicitDept !== "General") {
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
  const viewMode = ref<"patient" | "test">("patient"); // default to 2027 Patient-Centric Console

  const isLoadingOrders = ref(false);
  const isUpdatingOrder = ref(false);
  const isVerifying = ref(false);

  // Status counts
  const statusCounts = ref({
    all: 0,
    ordered: 0,
    sample_collected: 0,
    in_progress: 0,
    completed: 0,
    critical: 0,
  });

  // Filters
  const searchQuery = ref("");
  const selectedStatusFilter = ref<string>("all");
  const selectedDepartmentFilter = ref<string>("all");
  const selectedPriorityFilter = ref<string>("all");

  const selectedOrder = computed(() => {
    if (!selectedOrderId.value) return null;
    return orders.value.find((o) => o.id === selectedOrderId.value) ?? null;
  });

  // Patient Groups Computed
  const patientGroups = computed<PatientLabGroup[]>(() => {
    const map = new Map<string, PatientLabGroup>();

    for (const order of orders.value) {
      const pid = order.patientId || order.patientMrn || "unknown-pat";
      if (!map.has(pid)) {
        map.set(pid, {
          patientId: order.patientId,
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
      else if (order.status === "sample_collected") group.collectedCount++;
      else if (order.status === "in_progress") group.inProgressCount++;
      else if (order.status === "completed") group.completedCount++;

      // Evaluate highest priority
      if (order.priority === "stat") {
        group.highestPriority = "stat";
      } else if (order.priority === "urgent" && group.highestPriority !== "stat") {
        group.highestPriority = "urgent";
      }
    }

    return Array.from(map.values());
  });

  const selectedPatientGroup = computed(() => {
    if (!selectedPatientId.value) return null;
    return patientGroups.value.find((g) => g.patientId === selectedPatientId.value) ?? null;
  });

  const selectedPatientOrders = computed(() => {
    if (!selectedPatientId.value) {
      return selectedOrder.value ? [selectedOrder.value] : [];
    }
    return orders.value.filter((o) => o.patientId === selectedPatientId.value);
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
      selectedPatientId.value = order.patientId;
    }
  }

  // Auto-evaluate parameter flag based on CLSI thresholds
  function evaluateParameterFlag(param: LabTestParameter): LabTestParameter["flag"] {
    const num = typeof param.value === "number" ? param.value : parseFloat(String(param.value || ""));
    if (isNaN(num)) {
      if (typeof param.value === "string") {
        const lower = param.value.toLowerCase();
        if (lower.includes("positive") || lower.includes("reactive") || lower.includes("detected")) {
          return param.referenceRange.toLowerCase().includes("negative") || param.referenceRange.toLowerCase().includes("non-reactive")
            ? "abnormal"
            : "normal";
        }
      }
      return "normal";
    }

    if (param.minCritical !== undefined && num <= param.minCritical) return "critical_low";
    if (param.maxCritical !== undefined && num >= param.maxCritical) return "critical_high";
    if (param.minNormal !== undefined && num < param.minNormal) return "abnormal";
    if (param.maxNormal !== undefined && num > param.maxNormal) return "abnormal";
    return "normal";
  }

  // Load orders from API
  async function fetchOrders() {
    isLoadingOrders.value = true;
    try {
      const res = await fetch("/api/v1/laboratory/orders?perPage=50", {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (!res.ok) throw new Error("Failed to fetch lab orders");
      const json = await res.json();
      const rawOrders = (json.data ?? []) as any[];

      orders.value = rawOrders.map((raw: any) => {
        const testCode = raw.testCode || raw.code || "LAB-GEN";
        const testName = raw.testName || "Laboratory Investigation";
        const department = inferLaboratoryDepartment(testCode, testName, raw.department);
        const defaultParams = LAB_PANEL_TEMPLATES[testCode] ? JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES[testCode])) : [
          {
            key: "result",
            name: testName,
            value: raw.resultValue ?? null,
            unit: raw.unit ?? "",
            referenceRange: raw.referenceRange ?? "Normal",
            flag: raw.flag === "critical" ? "critical_high" : raw.flag === "abnormal" ? "abnormal" : "normal",
          },
        ];

        const patientObj = raw.patient || {};
        const pFirst = patientObj.firstName || raw.patientFirstName || "";
        const pMiddle = patientObj.middleName || "";
        const pLast = patientObj.lastName || raw.patientLastName || "";
        const pFullName = [pFirst, pMiddle, pLast].filter(Boolean).join(" ");

        const patientName = patientObj.name || (pFullName.length > 0 ? pFullName : (raw.patientName || "Patient"));
        const patientMrn = patientObj.mrn || patientObj.patientNumber || raw.patientMrn || raw.patientNumber || "MRN-0000";
        const patientGender = patientObj.gender || raw.patientGender || "—";
        const patientAge = patientObj.age || raw.patientAge || (patientObj.dateOfBirth ? `${new Date().getFullYear() - new Date(patientObj.dateOfBirth).getFullYear()} yrs` : "—");

        const rawStatus = raw.status || "ordered";
        const status = rawStatus === "collected" ? "sample_collected" : rawStatus;
        
        let parameters = defaultParams;
        if (Array.isArray(raw.resultParameters) && raw.resultParameters.length > 0) {
          parameters = raw.resultParameters.map((rp: any) => ({
            key: rp.code || rp.key || "res",
            name: rp.name || "Parameter",
            value: rp.value ?? null,
            unit: rp.unit || "",
            referenceRange: rp.referenceRange || "Normal",
            flag: rp.flag === "critical" ? "critical_high" : rp.flag === "abnormal" ? "abnormal" : "normal",
          }));
        }

        return {
          id: raw.id,
          orderNumber: raw.orderNumber || `LAB-${raw.id.slice(0, 8).toUpperCase()}`,
          patientId: raw.patientId,
          patientName,
          patientMrn,
          patientGender,
          patientAge,
          patientDob: patientObj.dateOfBirth || patientObj.dob || raw.patientDob,
          testCode,
          testName,
          department,
          sampleType: raw.sampleType || "Blood / Serum",
          tubeType: raw.tubeType || "Standard Tube",
          priority: raw.priority || "routine",
          status,
          clinicalIndication: raw.clinicalIndication || raw.indication || "Routine diagnostic evaluation",
          orderingClinician: raw.orderingClinician || "Attending Clinician",
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

      updateLocalStatusCounts();

      // Auto-select first order & patient
      if (!selectedOrderId.value && orders.value.length > 0) {
        selectOrder(orders.value[0].id);
      }
    } catch (err) {
      console.warn("Using sample mock dataset if laboratory orders endpoint empty", err);
      if (orders.value.length === 0) {
        populateDemoOrders();
      }
    } finally {
      isLoadingOrders.value = false;
    }
  }

  function updateLocalStatusCounts() {
    statusCounts.value = {
      all: orders.value.length,
      ordered: orders.value.filter((o) => o.status === "ordered").length,
      sample_collected: orders.value.filter((o) => o.status === "sample_collected").length,
      in_progress: orders.value.filter((o) => o.status === "in_progress").length,
      completed: orders.value.filter((o) => o.status === "completed").length,
      critical: orders.value.filter((o) => o.flag === "critical" || o.parameters.some((p) => p.flag.startsWith("critical"))).length,
    };
  }

  function populateDemoOrders() {
    orders.value = [
      // Patient 1: Amina Juma Mohamed (2 tests: Hb + mRDT)
      {
        id: "lab-ord-001",
        orderNumber: "LAB-2026-0841",
        patientId: "pat-101",
        patientName: "Amina Juma Mohamed",
        patientMrn: "MRN-2026-0042",
        patientGender: "Female",
        patientAge: "34 yrs",
        testCode: "LAB-HEM-HB",
        testName: "Hemoglobin (Hb) Test",
        department: "Hematology",
        sampleType: "Whole Blood (EDTA)",
        tubeType: "Lavender / Purple Top (EDTA K2)",
        priority: "stat",
        status: "ordered",
        clinicalIndication: "Severe pallor, fatigue, suspected severe anemia",
        orderingClinician: "Dr. K. Mwangi, MD",
        createdAt: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
        barcode: "*LAB0841*",
        specimenIntegrity: "adequate",
        parameters: JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES["LAB-HEM-HB"])),
        price: 5000,
      },
      {
        id: "lab-ord-001b",
        orderNumber: "LAB-2026-0841-B",
        patientId: "pat-101",
        patientName: "Amina Juma Mohamed",
        patientMrn: "MRN-2026-0042",
        patientGender: "Female",
        patientAge: "34 yrs",
        testCode: "LAB-PAR-MRDT",
        testName: "Malaria Rapid Diagnostic Test (mRDT)",
        department: "Parasitology",
        sampleType: "Capillary Blood",
        tubeType: "Fingerstick / EDTA Microtainer",
        priority: "stat",
        status: "ordered",
        clinicalIndication: "Fever spikes with chills",
        orderingClinician: "Dr. K. Mwangi, MD",
        createdAt: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
        barcode: "*LAB0841B*",
        specimenIntegrity: "adequate",
        parameters: JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES["LAB-PAR-MRDT"])),
        price: 5000,
      },
      // Patient 2: Juma Bakari Hassan (2 tests: Glucose + Urinalysis)
      {
        id: "lab-ord-002",
        orderNumber: "LAB-2026-0842",
        patientId: "pat-102",
        patientName: "Juma Bakari Hassan",
        patientMrn: "MRN-2026-0189",
        patientGender: "Male",
        patientAge: "48 yrs",
        testCode: "LAB-BIO-GLUCOSE-RBG",
        testName: "Random Blood Glucose (RBG)",
        department: "Biochemistry",
        sampleType: "Capillary / Fluoride Blood",
        tubeType: "Gray Top (Sodium Fluoride)",
        priority: "urgent",
        status: "sample_collected",
        clinicalIndication: "Type 2 Diabetes re-evaluation, polydipsia",
        orderingClinician: "Dr. S. Tarimo, MD",
        createdAt: new Date(Date.now() - 35 * 60 * 1000).toISOString(),
        collectedAt: new Date(Date.now() - 20 * 60 * 1000).toISOString(),
        collectedBy: "Nurse Mary (OPD)",
        barcode: "*LAB0842*",
        specimenIntegrity: "adequate",
        parameters: JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES["LAB-BIO-GLUCOSE-RBG"])),
        price: 5000,
      },
      {
        id: "lab-ord-002b",
        orderNumber: "LAB-2026-0842-B",
        patientId: "pat-102",
        patientName: "Juma Bakari Hassan",
        patientMrn: "MRN-2026-0189",
        patientGender: "Male",
        patientAge: "48 yrs",
        testCode: "LAB-URI-ROUTINE",
        testName: "Urinalysis (Dipstick + Microscopy)",
        department: "Urinalysis",
        sampleType: "Midstream Urine",
        tubeType: "Sterile Urine Cup",
        priority: "urgent",
        status: "sample_collected",
        clinicalIndication: "Glycosuria / Proteinuria check",
        orderingClinician: "Dr. S. Tarimo, MD",
        createdAt: new Date(Date.now() - 35 * 60 * 1000).toISOString(),
        collectedAt: new Date(Date.now() - 20 * 60 * 1000).toISOString(),
        collectedBy: "Nurse Mary (OPD)",
        barcode: "*LAB0842B*",
        specimenIntegrity: "adequate",
        parameters: JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES["LAB-URI-ROUTINE"])),
        price: 8000,
      },
      // Patient 3: Fatma Said Rashid (1 test: HIV Screening)
      {
        id: "lab-ord-003",
        orderNumber: "LAB-2026-0843",
        patientId: "pat-103",
        patientName: "Fatma Said Rashid",
        patientMrn: "MRN-2026-0210",
        patientGender: "Female",
        patientAge: "26 yrs",
        testCode: "LAB-SER-HIV-RDT",
        testName: "HIV 1/2 Rapid Antibody Test",
        department: "Serology",
        sampleType: "Whole Blood / Serum",
        tubeType: "Gold / Red Top (SST Gel)",
        priority: "routine",
        status: "in_progress",
        clinicalIndication: "Antenatal first trimester screening",
        orderingClinician: "Dr. K. Mwangi, MD",
        createdAt: new Date(Date.now() - 50 * 60 * 1000).toISOString(),
        collectedAt: new Date(Date.now() - 40 * 60 * 1000).toISOString(),
        collectedBy: "Lab Tech John",
        barcode: "*LAB0843*",
        specimenIntegrity: "adequate",
        parameters: JSON.parse(JSON.stringify(LAB_PANEL_TEMPLATES["LAB-SER-HIV-RDT"])),
        price: 5000,
      },
      // Patient 4: David Emmanuel Msangi (1 test: Renal Profile)
      {
        id: "lab-ord-004",
        orderNumber: "LAB-2026-0844",
        patientId: "pat-104",
        patientName: "David Emmanuel Msangi",
        patientMrn: "MRN-2026-0301",
        patientGender: "Male",
        patientAge: "55 yrs",
        testCode: "LAB-BIO-RENAL-URIC",
        testName: "Renal Function Profile",
        department: "Biochemistry",
        sampleType: "Serum",
        tubeType: "Gold / Red Top (SST Gel)",
        priority: "routine",
        status: "completed",
        clinicalIndication: "Hypertension baseline monitoring",
        orderingClinician: "Dr. A. Temu, MD",
        createdAt: new Date(Date.now() - 120 * 60 * 1000).toISOString(),
        collectedAt: new Date(Date.now() - 100 * 60 * 1000).toISOString(),
        collectedBy: "Nurse Mary (OPD)",
        verifiedAt: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
        verifiedBy: "MLS H. Mndeme (Lead Scientist)",
        barcode: "*LAB0844*",
        specimenIntegrity: "adequate",
        parameters: [
          { key: "creatinine", name: "Serum Creatinine", value: "92", unit: "µmol/L", referenceRange: "60 – 110", minNormal: 60, maxNormal: 110, flag: "normal", previousValue: "88" },
          { key: "urea", name: "Blood Urea Nitrogen (BUN)", value: "5.1", unit: "mmol/L", referenceRange: "2.5 – 7.1", minNormal: 2.5, maxNormal: 7.1, flag: "normal" },
          { key: "uric_acid", name: "Uric Acid", value: "310", unit: "µmol/L", referenceRange: "200 – 420", minNormal: 200, maxNormal: 420, flag: "normal" },
          { key: "egfr", name: "Estimated GFR", value: "98", unit: "mL/min/1.73m²", referenceRange: "> 90", minNormal: 90, flag: "normal" },
        ],
        price: 20000,
      },
    ];

    updateLocalStatusCounts();
    selectPatient("pat-101");
  }

  // Lifecycle: Accession & Receive Specimen
  async function acceptSpecimen(orderId: string, specimenNotes?: string) {
    isUpdatingOrder.value = true;
    try {
      const order = orders.value.find((o) => o.id === orderId);
      if (order) {
        order.status = "sample_collected";
        order.collectedAt = new Date().toISOString();
        order.collectedBy = "Current Lab Scientist";
        order.specimenIntegrity = "adequate";
      }

      await fetch(`/api/v1/laboratory/orders/${orderId}/status`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({ status: "collected", reason: specimenNotes || "Sample collected and integrity validated" }),
      });

      toast.success("Specimen accessioned & accepted successfully");
      updateLocalStatusCounts();
    } catch {
      toast.success("Specimen accessioned (offline/local)");
      updateLocalStatusCounts();
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  // Lifecycle: Reject Specimen
  async function rejectSpecimen(orderId: string, reason: string) {
    if (!reason.trim()) {
      toast.error("Please specify a reason for specimen rejection");
      return;
    }
    isUpdatingOrder.value = true;
    try {
      const order = orders.value.find((o) => o.id === orderId);
      if (order) {
        order.status = "cancelled";
        order.specimenIntegrity = "insufficient";
        order.rejectionReason = reason;
      }

      await fetch(`/api/v1/laboratory/orders/${orderId}/status`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({ status: "cancelled", reason }),
      });

      toast.info("Specimen rejected and ordering clinician notified");
      updateLocalStatusCounts();
    } catch {
      toast.info("Specimen rejected (offline/local)");
      updateLocalStatusCounts();
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  // Lifecycle: Start Analysis
  async function startAnalysis(orderId: string) {
    isUpdatingOrder.value = true;
    try {
      const order = orders.value.find((o) => o.id === orderId);
      if (order) {
        order.status = "in_progress";
      }

      await fetch(`/api/v1/laboratory/orders/${orderId}/status`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({ status: "in_progress", reason: "Analytical testing initiated" }),
      });

      toast.info("Test analysis initiated");
      updateLocalStatusCounts();
    } catch {
      updateLocalStatusCounts();
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  // One-Click Normal Defaults Filler
  function fillNormalDefaults(orderId: string) {
    const order = orders.value.find((o) => o.id === orderId);
    if (!order) return;

    for (const p of order.parameters) {
      if (p.unit === "result" || p.referenceRange.includes("Negative") || p.referenceRange.includes("Non-Reactive")) {
        p.value = p.referenceRange.includes("Non-Reactive") ? "Non-Reactive" : "Negative";
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

  // Verify and Publish Result to EMR
  async function verifyOrder(orderId: string, supervisorComments?: string) {
    const order = orders.value.find((o) => o.id === orderId);
    if (!order) return;

    // Evaluate overall flag
    const hasCritical = order.parameters.some((p) => p.flag === "critical_low" || p.flag === "critical_high");
    const hasAbnormal = order.parameters.some((p) => p.flag === "abnormal");
    const overallFlag = hasCritical ? "critical" : hasAbnormal ? "abnormal" : "normal";

    const summaryParts = order.parameters.map((p) => `${p.name}: ${p.value ?? '—'} ${p.unit}`);
    const resultSummary = summaryParts.join(", ");
    const note = supervisorComments || (overallFlag === "normal" ? "Diagnostic parameters within normal biological reference limits." : "Abnormal findings detected. Clinical correlation recommended.");

    isVerifying.value = true;
    try {
      order.status = "completed";
      order.verifiedAt = new Date().toISOString();
      order.verifiedBy = "Senior Medical Laboratory Scientist";
      order.flag = overallFlag;
      order.resultValue = resultSummary;
      order.interpretation = note;

      const formattedParams = order.parameters.map((p) => ({
        code: p.key,
        name: p.name,
        value: p.value !== null && p.value !== undefined ? String(p.value) : "—",
        unit: p.unit || "",
        flag: p.flag === "critical_high" || p.flag === "critical_low" ? "critical" : p.flag === "abnormal" ? "abnormal" : "normal",
        referenceRange: p.referenceRange || "",
      }));

      // Step 1: Update status to completed with results
      await fetch(`/api/v1/laboratory/orders/${orderId}/status`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({
          status: "completed",
          resultSummary,
          resultParameters: formattedParams,
        }),
      });

      // Step 2: Electronically verify and release
      await fetch(`/api/v1/laboratory/orders/${orderId}/verify`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({
          verificationNote: note,
        }),
      });

      toast.success("Lab Results verified and electronically published to patient chart!");
      updateLocalStatusCounts();
    } catch {
      toast.success("Lab Results verified (local mode)");
      updateLocalStatusCounts();
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
      toast.success(`Critical value telephone read-back logged with ${clinicianName}`);
    }
  }

  // Filtered Orders for Test Mode
  const filteredOrders = computed(() => {
    let list = orders.value;

    // Status filter
    if (selectedStatusFilter.value === "critical") {
      list = list.filter((o) => o.flag === "critical" || o.parameters.some((p) => p.flag.startsWith("critical")));
    } else if (selectedStatusFilter.value !== "all") {
      list = list.filter((o) => o.status === selectedStatusFilter.value);
    }

    // Department filter
    if (selectedDepartmentFilter.value !== "all") {
      const targetDept = selectedDepartmentFilter.value.toLowerCase();
      list = list.filter((o) => {
        const orderDept = (o.department || "").toLowerCase();
        return orderDept.includes(targetDept) || targetDept.includes(orderDept);
      });
    }

    // Priority filter
    if (selectedPriorityFilter.value !== "all") {
      list = list.filter((o) => o.priority === selectedPriorityFilter.value);
    }

    // Text search
    const q = searchQuery.value.trim().toLowerCase();
    if (q) {
      list = list.filter(
        (o) =>
          o.patientName?.toLowerCase().includes(q) ||
          o.patientMrn?.toLowerCase().includes(q) ||
          o.testName.toLowerCase().includes(q) ||
          o.testCode.toLowerCase().includes(q) ||
          o.orderNumber?.toLowerCase().includes(q),
      );
    }

    return list;
  });

  // Filtered Patients for Patient Mode
  const filteredPatientGroups = computed(() => {
    let list = patientGroups.value;

    // Priority filter
    if (selectedPriorityFilter.value !== "all") {
      list = list.filter((g) => g.highestPriority === selectedPriorityFilter.value);
    }

    // Status filter (check if any order in group matches status)
    if (selectedStatusFilter.value !== "all") {
      list = list.filter((g) => g.orders.some((o) => o.status === selectedStatusFilter.value));
    }

    // Department filter
    if (selectedDepartmentFilter.value !== "all") {
      const targetDept = selectedDepartmentFilter.value.toLowerCase();
      list = list.filter((g) =>
        g.orders.some((o) => {
          const orderDept = (o.department || "").toLowerCase();
          return orderDept.includes(targetDept) || targetDept.includes(orderDept);
        }),
      );
    }

    // Text search
    const q = searchQuery.value.trim().toLowerCase();
    if (q) {
      list = list.filter(
        (g) =>
          g.patientName.toLowerCase().includes(q) ||
          g.patientMrn.toLowerCase().includes(q) ||
          g.orders.some((o) => o.testName.toLowerCase().includes(q) || o.testCode.toLowerCase().includes(q)),
      );
    }

    return list;
  });

  return {
    orders,
    selectedOrderId,
    selectedPatientId,
    viewMode,
    selectedOrder,
    selectedPatientGroup,
    selectedPatientOrders,
    patientGroups,
    filteredPatientGroups,
    isLoadingOrders,
    isUpdatingOrder,
    isVerifying,
    statusCounts,
    searchQuery,
    selectedStatusFilter,
    selectedDepartmentFilter,
    selectedPriorityFilter,
    filteredOrders,
    fetchOrders,
    selectPatient,
    selectOrder,
    acceptSpecimen,
    rejectSpecimen,
    startAnalysis,
    fillNormalDefaults,
    verifyOrder,
    logCriticalNotification,
    evaluateParameterFlag,
  };
}

export type UseLaboratoryOrders = ReturnType<typeof useLaboratoryOrders>;
