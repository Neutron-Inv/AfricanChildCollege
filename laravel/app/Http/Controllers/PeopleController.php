<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\PeopleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PeopleController extends Controller
{
    private const TABS = ['students', 'staff', 'guardians'];

    public function __construct(private PeopleService $people)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $teacherView = $user->hasRole(Role::TEACHER) && ! $user->isPlatformOwner();
        $teacherStaffId = $teacherView ? (string) ($user->staff_id ?? '') : null;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'students';
        if ($teacherView && $tab === 'staff') {
            $tab = 'students';
        }
        $status = ! $teacherView && in_array($request->query('status'), ['LEFT', 'GRADUATED'], true)
            ? $request->query('status')
            : 'ACTIVE';
        $scope = $request->query('scope') === 'all' ? 'all' : 'class';
        $q = trim((string) $request->query('q', ''));

        $data = [
            'tab' => $tab,
            'status' => $status,
            'scope' => $scope,
            'q' => $q,
            'classId' => (string) $request->query('class', ''),
            'canEditStudents' => $user->hasRole(Role::ADMIN, Role::REGISTRAR, Role::PLATFORM_OWNER),
            'canCreateStaff' => $user->hasRole(Role::ADMIN, Role::PLATFORM_OWNER),
            'canSetPasswords' => $user->hasRole(Role::ADMIN, Role::PLATFORM_OWNER),
            'teacherView' => $teacherView,
            'currentSession' => $this->people->currentSession(),
            'staffRoles' => PeopleService::STAFF_ROLES,
        ];

        if ($tab === 'students') {
            $data['classes'] = $teacherView ? collect() : $this->people->classOptions();
            $data['students'] = $this->people->students($status, $data['classId'] ?: null, $q, $teacherStaffId, $scope);
            $data['selected'] = ($id = $request->query('student')) ? $this->people->findStudent((string) $id, $teacherStaffId) : null;
        } elseif ($tab === 'staff') {
            $kind = in_array($request->query('kind'), ['teacher', 'other'], true) ? $request->query('kind') : 'all';
            $data['staffKind'] = $kind;
            $data['staff'] = $this->people->staff($q, $kind === 'all' ? null : $kind);
            $data['selectedStaff'] = ($id = $request->query('staff')) ? $this->people->findStaff((string) $id) : null;
        } else {
            $data['guardians'] = $this->people->searchGuardians($q, $teacherStaffId);
            $picked = $q !== '' ? (string) $request->query('guardian', '') : '';
            $data['selectedGuardian'] = $data['guardians']->firstWhere('id', $picked) ?? ($q !== '' ? $data['guardians']->first() : null);
        }

        return view('people.index', $data);
    }
}
