<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\ExamsService;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExamsController extends Controller
{
    public function __construct(private readonly ExamsService $exams) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $terms = $this->exams->termsFor($user);
        $requestedTerm = $this->exams->findTerm($request->query('term'));
        $term = ($requestedTerm && $terms->contains('id', $requestedTerm->getKey()))
            ? $requestedTerm
            : ($user->hasRole(Role::STUDENT, Role::PARENT) ? $terms->first() : $this->exams->defaultTerm());

        $classes = collect();
        $selectedClass = null;
        if ($term && ! $user->hasRole(Role::STUDENT, Role::PARENT)) {
            $classes = $this->exams->classesFor($user, $term);
            $requested = $request->query('class');
            if ($requested && ! $classes->contains('id', $requested)) {
                abort(403, 'You cannot open this class.');
            }
            $selectedClass = $classes->firstWhere('id', $requested) ?? $classes->first();
        }

        $views = $this->exams->viewsFor($user, $term, $selectedClass);
        $view = $request->query('view');
        // Teachers' mark entry tab is keyed "subject"; map the office name for deep links.
        if ($view === 'marksheet' && isset($views['subject']) && ! isset($views['marksheet'])) {
            $view = 'subject';
        }
        // A teacher who is not this class's form teacher has no report tab. Send them to the
        // broadsheet instead of answering the tab click with 403.
        if ($view === 'report' && ! array_key_exists('report', $views) && $this->exams->isTeacher($user)) {
            return redirect()->route('exams', array_filter([
                'view' => array_key_exists('broadsheet', $views) ? 'broadsheet' : array_key_first($views),
                'term' => $term?->getKey(),
                'class' => $selectedClass?->getKey(),
            ]));
        }
        if (is_string($view) && $view !== '' && ! array_key_exists($view, $views)) {
            abort(403, 'You cannot open that exams view.');
        }
        $view = array_key_exists($view, $views) ? $view : array_key_first($views);

        $data = [
            'views' => $views,
            'view' => $view,
            'terms' => $terms,
            'term' => $term,
            'bands' => $term ? $this->exams->bands() : collect(),
            'isOffice' => $this->exams->isOffice($user),
            'classes' => $classes,
            'class' => null,
        ];

        if (! $term) {
            return view('exams.index', $data);
        }

        $data = array_merge($data, match ($view) {
            'marksheet' => $this->marksheet($request, $data),
            'subject' => $this->subjectTabs($request, $data),
            'broadsheet' => $this->broadsheet($request, $data),
            default => $this->report($request, $data),
        });

        return view('exams.index', $data);
    }

    public function saveScores(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'term_id' => ['required', 'string'],
            'scores' => ['required', 'array'],
            'scores.*' => ['array'],
            'scores.*.*' => ['array'],
            'scores.*.*.ca1' => ['nullable'],
            'scores.*.*.ca2' => ['nullable'],
            'scores.*.*.ca3' => ['nullable'],
            'scores.*.*.exam' => ['nullable'],
            'scores.*.*.absent' => ['nullable'],
        ]);

        $term = $this->exams->findTerm($data['term_id']);
        abort_unless($term, 404);

        try {
            // AuthorizationException (a teacher editing someone else's subject) bubbles up as a 403.
            $count = $this->exams->saveScores($request->user(), $term, $data['scores']);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('exams', $this->context($request, ['term' => $term->getKey()]))
            ->with('success', match ($count) {
                0 => 'Nothing to save - enter at least one mark.',
                1 => 'Saved 1 score.',
                default => "Saved {$count} scores.",
            });
    }

    public function saveReport(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'term_id' => ['required', 'string'],
            'enrolment_id' => ['required', 'string'],
            'days_present' => ['nullable'],
            'days_absent' => ['nullable'],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['nullable', 'integer', 'min:1', 'max:5'],
            'teacher_comment' => ['nullable', 'string', 'max:255'],
            'principal_comment' => ['nullable', 'string', 'max:255'],
        ]);

        $term = $this->exams->findTerm($data['term_id']);
        $enrolment = $this->exams->findEnrolment($data['enrolment_id']);
        abort_unless($term && $enrolment, 404);

        try {
            $this->exams->saveReport($request->user(), $enrolment, $term, $data);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('exams', $this->context($request, ['term' => $term->getKey(), 'enrolment' => $enrolment->getKey()]))
            ->with('success', 'Report saved.');
    }

    // ------------------------------------------------------------------ Views

    /** Office mark sheet: one subject for the whole class, or one student across all subjects. */
    private function marksheet(Request $request, array $data): array
    {
        $term = $data['term'];
        $class = $this->pickClass($request, $data['classes']);
        if (! $class) {
            return ['class' => null];
        }

        $subjects = $this->exams->classSubjects($class);
        $roster = $this->exams->roster($class, $term);

        $enrolment = $roster->firstWhere('id', $request->query('student'));
        if ($enrolment) {
            // One student, every subject.
            $scores = $enrolment->scores()->where('term_id', $term->getKey())->get()->keyBy('class_subject_id');

            return compact('class', 'subjects', 'roster', 'enrolment', 'scores') + ['mode' => 'student'];
        }

        $subject = $subjects->firstWhere('id', $request->query('subject')) ?? $subjects->first();

        return [
            'class' => $class,
            'subjects' => $subjects,
            'roster' => $roster,
            'subject' => $subject,
            'scores' => $subject ? $this->exams->scoresFor($subject, $term) : collect(),
            'registered' => $subject ? $this->exams->registeredFor($subject) : [],
            'mode' => 'subject',
        ];
    }

    /**
     * Teacher mark sheet: pick a class they teach in this session, then a subject tab.
     * Roster is students offering that subject (not the whole class).
     */
    private function subjectTabs(Request $request, array $data): array
    {
        $user = $request->user();
        $term = $data['term'];
        $classes = $data['classes'];

        if ($classes->isEmpty()) {
            return [
                'tabs' => collect(),
                'subjects' => collect(),
                'subject' => null,
                'class' => null,
                'roster' => collect(),
                'mode' => 'subject',
            ];
        }

        $class = $this->pickClass($request, $classes);
        $tabs = $this->exams->taughtSubjects($user, $term, $class);

        $requested = $request->query('subject');
        if ($requested && ! $tabs->contains('id', $requested)) {
            // Subject belongs to another class the teacher teaches — switch class to match.
            $across = $this->exams->taughtSubjects($user, $term);
            $match = $across->firstWhere('id', $requested);
            if (! $match) {
                abort(403, 'You can only open subjects you teach.');
            }
            $class = $match->class;
            $tabs = $this->exams->taughtSubjects($user, $term, $class);
        }

        $subject = $tabs->firstWhere('id', $requested) ?? $tabs->first();
        if (! $subject) {
            return [
                'tabs' => $tabs,
                'subjects' => $tabs,
                'subject' => null,
                'class' => $class,
                'roster' => collect(),
                'mode' => 'subject',
            ];
        }

        return [
            'tabs' => $tabs,
            'subjects' => $tabs,
            'subject' => $subject,
            'class' => $class,
            'roster' => $this->exams->offeringRoster($subject, $term),
            'scores' => $this->exams->scoresFor($subject, $term),
            'registered' => $this->exams->registeredFor($subject),
            'mode' => 'subject',
        ];
    }

    private function broadsheet(Request $request, array $data): array
    {
        $user = $request->user();
        $class = $this->pickClass($request, $data['classes']);
        [$sort, $dir] = $this->broadsheetSort($request);

        return [
            'class' => $class,
            'sort' => $sort,
            'dir' => $dir,
            'sheet' => $class
                ? $this->exams->broadsheet($class, $data['term'], $user, $sort, $dir)
                : null,
        ];
    }

    /** Download the current broadsheet as a branded landscape PDF. */
    public function broadsheetPdf(Request $request): Response
    {
        $user = $request->user();
        $term = $this->exams->findTerm($request->query('term')) ?? $this->exams->defaultTerm();
        abort_unless($term, 404);

        $views = $this->exams->viewsFor($user, $term);
        abort_unless(isset($views['broadsheet']), 403, 'You cannot open the broadsheet.');

        $classes = $this->exams->classesFor($user, $term);
        $class = $this->pickClass($request, $classes);
        abort_unless($class, 404);

        [$sort, $dir] = $this->broadsheetSort($request);
        $class->loadMissing(['level', 'arm', 'formTeacher', 'session']);
        $term->loadMissing('session');
        $sheet = $this->exams->broadsheet($class, $term, $user, $sort, $dir);
        $brand = $this->exams->documentBrand();

        $classLabel = trim(($class->level?->name ?? '').' '.($class->arm?->name ?? ''));
        $filename = Str::slug('broadsheet-'.$classLabel.'-'.$term->name.'-'.$term->session?->name).'.pdf';
        $subjectCount = $sheet['subjects']->count();

        $data = [
            'school' => $brand['school'],
            'logoDataUri' => $brand['logo_data_uri'],
            'signatureDataUri' => $brand['signature_data_uri'],
            'primary' => $brand['primary'],
            'generatedAt' => $brand['generated_at'],
            'generatedBy' => $user->email,
            'term' => $term,
            'class' => $class,
            'classLabel' => $classLabel,
            'sheet' => $sheet,
            'formTeacher' => $class->formTeacher,
            'subjectCount' => $subjectCount,
            'forScreen' => $request->query('preview') === 'html',
        ];

        if ($request->query('preview') === 'html') {
            return response()->view('exams.broadsheet-pdf', $data);
        }

        $pdf = Pdf::loadView('exams.broadsheet-pdf', $data)
            ->setPaper($this->exams->broadsheetPaper($subjectCount)); // already landscape-oriented (width ≥ height)

        return $this->pdfResponse($request, $pdf, $filename);
    }

    /** Download one student's report card as a branded A4 PDF. */
    public function reportPdf(Request $request): Response
    {
        $user = $request->user();
        $term = $this->exams->findTerm($request->query('term')) ?? $this->exams->defaultTerm();
        abort_unless($term, 404);

        $enrolment = $this->exams->findEnrolment($request->query('enrolment'));
        abort_unless($enrolment && $enrolment->session_id === $term->session_id, 404);
        abort_unless(isset($this->exams->viewsFor($user, $term, $enrolment->class)['report']), 403, 'You cannot open report cards.');

        if ($user->hasRole(Role::STUDENT, Role::PARENT)) {
            abort_unless(
                $this->exams->myEnrolments($user, $term)->contains('id', $enrolment->getKey()),
                403,
                'You can only open your own report card.',
            );
        } elseif ($this->exams->isTeacher($user)) {
            abort_unless(
                $this->exams->isFormTeacherOf($user, $enrolment->class),
                403,
                'You can only open report cards for a class you form-teach.',
            );
        }

        $term->loadMissing('session');
        $card = $this->exams->reportCard($enrolment, $term);
        $brand = $this->exams->documentBrand();
        $class = $card['class'];
        $classLabel = trim(($class?->level?->name ?? '').' '.($class?->arm?->name ?? ''));
        $studentName = $enrolment->student?->full_name ?: 'student';
        $filename = Str::slug('report-'.$studentName.'-'.$classLabel.'-'.$term->name.'-'.$term->session?->name).'.pdf';

        $data = [
            'school' => $brand['school'],
            'logoDataUri' => $brand['logo_data_uri'],
            'signatureDataUri' => $brand['signature_data_uri'],
            'primary' => $brand['primary'],
            'generatedAt' => $brand['generated_at'],
            'generatedBy' => $user->email,
            'term' => $term,
            'enrolment' => $enrolment,
            'card' => $card,
            'classLabel' => $classLabel,
            'bands' => $this->exams->bands(),
            'starDataUri' => $this->exams->highestStarDataUri(),
        ];

        if ($request->query('preview') === 'html') {
            return response()->view('exams.report-pdf', $data);
        }

        $pdf = Pdf::loadView('exams.report-pdf', $data)->setPaper('a4', 'portrait');

        return $this->pdfResponse($request, $pdf, $filename);
    }

    /** Inline when previewing in the page; otherwise a file download. */
    private function pdfResponse(Request $request, \Barryvdh\DomPDF\PDF $pdf, string $filename): Response
    {
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /** @return array{0: string, 1: string} */
    private function broadsheetSort(Request $request): array
    {
        $sort = $request->query('sort', 'position');
        $dir = $request->query('dir', 'asc');

        return [
            in_array($sort, ['name', 'position'], true) ? $sort : 'position',
            $dir === 'desc' ? 'desc' : 'asc',
        ];
    }

    private function report(Request $request, array $data): array
    {
        $user = $request->user();
        $term = $data['term'];

        // Students and parents only ever see their own children's cards.
        if ($user->hasRole(Role::STUDENT, Role::PARENT)) {
            $options = $this->exams->myEnrolments($user, $term);
            $requested = $request->query('enrolment');
            $enrolment = $requested ? $options->firstWhere('id', $requested) : null;
            if (! $enrolment && $requested) {
                // The form keeps the previous enrolment id when the term changes. That id is still
                // theirs, just from another session, so open their card in the session they picked.
                $other = $this->exams->findEnrolment($requested);
                if (! $other || ! $this->exams->canViewEnrolment($user, $other)) {
                    abort(403, 'You can only open your own report card.');
                }
                $enrolment = $options->firstWhere('student_id', $other->student_id) ?? $options->first();
            }
            $enrolment ??= $options->first();

            return [
                'class' => $enrolment?->class,
                'roster' => $options,
                'enrolment' => $enrolment,
                'card' => $enrolment ? $this->exams->reportCard($enrolment, $term) : null,
                'charts' => $enrolment ? $this->exams->reportCharts($enrolment, $term, false) : null,
                'ownOnly' => true,
                'canEditReport' => false,
            ];
        }

        // Subject teachers without a form-teacher role never get this tab; guard deep links.
        if ($this->exams->isTeacher($user) && ! $this->exams->isFormTeacherInSession($user, $term->session_id)) {
            abort(403, 'Only form teachers can open report cards.');
        }

        $class = $this->pickClass($request, $data['classes']);
        if (! $class) {
            return ['class' => null, 'ownOnly' => false, 'canEditReport' => false];
        }

        // A form teacher may only open report cards for their own class (office sees all).
        if ($this->exams->isTeacher($user) && ! $this->exams->isFormTeacherOf($user, $class)) {
            abort(403, 'You can only open report cards for a class you form-teach.');
        }

        $roster = $this->exams->roster($class, $term);
        $enrolment = $roster->firstWhere('id', $request->query('enrolment')) ?? $roster->first();

        return [
            'class' => $class,
            'roster' => $roster,
            'enrolment' => $enrolment,
            'card' => $enrolment ? $this->exams->reportCard($enrolment, $term) : null,
            'charts' => $enrolment ? $this->exams->reportCharts($enrolment, $term, true) : null,
            'ownOnly' => false,
            'canEditReport' => (bool) $enrolment && ($this->exams->isOffice($user) || $this->exams->isFormTeacherOf($user, $class)),
        ];
    }

    // ---------------------------------------------------------------- Helpers

    /** The requested class (403 when not allowed) or the first one available. */
    private function pickClass(Request $request, $classes)
    {
        $requested = $request->query('class');
        if ($requested && ! $classes->contains('id', $requested)) {
            abort(403, 'You cannot open this class.');
        }

        return $classes->firstWhere('id', $requested) ?? $classes->first();
    }

    /**
     * Whitelisted navigation context posted with the forms so we can redirect back to the same screen.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function context(Request $request, array $extra = []): array
    {
        $ctx = array_filter($request->only(['view', 'class', 'subject', 'student', 'enrolment']), fn ($v) => is_string($v) && $v !== '');

        return $extra + $ctx;
    }
}
