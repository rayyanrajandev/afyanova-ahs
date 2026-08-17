<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskFormularyClinicalCatalogSeeder extends Seeder
{
    /** @var array<string, array<string, mixed>> Overrides keyed by item code. */
    private array $overrides = [];

    /** Codes that are controlled substances. */
    private array $controlledCodes = [
        'MED-TRAM-50CAP', 'MED-TRAM-2IV',
        'MED-DIAZ-5TAB', 'MED-DIAZ-10IV',
        'MED-PREG-75CAP',
    ];

    /** Codes requiring cold chain. */
    private array $coldChainCodes = [
        'MED-TETAN-05IM',
    ];

    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $deptId = DepartmentModel::where('facility_id', $facility->id)->where('code', 'PHA')->value('id');

        if (!$deptId) {
            $this->command?->error('PHA department not found for DSK. Run DskDepartmentsSeeder first.');
            return;
        }

        $this->buildOverrides();

        $items = $this->items();

        foreach ($items as $item) {
            $derived = $this->deriveFields($item);

            // Top-level clinical descriptor columns
            $createData = [
                'tenant_id' => $facility->tenant_id,
                'name' => $item['name'],
                'department_id' => $deptId,
                'category' => $item['category'],
                'unit' => $item['unit'],
                'description' => $item['description'],
                'status' => 'active',
                'generic_name' => $derived['generic_name'],
                'dosage_form' => $derived['dosage_form'],
                'strength' => $derived['strength'],
                'route' => $derived['route'],
                'container_volume_value' => $derived['container_volume_value'],
                'container_volume_unit' => $derived['container_volume_unit'],
                'storage_conditions' => $derived['storage_conditions'],
                'requires_cold_chain' => $derived['requires_cold_chain'],
                'is_controlled_substance' => $derived['is_controlled_substance'],
                'controlled_substance_schedule' => $derived['controlled_substance_schedule'],
                'generic_group_code' => $derived['generic_group_code'],
                'metadata' => $derived['metadata'],
            ];

            ClinicalCatalogItemModel::updateOrCreate(
                [
                    'facility_id' => $facility->id,
                    'catalog_type' => 'formulary_item',
                    'code' => $item['code'],
                ],
                $createData,
            );
        }

        $this->command?->info('Seeded ' . count($items) . ' formulary catalog items for DSK Dispensary.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(): array
    {
        return [
            // ── Analgesics & Antipyretics ──
            ['code' => 'MED-PARA-500TAB', 'name' => 'Paracetamol 500 mg tablet', 'category' => 'analgesics_antipyretics', 'unit' => 'tablet', 'description' => 'Analgesic and antipyretic for pain and fever.'],
            ['code' => 'MED-PARA-100SYR', 'name' => 'Paracetamol 120 mg/5 ml syrup 100 ml', 'category' => 'analgesics_antipyretics', 'unit' => 'bottle', 'description' => 'Analgesic and antipyretic syrup for pain and fever in children.'],
            ['code' => 'MED-PARA-IV100', 'name' => 'Paracetamol 1 g/100 ml IV infusion', 'category' => 'analgesics_antipyretics', 'unit' => 'each', 'description' => 'IV paracetamol for pain and fever when oral route is not suitable.'],
            ['code' => 'MED-PARA-SUP125', 'name' => 'Paracetamol suppository 125 mg', 'category' => 'analgesics_antipyretics', 'unit' => 'each', 'description' => 'Rectal paracetamol for pain and fever when oral route is not suitable.'],
            ['code' => 'MED-IBUP-200TAB', 'name' => 'Ibuprofen 200 mg tablet', 'category' => 'anti_inflammatory', 'unit' => 'tablet', 'description' => 'NSAID tablet for pain, fever, and inflammation.'],
            ['code' => 'MED-IBUP-100SYR', 'name' => 'Ibuprofen 100 mg/5 ml syrup 100 ml', 'category' => 'anti_inflammatory', 'unit' => 'bottle', 'description' => 'NSAID syrup for pain, fever, and inflammation in children and adults.'],
            ['code' => 'MED-DICLO-3IM', 'name' => 'Diclofenac sodium 75 mg/3 ml injection', 'category' => 'anti_inflammatory', 'unit' => 'ampoule', 'description' => 'NSAID injection for acute pain, renal colic, and musculoskeletal conditions.'],
            ['code' => 'MED-DICLO-20GEL', 'name' => 'Diclofenac 1% gel 20 g', 'category' => 'anti_inflammatory', 'unit' => 'tube', 'description' => 'Topical NSAID gel for local pain, inflammation, and joint conditions.'],
            ['code' => 'MED-ACECL-100TAB', 'name' => 'Aceclofenac 100 mg tablet', 'category' => 'anti_inflammatory', 'unit' => 'tablet', 'description' => 'NSAID tablet for pain, inflammation, and musculoskeletal conditions.'],
            ['code' => 'MED-PIROX-20CAP', 'name' => 'Piroxicam 20 mg capsule', 'category' => 'anti_inflammatory', 'unit' => 'capsule', 'description' => 'Non-steroidal anti-inflammatory drug (NSAID) for pain and inflammation.'],
            ['code' => 'MED-MELOX-15TAB', 'name' => 'Meloxicam 15 mg tablet', 'category' => 'anti_inflammatory', 'unit' => 'tablet', 'description' => 'COX-2 preferential NSAID for osteoarthritis, rheumatoid arthritis, and pain.'],
            ['code' => 'MED-DUOCO-360TAB', 'name' => 'Duo-Cotex 360 mg tablet', 'category' => 'anti_inflammatory', 'unit' => 'tablet', 'description' => 'Combined analgesic tablet for pain and inflammation.'],
            ['code' => 'MED-TERMID-100SYR', 'name' => 'Termidol (Ibuprofen + Paracetamol) syrup 100 ml', 'category' => 'anti_inflammatory', 'unit' => 'bottle', 'description' => 'Combined NSAID and analgesic syrup for pain and fever.'],
            ['code' => 'MED-TRAM-50CAP', 'name' => 'Tramadol 50 mg capsule', 'category' => 'analgesics_antipyretics', 'unit' => 'capsule', 'description' => 'Opioid analgesic for moderate to severe pain management.'],
            ['code' => 'MED-TRAM-2IV', 'name' => 'Tramadol hydrochloride 100 mg/2 ml injection', 'category' => 'analgesics_antipyretics', 'unit' => 'ampoule', 'description' => 'Opioid analgesic injection for moderate to severe acute pain.'],

            // ── Antibiotics ──
            ['code' => 'MED-AMOX-250CAP', 'name' => 'Amoxicillin 250 mg capsule', 'category' => 'antibiotics', 'unit' => 'capsule', 'description' => 'Broad-spectrum penicillin antibiotic for respiratory, urinary, and ENT infections.'],
            ['code' => 'MED-AMOX-100SYR', 'name' => 'Amoxicillin 250 mg/5 ml syrup 100 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Broad-spectrum penicillin syrup for childhood respiratory and ENT infections.'],
            ['code' => 'MED-AMOCL-625TAB', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) 625 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Beta-lactamase inhibitor combination for resistant bacterial infections.'],
            ['code' => 'MED-AMOCL-375TAB', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) 375 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Beta-lactamase inhibitor combination for respiratory and ENT infections.'],
            ['code' => 'MED-AMOCL-100SYR', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) syrup 100 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Beta-lactamase inhibitor combination syrup for upper respiratory and ear infections.'],
            ['code' => 'MED-AMOCL-12IV', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) 1.2 g injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Beta-lactamase inhibitor combination for severe infections unresponsive to amoxicillin alone.'],
            ['code' => 'MED-AMPCLOX-500CAP', 'name' => 'Ampicillin + Cloxacillin 500 mg capsule', 'category' => 'antibiotics', 'unit' => 'capsule', 'description' => 'Combined penicillin antibiotic for skin, soft tissue, and respiratory infections.'],
            ['code' => 'MED-AMPCLX-100SYR', 'name' => 'Ampicillin + Cloxacillin (Ampiclox) syrup 100 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Combined penicillin syrup for skin, respiratory, and urinary tract infections.'],
            ['code' => 'MED-AMPCLXN-06SYR', 'name' => 'Ampicillin + Cloxacillin neonatal syrup 60 mg/ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Combined penicillin syrup formulated for neonatal and infant infections.'],
            ['code' => 'MED-AMPCLOX-500IV', 'name' => 'Ampicillin + Cloxacillin 500 mg injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Combined penicillin injection for severe skin, soft tissue, and respiratory infections.'],
            ['code' => 'MED-AMPIC-250IV', 'name' => 'Ampicillin 250 mg injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Aminopenicillin antibiotic for respiratory, urinary, and meningococcal infections.'],
            ['code' => 'MED-CEPH-250CAP', 'name' => 'Cephalexin 250 mg capsule', 'category' => 'antibiotics', 'unit' => 'capsule', 'description' => 'First-generation cephalosporin for bacterial infections of skin, bone, and respiratory tract.'],
            ['code' => 'MED-CEPH-100SYR', 'name' => 'Cephalexin 250 mg/5 ml syrup 100 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'First-generation cephalosporin syrup for paediatric skin, bone, and respiratory infections.'],
            ['code' => 'MED-CEFAD-500CAP', 'name' => 'Cefadroxil 500 mg capsule', 'category' => 'antibiotics', 'unit' => 'capsule', 'description' => 'First-generation cephalosporin for skin, urinary tract, and respiratory infections.'],
            ['code' => 'MED-CEFIX-400TAB', 'name' => 'Cefixime 400 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Third-generation cephalosporin for urinary tract and respiratory infections.'],
            ['code' => 'MED-CEFTR-1IV', 'name' => 'Ceftriaxone 1 g injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Third-generation cephalosporin for meningitis, pneumonia, and septicaemia.'],
            ['code' => 'MED-CEFTRS-15IV', 'name' => 'Ceftriaxone + Sulbactam 1.5 g injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Third-generation cephalosporin with beta-lactamase inhibitor for resistant infections.'],
            ['code' => 'MED-CEFOT-12IV', 'name' => 'Cefotaxime 1.2 g injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Third-generation cephalosporin for meningitis, septicaemia, and severe bacterial infections.'],
            ['code' => 'MED-CIPRO-500TAB', 'name' => 'Ciprofloxacin 500 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Fluoroquinolone antibiotic for urinary tract, gastrointestinal, and respiratory infections.'],
            ['code' => 'MED-CIPRO-EYEDROP', 'name' => 'Ciprofloxacin 0.3% eye drops 10 ml', 'category' => 'antibiotics', 'unit' => 'each', 'description' => 'Fluoroquinolone antibiotic eye drops for bacterial conjunctivitis and eye infections.'],
            ['code' => 'MED-CIPRO-IV100', 'name' => 'Ciprofloxacin 200 mg/100 ml IV infusion', 'category' => 'antibiotics', 'unit' => 'each', 'description' => 'Fluoroquinolone antibiotic IV infusion for severe bacterial infections.'],
            ['code' => 'MED-CIPT-600TAB', 'name' => 'Ciprofloxacin + Tinidazole 600 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Combined fluoroquinolone and antiprotozoal for mixed aerobic-anaerobic infections.'],
            ['code' => 'MED-AZITH-500TAB', 'name' => 'Azithromycin 500 mg (Azuma) tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Macrolide antibiotic for respiratory, ENT, and sexually transmitted infections.'],
            ['code' => 'MED-AZITH-250TAB', 'name' => 'Azithromycin 250 mg (Azuma) tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Macrolide antibiotic for paediatric respiratory and ENT infections.'],
            ['code' => 'MED-AZITH-30SYR', 'name' => 'Azithromycin 200 mg/5 ml syrup 30 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Macrolide antibiotic syrup for respiratory, ENT, and sexually transmitted infections.'],
            ['code' => 'MED-CLARI-500TAB', 'name' => 'Clarithromycin 500 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Macrolide antibiotic for respiratory, skin, and H. pylori infections.'],
            ['code' => 'MED-ERYTH-250TAB', 'name' => 'Erythromycin stearate 250 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Macrolide antibiotic for respiratory, skin, and ENT infections in penicillin-allergic patients.'],
            ['code' => 'MED-ERYTH-100SYR', 'name' => 'Erythromycin stearate 250 mg/5 ml syrup 100 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Macrolide antibiotic syrup for respiratory, skin, and ENT infections in penicillin-allergic patients.'],
            ['code' => 'MED-DOXY-100CAP', 'name' => 'Doxycycline 100 mg capsule', 'category' => 'antibiotics', 'unit' => 'capsule', 'description' => 'Tetracycline antibiotic for malaria prophylaxis, respiratory, and sexually transmitted infections.'],
            ['code' => 'MED-COTRI-480TAB', 'name' => 'Co-trimoxazole 480 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Sulphonamide antibiotic for respiratory, urinary, and gastrointestinal infections.'],
            ['code' => 'MED-COTRI-100SYR', 'name' => 'Co-trimoxazole 240 mg/5 ml syrup 100 ml', 'category' => 'antibiotics', 'unit' => 'bottle', 'description' => 'Sulphonamide antibiotic syrup for respiratory, urinary, and gastrointestinal infections.'],
            ['code' => 'MED-GENT-40IM', 'name' => 'Gentamicin 40 mg/ml injection 2 ml', 'category' => 'antibiotics', 'unit' => 'ampoule', 'description' => 'Aminoglycoside antibiotic for serious gram-negative infections.'],
            ['code' => 'MED-PVPC-250TAB', 'name' => 'Phenoxymethylpenicillin 250 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Oral penicillin for streptococcal pharyngitis, tonsillitis, and rheumatic fever prophylaxis.'],
            ['code' => 'MED-PENAD-24IM', 'name' => 'Benzathine benzylpenicillin (Penadur) 2.4 MU injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Long-acting penicillin for syphilis, rheumatic fever prophylaxis, and streptococcal infections.'],
            ['code' => 'MED-BENZP-5MU', 'name' => 'Benzylpenicillin (Penicillin G) 5 MU injection', 'category' => 'antibiotics', 'unit' => 'vial', 'description' => 'Natural penicillin for syphilis, meningococcal infections, and gas gangrene.'],
            ['code' => 'MED-NITF-100TAB', 'name' => 'Nitrofurantoin 100 mg tablet', 'category' => 'antibiotics', 'unit' => 'tablet', 'description' => 'Urinary antiseptic for prevention and treatment of urinary tract infections.'],
            ['code' => 'MED-MUPI-10OINT', 'name' => 'Mupirocin 2% ointment 10 g', 'category' => 'antibiotics', 'unit' => 'tube', 'description' => 'Topical antibiotic ointment for impetigo and secondary skin infections.'],
            ['code' => 'MED-TETRA-15OINT', 'name' => 'Tetracycline 1% ointment 15 g', 'category' => 'antibiotics', 'unit' => 'tube', 'description' => 'Topical tetracycline antibiotic for superficial eye and skin infections.'],
            ['code' => 'MED-SILVEX-10CREAM', 'name' => 'Silver sulfadiazine + chlorhexidine (Silverex) 10 g', 'category' => 'antibiotics', 'unit' => 'tube', 'description' => 'Topical antimicrobial cream for prevention and treatment of burn wound infections.'],

            // ── Antimalarials ──
            ['code' => 'MED-ALUME-12TAB', 'name' => 'Artemether + Lumefantrine 20/120 mg (12 tablets)', 'category' => 'antimalarials', 'unit' => 'tablet', 'description' => 'ACT combination antimalarial for uncomplicated falciparum malaria.'],
            ['code' => 'MED-ALUME-24TAB', 'name' => 'Artemether + Lumefantrine 20/120 mg (24 tablets)', 'category' => 'antimalarials', 'unit' => 'tablet', 'description' => 'ACT combination antimalarial for uncomplicated falciparum malaria.'],
            ['code' => 'MED-ALUME-6TAB', 'name' => 'Artemether + Lumefantrine 80/480 mg (Lonart DS) (6 tablets)', 'category' => 'antimalarials', 'unit' => 'tablet', 'description' => 'ACT combination antimalarial for treatment of uncomplicated malaria.'],
            ['code' => 'MED-AL-22SYR', 'name' => 'Artemether + Lumefantrine (AL) 22.4 mg/ml syrup', 'category' => 'antimalarials', 'unit' => 'bottle', 'description' => 'ACT combination antimalarial syrup for uncomplicated falciparum malaria in children.'],
            ['code' => 'MED-LONART-24SYR', 'name' => 'Artemether + Lumefantrine (Lonart DS) 80 mg/480 mg syrup 24 ml', 'category' => 'antimalarials', 'unit' => 'bottle', 'description' => 'ACT combination antimalarial syrup for treatment of uncomplicated malaria.'],
            ['code' => 'MED-ARTE-80IM', 'name' => 'Artemether 80 mg/ml injection 1 ml', 'category' => 'antimalarials', 'unit' => 'ampoule', 'description' => 'Artemisinin derivative for treatment of severe falciparum malaria.'],
            ['code' => 'MED-ARTSN-60IV', 'name' => 'Artesunate 60 mg injection (IV/IM)', 'category' => 'antimalarials', 'unit' => 'vial', 'description' => 'Water-soluble artemisinin for treatment of severe and complicated malaria.'],
            ['code' => 'MED-ARTSN-120IV', 'name' => 'Artesunate 120 mg injection (IV/IM)', 'category' => 'antimalarials', 'unit' => 'vial', 'description' => 'Water-soluble artemisinin for treatment of severe and complicated malaria.'],
            ['code' => 'MED-MALAF-525TAB', 'name' => 'Malafin (Sulfamethoxypyrazine + Pyrimethamine) 525 mg tablet', 'category' => 'antimalarials', 'unit' => 'tablet', 'description' => 'Antimalarial combination for treatment of uncomplicated malaria.'],

            // ── Antifungals ──
            ['code' => 'MED-FLUC-150CAP', 'name' => 'Fluconazole 150 mg capsule', 'category' => 'antifungals', 'unit' => 'capsule', 'description' => 'Azole antifungal for vaginal candidiasis and systemic fungal infections.'],
            ['code' => 'MED-FLUC-IV100', 'name' => 'Fluconazole 200 mg/100 ml IV infusion', 'category' => 'antifungals', 'unit' => 'each', 'description' => 'Antifungal IV infusion for systemic candidiasis and cryptococcal meningitis.'],
            ['code' => 'MED-KETO-30CREAM', 'name' => 'Ketoconazole 2% cream 30 g', 'category' => 'antifungals', 'unit' => 'tube', 'description' => 'Azole antifungal cream for seborrhoeic dermatitis, tinea, and cutaneous candidiasis.'],
            ['code' => 'MED-CLTR-15CREAM', 'name' => 'Clotrimazole 1% cream 15 g', 'category' => 'antifungals', 'unit' => 'tube', 'description' => 'Azole antifungal cream for dermatophyte and Candida skin infections.'],
            ['code' => 'MED-CLTR-100PESS', 'name' => 'Clotrimazole 100 mg vaginal pessary', 'category' => 'antifungals', 'unit' => 'each', 'description' => 'Azole antifungal vaginal pessary for vulvovaginal candidiasis.'],
            ['code' => 'MED-MICG-400PESS', 'name' => 'Miconazole (Gynazol) nitrate 400 mg vaginal pessary', 'category' => 'antifungals', 'unit' => 'each', 'description' => 'Azole antifungal vaginal pessary for single-dose treatment of vulvovaginal candidiasis.'],
            ['code' => 'MED-GYNEX-PESS', 'name' => 'Miconazole + Metronidazole (Gynex) pessary', 'category' => 'antifungals', 'unit' => 'each', 'description' => 'Combined antifungal and antiprotozoal vaginal pessary for vaginitis and vaginal trichomoniasis.'],
            ['code' => 'MED-NYST-30SYR', 'name' => 'Nystatin oral suspension 100,000 IU/ml 30 ml', 'category' => 'antifungals', 'unit' => 'bottle', 'description' => 'Antifungal oral suspension for oral and oesophageal candidiasis.'],
            ['code' => 'MED-GRIS-500TAB', 'name' => 'Griseofulvin 500 mg tablet', 'category' => 'antifungals', 'unit' => 'tablet', 'description' => 'Antifungal tablet for dermatophyte infections of skin, hair, and nails.'],
            ['code' => 'MED-WHFL-20OINT', 'name' => "Whitfield's ointment 20 g", 'category' => 'antifungals', 'unit' => 'tube', 'description' => 'Salicylic acid and benzoic acid ointment for fungal skin infections (tinea).'],

            // ── Antivirals ──
            ['code' => 'MED-ACICV-200TAB', 'name' => 'Aciclovir 200 mg tablet', 'category' => 'antivirals', 'unit' => 'tablet', 'description' => 'Antiviral tablet for herpes simplex and varicella-zoster infections.'],
            ['code' => 'MED-ACICV-10CREAM', 'name' => 'Aciclovir 5% cream 10 g', 'category' => 'antivirals', 'unit' => 'tube', 'description' => 'Antiviral cream for treatment of herpes simplex and varicella-zoster skin infections.'],

            // ── Anthelmintics & Antiprotozoals ──
            ['code' => 'MED-ALBEN-200TAB', 'name' => 'Albendazole 400 mg tablet', 'category' => 'antihelminthics', 'unit' => 'tablet', 'description' => 'Broad-spectrum anthelmintic for intestinal worms, neurocysticercosis, and hydatid disease.'],
            ['code' => 'MED-ALBEN-10SYR', 'name' => 'Albendazole 400 mg/10 ml syrup', 'category' => 'antihelminthics', 'unit' => 'bottle', 'description' => 'Broad-spectrum anthelmintic syrup for intestinal worms and hydatid disease.'],
            ['code' => 'MED-MEBEN-100TAB', 'name' => 'Mebendazole 100 mg tablet', 'category' => 'antihelminthics', 'unit' => 'tablet', 'description' => 'Anthelmintic tablet for threadworm, roundworm, and whipworm infections.'],
            ['code' => 'MED-METRO-200TAB', 'name' => 'Metronidazole 200 mg tablet', 'category' => 'antiprotozoals', 'unit' => 'tablet', 'description' => 'Antiprotozoal and anaerobic antibacterial for giardiasis, amoebiasis, and dental infections.'],
            ['code' => 'MED-METRO-100SYR', 'name' => 'Metronidazole 200 mg/5 ml syrup 100 ml', 'category' => 'antiprotozoals', 'unit' => 'bottle', 'description' => 'Antiprotozoal and anaerobic antibacterial syrup for giardiasis, amoebiasis, and dental infections.'],
            ['code' => 'MED-METRO-IV100', 'name' => 'Metronidazole 500 mg/100 ml IV infusion', 'category' => 'antiprotozoals', 'unit' => 'each', 'description' => 'Antiprotozoal and anaerobic antibacterial IV infusion for serious infections.'],
            ['code' => 'MED-METMI-200TAB', 'name' => 'Metronidazole + Miconazole vaginal tablet', 'category' => 'antifungals', 'unit' => 'tablet', 'description' => 'Combined antiprotozoal and antifungal vaginal tablet for mixed vaginitis.'],
            ['code' => 'MED-TINI-500TAB', 'name' => 'Tinidazole 500 mg tablet', 'category' => 'antiprotozoals', 'unit' => 'tablet', 'description' => 'Antiprotozoal for giardiasis, amoebiasis, and bacterial vaginosis.'],

            // ── Cardiovascular ──
            ['code' => 'MED-ATEN-50TAB', 'name' => 'Atenolol 50 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Beta-1 selective blocker for hypertension, angina, and post-MI prophylaxis.'],
            ['code' => 'MED-CAPT-25TAB', 'name' => 'Captopril 25 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'ACE inhibitor for hypertension, heart failure, and diabetic nephropathy.'],
            ['code' => 'MED-NIFE-20TAB', 'name' => 'Nifedipine 20 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Calcium channel blocker for hypertension and angina pectoris.'],
            ['code' => 'MED-FURO-40TAB', 'name' => 'Furosemide 40 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Loop diuretic for hypertension, heart failure, and oedema.'],
            ['code' => 'MED-FURO-10IV', 'name' => 'Furosemide 10 mg/ml injection 2 ml', 'category' => 'cardiovascular', 'unit' => 'ampoule', 'description' => 'Loop diuretic for acute pulmonary oedema, heart failure, and fluid overload.'],
            ['code' => 'MED-HYDR-25TAB', 'name' => 'Hydralazine 25 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Direct vasodilator for hypertension and heart failure.'],
            ['code' => 'MED-BENDFT-5TAB', 'name' => 'Bendroflumethiazide 5 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Thiazide diuretic for hypertension and oedema.'],
            ['code' => 'MED-ASPJ-75TAB', 'name' => 'Aspirin Junior 75 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Low-dose aspirin for cardiovascular prophylaxis and antiplatelet therapy.'],
            ['code' => 'MED-ADREN-1ML', 'name' => 'Adrenaline (Epinephrine) 1 mg/ml injection 1 ml', 'category' => 'cardiovascular', 'unit' => 'ampoule', 'description' => 'Sympathomimetic amine for cardiac arrest, anaphylaxis, and severe asthma.'],
            ['code' => 'MED-ATROP-1IV', 'name' => 'Atropine sulfate 1 mg/ml injection 1 ml', 'category' => 'cardiovascular', 'unit' => 'ampoule', 'description' => 'Anticholinergic for bradycardia, organophosphate poisoning, and pre-anaesthesia.'],

            // ── Respiratory ──
            ['code' => 'MED-SALB-NEB25', 'name' => 'Salbutamol 2.5 mg nebulisation solution', 'category' => 'respiratory', 'unit' => 'each', 'description' => 'Short-acting beta-2 agonist solution for nebulisation in acute asthma and bronchospasm.'],
            ['code' => 'MED-AMINO-100TAB', 'name' => 'Aminophylline 100 mg tablet', 'category' => 'respiratory', 'unit' => 'tablet', 'description' => 'Xanthine bronchodilator tablet for chronic asthma and bronchospasm.'],
            ['code' => 'MED-AMINO-250IV', 'name' => 'Aminophylline 250 mg/10 ml injection', 'category' => 'respiratory', 'unit' => 'ampoule', 'description' => 'Xanthine bronchodilator for acute severe asthma and bronchospasm.'],
            ['code' => 'MED-CETIR-10TAB', 'name' => 'Cetirizine 10 mg tablet', 'category' => 'respiratory', 'unit' => 'tablet', 'description' => 'Second-generation antihistamine for allergic rhinitis and chronic urticaria.'],
            ['code' => 'MED-CETIR-60SYR', 'name' => 'Cetirizine hydrochloride 10 mg syrup 60 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Second-generation antihistamine syrup for allergic rhinitis, urticaria, and pruritus.'],
            ['code' => 'MED-LORA-10TAB', 'name' => 'Loratadine 10 mg tablet', 'category' => 'respiratory', 'unit' => 'tablet', 'description' => 'Second-generation antihistamine for allergic rhinitis and chronic urticaria.'],
            ['code' => 'MED-MONT-10TAB', 'name' => 'Montelukast 10 mg tablet', 'category' => 'respiratory', 'unit' => 'tablet', 'description' => 'Leukotriene receptor antagonist for prevention of chronic asthma.'],
            ['code' => 'MED-MONT-5TAB', 'name' => 'Montelukast 5 mg tablet', 'category' => 'respiratory', 'unit' => 'tablet', 'description' => 'Leukotriene receptor antagonist for prevention of chronic asthma in children.'],
            ['code' => 'MED-MUCAD-100SYR', 'name' => 'Ambroxol (Mucolyn Adult) syrup 100 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Mucolytic syrup for productive cough and respiratory secretions.'],
            ['code' => 'MED-MUCPA-100SYR', 'name' => 'Ambroxol (Mucolyn Paediatric) syrup 100 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Mucolytic syrup for productive cough in children.'],
            ['code' => 'MED-CODRIL-100SYR', 'name' => 'Codril cough syrup 100 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Combined cough suppressant and expectorant syrup for dry and productive cough.'],
            ['code' => 'MED-COUGH-100SYR', 'name' => 'Cough syrup (Prynalyn) 100 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Combined cough suppressant syrup for dry cough.'],
            ['code' => 'MED-DRCOLD-100SYR', 'name' => 'Dr Cold (Phenylephrine + Chlorphenamine) syrup 100 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Combined decongestant and antihistamine syrup for cold and flu symptoms.'],
            ['code' => 'MED-ZECUF-100SYR', 'name' => 'Zecuf herbal cough syrup 100 ml', 'category' => 'respiratory', 'unit' => 'bottle', 'description' => 'Herbal cough syrup for relief of cough and respiratory discomfort.'],
            ['code' => 'MED-NASAL-ADULT', 'name' => 'Nasal decongestant drops (adult) 15 ml', 'category' => 'respiratory', 'unit' => 'each', 'description' => 'Imidazoline-based nasal decongestant drops for nasal congestion in adults.'],
            ['code' => 'MED-NASAL-PAED', 'name' => 'Nasal decongestant drops (paediatric) 15 ml', 'category' => 'respiratory', 'unit' => 'each', 'description' => 'Gentle nasal decongestant drops for nasal congestion in children.'],

            // ── Gastrointestinal ──
            ['code' => 'MED-OMPZ-20CAP', 'name' => 'Omeprazole 20 mg capsule', 'category' => 'gastrointestinal', 'unit' => 'capsule', 'description' => 'Proton pump inhibitor for gastric ulcer, GERD, and H. pylori eradication.'],
            ['code' => 'MED-PANTO-40TAB', 'name' => 'Pantoprazole 40 mg tablet', 'category' => 'gastrointestinal', 'unit' => 'tablet', 'description' => 'Proton pump inhibitor for gastric ulcer, GERD, and erosive oesophagitis.'],
            ['code' => 'MED-PANTO-40IV', 'name' => 'Pantoprazole 40 mg injection', 'category' => 'gastrointestinal', 'unit' => 'vial', 'description' => 'Proton pump inhibitor IV for stress ulcer prophylaxis and acute GI bleeding.'],
            ['code' => 'MED-LOPER-2TAB', 'name' => 'Loperamide hydrochloride 2 mg tablet', 'category' => 'gastrointestinal', 'unit' => 'tablet', 'description' => 'Antidiarrhoeal agent for symptomatic relief of acute and chronic diarrhoea.'],
            ['code' => 'MED-LOPER-5CAP', 'name' => 'Loperamide 5 mg capsule', 'category' => 'gastrointestinal', 'unit' => 'capsule', 'description' => 'Antidiarrhoeal agent for symptomatic relief of acute diarrhoea.'],
            ['code' => 'MED-METOC-10TAB', 'name' => 'Metoclopramide hydrochloride 10 mg tablet', 'category' => 'gastrointestinal', 'unit' => 'tablet', 'description' => 'Antiemetic and prokinetic for nausea, vomiting, and gastroparesis.'],
            ['code' => 'MED-METOC-2IV', 'name' => 'Metoclopramide hydrochloride 10 mg/2 ml injection', 'category' => 'gastrointestinal', 'unit' => 'ampoule', 'description' => 'Antiemetic and prokinetic for nausea, vomiting, and gastroparesis.'],
            ['code' => 'MED-HYOSC-10TAB', 'name' => 'Hyoscine butylbromide 10 mg tablet', 'category' => 'gastrointestinal', 'unit' => 'tablet', 'description' => 'Antispasmodic tablet for irritable bowel syndrome and abdominal cramps.'],
            ['code' => 'MED-HYOSC-10IV', 'name' => 'Hyoscine butylbromide 20 mg/5 ml injection', 'category' => 'gastrointestinal', 'unit' => 'ampoule', 'description' => 'Antispasmodic for acute abdominal cramps and renal/biliary colic.'],
            ['code' => 'MED-BISAC-5TAB', 'name' => 'Bisacodyl 5 mg tablet', 'category' => 'gastrointestinal', 'unit' => 'tablet', 'description' => 'Stimulant laxative for constipation and bowel evacuation.'],
            ['code' => 'MED-LACT-100SYR', 'name' => 'Lactulose syrup 100 ml', 'category' => 'gastrointestinal', 'unit' => 'bottle', 'description' => 'Osmotic laxative syrup for constipation and hepatic encephalopathy.'],
            ['code' => 'MED-ANTAC-100SYR', 'name' => 'Antacid / Relcergel syrup 100 ml', 'category' => 'gastrointestinal', 'unit' => 'bottle', 'description' => 'Aluminium and magnesium hydroxide antacid syrup for dyspepsia and gastric hyperacidity.'],
            ['code' => 'MED-CMAG-250TAB', 'name' => 'Compound Magnesium Trisilicate 250 mg tablet', 'category' => 'gastrointestinal', 'unit' => 'tablet', 'description' => 'Antacid tablet for dyspepsia, heartburn, and gastric hyperacidity.'],
            ['code' => 'MED-BELLAD-100SYR', 'name' => 'Belladonna syrup 100 ml', 'category' => 'gastrointestinal', 'unit' => 'bottle', 'description' => 'Anticholinergic syrup for gastrointestinal spasms and colic.'],
            ['code' => 'MED-GRIPE-100SYR', 'name' => 'Gripe water 100 ml', 'category' => 'gastrointestinal', 'unit' => 'bottle', 'description' => 'Carminative remedy for infantile colic and flatulence.'],
            ['code' => 'MED-CITAL-100SYR', 'name' => 'Sodium citrate (Cital) syrup 100 ml', 'category' => 'gastrointestinal', 'unit' => 'bottle', 'description' => 'Urinary alkaliniser syrup for urinary tract infections and gout.'],

            // ── Endocrine & Metabolic ──
            ['code' => 'MED-METF-500TAB', 'name' => 'Metformin hydrochloride 500 mg tablet', 'category' => 'endocrine_metabolic', 'unit' => 'tablet', 'description' => 'Biguanide oral antidiabetic for type 2 diabetes mellitus.'],
            ['code' => 'MED-METGLIM-501TAB', 'name' => 'Metformin + Glimepiride 500 mg/1 mg tablet', 'category' => 'endocrine_metabolic', 'unit' => 'tablet', 'description' => 'Combined oral antidiabetic for type 2 diabetes not controlled by monotherapy.'],
            ['code' => 'MED-PRED-5TAB', 'name' => 'Prednisolone 5 mg tablet', 'category' => 'anti_inflammatory', 'unit' => 'tablet', 'description' => 'Corticosteroid for asthma, allergic conditions, autoimmune diseases, and inflammation.'],

            // ── Dermatological ──
            ['code' => 'MED-HYDC-15CREAM', 'name' => 'Hydrocortisone 1% cream 15 g', 'category' => 'anti_inflammatory', 'unit' => 'tube', 'description' => 'Mild topical corticosteroid for eczema, dermatitis, and allergic skin reactions.'],
            ['code' => 'MED-HYDC-100IV', 'name' => 'Hydrocortisone 100 mg injection', 'category' => 'anti_inflammatory', 'unit' => 'vial', 'description' => 'Corticosteroid for adrenal crisis, severe allergic reactions, and acute asthma.'],
            ['code' => 'MED-CLOB-10CREAM', 'name' => 'Clobetasol propionate 0.05% cream 10 g', 'category' => 'anti_inflammatory', 'unit' => 'tube', 'description' => 'Potent topical corticosteroid for inflammatory dermatoses and eczema.'],
            ['code' => 'MED-SKDERM-30CREAM', 'name' => 'SK Derm 30 g cream', 'category' => 'anti_inflammatory', 'unit' => 'tube', 'description' => 'Combined clotrimazole and betamethasone cream for infected eczema and dermatophytosis.'],
            ['code' => 'MED-GENTR-10CREAM', 'name' => 'Gentrisone 10 g cream', 'category' => 'anti_inflammatory', 'unit' => 'tube', 'description' => 'Combined betamethasone and gentamicin cream for infected inflammatory skin conditions.'],
            ['code' => 'MED-BETBZ-30CREAM', 'name' => 'Betamethasone benzoate 0.1% cream 30 g', 'category' => 'anti_inflammatory', 'unit' => 'tube', 'description' => 'Topical corticosteroid for inflammatory and pruritic skin conditions.'],
            ['code' => 'MED-DEXAM-4IV', 'name' => 'Dexamethasone sodium phosphate 4 mg injection', 'category' => 'anti_inflammatory', 'unit' => 'ampoule', 'description' => 'Corticosteroid injection for cerebral oedema, severe allergic reactions, and inflammation.'],
            ['code' => 'MED-TRIAM-40IM', 'name' => 'Triamcinolone acetonide 40 mg/ml injection', 'category' => 'anti_inflammatory', 'unit' => 'vial', 'description' => 'Intramuscular corticosteroid for severe inflammatory and allergic conditions.'],
            ['code' => 'MED-BURN-30CREAM', 'name' => 'Burnox 30 g cream', 'category' => 'dermatological', 'unit' => 'tube', 'description' => 'Topical burn care cream for minor burns and wound healing.'],
            ['code' => 'MED-BPO-20GEL', 'name' => 'Benzoyl peroxide 5% gel (Persone) 20 g', 'category' => 'dermatological', 'unit' => 'tube', 'description' => 'Topical agent for mild to moderate acne vulgaris.'],
            ['code' => 'MED-BBE-100LOT', 'name' => 'BB lotion 100 ml', 'category' => 'dermatological', 'unit' => 'bottle', 'description' => 'Topical lotion for skin moisturising and minor skin conditions.'],
            ['code' => 'MED-CALZ-100LOT', 'name' => 'Calamine + Zinc oxide lotion 100 ml', 'category' => 'dermatological', 'unit' => 'bottle', 'description' => 'Anti-pruritic and soothing lotion for chickenpox, sunburn, and mild skin irritations.'],

            // ── Haematological ──
            ['code' => 'MED-FERSUL-200TAB', 'name' => 'Ferrous sulphate 200 mg tablet', 'category' => 'haematological', 'unit' => 'tablet', 'description' => 'Iron supplement tablet for prevention and treatment of iron-deficiency anaemia.'],
            ['code' => 'MED-FOLIC-5TAB', 'name' => 'Folic acid 5 mg tablet', 'category' => 'haematological', 'unit' => 'tablet', 'description' => 'Vitamin supplement for folic acid deficiency anaemia and neural tube defect prophylaxis.'],
            ['code' => 'MED-FERR-162CAP', 'name' => 'Ferrotone 162 mg capsule', 'category' => 'haematological', 'unit' => 'capsule', 'description' => 'Iron supplement capsule for prevention and treatment of iron-deficiency anaemia.'],
            ['code' => 'MED-IRONS-20IV', 'name' => 'Iron sucrose 20 mg/ml injection', 'category' => 'haematological', 'unit' => 'vial', 'description' => 'Intravenous iron replacement for iron-deficiency anaemia when oral iron is not tolerated.'],
            ['code' => 'MED-TRANE-5IV', 'name' => 'Tranexamic acid 500 mg/5 ml injection', 'category' => 'haematological', 'unit' => 'ampoule', 'description' => 'Antifibrinolytic agent for control of haemorrhage and excessive bleeding.'],
            ['code' => 'MED-GLOBZ-200SYR', 'name' => 'Globin Z haematinic syrup 200 ml', 'category' => 'haematological', 'unit' => 'bottle', 'description' => 'Iron, folic acid, and vitamin B12 haematinic syrup for anaemia.'],
            ['code' => 'MED-HEMOV-200SYR', 'name' => 'Hemovit syrup 200 ml', 'category' => 'haematological', 'unit' => 'bottle', 'description' => 'Iron and vitamin haematinic syrup for iron-deficiency anaemia.'],
            ['code' => 'MED-HEMAT-200SYR', 'name' => 'Hematone haematinic syrup 200 ml', 'category' => 'haematological', 'unit' => 'bottle', 'description' => 'Iron, folic acid, and B-vitamin haematinic syrup for anaemia.'],
            ['code' => 'MED-SKTONE-100SYR', 'name' => 'Sktonic (Iron, Vitamin B, Folic Acid, Zinc) syrup 100 ml', 'category' => 'haematological', 'unit' => 'bottle', 'description' => 'Combined haematinic syrup with iron, B-vitamins, folic acid, and zinc for anaemia.'],
            ['code' => 'MED-MUMFER-150SYR', 'name' => 'Mumfer iron and folic acid syrup 150 ml', 'category' => 'haematological', 'unit' => 'bottle', 'description' => 'Iron and folic acid supplement syrup for pregnancy and anaemia.'],

            // ── Hormonal & Contraceptives ──
            ['code' => 'MED-MEDRO-150IM', 'name' => 'Medroxyprogesterone acetate 150 mg/ml injection', 'category' => 'hormones_contraceptives', 'unit' => 'vial', 'description' => 'Depot progestogen contraceptive injection for long-acting reversible contraception.'],
            ['code' => 'MED-NORE-5TAB', 'name' => 'Norethisterone (NOR 5) 5 mg tablet', 'category' => 'hormones_contraceptives', 'unit' => 'tablet', 'description' => 'Progestogen for menstrual disorders, endometriosis, and contraception.'],
            ['code' => 'MED-DUPH-10TAB', 'name' => 'Duphaston (Dydrogesterone) 10 mg tablet', 'category' => 'hormones_contraceptives', 'unit' => 'tablet', 'description' => 'Progestogen for threatened miscarriage, endometriosis, and menstrual disorders.'],
            ['code' => 'MED-MISO-200TAB', 'name' => 'Misoprostol 200 mcg tablet', 'category' => 'hormones_contraceptives', 'unit' => 'tablet', 'description' => 'Prostaglandin analogue for prevention of NSAID-induced gastric ulcer, and obstetric use.'],
            ['code' => 'MED-OXYT-10IU', 'name' => 'Oxytocin 10 IU/ml injection', 'category' => 'hormones_contraceptives', 'unit' => 'ampoule', 'description' => 'Oxytocic hormone for prevention and treatment of postpartum haemorrhage.'],

            // ── Mental Health & Psychiatric ──
            ['code' => 'MED-DIAZ-5TAB', 'name' => 'Diazepam 5 mg tablet', 'category' => 'mental_health_psychiatric', 'unit' => 'tablet', 'description' => 'Benzodiazepine for anxiety, muscle spasms, seizures, and alcohol withdrawal.'],
            ['code' => 'MED-DIAZ-10IV', 'name' => 'Diazepam 10 mg/2 ml injection', 'category' => 'mental_health_psychiatric', 'unit' => 'ampoule', 'description' => 'Benzodiazepine for status epilepticus, severe anxiety, and muscle spasms.'],
            ['code' => 'MED-PROM-25TAB', 'name' => 'Promethazine 25 mg tablet', 'category' => 'mental_health_psychiatric', 'unit' => 'tablet', 'description' => 'Phenothiazine antihistamine for allergy, sedation, and nausea.'],
            ['code' => 'MED-PROM-2IM', 'name' => 'Promethazine hydrochloride 25 mg/ml injection 2 ml', 'category' => 'mental_health_psychiatric', 'unit' => 'ampoule', 'description' => 'Phenothiazine antihistamine and antiemetic for severe allergy and nausea.'],

            // ── Neurological ──
            ['code' => 'MED-PREG-75CAP', 'name' => 'Pregabalin 75 mg capsule', 'category' => 'neurological', 'unit' => 'capsule', 'description' => 'Anticonvulsant and neuropathic pain agent for diabetic neuropathy and post-herpetic neuralgia.'],
            ['code' => 'MED-BACL-10TAB', 'name' => 'Baclofen 10 mg tablet', 'category' => 'neurological', 'unit' => 'tablet', 'description' => 'GABA-B agonist for spasticity in multiple sclerosis, spinal cord injury, and stroke.'],
            ['code' => 'MED-TIZA-4TAB', 'name' => 'Tizanidine 4 mg tablet', 'category' => 'neurological', 'unit' => 'tablet', 'description' => 'Centrally acting muscle relaxant for spasticity and musculoskeletal pain.'],
            ['code' => 'MED-NEURO-300TAB', 'name' => 'Neurotone (Methylcobalamin) 300 mcg tablet', 'category' => 'vitamins_minerals', 'unit' => 'tablet', 'description' => 'Vitamin B12 supplement for peripheral neuropathy and B12 deficiency.'],

            // ── Eye & ENT ──
            ['code' => 'MED-CHLOR-EYEDROP', 'name' => 'Chloramphenicol eye drops 0.5% 5 ml', 'category' => 'antibiotics', 'unit' => 'each', 'description' => 'Broad-spectrum antibiotic eye drops for bacterial conjunctivitis.'],
            ['code' => 'MED-CHLOR-EYEINT', 'name' => 'Chloramphenicol eye ointment', 'category' => 'antibiotics', 'unit' => 'tube', 'description' => 'Broad-spectrum antibiotic eye ointment for bacterial conjunctivitis and eye infections.'],
            ['code' => 'MED-GENT-EYEDROP', 'name' => 'Gentamicin 0.3% eye drops 10 ml', 'category' => 'antibiotics', 'unit' => 'each', 'description' => 'Aminoglycoside antibiotic eye drops for external eye infections.'],
            ['code' => 'MED-DEXNEO-EYEDROP', 'name' => 'Dexamethasone + Neomycin (Dexaneomycin) eye drops 10 ml', 'category' => 'anti_inflammatory', 'unit' => 'each', 'description' => 'Combined corticosteroid and antibiotic eye drops for inflammatory eye conditions with infection.'],
            ['code' => 'MED-DEXP-EYEDROP', 'name' => 'Dexamethasone sodium phosphate eye drops 0.1%', 'category' => 'anti_inflammatory', 'unit' => 'each', 'description' => 'Corticosteroid eye drops for non-infective inflammatory eye conditions.'],
            ['code' => 'MED-BORIC-EARDROP', 'name' => 'Boric acid ear drops 15 ml', 'category' => 'otological', 'unit' => 'each', 'description' => 'Antiseptic ear drops for chronic otitis externa and ear canal infections.'],

            // ── IV Fluids & Nutritional ──
            ['code' => 'MED-NS-IV500', 'name' => 'Normal Saline 0.9% 500 ml IV infusion', 'category' => 'nutritional', 'unit' => 'each', 'description' => 'Isotonic sodium chloride solution for fluid and electrolyte replacement.'],
            ['code' => 'MED-D5-IV500', 'name' => 'Dextrose 5% 500 ml IV infusion', 'category' => 'nutritional', 'unit' => 'each', 'description' => 'Isotonic glucose solution for fluid replacement and calorie provision.'],
            ['code' => 'MED-DNS-IV500', 'name' => 'Dextrose Normal Saline 500 ml IV infusion', 'category' => 'nutritional', 'unit' => 'each', 'description' => 'Combined dextrose and sodium chloride solution for fluid and electrolyte replacement.'],
            ['code' => 'MED-RL-IV500', 'name' => "Ringer's Lactate 500 ml IV infusion", 'category' => 'nutritional', 'unit' => 'each', 'description' => 'Crystalloid solution for fluid resuscitation and electrolyte replacement.'],
            ['code' => 'MED-GLUC-80POW', 'name' => 'Oral rehydration glucose powder 80 g sachet', 'category' => 'nutritional', 'unit' => 'sachet', 'description' => 'Glucose powder for preparation of oral rehydration solution.'],
            ['code' => 'MED-ORS-POW', 'name' => 'ORS rehydration salt sachet', 'category' => 'nutritional', 'unit' => 'sachet', 'description' => 'Oral rehydration salts for prevention and treatment of dehydration from diarrhoea.'],

            // ── Vitamins & Minerals ──
            ['code' => 'MED-MULTV-TAB', 'name' => 'Multivitamin tablet', 'category' => 'nutritional', 'unit' => 'tablet', 'description' => 'Daily multivitamin supplement for nutritional support.'],
            ['code' => 'MED-MULTV-100SYR', 'name' => 'Multivitamin syrup 100 ml', 'category' => 'nutritional', 'unit' => 'bottle', 'description' => 'Multivitamin supplement syrup for growth, development, and nutritional support.'],
            ['code' => 'MED-VITBC-10TAB', 'name' => 'Vitamin B complex 10 mg tablet', 'category' => 'vitamins_minerals', 'unit' => 'tablet', 'description' => 'B-complex vitamin supplement for deficiency states.'],
            ['code' => 'MED-VITBC-100SYR', 'name' => 'Vitamin B-complex syrup 100 ml', 'category' => 'vitamins_minerals', 'unit' => 'bottle', 'description' => 'B-complex vitamin supplement syrup.'],
            ['code' => 'MED-VITB-10IM', 'name' => 'Vitamin B complex 10 ml injection', 'category' => 'vitamins_minerals', 'unit' => 'ampoule', 'description' => 'B-complex vitamin injection for deficiency states and neuropathy.'],
            ['code' => 'MED-ZNSUL-20TAB', 'name' => 'Zinc sulphate 20 mg dispersible tablet', 'category' => 'nutritional', 'unit' => 'tablet', 'description' => 'Zinc supplement dispersible tablet for diarrhoea management and nutritional deficiency.'],
            ['code' => 'MED-ZNSUL-100SYR', 'name' => 'Zinc sulphate 20 mg/5 ml syrup 100 ml', 'category' => 'nutritional', 'unit' => 'bottle', 'description' => 'Zinc supplement syrup for diarrhoea management in children and nutritional supplementation.'],

            // ── Immunological / Vaccines ──
            ['code' => 'MED-TETAN-05IM', 'name' => 'Tetanus toxoid vaccine 0.5 ml', 'category' => 'immunological_vaccines', 'unit' => 'ampoule', 'description' => 'Active immunisation agent for prevention of tetanus.'],

            // ── Urological ──
            ['code' => 'MED-TAMS-04CAP', 'name' => 'Tamsulosin 0.4 mg capsule', 'category' => 'urological_genitourinary', 'unit' => 'capsule', 'description' => 'Alpha-1 blocker for benign prostatic hyperplasia (BPH) symptom relief.'],

            // ── NEMLIT Level A/B First-Line Additions ──
            ['code' => 'MED-AMLO-5TAB', 'name' => 'Amlodipine 5 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Calcium channel blocker for first-line treatment of essential hypertension per Tanzania STG/NEMLIT.'],
            ['code' => 'MED-AMLO-10TAB', 'name' => 'Amlodipine 10 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'Calcium channel blocker for hypertension requiring higher dose.'],
            ['code' => 'MED-ENAL-5TAB', 'name' => 'Enalapril 5 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'ACE inhibitor for hypertension and heart failure per Tanzania STG/NEMLIT.'],
            ['code' => 'MED-ENAL-10TAB', 'name' => 'Enalapril 10 mg tablet', 'category' => 'cardiovascular', 'unit' => 'tablet', 'description' => 'ACE inhibitor for hypertension and heart failure at standard dose.'],
            ['code' => 'MED-GLIB-5TAB', 'name' => 'Glibenclamide 5 mg tablet', 'category' => 'endocrine_metabolic', 'unit' => 'tablet', 'description' => 'Sulfonylurea oral hypoglycaemic for Type 2 Diabetes Mellitus per Tanzania STG/NEMLIT.'],
            ['code' => 'MED-MGSO4-50IV', 'name' => 'Magnesium Sulphate 50% injection 10 ml', 'category' => 'obstetric_emergency', 'unit' => 'ampoule', 'description' => 'Anticonvulsant for severe pre-eclampsia and eclampsia per Tanzania STG/NEMLIT. Critical obstetric emergency medicine.'],
        ];
    }

    private function buildOverrides(): void
    {
        // Override derivation only where name doesn't cleanly parse
        $this->overrides = [
            'MED-CODRIL-100SYR' => ['generic_name' => 'Codeine-based cough preparation', 'strength' => null],
            'MED-COUGH-100SYR' => ['generic_name' => 'Cough suppressant combination', 'strength' => null],
            'MED-DRCOLD-100SYR' => ['generic_name' => 'Phenylephrine + Chlorphenamine', 'strength' => null],
            'MED-ZECUF-100SYR' => ['generic_name' => 'Herbal cough preparation', 'strength' => null],
            'MED-BBE-100LOT' => ['generic_name' => 'Moisturising lotion', 'strength' => null],
            'MED-BURN-30CREAM' => ['generic_name' => 'Burn care cream', 'strength' => null],
            'MED-BELLAD-100SYR' => ['generic_name' => 'Belladonna tincture', 'strength' => null],
            'MED-GRIPE-100SYR' => ['generic_name' => 'Gripe water', 'strength' => null],
            'MED-MULTV-TAB' => ['generic_name' => 'Multivitamin', 'strength' => null],
            'MED-MULTV-100SYR' => ['generic_name' => 'Multivitamin', 'strength' => null],
            'MED-VITBC-10TAB' => ['generic_name' => 'Vitamin B complex', 'strength' => null],
            'MED-VITBC-100SYR' => ['generic_name' => 'Vitamin B complex', 'strength' => null],
            'MED-VITB-10IM' => ['generic_name' => 'Vitamin B complex', 'strength' => null],
            'MED-SKDERM-30CREAM' => ['generic_name' => 'Clotrimazole + Betamethasone', 'strength' => null],
            'MED-GENTR-10CREAM' => ['generic_name' => 'Betamethasone + Gentamicin', 'strength' => null],
            'MED-GYNEX-PESS' => ['generic_name' => 'Miconazole + Metronidazole', 'strength' => null],
            'MED-METMI-200TAB' => ['generic_name' => 'Metronidazole + Miconazole', 'strength' => null],
            'MED-DUOCO-360TAB' => ['generic_name' => 'Ibuprofen + Paracetamol', 'strength' => null],
            'MED-TERMID-100SYR' => ['generic_name' => 'Ibuprofen + Paracetamol', 'strength' => null],
            'MED-CALZ-100LOT' => ['generic_name' => 'Calamine + Zinc oxide', 'strength' => null],
            'MED-ALUME-12TAB' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '20/120 mg'],
            'MED-ALUME-24TAB' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '20/120 mg'],
            'MED-ALUME-6TAB' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '80/480 mg'],
            'MED-LONART-24SYR' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '80/480 mg per 24 ml'],
            'MED-AL-22SYR' => ['generic_name' => 'Artemether + Lumefantrine', 'strength' => '22.4 mg/ml'],
            'MED-ARTE-80IM' => ['generic_name' => 'Artemether', 'strength' => '80 mg/ml'],
            'MED-ARTSN-60IV' => ['generic_name' => 'Artesunate', 'strength' => '60 mg'],
            'MED-ARTSN-120IV' => ['generic_name' => 'Artesunate', 'strength' => '120 mg'],
            'MED-MALAF-525TAB' => ['generic_name' => 'Sulfamethoxypyrazine + Pyrimethamine', 'strength' => '525 mg'],
            'MED-SILVEX-10CREAM' => ['generic_name' => 'Silver sulfadiazine + Chlorhexidine', 'strength' => null],
            'MED-AMPCLOX-500CAP' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => '500 mg'],
            'MED-AMPCLX-100SYR' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => null],
            'MED-AMPCLXN-06SYR' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => '60 mg/ml'],
            'MED-AMPCLOX-500IV' => ['generic_name' => 'Ampicillin + Cloxacillin', 'strength' => '500 mg'],
            'MED-AMOCL-625TAB' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '625 mg'],
            'MED-AMOCL-375TAB' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '375 mg'],
            'MED-AMOCL-100SYR' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => null],
            'MED-AMOCL-12IV' => ['generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '1.2 g'],
            'MED-CEFTRS-15IV' => ['generic_name' => 'Ceftriaxone + Sulbactam', 'strength' => '1.5 g'],
            'MED-CIPT-600TAB' => ['generic_name' => 'Ciprofloxacin + Tinidazole', 'strength' => '600 mg'],
            'MED-METGLIM-501TAB' => ['generic_name' => 'Metformin + Glimepiride', 'strength' => '500 mg/1 mg'],
            'MED-NEURO-300TAB' => ['generic_name' => 'Methylcobalamin', 'strength' => '300 mcg'],
            'MED-PENAD-24IM' => ['generic_name' => 'Benzathine benzylpenicillin', 'strength' => '2.4 MU'],
            'MED-BENZP-5MU' => ['generic_name' => 'Benzylpenicillin', 'strength' => '5 MU'],
            'MED-ANTAC-100SYR' => ['generic_name' => 'Aluminium + Magnesium hydroxide', 'strength' => null],
            'MED-CMAG-250TAB' => ['generic_name' => 'Magnesium trisilicate compound', 'strength' => '250 mg'],
            'MED-CITAL-100SYR' => ['generic_name' => 'Sodium citrate', 'strength' => null],
            'MED-GLOBZ-200SYR' => ['generic_name' => 'Iron + Folic acid + Vitamin B12', 'strength' => null],
            'MED-HEMOV-200SYR' => ['generic_name' => 'Iron + Vitamin B complex', 'strength' => null],
            'MED-HEMAT-200SYR' => ['generic_name' => 'Iron + Folic acid + Vitamin B', 'strength' => null],
            'MED-SKTONE-100SYR' => ['generic_name' => 'Iron + Vitamin B + Folic acid + Zinc', 'strength' => null],
            'MED-MUMFER-150SYR' => ['generic_name' => 'Iron + Folic acid', 'strength' => null],
            'MED-FERR-162CAP' => ['generic_name' => 'Iron supplement', 'strength' => '162 mg'],
            'MED-WHFL-20OINT' => ['generic_name' => 'Salicylic acid + Benzoic acid', 'strength' => null],
            'MED-BPO-20GEL' => ['generic_name' => 'Benzoyl peroxide', 'strength' => '5%'],
            'MED-DEXNEO-EYEDROP' => ['generic_name' => 'Dexamethasone + Neomycin', 'strength' => null],
            'MED-NASAL-ADULT' => ['generic_name' => 'Oxymetazoline / Xylometazoline', 'strength' => null],
            'MED-NASAL-PAED' => ['generic_name' => 'Oxymetazoline / Xylometazoline (paediatric)', 'strength' => null],
            'MED-BORIC-EARDROP' => ['generic_name' => 'Boric acid', 'strength' => null],
            'MED-MICG-400PESS' => ['generic_name' => 'Miconazole nitrate', 'strength' => '400 mg'],

            // Pure crystalloid/electrolyte IV fluids: the administered dose is the
            // volume, not the concentration in the name -- classify distinctly from
            // generic 'injection' so dose validation and instruction wording use
            // container_volume instead of treating strength (e.g. "0.9%") as a dose.
            'MED-NS-IV500' => ['dosage_form' => 'iv fluid', 'container_volume_value' => 500, 'container_volume_unit' => 'ml'],
            'MED-D5-IV500' => ['dosage_form' => 'iv fluid', 'container_volume_value' => 500, 'container_volume_unit' => 'ml'],
            'MED-DNS-IV500' => ['dosage_form' => 'iv fluid', 'container_volume_value' => 500, 'container_volume_unit' => 'ml'],
            'MED-RL-IV500' => ['dosage_form' => 'iv fluid', 'container_volume_value' => 500, 'container_volume_unit' => 'ml'],
        ];

        // Set controlled_substance_schedule for controlled drugs
        foreach ($this->controlledCodes as $code) {
            $this->overrides[$code]['is_controlled_substance'] = true;
            if (str_starts_with($code, 'MED-TRAM') || str_starts_with($code, 'MED-PREG')) {
                $this->overrides[$code]['controlled_substance_schedule'] = 'schedule_IV';
            }
            if (str_starts_with($code, 'MED-DIAZ')) {
                $this->overrides[$code]['controlled_substance_schedule'] = 'schedule_IV';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function deriveFields(array $item): array
    {
        $code = $item['code'];
        $name = $item['name'];
        $unit = $item['unit'];

        // Check for explicit override
        if (isset($this->overrides[$code])) {
            $override = $this->overrides[$code];
        } else {
            $override = [];
        }

        // ── Strength ──
        $strength = array_key_exists('strength', $override) ? $override['strength'] : $this->parseStrength($name);

        // ── Generic name ──
        $genericName = array_key_exists('generic_name', $override) ? $override['generic_name'] : $this->parseGenericName($name);

        // ── Dosage form ──
        $dosageForm = array_key_exists('dosage_form', $override) ? $override['dosage_form'] : $this->mapUnitToDosageForm($unit, $name);

        // ── Route ──
        $route = $this->mapDosageFormToRoute($dosageForm, $name);

        // ── Container volume (IV fluids only; the administered dose) ──
        $containerVolumeValue = $override['container_volume_value'] ?? null;
        $containerVolumeUnit = $override['container_volume_unit'] ?? null;

        // ── Storage conditions ──
        $storageConditions = in_array($code, $this->coldChainCodes)
            ? 'Store at 2–8°C. Do not freeze.'
            : 'Store below 25°C, protect from light.';

        // ── Controlled substance ──
        $isControlled = $override['is_controlled_substance'] ?? in_array($code, $this->controlledCodes);
        $controlledSchedule = $override['controlled_substance_schedule'] ?? null;

        // ── Generic group code ──
        $groupCode = $this->toGroupCode($genericName);

        // ── Metadata ──
        $otcAllowed = !$isControlled && !str_contains($genericName, 'antibiotic') && !str_contains($name, 'injection');
        $packSize = $this->defaultPackSize($dosageForm, $unit);
        $stockUnit = $this->defaultStockUnit($dosageForm, $name);
        $conversionFactor = $this->defaultConversionFactor($dosageForm);
        $purchaseUnit = $this->defaultPurchaseUnit($dosageForm, $name);
        $purchaseUnitQuantity = $this->defaultPurchaseUnitQuantity($dosageForm);

        return [
            'generic_name' => $genericName,
            'dosage_form' => $dosageForm,
            'strength' => $strength,
            'route' => $route,
            'container_volume_value' => $containerVolumeValue,
            'container_volume_unit' => $containerVolumeUnit,
            'storage_conditions' => $storageConditions,
            'requires_cold_chain' => in_array($code, $this->coldChainCodes),
            'is_controlled_substance' => $isControlled,
            'controlled_substance_schedule' => $controlledSchedule,
            'generic_group_code' => $groupCode,
            'metadata' => [
                'strength' => $strength,
                'dosageForm' => $dosageForm,
                'route' => $route,
                'otcAllowed' => $otcAllowed,
                'packSize' => $packSize,
                'stockUnit' => $stockUnit,
                'conversionFactor' => $conversionFactor,
                'purchaseUnit' => $purchaseUnit,
                'purchaseUnitQuantity' => $purchaseUnitQuantity,
            ],
        ];
    }

    private function parseStrength(string $name): ?string
    {
        // Match patterns like "500 mg", "250 mg/5 ml", "200 mcg", "5%", "0.3%", "2.4 MU", "5 MU", "1 g", "20/120 mg"
        if (preg_match('/(\d[\d\/.,]*\s*(mg|mcg|g|ml|%|IU|MU))/i', $name, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function parseGenericName(string $name): string
    {
        // Strip strength in parentheses: "Paracetamol 500 mg" -> "Paracetamol"
        $generic = preg_replace('/\s+\d[\d\/.,]*\s*(mg|mcg|g|ml|%|IU|MU).*$/i', '', $name);

        // Strip brand name in parentheses at end: "Azithromycin 500 mg (Azuma)" -> "Azithromycin"
        $generic = preg_replace('/\s*\([^)]*\)\s*$/', '', $generic);

        // Strip trailing dosage form words: "Paracetamol 500 mg tablet" -> "Paracetamol" (but only after strength is already stripped)
        $generic = preg_replace('/\s+(tablet|capsule|injection|ointment|cream|gel|syrup|suspension|lotion|drops|infusion|solution|nebulisation)$/i', '', $generic);

        // Clean up
        $generic = trim($generic);

        // Fallback
        if (empty($generic)) {
            // Use name up to first number
            $parts = preg_split('/\s+\d/', $name);
            $generic = trim($parts[0] ?? $name);
        }

        return $generic ?: $name;
    }

    private function mapUnitToDosageForm(string $unit, string $name): string
    {
        if ($unit === 'each') {
            if (preg_match('/\b(IV\s+infusion|infusion)\b/i', $name)) return 'injection';
            if (preg_match('/\b(eye\s+(drops?|ointment)|ophthalmic)\b/i', $name)) return 'eye drops';
            if (preg_match('/\b(ear\s+drops?|otic)\b/i', $name)) return 'ear drops';
            if (preg_match('/\b(nasal\s+drops?|nasal)\b/i', $name)) return 'nasal drops';
            if (preg_match('/\bnebulisation\b/i', $name)) return 'solution';
            if (preg_match('/\binhalation\b/i', $name)) return 'solution';
            if (preg_match('/\bsuppository\b/i', $name)) return 'suppository';
            if (preg_match('/\bpessary\b/', $name)) return 'pessary';
            return 'solution';
        }

        if ($unit === 'tube') {
            if (preg_match('/\bo(e|i)nt(ment)?\b/i', $name)) return 'ointment';
            if (preg_match('/\bgel\b/i', $name)) return 'gel';
            if (preg_match('/\bcream\b/i', $name)) return 'cream';
            return 'cream';
        }

        if ($unit === 'bottle') {
            if (preg_match('/\blotion\b/i', $name)) return 'lotion';
            if (preg_match('/\bsuspension\b/i', $name)) return 'suspension';
            if (preg_match('/\bsolution\b/i', $name)) return 'solution';
            return 'syrup';
        }

        return match ($unit) {
            'tablet' => 'tablet',
            'capsule' => 'capsule',
            'ampoule' => 'injection',
            'vial' => 'injection',
            'sachet' => 'powder',
            default => 'solution',
        };
    }

    private function mapDosageFormToRoute(string $dosageForm, string $name): string
    {
        if (preg_match('/\b(IV\s+infusion|infusion)\b/i', $name)) return 'intravenous';
        if (preg_match('/\b(eye\s+(drops?|ointment)|ophthalmic)\b/i', $name)) return 'ophthalmic';
        if (preg_match('/\b(ear\s+drops?|otic)\b/i', $name)) return 'otic';
        if (preg_match('/\b(nasal\s+drops?)\b/i', $name)) return 'nasal';
        if (preg_match('/\bnebulisation\b/i', $name)) return 'inhalation';
        if (preg_match('/\binhalation\b/i', $name)) return 'inhalation';
        if (preg_match('/\bsuppository\b/i', $name)) return 'rectal';
        if (preg_match('/\bpessary\b/', $name)) return 'vaginal';

        return match ($dosageForm) {
            'tablet', 'capsule', 'syrup', 'suspension', 'solution', 'elixir', 'mixture', 'powder' => 'oral',
            'cream', 'ointment', 'gel', 'lotion' => 'topical',
            'injection' => preg_match('/\b(IV\s+infusion|infusion|IV)\b/i', $name) ? 'intravenous' : 'intramuscular',
            'eye drops' => 'ophthalmic',
            'ear drops' => 'otic',
            'nasal drops' => 'nasal',
            'suppository' => 'rectal',
            'pessary' => 'vaginal',
            'inhaler', 'spray' => 'inhalation',
            'patch' => 'transdermal',
            default => 'oral',
        };
    }

    private function toGroupCode(string $genericName): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s+\/-]/', '', $genericName);
        $clean = preg_replace('/[\s\/]+/', '_', trim($clean));

        return strtoupper($clean);
    }

    private function defaultPackSize(string $dosageForm, string $unit): int
    {
        return match ($dosageForm) {
            'tablet', 'capsule' => 100,
            'injection' => 10,
            default => 1,
        };
    }

    private function defaultStockUnit(string $dosageForm, string $name): string
    {
        return match ($dosageForm) {
            'tablet' => 'tablet',
            'capsule' => 'capsule',
            'cream', 'ointment', 'gel' => 'tube',
            'lotion', 'syrup', 'suspension', 'solution', 'elixir', 'mixture', 'eye drops', 'ear drops', 'nasal drops' => 'bottle',
            'injection' => 'ampoule',
            'powder' => 'sachet',
            'suppository', 'pessary' => 'each',
            'inhaler', 'spray' => 'each',
            'patch' => 'each',
            default => 'each',
        };
    }

    private function defaultConversionFactor(string $dosageForm): int
    {
        return 1;
    }

    private function defaultPurchaseUnit(string $dosageForm, string $name): string
    {
        return match ($dosageForm) {
            'tablet', 'capsule', 'injection', 'suppository', 'pessary',
            'cream', 'ointment', 'gel', 'lotion',
            'syrup', 'suspension', 'solution', 'elixir', 'mixture',
            'eye drops', 'ear drops', 'nasal drops',
            'powder', 'inhaler', 'spray', 'patch' => 'box',
            default => 'each',
        };
    }

    private function defaultPurchaseUnitQuantity(string $dosageForm): int
    {
        return match ($dosageForm) {
            'tablet', 'capsule', 'suppository', 'pessary' => 100,
            'cream', 'ointment', 'gel', 'lotion', 'syrup', 'suspension', 'solution', 'elixir', 'mixture', 'eye drops', 'ear drops', 'nasal drops' => 12,
            'injection' => 50,
            'powder' => 50,
            'inhaler', 'spray' => 12,
            'patch' => 12,
            default => 1,
        };
    }
}
