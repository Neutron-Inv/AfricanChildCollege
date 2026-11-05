<?php

namespace App\Http\Controllers;

use App\Services\SchoolSettingsService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchoolLogoController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $school = Tenant::get();
        abort_unless($school, 404);

        $school->loadMissing('logo');
        $file = $school->logo;
        $disk = Storage::disk(SchoolSettingsService::DISK);
        abort_unless($file && $disk->exists($file->storage_key), 404);

        return $disk->response($file->storage_key, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
