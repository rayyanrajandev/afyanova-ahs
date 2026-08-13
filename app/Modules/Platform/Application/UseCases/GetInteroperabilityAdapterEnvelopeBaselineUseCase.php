<?php

namespace App\Modules\Platform\Application\UseCases;

class GetInteroperabilityAdapterEnvelopeBaselineUseCase
{
    /**
     * @return array<string, mixed>|null
     */
    public function execute(?string $version): ?array
    {
        $registry = config('platform_interoperability.adapter_envelopes', []);
        if (! is_array($registry) || $registry === []) {
            return null;
        }

        $requestedVersion = $this->normalizeVersion($version);
        if ($requestedVersion === null) {
            $requestedVersion = $this->normalizeVersion((string) config('platform_interoperability.default_version', 'v1'));
        }

        if ($requestedVersion === null || ! isset($registry[$requestedVersion]) || ! is_array($registry[$requestedVersion])) {
            return null;
        }

        $baseline = $registry[$requestedVersion];

        return [
            'version' => $requestedVersion,
            'eventTypePattern' => $baseline['eventTypePattern'] ?? null,
            'envelope' => is_array($baseline['envelope'] ?? null) ? $baseline['envelope'] : [],
            'priorityFlows' => is_array($baseline['priorityFlows'] ?? null) ? $baseline['priorityFlows'] : [],
            'nonFunctionalControls' => is_array($baseline['nonFunctionalControls'] ?? null)
                ? $baseline['nonFunctionalControls']
                : [],
        ];
    }

    private function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $normalized = strtolower(trim($version));

        return $normalized !== '' ? $normalized : null;
    }
}
