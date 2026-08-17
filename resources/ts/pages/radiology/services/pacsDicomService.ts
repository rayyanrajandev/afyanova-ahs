/**
 * pacsDicomService.ts — DICOM PACS & Medical Modality Ingestion Service
 * =====================================================================
 * Handles:
 * - Modality Equipment AE Titles (Ultrasound GE Logiq, Philips DR X-Ray, Siemens SOMATOM CT)
 * - DICOM Modality Worklist (MWL) and C-STORE simulation
 * - Diagnostic Medical Image Generator for clinical examination views
 * - Client-side file ingestion & DICOM parsing
 */

export interface DicomImageInstance {
  id: string;
  sopInstanceUid: string;
  seriesDescription: string;
  modality: string;
  instanceNumber: number;
  imageUrl: string;
  thumbnailUrl?: string;
  isKeyImage?: boolean;
  acquisitionDateTime: string;
  windowCenter: number;
  windowWidth: number;
  kvp?: number;
  mas?: number;
  sliceThickness?: string;
  matrixSize: string;
  notes?: string;
}

export interface PacsModalityNode {
  id: string;
  name: string;
  aeTitle: string;
  ipAddress: string;
  port: number;
  modality: string;
  status: "online" | "acquiring" | "offline";
  lastPing: string;
  location: string;
}

export const MODALITY_NODES: PacsModalityNode[] = [
  {
    id: "us-01",
    name: "GE Logiq E10 Ultrasound (Suite 1)",
    aeTitle: "GE_LOGIQ_US1",
    ipAddress: "192.168.1.140",
    port: 104,
    modality: "US",
    status: "online",
    lastPing: "Just now",
    location: "Ultrasound Room 1",
  },
  {
    id: "xr-01",
    name: "Philips Digital Diagnost X-Ray (Room 2)",
    aeTitle: "PHILIPS_DR_XR2",
    ipAddress: "192.168.1.142",
    port: 104,
    modality: "XR",
    status: "online",
    lastPing: "Just now",
    location: "X-Ray Suite A",
  },
  {
    id: "ct-01",
    name: "Siemens SOMATOM go.Top 128 (CT)",
    aeTitle: "SIEMENS_CT_01",
    ipAddress: "192.168.1.145",
    port: 11112,
    modality: "CT",
    status: "online",
    lastPing: "1m ago",
    location: "CT Scanner Suite",
  },
];

/**
 * Creates SVG Data URIs representing high-contrast realistic medical imaging captures.
 */
function createMedicalSvgDataUri(
  type: "ultrasound_abdomen" | "ultrasound_obstetric" | "xray_chest" | "xray_pelvis" | "ct_abdomen",
  viewLabel: string,
  measurements?: string,
): string {
  let innerArt = "";

  if (type === "xray_chest") {
    innerArt = `
      <!-- Ribs & Thoracic Cage -->
      <path d="M120,90 Q200,60 280,90 Q200,120 120,90" fill="none" stroke="#64748b" stroke-width="3" opacity="0.6"/>
      <path d="M110,130 Q200,95 290,130 Q200,165 110,130" fill="none" stroke="#64748b" stroke-width="4" opacity="0.7"/>
      <path d="M105,175 Q200,135 295,175 Q200,215 105,175" fill="none" stroke="#64748b" stroke-width="4" opacity="0.7"/>
      <path d="M110,220 Q200,180 290,220 Q200,260 110,220" fill="none" stroke="#64748b" stroke-width="4" opacity="0.7"/>

      <!-- Lung Fields (Dark/Lucent) -->
      <path d="M125,90 C110,140 110,240 145,280 C165,285 180,260 185,210 C185,140 175,95 140,85 Z" fill="#0f172a" stroke="#475569" stroke-width="2"/>
      <path d="M275,90 C290,140 290,240 255,280 C235,285 220,260 215,210 C215,140 225,95 260,85 Z" fill="#0f172a" stroke="#475569" stroke-width="2"/>

      <!-- Bronchovascular Markings -->
      <path d="M140,150 Q160,180 145,220" stroke="#94a3b8" stroke-width="1.5" opacity="0.6"/>
      <path d="M260,150 Q240,180 255,220" stroke="#94a3b8" stroke-width="1.5" opacity="0.6"/>

      <!-- Cardiac Silhouette (Radiopaque white) -->
      <path d="M175,160 C160,210 170,270 235,275 C245,270 240,220 215,160 Z" fill="#cbd5e1" opacity="0.85"/>

      <!-- Diaphragm & Costophrenic Angles -->
      <path d="M100,290 Q150,265 200,285" fill="none" stroke="#e2e8f0" stroke-width="3"/>
      <path d="M200,285 Q250,265 300,290" fill="none" stroke="#e2e8f0" stroke-width="3"/>
      <line x1="200" y1="50" x2="200" y2="340" stroke="#94a3b8" stroke-dasharray="2,4" stroke-width="1" opacity="0.4"/>
    `;
  } else if (type === "ultrasound_abdomen") {
    innerArt = `
      <!-- Ultrasound Sector Cone -->
      <path d="M200,40 L90,320 A180,180 0 0,0 310,320 Z" fill="#090d16" stroke="#334155" stroke-width="2"/>

      <!-- Speckle Echotexture / Parenchyma -->
      <circle cx="180" cy="160" r="45" fill="#1e293b" opacity="0.7"/>
      <circle cx="230" cy="190" r="35" fill="#1e293b" opacity="0.6"/>

      <!-- Gallbladder (Anechoic Black Lumen + Acoustic Enhancement) -->
      <ellipse cx="195" cy="180" rx="28" ry="18" fill="#020617" stroke="#94a3b8" stroke-width="2"/>
      <path d="M175,200 L215,200 L230,290 L160,290 Z" fill="#475569" opacity="0.25"/>

      <!-- Portal Vein / Vessel Callout -->
      <path d="M160,140 Q190,150 220,135" fill="none" stroke="#38bdf8" stroke-width="3"/>
      <circle cx="160" cy="140" r="5" fill="#ef4444"/>

      <!-- Depth Scale Marks -->
      <g fill="#64748b" font-size="8" font-family="monospace">
        <text x="320" y="100">- 5cm</text>
        <text x="320" y="180">- 10cm</text>
        <text x="320" y="260">- 15cm</text>
      </g>
    `;
  } else if (type === "ultrasound_obstetric") {
    innerArt = `
      <!-- Ultrasound Sector Cone -->
      <path d="M200,40 L90,320 A180,180 0 0,0 310,320 Z" fill="#090d16" stroke="#334155" stroke-width="2"/>

      <!-- Gestational Sac (Anechoic) -->
      <ellipse cx="200" cy="190" rx="65" ry="55" fill="#020617" stroke="#64748b" stroke-width="2"/>

      <!-- Fetal Head Profile (BPD Calipers) -->
      <circle cx="180" cy="165" r="26" fill="#1e293b" stroke="#cbd5e1" stroke-width="2"/>
      <!-- Fetal Body Spine -->
      <path d="M195,180 Q235,205 210,230" fill="none" stroke="#e2e8f0" stroke-width="3" stroke-linecap="round"/>

      <!-- Caliper Measurement Crosshairs -->
      <g stroke="#38bdf8" stroke-width="1.5">
        <line x1="150" y1="165" x2="160" y2="165"/>
        <line x1="155" y1="160" x2="155" y2="170"/>
        <line x1="200" y1="165" x2="210" y2="165"/>
        <line x1="205" y1="160" x2="205" y2="170"/>
        <line x1="155" y1="165" x2="205" y2="165" stroke-dasharray="2,2"/>
      </g>
      <text x="160" y="155" fill="#38bdf8" font-size="9" font-family="monospace" font-weight="bold">BPD: 52.4mm</text>
    `;
  } else {
    // Default X-Ray Pelvis / Ortho / General
    innerArt = `
      <rect x="80" y="60" width="240" height="260" rx="8" fill="#0f172a" stroke="#334155" stroke-width="2"/>
      <!-- Pelvic Brim & Femoral Heads -->
      <path d="M120,120 Q200,160 280,120" fill="none" stroke="#cbd5e1" stroke-width="5"/>
      <path d="M140,160 Q200,210 260,160" fill="none" stroke="#cbd5e1" stroke-width="6"/>
      <circle cx="130" cy="220" r="22" fill="#94a3b8" stroke="#f8fafc" stroke-width="3"/>
      <circle cx="270" cy="220" r="22" fill="#94a3b8" stroke="#f8fafc" stroke-width="3"/>
      <path d="M130,242 L120,310" stroke="#cbd5e1" stroke-width="10" stroke-linecap="round"/>
      <path d="M270,242 L280,310" stroke="#cbd5e1" stroke-width="10" stroke-linecap="round"/>
    `;
  }

  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 380" width="100%" height="100%">
      <rect width="400" height="380" fill="#030712"/>
      ${innerArt}

      <!-- Clinical HUD Overlays -->
      <text x="15" y="24" fill="#38bdf8" font-size="10" font-family="monospace" font-weight="bold">${viewLabel.toUpperCase()}</text>
      <text x="15" y="38" fill="#94a3b8" font-size="8.5" font-family="monospace">MI 1.1 · TIS 0.4 · 5.0 MHz</text>
      <text x="15" y="365" fill="#64748b" font-size="8" font-family="monospace">FPS: 32 · GAIN: 64dB · DR: 75</text>
      <text x="310" y="24" fill="#cbd5e1" font-size="9" font-family="monospace" text-anchor="end">AFYANOVA PACS</text>
      <text x="385" y="365" fill="#e2e8f0" font-size="10" font-family="monospace" font-weight="bold" text-anchor="end">R</text>
    </svg>
  `;

  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

/**
 * Generates sample diagnostic imaging series matching a procedure.
 */
export function generateDefaultDicomSeries(modality: string, studyDescription: string): DicomImageInstance[] {
  const mod = modality?.toLowerCase() || "";
  const desc = studyDescription?.toLowerCase() || "";
  const now = new Date().toISOString();

  if (mod.includes("us") || mod.includes("ultrasound")) {
    if (desc.includes("obstetric") || desc.includes("pregnancy") || desc.includes("pelvic")) {
      return [
        {
          id: "inst-us-01",
          sopInstanceUid: "1.2.840.10008.5.1.4.1.1.6.1.20260817001",
          seriesDescription: "Sagittal Intrauterine Gestation",
          modality: "US",
          instanceNumber: 1,
          imageUrl: createMedicalSvgDataUri("ultrasound_obstetric", "Sagittal Uterus Viability"),
          isKeyImage: true,
          acquisitionDateTime: now,
          windowCenter: 128,
          windowWidth: 256,
          matrixSize: "800x600",
          notes: "Single viable fetus. Normal amniotic liquor volume.",
        },
        {
          id: "inst-us-02",
          sopInstanceUid: "1.2.840.10008.5.1.4.1.1.6.1.20260817002",
          seriesDescription: "Biparietal Diameter (BPD) Biometry",
          modality: "US",
          instanceNumber: 2,
          imageUrl: createMedicalSvgDataUri("ultrasound_obstetric", "BPD Caliper Measurement", "BPD: 52.4mm"),
          isKeyImage: true,
          acquisitionDateTime: now,
          windowCenter: 128,
          windowWidth: 256,
          matrixSize: "800x600",
          notes: "BPD calipers placed outer-to-inner. Gestational age matches LMP.",
        },
        {
          id: "inst-us-03",
          sopInstanceUid: "1.2.840.10008.5.1.4.1.1.6.1.20260817003",
          seriesDescription: "Fundal Placental Localization",
          modality: "US",
          instanceNumber: 3,
          imageUrl: createMedicalSvgDataUri("ultrasound_abdomen", "Placental Site Grade I"),
          isKeyImage: false,
          acquisitionDateTime: now,
          windowCenter: 128,
          windowWidth: 256,
          matrixSize: "800x600",
        },
      ];
    }

    // Default Abdominal Ultrasound
    return [
      {
        id: "inst-us-01",
        sopInstanceUid: "1.2.840.10008.5.1.4.1.1.6.1.20260817101",
        seriesDescription: "Liver Parenchyma & Right Kidney",
        modality: "US",
        instanceNumber: 1,
        imageUrl: createMedicalSvgDataUri("ultrasound_abdomen", "Hepatorenal Interface"),
        isKeyImage: true,
        acquisitionDateTime: now,
        windowCenter: 128,
        windowWidth: 256,
        matrixSize: "800x600",
        notes: "Liver echotexture normal. Corticomedullary differentiation intact.",
      },
      {
        id: "inst-us-02",
        sopInstanceUid: "1.2.840.10008.5.1.4.1.1.6.1.20260817102",
        seriesDescription: "Gallbladder Longitudinal & CBD",
        modality: "US",
        instanceNumber: 2,
        imageUrl: createMedicalSvgDataUri("ultrasound_abdomen", "Gallbladder Long View"),
        isKeyImage: true,
        acquisitionDateTime: now,
        windowCenter: 128,
        windowWidth: 256,
        matrixSize: "800x600",
        notes: "Gallbladder wall < 3mm. No intraluminal calculi.",
      },
      {
        id: "inst-us-03",
        sopInstanceUid: "1.2.840.10008.5.1.4.1.1.6.1.20260817103",
        seriesDescription: "Spleen & Left Kidney Long View",
        modality: "US",
        instanceNumber: 3,
        imageUrl: createMedicalSvgDataUri("ultrasound_abdomen", "Splenorenal View"),
        isKeyImage: false,
        acquisitionDateTime: now,
        windowCenter: 128,
        windowWidth: 256,
        matrixSize: "800x600",
      },
    ];
  }

  // Default X-Ray Series (Chest or Plain Radiograph)
  return [
    {
      id: "inst-xr-01",
      sopInstanceUid: "1.2.840.10008.5.1.4.1.1.1.20260817201",
      seriesDescription: "Chest PA Erect Projection",
      modality: "DX",
      instanceNumber: 1,
      imageUrl: createMedicalSvgDataUri("xray_chest", "Chest Radiograph PA View"),
      isKeyImage: true,
      acquisitionDateTime: now,
      windowCenter: 2048,
      windowWidth: 4096,
      kvp: 120,
      mas: 4.5,
      matrixSize: "2048x2048",
      notes: "Erect PA inspiratory view. Lung parenchyma clear without active consolidations.",
    },
    {
      id: "inst-xr-02",
      sopInstanceUid: "1.2.840.10008.5.1.4.1.1.1.20260817202",
      seriesDescription: "Chest Lateral View",
      modality: "DX",
      instanceNumber: 2,
      imageUrl: createMedicalSvgDataUri("xray_chest", "Chest Lateral View"),
      isKeyImage: false,
      acquisitionDateTime: now,
      windowCenter: 2048,
      windowWidth: 4096,
      kvp: 125,
      mas: 8.0,
      matrixSize: "2048x2048",
    },
  ];
}
