<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

class StaffCredentialingSummaryResponseTransformer
{
    public static function transform(array $summary): array
    {
        return [
            'id' => $summary['id'] ?? null,
            'credentialingState' => $summary['credentialing_state'] ?? null,
            'blockingReasons' => $summary['blocking_reasons'] ?? [],
            'nextExpiryAt' => $summary['next_expiry_at'] ?? null,
            'regulatoryProfile' => isset($summary['regulatory_profile']) && is_array($summary['regulatory_profile'])
                ? StaffRegulatoryProfileResponseTransformer::transform($summary['regulatory_profile'])
                : null,
            'activeRegistration' => isset($summary['active_registration']) && is_array($summary['active_registration'])
                ? StaffProfessionalRegistrationResponseTransformer::transformSummary($summary['active_registration'])
                : null,
            'registrationSummary' => [
                'total' => $summary['registration_summary']['total'] ?? 0,
                'verified' => $summary['registration_summary']['verified'] ?? 0,
                'pendingVerification' => $summary['registration_summary']['pending_verification'] ?? 0,
                'expired' => $summary['registration_summary']['expired'] ?? 0,
            ],
        ];
    }
}
