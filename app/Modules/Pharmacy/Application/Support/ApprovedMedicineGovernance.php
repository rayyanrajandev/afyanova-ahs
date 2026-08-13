<?php

namespace App\Modules\Pharmacy\Application\Support;

final class ApprovedMedicineGovernance
{
    /**
     * @param  array<string, mixed>|null  $catalogItem
     * @return array{
     *     reviewMode:'auto_formulary'|'policy_review_required',
     *     formularyLabel:string,
     *     restrictionNote:string|null,
     *     substitutionAllowed:bool,
     *     indicationKeywords:list<string>,
     *     preferredAlternatives:list<string>,
     *     preferredAlternativeGroups:list<array{keywords:list<string>, alternatives:list<string>}>
     * }
     */
    public static function profile(?array $catalogItem): array
    {
        $metadata = self::metadata($catalogItem);

        $reviewMode = self::stringValue($metadata, [
            'reviewMode',
            'review_mode',
            'policyReviewMode',
            'policy_review_mode',
        ]);

        $formularyStatus = self::stringValue($metadata, [
            'formularyStatus',
            'formulary_status',
            'formularyClassification',
            'formulary_classification',
        ]);

        $requiresPolicyReview = in_array($reviewMode, ['policy_review_required', 'restricted'], true)
            || in_array($formularyStatus, ['restricted', 'requires_review'], true);

        return [
            'reviewMode' => $requiresPolicyReview ? 'policy_review_required' : 'auto_formulary',
            'formularyLabel' => $requiresPolicyReview ? 'Restricted review' : 'Formulary',
            'restrictionNote' => self::stringValue($metadata, [
                'restrictionReason',
                'restriction_reason',
                'policyNote',
                'policy_note',
            ]),
            'substitutionAllowed' => self::boolValue($metadata, [
                'substitutionAllowed',
                'substitution_allowed',
            ]),
            'indicationKeywords' => self::stringList($metadata, [
                'indicationKeywords',
                'indication_keywords',
                'allowedIndicationKeywords',
                'allowed_indication_keywords',
            ]),
            'preferredAlternatives' => self::stringList($metadata, [
                'preferredAlternatives',
                'preferred_alternatives',
            ]),
            'preferredAlternativeGroups' => self::alternativeGroups($metadata, [
                'preferredAlternativesByIndication',
                'preferred_alternatives_by_indication',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     * @return array<string, mixed>
     */
    public static function draftPolicyDefaults(?array $catalogItem): array
    {
        $profile = self::profile($catalogItem);

        return [
            'formulary_decision_status' => $profile['reviewMode'] === 'policy_review_required'
                ? 'not_reviewed'
                : 'formulary',
            'formulary_decision_reason' => null,
            'formulary_reviewed_at' => null,
            'formulary_reviewed_by_user_id' => null,
            'substitution_allowed' => $profile['substitutionAllowed'],
            'substitution_made' => false,
            'substituted_medication_code' => null,
            'substituted_medication_name' => null,
            'substitution_reason' => null,
            'substitution_approved_at' => null,
            'substitution_approved_by_user_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     */
    public static function requiresPolicyReview(?array $catalogItem): bool
    {
        return self::profile($catalogItem)['reviewMode'] === 'policy_review_required';
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     */
    public static function preferredAlternativesSummary(?array $catalogItem, ?string $clinicalIndication = null): ?string
    {
        $alternatives = self::preferredAlternativesForIndication($catalogItem, $clinicalIndication);
        if ($alternatives === []) {
            return null;
        }

        return implode(', ', $alternatives);
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     */
    public static function indicationNeedsClarification(?array $catalogItem, ?string $clinicalIndication): bool
    {
        $keywords = self::profile($catalogItem)['indicationKeywords'];
        $normalizedIndication = self::normalizeText($clinicalIndication);

        if ($keywords === [] || $normalizedIndication === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedIndication, self::normalizeText($keyword))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     * @param  array<string, mixed>|null  $order
     * @return array{
     *     key:string,
     *     severity:'info'|'warning',
     *     title:string,
     *     summary:string,
     *     suggestedDecisionStatus:'not_reviewed'|'formulary'|'non_formulary'|'restricted',
     *     suggestedDecisionReason:?string,
     *     requiresDecisionReason:bool,
     *     substitutionRecommended:bool,
     *     suggestedSubstitutionSelectionMode:'approved'|'outside_list'|null,
     *     preferredAlternatives:list<string>,
     *     indicationMatched:bool|null,
     *     indicationKeywords:list<string>,
     *     restrictionNote:?string
     * }
     */
    public static function policyRecommendation(
        ?array $catalogItem,
        ?string $clinicalIndication,
        ?array $order = null,
    ): array {
        $profile = self::profile($catalogItem);
        $preferredAlternatives = self::preferredAlternativesForIndication($catalogItem, $clinicalIndication);
        $indicationKeywords = $profile['indicationKeywords'];
        $normalizedIndication = self::normalizeText($clinicalIndication);
        $hasIndicationKeywords = $indicationKeywords !== [];
        $indicationNeedsClarification = $hasIndicationKeywords
            ? ($normalizedIndication === '' || self::indicationNeedsClarification($catalogItem, $clinicalIndication))
            : false;
        $indicationMatched = $hasIndicationKeywords
            ? ($normalizedIndication === '' ? null : ! $indicationNeedsClarification)
            : null;

        $formularyDecision = self::normalizeText($order['formulary_decision_status'] ?? 'not_reviewed');
        $substitutionMade = (bool) ($order['substitution_made'] ?? false);
        $substitutionAllowed = array_key_exists('substitution_allowed', $order ?? [])
            && ($order['substitution_allowed'] ?? null) !== null
            ? (bool) $order['substitution_allowed']
            : $profile['substitutionAllowed'];

        if (
            $formularyDecision === 'non_formulary'
            && ! $substitutionMade
            && $preferredAlternatives !== []
            && $substitutionAllowed
        ) {
            return [
                'key' => 'use_preferred_alternative',
                'severity' => 'warning',
                'title' => 'Use a preferred approved alternative before release.',
                'summary' => 'This order is currently outside the approved list. Choose one of the preferred approved alternatives or record the exception reason clearly.',
                'suggestedDecisionStatus' => 'non_formulary',
                'suggestedDecisionReason' => 'Requested medicine remains outside the approved list while pharmacy reviews the preferred alternative path.',
                'requiresDecisionReason' => true,
                'substitutionRecommended' => true,
                'suggestedSubstitutionSelectionMode' => 'approved',
                'preferredAlternatives' => $preferredAlternatives,
                'indicationMatched' => $indicationMatched,
                'indicationKeywords' => $indicationKeywords,
                'restrictionNote' => $profile['restrictionNote'],
            ];
        }

        if ($profile['reviewMode'] === 'policy_review_required') {
            if ($indicationNeedsClarification) {
                return [
                    'key' => 'clarify_restricted_indication',
                    'severity' => 'warning',
                    'title' => 'Clarify the indication before approving this restricted medicine.',
                    'summary' => $hasIndicationKeywords
                        ? 'The documented indication does not clearly fit the restricted-use scope. Keep the order under restricted review until the indication is clarified or an exception is documented.'
                        : 'This medicine remains under restricted review until the clinical indication is confirmed.',
                    'suggestedDecisionStatus' => 'restricted',
                    'suggestedDecisionReason' => 'Restricted-use indication needs clarification before approval.',
                    'requiresDecisionReason' => true,
                    'substitutionRecommended' => $preferredAlternatives !== [] && $profile['substitutionAllowed'],
                    'suggestedSubstitutionSelectionMode' => $preferredAlternatives !== [] && $profile['substitutionAllowed']
                        ? 'approved'
                        : null,
                    'preferredAlternatives' => $preferredAlternatives,
                    'indicationMatched' => $indicationMatched,
                    'indicationKeywords' => $indicationKeywords,
                    'restrictionNote' => $profile['restrictionNote'],
                ];
            }

            return [
                'key' => 'approve_restricted_use_if_appropriate',
                'severity' => 'warning',
                'title' => 'Restricted-use guidance is aligned and ready for pharmacist decision.',
                'summary' => $profile['restrictionNote'] !== null
                    ? 'The recorded indication fits the restricted-use guidance. Approve the medicine if it is clinically appropriate, or choose a preferred alternative when policy or stock requires it. '.$profile['restrictionNote']
                    : 'The recorded indication fits the restricted-use guidance. Approve the medicine if it is clinically appropriate, or choose a preferred alternative when policy or stock requires it.',
                'suggestedDecisionStatus' => 'formulary',
                'suggestedDecisionReason' => null,
                'requiresDecisionReason' => false,
                'substitutionRecommended' => false,
                'suggestedSubstitutionSelectionMode' => null,
                'preferredAlternatives' => $preferredAlternatives,
                'indicationMatched' => $indicationMatched,
                'indicationKeywords' => $indicationKeywords,
                'restrictionNote' => $profile['restrictionNote'],
            ];
        }

        return [
            'key' => 'formulary_path_clear',
            'severity' => 'info',
            'title' => 'The approved-medicines path is clear.',
            'summary' => $preferredAlternatives !== []
                ? 'This medicine can stay on the approved list. Use a preferred alternative only if stock, policy, or substitution needs change.'
                : 'This medicine can stay on the approved list. Policy review only needs updating if substitution or a governance exception changes the release path.',
            'suggestedDecisionStatus' => 'formulary',
            'suggestedDecisionReason' => null,
            'requiresDecisionReason' => false,
            'substitutionRecommended' => false,
            'suggestedSubstitutionSelectionMode' => null,
            'preferredAlternatives' => $preferredAlternatives,
            'indicationMatched' => $indicationMatched,
            'indicationKeywords' => $indicationKeywords,
            'restrictionNote' => $profile['restrictionNote'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     */
    public static function isPreferredAlternativeMatch(
        ?array $catalogItem,
        ?string $medicationCode,
        ?string $medicationName,
    ): bool {
        $alternatives = array_map(
            static fn (string $item): string => self::normalizeText($item),
            self::allPreferredAlternatives(self::profile($catalogItem)),
        );

        if ($alternatives === []) {
            return false;
        }

        $code = self::normalizeText($medicationCode);
        $name = self::normalizeText($medicationName);

        foreach ($alternatives as $alternative) {
            if ($alternative === '') {
                continue;
            }

            if ($code !== '' && $alternative === $code) {
                return true;
            }

            if ($name !== '' && $alternative === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public static function workflowBlocked(array $order): bool
    {
        $formularyDecision = self::normalizeText($order['formulary_decision_status'] ?? 'not_reviewed');
        $substitutionMade = (bool) ($order['substitution_made'] ?? false);

        if ($formularyDecision === 'non_formulary' && $substitutionMade) {
            return false;
        }

        return in_array($formularyDecision, ['not_reviewed', 'non_formulary', 'restricted'], true);
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     * @return array<string, mixed>
     */
    private static function metadata(?array $catalogItem): array
    {
        $metadata = $catalogItem['metadata'] ?? null;
        $baseMetadata = is_array($metadata) ? $metadata : [];

        return array_replace_recursive(
            self::knownMedicineDefaults($catalogItem),
            $baseMetadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $keys
     */
    private static function stringValue(array $metadata, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $keys
     */
    private static function boolValue(array $metadata, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (is_bool($value)) {
                return $value;
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['true', '1', 'yes'], true)) {
                    return true;
                }

                if (in_array($normalized, ['false', '0', 'no'], true)) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function stringList(array $metadata, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;

            if (is_array($value)) {
                return array_values(array_filter(
                    array_map(
                        static fn (mixed $item): string => trim((string) $item),
                        $value,
                    ),
                    static fn (string $item): bool => $item !== '',
                ));
            }

            if (is_string($value) && trim($value) !== '') {
                return array_values(array_filter(
                    array_map(
                        static fn (string $item): string => trim($item),
                        explode(',', $value),
                    ),
                    static fn (string $item): bool => $item !== '',
                ));
            }
        }

        return [];
    }

    private static function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }

    /**
     * @param array<string, mixed>|null $catalogItem
     * @return array<string, mixed>
     */
    private static function knownMedicineDefaults(?array $catalogItem): array
    {
        $code = self::normalizeText($catalogItem['code'] ?? null);
        $name = self::normalizeText($catalogItem['name'] ?? null);

        if ($code === 'med-oxyt-10inj' || str_contains($name, 'oxytocin')) {
            return [
                'reviewMode' => 'policy_review_required',
                'restrictionReason' => 'Restricted to maternity workflows, postpartum hemorrhage management, labor induction, or closely related obstetric use with explicit clinical justification.',
                'allowedIndicationKeywords' => [
                    'postpartum',
                    'hemorrhage',
                    'haemorrhage',
                    'uterine atony',
                    'labor',
                    'labour',
                    'induction',
                    'augmentation',
                    'retained placenta',
                ],
                'substitutionAllowed' => false,
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed>|null $catalogItem
     * @return list<string>
     */
    private static function preferredAlternativesForIndication(?array $catalogItem, ?string $clinicalIndication): array
    {
        $profile = self::profile($catalogItem);
        $normalizedIndication = self::normalizeText($clinicalIndication);
        if ($normalizedIndication === '') {
            return $profile['preferredAlternatives'];
        }

        $matchedAlternatives = [];

        foreach ($profile['preferredAlternativeGroups'] as $group) {
            if (! self::indicationContainsAnyKeyword($normalizedIndication, $group['keywords'])) {
                continue;
            }

            foreach ($group['alternatives'] as $alternative) {
                $trimmedAlternative = trim($alternative);
                if ($trimmedAlternative === '') {
                    continue;
                }

                $matchedAlternatives[] = $trimmedAlternative;
            }
        }

        if ($matchedAlternatives !== []) {
            return array_values(array_unique($matchedAlternatives));
        }

        return $profile['preferredAlternatives'];
    }

    /**
     * @param array{
     *     preferredAlternatives:list<string>,
     *     preferredAlternativeGroups:list<array{keywords:list<string>, alternatives:list<string>}>
     * } $profile
     * @return list<string>
     */
    private static function allPreferredAlternatives(array $profile): array
    {
        $alternatives = $profile['preferredAlternatives'];

        foreach ($profile['preferredAlternativeGroups'] as $group) {
            foreach ($group['alternatives'] as $alternative) {
                $trimmedAlternative = trim($alternative);
                if ($trimmedAlternative === '') {
                    continue;
                }

                $alternatives[] = $trimmedAlternative;
            }
        }

        return array_values(array_unique($alternatives));
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $keys
     * @return list<array{keywords:list<string>, alternatives:list<string>}>
     */
    private static function alternativeGroups(array $metadata, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (! is_array($value)) {
                continue;
            }

            $groups = [];

            foreach ($value as $groupKey => $groupValue) {
                if (is_array($groupValue) && array_is_list($value)) {
                    $keywords = self::normalizeStringList($groupValue['keywords'] ?? []);
                    $alternatives = self::normalizeStringList($groupValue['alternatives'] ?? []);
                } else {
                    $keywords = self::normalizeStringList($groupKey);
                    $alternatives = self::normalizeStringList($groupValue);
                }

                if ($keywords === [] || $alternatives === []) {
                    continue;
                }

                $groups[] = [
                    'keywords' => $keywords,
                    'alternatives' => $alternatives,
                ];
            }

            return $groups;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(
                    static fn (mixed $item): string => trim((string) $item),
                    $value,
                ),
                static fn (string $item): bool => $item !== '',
            ));
        }

        if (is_string($value) && trim($value) !== '') {
            return array_values(array_filter(
                array_map(
                    static fn (string $item): string => trim($item),
                    explode(',', $value),
                ),
                static fn (string $item): bool => $item !== '',
            ));
        }

        return [];
    }

    /**
     * @param list<string> $keywords
     */
    private static function indicationContainsAnyKeyword(string $normalizedIndication, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $normalizedKeyword = self::normalizeText($keyword);
            if ($normalizedKeyword !== '' && str_contains($normalizedIndication, $normalizedKeyword)) {
                return true;
            }
        }

        return false;
    }
}
