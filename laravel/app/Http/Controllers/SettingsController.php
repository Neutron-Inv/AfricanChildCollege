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
        $school?->load(['logo', 'principalSignature']);

        return view('settings', [
            'school' => $school,
            'logoPreview' => $this->settings->dataUri($school?->logo),
            'signaturePreview' => $this->settings->dataUri($school?->principalSignature),
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
            'clear_logo' => ['sometimes', 'boolean'],
            'clear_signature' => ['sometimes', 'boolean'],
        ]);

        foreach (['address', 'email', 'phone', 'motto'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        $data['primary_color'] = strtolower($data['primary_color']);

        $clearLogo = $request->boolean('clear_logo');
        $clearSignature = $request->boolean('clear_signature');
        unset($data['logo'], $data['principal_signature'], $data['clear_logo'], $data['clear_signature']);

        try {
            $this->settings->update(
                Tenant::get(),
                $data,
                $request->file('logo'),
                $request->file('principal_signature'),
                $clearLogo,
                $clearSignature,
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('settings')->with('success', 'Settings saved.');
    }
}
