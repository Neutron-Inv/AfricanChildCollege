<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\TimetableService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TimetableController extends Controller
{
    public function __construct(private readonly TimetableService $timetable) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $canManage = $this->timetable->canManage($user);

        $sessions = $this->timetable->sessions();
        $session = $sessions->firstWhere('id', $request->query('session'))
            ?? $sessions->firstWhere('is_current', true)
            ?? $sessions->first();

        $classes = $this->timetable->classesFor($user, $session?->getKey());

        // Teachers land on "my periods"; everyone else on the first class they can see.
        $wantsMine = $user->role === Role::TEACHER
            && ($request->query('class') === 'mine' || ! $request->filled('class'));
        $class = $wantsMine ? null : ($classes->firstWhere('id', $request->query('class')) ?? $classes->first());

        $periods = collect();
        if ($class) {
            $periods = $this->timetable->periodsForClass($class->getKey());
        } elseif ($wantsMine && $user->staff_id && $session) {
            $periods = $this->timetable->periodsForTeacher($user->staff_id, $session->getKey());
        }

        $slots = $this->timetable->slots();
        $layout = $this->timetable->layout($slots);
        $useStructure = $class && $slots->isNotEmpty() && ! $wantsMine;

        return view('timetable.index', [
            'canManage' => $canManage,
            'sessions' => $sessions,
            'session' => $session,
            'classes' => $classes,
            'class' => $class,
            'showMine' => $wantsMine,
            'isTeacher' => $user->role === Role::TEACHER,
            'periods' => $periods,
            'grid' => $this->timetable->grid($periods),
            'classSubjects' => ($canManage || $useStructure) && $class ? $this->timetable->classSubjects($class->getKey()) : collect(),
            'rooms' => $canManage ? $this->timetable->rooms() : collect(),
            'days' => TimetableService::DAYS,
            'slots' => $slots,
            'layout' => $layout,
            'useStructure' => $useStructure,
            'selection' => $useStructure ? $this->timetable->selectionMap($periods, $layout) : [],
            'unmatched' => $useStructure ? $this->timetable->unmatchedPeriods($periods, $layout) : collect(),
        ]);
    }

    public function structure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'session' => ['nullable', 'string'],
            'class' => ['nullable', 'string'],
            'start' => ['required', 'date_format:H:i'],
            'period_minutes' => ['required', 'integer', 'min:5', 'max:180'],
            'periods' => ['required', 'integer', 'min:1', 'max:12'],
            'breaks' => ['nullable', 'array'],
            'breaks.*.after' => ['nullable', 'integer'],
            'breaks.*.minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'breaks.*.label' => ['nullable', 'string', 'max:40'],
            'friday_different' => ['nullable', 'boolean'],
            'friday_start' => ['nullable', 'required_if:friday_different,1', 'date_format:H:i'],
            'friday_period_minutes' => ['nullable', 'required_if:friday_different,1', 'integer', 'min:5', 'max:180'],
            'friday_periods' => ['nullable', 'required_if:friday_different,1', 'integer', 'min:1', 'max:12'],
            'friday_breaks' => ['nullable', 'array'],
            'friday_breaks.*.after' => ['nullable', 'integer'],
            'friday_breaks.*.minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'friday_breaks.*.label' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $friday = $request->boolean('friday_different') ? [
                'start' => $data['friday_start'],
                'period_minutes' => $data['friday_period_minutes'],
                'periods' => $data['friday_periods'],
                'breaks' => $data['friday_breaks'] ?? [],
            ] : null;
            $this->timetable->saveStructure([
                'start' => $data['start'],
                'period_minutes' => $data['period_minutes'],
                'periods' => $data['periods'],
                'breaks' => $data['breaks'] ?? [],
            ], $friday);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('timetable', array_filter(['session' => $data['session'] ?? null, 'class' => $data['class'] ?? null]))
            ->with('success', 'School day saved. Choose a subject for each period.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'session' => ['nullable', 'string'],
            'class_id' => ['required', 'string'],
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['array'],
            'assignments.*.*' => ['nullable', 'string'],
        ]);

        try {
            $this->timetable->saveAssignments($data['class_id'], $data['assignments'] ?? []);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('timetable', array_filter(['session' => $data['session'] ?? null, 'class' => $data['class_id']]))
            ->with('success', 'Timetable saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $period = $this->timetable->createPeriod($data);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->backToGrid($period->classSubject()->first(), 'Period added.');
    }

    public function update(Request $request, string $period): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $updated = $this->timetable->updatePeriod($period, $data);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->backToGrid($updated->classSubject()->first(), 'Period updated.');
    }

    public function destroy(string $period): RedirectResponse
    {
        try {
            $deleted = $this->timetable->deletePeriod($period);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->backToGrid($deleted->classSubject, 'Period deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'class_subject_id' => ['required', 'string'],
            'day' => ['required', Rule::in(array_keys(TimetableService::DAYS))],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'room_id' => ['nullable', 'string'],
        ]);
    }

    private function backToGrid($classSubject, string $message): RedirectResponse
    {
        $class = $classSubject?->class;

        return redirect()
            ->route('timetable', array_filter(['session' => $class?->session_id, 'class' => $class?->getKey()]))
            ->with('success', $message);
    }
}
