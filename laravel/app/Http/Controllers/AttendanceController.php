<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\AttendanceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Students and parents only see their own history.
        if ($user->hasRole(Role::STUDENT, Role::PARENT)) {
            return view('attendance.index', [
                'mode' => 'history',
                'history' => $this->attendance->mine($user),
                'statuses' => AttendanceService::STATUSES,
            ]);
        }

        $classes = $this->attendance->classesFor($user);

        $requested = $request->query('class');
        if ($requested && ! $classes->contains('id', $requested)) {
            // A class the user is not allowed to open (or that does not exist).
            abort(403, 'Only the form teacher or the office can open this register.');
        }
        $class = $classes->firstWhere('id', $requested) ?? $classes->first();

        $dateError = null;
        try {
            $date = $this->attendance->parseDate($request->query('date', now()->toDateString()));
        } catch (DomainException $e) {
            $dateError = $e->getMessage();
            $date = now()->startOfDay();
        }

        return view('attendance.index', [
            'mode' => 'register',
            'classes' => $classes,
            'class' => $class,
            'date' => $date,
            'dateError' => $dateError,
            'rows' => $class ? $this->attendance->register($class, $date) : collect(),
            'canSave' => $class ? $this->attendance->canMark($user, $class) : false,
            'statuses' => AttendanceService::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'marks' => ['nullable', 'array'],
            'marks.*' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $class = $this->attendance->findClass($data['class_id']);
        abort_unless($class && $this->attendance->canMark($user, $class), 403, 'Only the form teacher or the office can save this register.');

        try {
            $date = $this->attendance->parseDate($data['date']);
            $count = $this->attendance->save($user, $class, $date, $data['marks'] ?? []);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('attendance', ['class' => $class->getKey(), 'date' => $date->toDateString()])
            ->with('success', $count === 1 ? 'Attendance saved for 1 student.' : "Attendance saved for {$count} students.");
    }
}
