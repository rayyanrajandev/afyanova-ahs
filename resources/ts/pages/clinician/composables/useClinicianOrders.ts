/**
 * Clinician Orders Management Composable (Volume 2.2 §8)
 * =======================================================
 * Manages ordering of Diagnostic Laboratory Panels, Radiology/Imaging Exams,
 * Pharmacy Prescriptions, and Specialist Referrals.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";

export interface LabCatalogItem {
  id: string;
  code: string;
  name: string;
  department: string;
  sampleType: string;
  price?: number;
}

export interface RadiologyCatalogItem {
  id: string;
  code: string;
  name: string;
  modality: "X-Ray" | "Ultrasound" | "CT" | "MRI" | "ECG" | "ECHO";
  price?: number;
}

export interface MedicationCatalogItem {
  id: string;
  code: string;
  name: string;
  genericName?: string;
  strength?: string;
  form?: string;
  defaultRoute?: string;
  price?: number;
}

export interface ActivePrescriptionOrder {
  id?: string;
  catalogItemId?: string;
  drugCode?: string;
  drugName: string;
  dosage: string;
  route: string;
  frequency: string;
  durationDays: number;
  quantityPrescribed?: number;
  instructions: string;
  unitPrice?: number;
}

export interface PlacedClinicalOrder {
  id: string;
  type: "lab" | "imaging" | "medication" | "referral";
  name: string;
  priority: "routine" | "urgent" | "stat";
  status: "pending" | "in_progress" | "complete" | "cancelled";
  createdAt: string;
  details?: string;
  price?: number;
}
export const STANDARD_LAB_CATALOG: LabCatalogItem[] = [
  { id: "lab-mrdt", code: "LAB-PAR-MRDT", name: "Malaria Rapid Diagnostic Test (mRDT)", department: "Parasitology", sampleType: "Capillary / Whole Blood", price: 5000 },
  { id: "lab-hiv", code: "LAB-SER-HIV-RDT", name: "HIV 1/2 Rapid Antibody Test", department: "Serology", sampleType: "Whole Blood / Serum", price: 5000 },
  { id: "lab-hpylori", code: "LAB-SER-HPYLORI-RDT", name: "H. pylori Antibody Test", department: "Serology", sampleType: "Serum", price: 15000 },
  { id: "lab-vdrl", code: "LAB-SER-SYPHILIS-RPR", name: "Syphilis Test (VDRL / RPR)", department: "Serology", sampleType: "Serum", price: 8000 },
  { id: "lab-hb", code: "LAB-HEM-HB", name: "Hemoglobin (Hb) Test", department: "Hematology", sampleType: "Whole Blood (EDTA)", price: 5000 },
  { id: "lab-rbg", code: "LAB-BIO-GLUCOSE-RBG", name: "Random Blood Glucose (RBG)", department: "Biochemistry", sampleType: "Capillary / Whole Blood", price: 5000 },
  { id: "lab-abo", code: "LAB-BB-ABO-RH", name: "Blood Grouping & Rh Factor", department: "Blood Bank", sampleType: "Whole Blood", price: 10000 },
  { id: "lab-urine", code: "LAB-URI-ROUTINE", name: "Urinalysis (Dipstick + Microscopy)", department: "Urinalysis", sampleType: "Midstream Urine", price: 8000 },
  { id: "lab-stool", code: "LAB-PAR-STOOL-ROUTINE", name: "Stool Routine Analysis", department: "Parasitology", sampleType: "Fresh Stool", price: 8000 },
  { id: "lab-esr", code: "LAB-HEM-ESR", name: "Erythrocyte Sedimentation Rate (ESR)", department: "Hematology", sampleType: "Whole Blood (Citrate)", price: 10000 },
  { id: "lab-hvs", code: "LAB-MIC-HVS", name: "High Vaginal Swab Test (HVS)", department: "Microbiology", sampleType: "Swab", price: 15000 },
  { id: "lab-upt", code: "LAB-SER-UPT", name: "Urine Pregnancy Test (UPT)", department: "Serology", sampleType: "Urine", price: 5000 },
  { id: "lab-hbsag", code: "LAB-SER-HBSAG-RDT", name: "Hepatitis B Surface Antigen (HBsAg)", department: "Serology", sampleType: "Serum", price: 12000 },
  { id: "lab-hcv", code: "LAB-SER-HCV-RDT", name: "Hepatitis C Antibody Test (Anti-HCV)", department: "Serology", sampleType: "Serum", price: 15000 },
  { id: "lab-widal", code: "LAB-SER-WIDAL", name: "Typhoid Test (Widal Agglutination)", department: "Serology", sampleType: "Serum", price: 10000 },
  { id: "lab-cho", code: "LAB-BIO-LIPID-CHO", name: "Lipid Profile (Cholesterol)", department: "Biochemistry", sampleType: "Serum", price: 25000 },
  { id: "lab-ura", code: "LAB-BIO-RENAL-URIC", name: "Renal Function Test (Uric Acid)", department: "Biochemistry", sampleType: "Serum", price: 20000 },
];

export const STANDARD_RADIOLOGY_CATALOG: RadiologyCatalogItem[] = [
  { id: "rad-abd-us", code: "RAD-US-ABDOMEN", name: "Abdominal Ultrasound", modality: "Ultrasound", price: 35000 },
  { id: "rad-pelv-us", code: "RAD-US-PELVIS", name: "Pelvic Ultrasound", modality: "Ultrasound", price: 30000 },
  { id: "rad-obs-us", code: "RAD-US-OBSTETRIC", name: "Obstetric Ultrasound", modality: "Ultrasound", price: 30000 },
  { id: "rad-thy-us", code: "RAD-US-THYROID", name: "Thyroid / Neck Ultrasound", modality: "Ultrasound", price: 40000 },
  { id: "rad-scr-us", code: "RAD-US-SCROTAL", name: "Scrotal Ultrasound", modality: "Ultrasound", price: 35000 },
];

export const STANDARD_DRUG_CATALOG: MedicationCatalogItem[] = [
  { id: "01a005c5-d22b-732d-83b9-3fd1f39dc163", code: "MED-PARA-500TAB", name: "Paracetamol 500 mg tablet", genericName: "Paracetamol", strength: "500 mg", form: "Tablet", defaultRoute: "Oral", price: 100 },
  { id: "01a005c5-d34a-73a4-a4d5-9dc115df4702", code: "MED-ALBEN-200TAB", name: "Albendazole 400 mg tablet", genericName: "Albendazole", strength: "400 mg", form: "Tablet", defaultRoute: "Oral", price: 500 },
  { id: "01a005c5-d24e-707c-a257-99f541da9d26", code: "MED-ACECL-100TAB", name: "Aceclofenac 100 mg tablet", genericName: "Aceclofenac", strength: "100 mg", form: "Tablet", defaultRoute: "Oral", price: 300 },
  { id: "01a005c5-d343-73f0-8167-ca6655296a73", code: "MED-ACICV-200TAB", name: "Aciclovir 200 mg tablet", genericName: "Aciclovir", strength: "200 mg", form: "Tablet", defaultRoute: "Oral", price: 300 },
  { id: "01a005c5-d383-73ae-9067-f67a3cb1d9ab", code: "MED-ADREN-1ML", name: "Adrenaline 1 mg/ml injection 1 ml", genericName: "Adrenaline", strength: "1 mg", form: "Injection", defaultRoute: "IV / IM", price: 2500 },
];

export function useClinicianOrders() {
  const { t } = useI18n({ useScope: "global" });
  const toast = useToast();

  const isPlacingOrder = ref(false);
  const activeOrders = ref<PlacedClinicalOrder[]>([]);

  // Live medications catalog from the workspace API
  const medicationCatalog = ref<MedicationCatalogItem[]>([]);
  const isSearchingMedications = ref(false);

  // Prescriptions state for the active consultation
  const prescriptionDrafts = ref<ActivePrescriptionOrder[]>([]);

  async function searchMedicationCatalog(query = ""): Promise<MedicationCatalogItem[]> {
    isSearchingMedications.value = true;
    try {
      const params = new URLSearchParams();
      params.set("status", "active");
      params.set("perPage", "300");
      params.set("sortBy", "name");
      params.set("sortDir", "asc");
      if (query.trim()) {
        params.set("q", query.trim());
      }

      const res = await fetch(`/api/v1/clinician/catalog/medications?${params.toString()}`, {
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!res.ok) {
        return [];
      }

      const json = await res.json();
      const items: MedicationCatalogItem[] = (json.data || []).map((item: any) => ({
        id: item.id,
        code: item.code,
        name: item.name,
        genericName: item.genericName || undefined,
        strength: item.strength || undefined,
        form: item.dosageForm || undefined,
        defaultRoute: item.route || "Oral",
        price: item.billingLink?.item?.basePrice ?? 0,
      }));

      medicationCatalog.value = items;
      return items;
    } catch {
      return [];
    } finally {
      isSearchingMedications.value = false;
    }
  }

  function getFrequencyMultiplier(frequency: string): number {
    switch (frequency?.toUpperCase()) {
      case "OD": return 1;
      case "BID": return 2;
      case "TID": return 3;
      case "QID": return 4;
      case "PRN": return 1;
      case "STAT": return 1;
      default: return 3;
    }
  }

  function addPrescriptionItem(drug: MedicationCatalogItem) {
    prescriptionDrafts.value.push({
      catalogItemId: drug.id,
      drugCode: drug.code,
      drugName: drug.name,
      dosage: drug.strength || "",
      route: drug.defaultRoute || "Oral",
      frequency: "TID",
      durationDays: null,
      quantityPrescribed: null,
      instructions: "",
      unitPrice: drug.price,
    });
  }

  function removePrescriptionItem(index: number) {
    prescriptionDrafts.value.splice(index, 1);
  }

  async function submitLabOrder(
    encounterId: string,
    patientId: string,
    test: LabCatalogItem,
    priority: "routine" | "urgent" | "stat" = "routine",
    indication = ""
  ): Promise<boolean> {
    isPlacingOrder.value = true;
    try {
      const payload = {
        encounterId,
        patientId,
        testCode: test.code,
        testName: test.name,
        specimenType: test.sampleType,
        priority,
        clinicalNotes: indication,
      };

      const res = await fetch("/api/v1/clinician/orders/lab", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify(payload),
      });

      if (!res.ok) {
        throw new Error("Failed to place laboratory order");
      }

      const created = (await res.json())?.data;
      activeOrders.value.unshift({
        id: created?.id || `lab-${Date.now()}`,
        type: "lab",
        name: created?.testName || test.name,
        priority,
        status: (created?.status?.toLowerCase() as any) || "pending",
        createdAt: created?.orderedAt || created?.createdAt || new Date().toISOString(),
        details: indication || test.sampleType,
        price: test.price,
      });

      toast.success(t("clinician.order_placed_lab", "Laboratory order placed successfully"));
      return true;
    } catch (err: any) {
      toast.error(err.message || "Failed to submit lab order");
      return false;
    } finally {
      isPlacingOrder.value = false;
    }
  }

  async function submitRadiologyOrder(
    encounterId: string,
    patientId: string,
    exam: RadiologyCatalogItem,
    priority: "routine" | "urgent" | "stat" = "routine",
    indication = ""
  ): Promise<boolean> {
    isPlacingOrder.value = true;
    try {
      const modalityMap: Record<string, string> = {
        ultrasound: "ultrasound",
        "x-ray": "xray",
        xray: "xray",
        ct: "ct",
        mri: "mri",
      };
      const normalizedModality = modalityMap[exam.modality?.toLowerCase()] || "other";

      const payload = {
        encounterId,
        patientId,
        procedureCode: exam.code,
        studyDescription: exam.name,
        modality: normalizedModality,
        clinicalIndication: indication,
      };

      const res = await fetch("/api/v1/clinician/orders/imaging", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify(payload),
      });

      if (!res.ok) {
        throw new Error("Failed to place radiology order");
      }

      const created = (await res.json())?.data;
      activeOrders.value.unshift({
        id: created?.id || `rad-${Date.now()}`,
        type: "imaging",
        name: created?.studyDescription || exam.name,
        priority,
        status: (created?.status?.toLowerCase() as any) || "pending",
        createdAt: created?.orderedAt || created?.createdAt || new Date().toISOString(),
        details: indication || exam.modality,
        price: exam.price,
      });

      toast.success(t("clinician.order_placed_imaging", "Radiology order placed successfully"));
      return true;
    } catch (err: any) {
      toast.error(err.message || "Failed to submit radiology order");
      return false;
    } finally {
      isPlacingOrder.value = false;
    }
  }

  async function submitPrescriptions(encounterId: string, patientId: string): Promise<boolean> {
    if (prescriptionDrafts.value.length === 0) return true;
    isPlacingOrder.value = true;
    try {
      const createdOrders = await Promise.all(
        prescriptionDrafts.value.map(async (item) => {
          const qty = item.quantityPrescribed && item.quantityPrescribed > 0
            ? item.quantityPrescribed
            : Math.max(1, getFrequencyMultiplier(item.frequency) * (item.durationDays || 5));
          const instruction = `${item.dosage} ${item.route} ${item.frequency} for ${item.durationDays} days${item.instructions ? `. ${item.instructions}` : ""}`;

          const payload: Record<string, any> = {
            patientId,
            encounterId,
            dosageInstruction: instruction,
            quantityPrescribed: qty,
            entryMode: "draft",
            route: item.route ? item.route.toLowerCase() : undefined,
            frequency: item.frequency ? item.frequency.toLowerCase() : undefined,
            clinicalIndication: item.instructions || undefined,
          };

          if (item.catalogItemId) {
            payload.approvedMedicineCatalogItemId = item.catalogItemId;
          } else if (item.drugCode) {
            payload.medicationCode = item.drugCode;
          }

          const res = await fetch("/api/v1/clinician/orders/medication", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "Accept": "application/json",
              "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify(payload),
          });

          if (!res.ok) {
            const errData = await res.json().catch(() => ({}));
            const detailMsg = errData.message || (errData.errors ? Object.values(errData.errors).flat().join(", ") : "");
            throw new Error(detailMsg || `Failed to submit ${item.drugName}`);
          }

          const created = (await res.json())?.data;
          return {
            id: created?.id || `med-${Date.now()}-${Math.random().toString(36).substring(2, 5)}`,
            type: "medication" as const,
            name: created?.medicationName || item.drugName,
            priority: "routine" as const,
            status: (created?.status?.toLowerCase() as any) || "pending",
            createdAt: created?.orderedAt || created?.createdAt || new Date().toISOString(),
            details: `${item.dosage} · ${item.route} · ${item.frequency} for ${item.durationDays} days · Qty: ${qty}`,
            price: (item.unitPrice || 0) * qty,
          };
        })
      );

      createdOrders.forEach((order) => {
        activeOrders.value.unshift(order);
      });

      toast.success(t("clinician.order_placed_success", "Prescriptions submitted to Pharmacy successfully"));
      prescriptionDrafts.value = [];
      return true;
    } catch (err: any) {
      toast.error(err.message || "Failed to submit prescriptions");
      return false;
    } finally {
      isPlacingOrder.value = false;
    }
  }

  async function cancelOrder(order: PlacedClinicalOrder, reason = "Ordered in error"): Promise<boolean> {
    try {
      const typeEndpointMap: Record<string, string> = {
        lab: `/api/v1/clinician/orders/lab/${order.id}/cancel`,
        imaging: `/api/v1/clinician/orders/imaging/${order.id}/cancel`,
        medication: `/api/v1/clinician/orders/medication/${order.id}/cancel`,
      };

      const endpoint = typeEndpointMap[order.type];
      if (!endpoint) {
        throw new Error("Cannot cancel this order type");
      }

      const res = await fetch(endpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          action: "cancel",
          reason: reason || "Cancelled by clinician",
        }),
      });

      if (!res.ok) {
        const errData = await res.json().catch(() => ({}));
        const detailMsg = errData.message || (errData.errors ? Object.values(errData.errors).flat().join(", ") : "");
        throw new Error(detailMsg || "Failed to cancel order");
      }

      // Update local state
      const target = activeOrders.value.find((o) => o.id === order.id);
      if (target) {
        target.status = "cancelled";
      }

      toast.success(t("clinician.order_cancelled_success", "Order has been cancelled successfully"));
      return true;
    } catch (err: any) {
      toast.error(err.message || "Failed to cancel order");
      return false;
    }
  }

  function hydrateOrdersFromWorkspace(workspace: any) {
    if (!workspace) {
      activeOrders.value = [];
      return;
    }

    const orders: PlacedClinicalOrder[] = [];

    // 1. Lab Orders
    if (Array.isArray(workspace.laboratoryOrders)) {
      workspace.laboratoryOrders.forEach((lab: any) => {
        orders.push({
          id: lab.id || `lab-${lab.orderNumber || Date.now()}`,
          type: "lab",
          name: lab.testName || lab.testCode || "Lab Test",
          priority: (lab.priority?.toLowerCase() as any) || "routine",
          status: (lab.status?.toLowerCase() as any) || "pending",
          createdAt: lab.orderedAt || lab.createdAt || new Date().toISOString(),
          details: lab.clinicalNotes || lab.specimenType || undefined,
          price: lab.price || lab.basePrice,
        });
      });
    }

    // 2. Radiology Orders
    if (Array.isArray(workspace.radiologyOrders)) {
      workspace.radiologyOrders.forEach((rad: any) => {
        orders.push({
          id: rad.id || `rad-${rad.orderNumber || Date.now()}`,
          type: "imaging",
          name: rad.studyDescription || rad.procedureCode || "Radiology Exam",
          priority: (rad.priority?.toLowerCase() as any) || "routine",
          status: (rad.status?.toLowerCase() as any) || "pending",
          createdAt: rad.orderedAt || rad.createdAt || new Date().toISOString(),
          details: rad.clinicalIndication || rad.modality || undefined,
          price: rad.price || rad.basePrice,
        });
      });
    }

    // 3. Pharmacy Orders
    if (Array.isArray(workspace.pharmacyOrders)) {
      workspace.pharmacyOrders.forEach((med: any) => {
        orders.push({
          id: med.id || `med-${med.orderNumber || Date.now()}`,
          type: "medication",
          name: med.medicationName || med.medicationCode || "Medication",
          priority: "routine",
          status: (med.status?.toLowerCase() as any) || "pending",
          createdAt: med.orderedAt || med.createdAt || new Date().toISOString(),
          details: med.dosageInstruction || `${med.doseQuantity ?? ""} ${med.route ?? ""} ${med.frequency ?? ""}`.trim() || undefined,
          price: med.price || med.totalPrice,
        });
      });
    }

    // Sort by createdAt descending
    orders.sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime());
    activeOrders.value = orders;
  }

  function clearOrders() {
    activeOrders.value = [];
    prescriptionDrafts.value = [];
  }

  return {
    isPlacingOrder,
    activeOrders,
    medicationCatalog,
    isSearchingMedications,
    prescriptionDrafts,
    getFrequencyMultiplier,
    searchMedicationCatalog,
    addPrescriptionItem,
    removePrescriptionItem,
    submitLabOrder,
    submitRadiologyOrder,
    submitPrescriptions,
    cancelOrder,
    hydrateOrdersFromWorkspace,
    clearOrders,
  };
}
