<?php

namespace App\Http\Controllers;

use App\Services\AssignmentsService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentsService $assignments) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('assignments.index', [
            'assignments' => $this->assignments->list($user),
            'canCreate' => $this->assignments->canCreate($user),
            'offerings' => $this->assignments->offeringsFor($user),
            'terms' => $this->assignments->terms(),
            'service' => $this->assignments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->assignments->canCreate($request->user()), 403, 'Only teachers can create assignments.');

        $data = $request->validate([
            'class_subject_id' => ['required', 'string'],
            'term_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'file' => ['nullable', 'file', 'max:'.AssignmentsService::MAX_FILE_KB, 'mimes:'.AssignmentsService::FILE_TYPES],
        ]);

        try {
            $this->assignments->create($request->user(), $data, $request->file('file'));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('assignments')->with('success', 'Assignment created.');
    }

    public function destroy(Request $request, string $assignment): RedirectResponse
    {
        try {
            $this->assignments->delete($request->user(), $assignment);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('assignments')->with('success', 'Assignment deleted.');
    }

    public function file(Request $request, string $assignment): StreamedResponse|RedirectResponse
    {
        $found = $this->assignments->find($request->user(), $assignment) ?? abort(404);

        try {
            return $this->assignments->download($found);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
