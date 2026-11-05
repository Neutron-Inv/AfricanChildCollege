<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the school shell: a school must be resolved (host or "entered" by the
 * platform owner) and the signed-in user must belong to it.
 */
class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! Tenant::check()) {
            if ($user?->isPlatformOwner()) {
                return redirect()->route('platform.schools');
            }

            return $this->logout($request);
        }

        // Session cookies may be shared across subdomains; never cross schools.
        if (! $user->isPlatformOwner() && $user->school_id !== Tenant::id()) {
            return $this->logout($request);
        }

        return $next($request);
    }

    private function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
