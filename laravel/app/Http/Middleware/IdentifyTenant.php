<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\School;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    /** Subdomains that mean "platform / no school". */
    private const PLATFORM_SUBDOMAINS = ['', 'admin', 'www', 'app', 'localhost'];

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = $this->subdomain($request->getHost());

        if (in_array($subdomain, self::PLATFORM_SUBDOMAINS, true)) {
            Tenant::clear();
            $request->attributes->set('tenant_mode', 'platform');

            // Platform owner "entering" a school via session.
            $user = Auth::user();
            if ($user && $user->role === Role::PLATFORM_OWNER) {
                $activeId = Tenant::activeSchoolId();
                if ($activeId && ($school = School::find($activeId))) {
                    Tenant::set($school);
                    $request->attributes->set('tenant_entered', true);
                }
            }
        } else {
            $school = School::query()
                ->where('subdomain', $subdomain)
                ->orWhere('slug', $subdomain)
                ->first();

            if (! $school) {
                abort(404, 'School not found.');
            }

            Tenant::set($school);
            $request->attributes->set('tenant_mode', 'school');
        }

        try {
            return $next($request);
        } finally {
            // Avoid leaking tenant between requests (Octane / tests).
            Tenant::clear();
        }
    }

    /**
     * Extract the school subdomain from a host. Returns '' when there is none.
     */
    private function subdomain(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || str_starts_with($host, '[')) {
            return '';
        }

        $appDomain = strtolower((string) config('app.domain', ''));

        if ($appDomain !== '') {
            if ($host === $appDomain) {
                return '';
            }
            if (str_ends_with($host, '.'.$appDomain)) {
                return $this->firstLabel(substr($host, 0, -strlen('.'.$appDomain)));
            }
        }

        // e.g. greenfield.localhost
        if (str_ends_with($host, '.localhost')) {
            return $this->firstLabel(substr($host, 0, -strlen('.localhost')));
        }

        if ($host === 'localhost') {
            return '';
        }

        // Fallback: sub.example.com -> sub ; example.com -> ''
        $labels = explode('.', $host);

        return count($labels) >= 3 ? $labels[0] : '';
    }

    private function firstLabel(string $prefix): string
    {
        // For "a.b" take the left-most label
        return explode('.', $prefix)[0];
    }
}
