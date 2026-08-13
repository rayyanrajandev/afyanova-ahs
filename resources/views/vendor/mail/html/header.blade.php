@props(['url'])

@php
    $brandingManager = app(\App\Support\Branding\SystemBrandingManager::class);
    $systemName = $brandingManager->systemName();
    $logoUrl = $brandingManager->emailHeaderLogoUrl();
@endphp

<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ $logoUrl }}" class="logo" alt="{{ $systemName }} logo">
<div style="margin-top: 10px; color: #0f172a; font-size: 13px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;">
{{ $systemName }}
</div>
</a>
</td>
</tr>
