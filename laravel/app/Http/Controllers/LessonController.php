<?php

namespace App\Http\Controllers;

use App\Models\LessonPlan;
use App\Services\LessonsService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function __construct(private readonly LessonsService $lessons) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('lessons.index', [
            'plans' => $this->lessons->list($user, $request->only(['status', 'class_subject_id'])),
            'offerings' => $this->lessons->filterOfferings($user),
            'statuses' => LessonsService::STATUSES,
            'canAuthor' => $this->lessons->canAuthor($user),
            'filters' => $request->only(['status', 'class_subject_id']),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->lessons->canAuthor($request->user()), 403, 'Only teachers can write lesson notes.');

        return $this->form(new LessonPlan, $request);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->lessons->canAuthor($request->user()), 403, 'Only teachers can write lesson notes.');

        try {
            $plan = $this->lessons->create($request->user(), $this->validated($request));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('lessons.show', $plan->getKey())->with('success', 'Lesson note saved as draft.');
    }

    public function show(Request $request, string $lesson): View
    {
        $user = $request->user();
        $plan = $this->lessons->find($user, $lesson) ?? abort(404);

        return view('lessons.show', [
            'plan' => $plan,
            'canEdit' => $this->lessons->canEdit($user, $plan),
            'canSubmit' => $this->lessons->canEdit($user, $plan),
            'canReview' => $plan->status === LessonsService::SUBMITTED && $this->lessons->canApprove($user, $plan),
        ]);
    }

    public function edit(Request $request, string $lesson): View
    {
        $user = $request->user();
        $plan = $this->lessons->find($user, $lesson) ?? abort(404);
        abort_unless($this->lessons->canEdit($user, $plan), 403, 'Only the author can edit a draft lesson note.');

        return $this->form($plan, $request);
    }

    public function update(Request $request, string $lesson): RedirectResponse
    {
        try {
            $plan = $this->lessons->update($request->user(), $lesson, $this->validated($request));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('lessons.show', $plan->getKey())->with('success', 'Lesson note updated.');
    }

    public function submit(Request $request, string $lesson): RedirectResponse
    {
        return $this->transition(fn () => $this->lessons->submit($request->user(), $lesson), 'Lesson note submitted for approval.');
    }

    public function approve(Request $request, string $lesson): RedirectResponse
    {
        return $this->transition(fn () => $this->lessons->approve($request->user(), $lesson), 'Lesson note approved.');
    }

    public function returnToDraft(Request $request, string $lesson): RedirectResponse
    {
        return $this->transition(fn () => $this->lessons->returnToDraft($request->user(), $lesson), 'Lesson note returned to the teacher.');
    }

    private function transition(\Closure $action, string $message): RedirectResponse
    {
        try {
            $plan = $action();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('lessons.show', $plan->getKey())->with('success', $message);
    }

    private function form(LessonPlan $plan, Request $request): View
    {
        return view('lessons.form', [
            'plan' => $plan,
            'offerings' => $this->lessons->offeringsFor($request->user()),
            'terms' => $this->lessons->terms(),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'class_subject_id' => ['required', 'string'],
            'term_id' => ['required', 'string'],
            'topic' => ['required', 'string', 'max:255'],
            'sub_topic' => ['nullable', 'string', 'max:255'],
            'week' => ['nullable', 'string', 'max:50'],
            'lesson_date' => ['nullable', 'date_format:Y-m-d'],
            'specific_objective' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:20000'],
            'materials' => ['nullable', 'string', 'max:5000'],
            'previous_knowledge' => ['nullable', 'string', 'max:5000'],
            'evaluation' => ['nullable', 'string', 'max:5000'],
            'assignment' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
