<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Presentation\Http\Requests\UpdateSystemBrandingRequest;
use App\Support\Branding\SystemBrandingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformBrandingController extends Controller
{
    public function update(
        UpdateSystemBrandingRequest $request,
        SystemBrandingManager $brandingManager,
    ): JsonResponse {
        $branding = $brandingManager->update([
            'systemName' => $request->string('systemName')->value(),
            'shortName' => $request->input('shortName'),
            'logoFile' => $request->file('logo'),
            'removeLogo' => $request->boolean('removeLogo'),
            'appIconFile' => $request->file('appIcon'),
            'removeAppIcon' => $request->boolean('removeAppIcon'),
            'mailFromName' => $request->input('mailFromName'),
            'mailFromAddress' => $request->input('mailFromAddress'),
            'mailReplyToAddress' => $request->input('mailReplyToAddress'),
            'mailFooterText' => $request->input('mailFooterText'),
        ]);

        return response()->json([
            'data' => $branding,
        ]);
    }

    public function logo(SystemBrandingManager $brandingManager): StreamedResponse
    {
        $logoPath = $brandingManager->logoPath();
        abort_if($logoPath === null, 404, 'Brand logo not found.');

        return Storage::disk('local')->response($logoPath, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function icon(SystemBrandingManager $brandingManager): StreamedResponse|BinaryFileResponse
    {
        $iconPath = $brandingManager->appIconPath();

        if ($iconPath !== null) {
            return Storage::disk('local')->response($iconPath, null, [
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $defaultIconPath = public_path('apple-touch-icon.png');
        abort_unless(is_file($defaultIconPath), 404, 'Brand icon not found.');

        return response()->file($defaultIconPath, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
