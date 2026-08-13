@props([
    'branding',
    'eyebrow' => 'Document',
    'title',
    'subtitle' => null,
    'documentNumber' => null,
    'statusLabel' => null,
    'generatedAt' => null,
])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; line-height: 1.45; margin: 0; }
        .page { width: 100%; }
        .header { border: 1px solid #d8dee8; background: #ffffff; padding: 14px; }
        .header-table, .grid-table, .two-col, .stats-grid { width: 100%; border-collapse: collapse; }
        .header-logo { width: 58px; vertical-align: top; }
        .logo-box { width: 46px; height: 46px; border: 1px solid #d8dee8; background: #ffffff; text-align: center; }
        .logo-box img { max-width: 34px; max-height: 34px; margin-top: 6px; }
        .brand-fallback { font-size: 16px; font-weight: bold; line-height: 46px; color: #164e63; }
        .eyebrow { font-size: 10px; text-transform: uppercase; letter-spacing: 0.24em; color: #64748b; margin-bottom: 6px; }
        .title { font-size: 20px; font-weight: bold; margin: 0; }
        .subtitle { margin-top: 6px; color: #475569; }
        .meta-card { border: 1px solid #d8dee8; background: #ffffff; padding: 10px; }
        .meta-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.18em; color: #64748b; }
        .meta-value { margin-top: 4px; font-size: 12px; font-weight: bold; }
        .section { margin-top: 12px; border: 1px solid #d8dee8; padding: 12px; }
        .section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 0.24em; color: #64748b; margin: 0 0 4px; }
        .section-subtitle { font-size: 14px; font-weight: bold; margin: 0 0 8px; }
        .card { border: 1px solid #d8dee8; background: #ffffff; padding: 10px; }
        .card-title { font-size: 12px; font-weight: bold; margin: 0 0 6px; }
        .muted { color: #64748b; }
        .small { font-size: 11px; }
        .badge { display: inline-block; padding: 4px 9px; border-radius: 999px; border: 1px solid #cbd5e1; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; }
        .badge.good { background: #ecfdf5; color: #166534; border-color: #bbf7d0; }
        .badge.warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .badge.blocked { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .stats-grid td { width: 50%; vertical-align: top; padding: 4px; }
        .two-col td { width: 50%; vertical-align: top; padding: 4px; }
        .grid-table td { vertical-align: top; padding: 4px; }
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 3px 0; vertical-align: top; }
        .kv .k { color: #64748b; width: 42%; }
        .kv .v { text-align: right; font-weight: bold; }
        .table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .table th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.12em; color: #64748b; padding: 6px; border-bottom: 1px solid #d8dee8; }
        .table td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .footer { margin-top: 12px; padding-top: 8px; border-top: 1px solid #d8dee8; color: #475569; font-size: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="header-logo">
                        <div class="logo-box">
                            @if(!empty($branding['logoDataUri']))
                                <img src="{{ $branding['logoDataUri'] }}" alt="{{ $branding['systemName'] }} logo">
                            @else
                                <div class="brand-fallback">A</div>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div class="eyebrow">{{ $eyebrow }}</div>
                        <p class="title">{{ $title }}</p>
                        @if($subtitle)
                            <div class="subtitle">{{ $subtitle }}</div>
                        @endif
                    </td>
                    <td style="width: 220px; vertical-align: top;">
                        <div class="meta-card">
                            <div class="meta-label">Issued By</div>
                            <div class="meta-value">{{ $branding['issuedByName'] ?? $branding['systemName'] }}</div>
                            @if($documentNumber)
                                <div class="meta-label" style="margin-top: 12px;">Document No.</div>
                                <div class="meta-value">{{ $documentNumber }}</div>
                            @endif
                            @if($statusLabel)
                                <div class="meta-label" style="margin-top: 12px;">Status</div>
                                <div class="meta-value">{{ $statusLabel }}</div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{ $slot }}

        <div class="footer">
            <div><strong>{{ $branding['systemName'] ?? 'System' }}</strong></div>
            @if(!empty($branding['supportEmail']))
                <div>Support: {{ $branding['supportEmail'] }}</div>
            @endif
            <div>{{ $branding['footerText'] ?? '' }}</div>
            @if($generatedAt)
                <div class="small muted" style="margin-top: 6px;">Generated {{ $generatedAt }}</div>
            @endif
        </div>
    </div>
</body>
</html>
