<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\AuditService;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SchoolController extends Controller
{
    public function index(): View
    {
        // Counts must not be narrowed to a school the owner has "entered".
        $schools = Tenant::run(null, fn () => School::query()
            ->withCount(['users', 'staff', 'students'])
            ->orderBy('name')
            ->get());

        return view('platform.schools', ['schools' => $schools]);
    }

    /**
     * "Enter" a school: remember it in the session; IdentifyTenant picks it up
     * on the next request so the school shell works on the platform host.
     */
    public function enter(School $school): RedirectResponse
    {
        Tenant::setActiveSchoolId($school->getKey());

        Tenant::run($school, fn () => AuditService::record('platform.enter_school', 'School', $school->getKey()));

        return redirect()->route('dashboard');
    }

    public function leave(): RedirectResponse
    {
        Tenant::setActiveSchoolId(null);

        return redirect()->route('platform.schools');
    }
}
