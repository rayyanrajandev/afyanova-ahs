@php
    $brandingManager = app(\App\Support\Branding\SystemBrandingManager::class);
@endphp

{{ $brandingManager->mailFooterText() }}
