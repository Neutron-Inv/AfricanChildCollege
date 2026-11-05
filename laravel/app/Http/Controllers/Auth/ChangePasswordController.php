<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => $validated['password'], // hashed by the model cast
            'must_change_password' => false,
        ])->save();

        AuditService::record('auth.password_changed', 'User', $user->getKey());

        return redirect()
            ->route($user->isPlatformOwner() ? 'platform.schools' : 'dashboard')
            ->with('success', 'Your password has been changed.');
    }
}
