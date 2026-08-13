@php
    $brandingManager = app(\App\Support\Branding\SystemBrandingManager::class);
    $footerText = $brandingManager->mailFooterText();
@endphp

<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{!! Illuminate\Mail\Markdown::parse($footerText) !!}
</td>
</tr>
</table>
</td>
</tr>
