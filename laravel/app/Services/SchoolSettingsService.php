<?php

namespace App\Services;

use App\Models\School;
use App\Models\StoredFile;
use App\Support\Tenant;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * School profile settings, including logo and principal signature image uploads.
 */
class SchoolSettingsService
{
    public const DISK = 'local';

    public const PURPOSE_LOGO = 'SCHOOL_LOGO';

    public const PURPOSE_SIGNATURE = 'PRINCIPAL_SIGNATURE';

    public const MAX_IMAGE_KB = 2048;

    /** @var list<string> */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * @param  array{name:string,address?:string,email?:string,phone?:string,motto?:string,primary_color:string}  $data
     */
    public function update(School $school, array $data, ?UploadedFile $logo = null, ?UploadedFile $signature = null, bool $clearLogo = false, bool $clearSignature = false): School
    {
        return DB::transaction(function () use ($school, $data, $logo, $signature, $clearLogo, $clearSignature) {
            if ($clearLogo) {
                $this->detachImage($school, 'logo_file_id');
            } elseif ($logo) {
                $this->replaceImage($school, 'logo_file_id', $logo, self::PURPOSE_LOGO);
            }

            if ($clearSignature) {
                $this->detachImage($school, 'principal_signature_file_id');
            } elseif ($signature) {
                $this->replaceImage($school, 'principal_signature_file_id', $signature, self::PURPOSE_SIGNATURE);
            }

            $school->update($data);
            $school->refresh()->load(['logo', 'principalSignature']);

            AuditService::record('school.settings_updated', 'School', $school->getKey(), [
                'fields' => array_keys($data),
                'logo' => $logo ? 'replaced' : ($clearLogo ? 'cleared' : 'unchanged'),
                'signature' => $signature ? 'replaced' : ($clearSignature ? 'cleared' : 'unchanged'),
            ]);

            return $school;
        });
    }

    public function dataUri(?StoredFile $file): ?string
    {
        if (! $file || ! Storage::disk(self::DISK)->exists($file->storage_key)) {
            return null;
        }

        $bytes = Storage::disk(self::DISK)->get($file->storage_key);
        $mime = $file->mime_type ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function replaceImage(School $school, string $column, UploadedFile $file, string $purpose): void
    {
        $this->assertImage($file);

        $oldId = $school->{$column};
        $stored = $this->storeImage($file, $purpose);
        $school->{$column} = $stored->getKey();
        $school->save();

        if ($oldId) {
            $this->deleteStoredFile($oldId);
        }
    }

    private function detachImage(School $school, string $column): void
    {
        $oldId = $school->{$column};
        if (! $oldId) {
            return;
        }

        $school->{$column} = null;
        $school->save();
        $this->deleteStoredFile($oldId);
    }

    private function assertImage(UploadedFile $file): void
    {
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        if (! in_array($mime, self::IMAGE_MIMES, true)) {
            throw new DomainException('Upload a JPEG, PNG, WebP or GIF image.');
        }

        if ($file->getSize() > self::MAX_IMAGE_KB * 1024) {
            throw new DomainException('Each image must be under '.self::MAX_IMAGE_KB.' KB.');
        }
    }

    private function storeImage(UploadedFile $file, string $purpose): StoredFile
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
        $folder = $purpose === self::PURPOSE_LOGO ? 'logo' : 'signature';
        $key = 'schools/'.Tenant::id().'/branding/'.$folder.'/'.Str::ulid().'.'.$extension;

        $meta = [
            'purpose' => $purpose,
            'storage_key' => $key,
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType() ?: 'image/png',
            'size' => (int) $file->getSize(),
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
        ];

        Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

        return StoredFile::create($meta);
    }

    private function deleteStoredFile(string $id): void
    {
        $file = StoredFile::query()->find($id);
        if (! $file) {
            return;
        }

        Storage::disk(self::DISK)->delete($file->storage_key);
        $file->delete();
    }
}
