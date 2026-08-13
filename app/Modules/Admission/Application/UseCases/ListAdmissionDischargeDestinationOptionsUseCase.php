<?php

namespace App\Modules\Admission\Application\UseCases;

class ListAdmissionDischargeDestinationOptionsUseCase
{
    /**
     * @return array<int, array{value:string,label:string,group?:string|null,description?:string|null,keywords?:array<int,string>}>
     */
    public function execute(): array
    {
        return [
            [
                'value' => 'Home / self-care',
                'label' => 'Home / self-care',
                'group' => 'Community discharge',
                'description' => 'Patient is discharged home without a facility transfer.',
                'keywords' => ['home', 'self care', 'community'],
            ],
            [
                'value' => 'Home with family support',
                'label' => 'Home with family support',
                'group' => 'Community discharge',
                'description' => 'Patient is going home with caregiver or family support.',
                'keywords' => ['home', 'family', 'caregiver'],
            ],
            [
                'value' => 'Home with home-based care',
                'label' => 'Home with home-based care',
                'group' => 'Community discharge',
                'description' => 'Patient is discharged home with home-based follow-up support.',
                'keywords' => ['home', 'home care', 'community'],
            ],
            [
                'value' => 'Transfer to another acute hospital',
                'label' => 'Transfer to another acute hospital',
                'group' => 'Facility transfer',
                'description' => 'Patient is being transferred for continuing inpatient acute care.',
                'keywords' => ['transfer', 'acute hospital', 'referral'],
            ],
            [
                'value' => 'Transfer to regional/referral hospital',
                'label' => 'Transfer to regional/referral hospital',
                'group' => 'Facility transfer',
                'description' => 'Patient is being referred upward for specialist or higher-level care.',
                'keywords' => ['transfer', 'regional', 'referral hospital', 'specialist'],
            ],
            [
                'value' => 'Rehabilitation unit',
                'label' => 'Rehabilitation unit',
                'group' => 'Facility transfer',
                'description' => 'Patient is discharged onward to a rehabilitation setting.',
                'keywords' => ['rehab', 'rehabilitation', 'physiotherapy'],
            ],
            [
                'value' => 'Skilled nursing / long-term care',
                'label' => 'Skilled nursing / long-term care',
                'group' => 'Supportive care',
                'description' => 'Patient requires nursing support or longer-term placement after discharge.',
                'keywords' => ['nursing', 'long term care', 'care home'],
            ],
            [
                'value' => 'Hospice / palliative care',
                'label' => 'Hospice / palliative care',
                'group' => 'Supportive care',
                'description' => 'Patient is discharged with a palliative or hospice care plan.',
                'keywords' => ['hospice', 'palliative', 'comfort care'],
            ],
        ];
    }
}
