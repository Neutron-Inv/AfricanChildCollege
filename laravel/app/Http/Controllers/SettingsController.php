<?php

namespace App\Http\Controllers;

use App\Services\SchoolSettingsService;
use App\Support\Tenant;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly SchoolSettingsService $settings) {}

    public function show(): View
    {
        $school = Tenant::get();
        $school?->load(['logo', 'principalSignature', 'secondSignature']);

        return view('settings', [
            'school' => $school,
            'logoPreview' => $this->settings->dataUri($school?->logo),
            'signaturePreview' => $this->settings->dataUri($school?->principalSignature),
            'secondSignaturePreview' => $this->settings->dataUri($school?->secondSignature),
            'maxImageKb' => SchoolSettingsService::MAX_IMAGE_KB,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'motto' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'file', 'image', 'max:'.SchoolSettingsService::MAX_IMAGE_KB],
            'principal_signature' => ['nullable', 'file', 'image', 'max:'.SchoolSettingsService::MAX_IMAGE_KB],
            'principal_signature_label' => ['nullable', 'string', 'max:80'],
            'second_signature' => ['nullable', 'file', 'image', 'max:'.SchoolSettingsService::MAX_IMAGE_KB],
            'second_signature_label' => ['nullable', 'string', 'max:80'],
            'clear_logo' => ['sometimes', 'boolean'],
            'clear_signature' => ['sometimes', 'boolean'],
            'clear_second_signature' => ['sometimes', 'boolean'],
        ]);

        foreach (['address', 'email', 'phone', 'motto'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        $data['primary_color'] = strtolower($data['primary_color']);

        $data['principal_signature_label'] = trim($data['principal_signature_label'] ?? '');
        $data['second_signature_label'] = trim($data['second_signature_label'] ?? '');

        $clearLogo = $request->boolean('clear_logo');
        $clearSignature = $request->boolean('clear_signature');
        $clearSecondSignature = $request->boolean('clear_second_signature');
        unset($data['logo'], $data['principal_signature'], $data['second_signature'], $data['clear_logo'], $data['clear_signature'], $data['clear_second_signature']);

        try {
            $this->settings->update(
                Tenant::get(),
                $data,
                $request->file('logo'),
                $request->file('principal_signature'),
                $clearLogo,
                $clearSignature,
                $request->file('second_signature'),
                $clearSecondSignature,
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('settings')->with('success', 'Settings saved.');
    }
}
