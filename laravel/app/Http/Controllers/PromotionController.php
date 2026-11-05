<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RunsEnrolmentActions;
use App\Services\EnrolmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    use RunsEnrolmentActions;

    public function __construct(private readonly EnrolmentService $enrolment) {}

    public function create(Request $request): View
    {
        $sessions = $this->enrolment->sessions();
        $from = $sessions->firstWhere('id', $request->query('from')) ?? $this->enrolment->resolveSession(null);

        // Default target: the session that starts right after the source.
        $to = $sessions->firstWhere('id', $request->query('to'))
            ?? $sessions->where('start_year', '>', $from?->start_year ?? 0)->sortBy('start_year')->first();
        if ($to && $from && $to->getKey() === $from->getKey()) {
            $to = null;
        }

        return view('enrolment.promote', [
            'sessions' => $sessions,
            'from' => $from,
            'to' => $to,
            'plan' => $from && $to ? $this->enrolment->promotionPlan($from->getKey(), $to->getKey()) : [],
            'targets' => $to ? $this->enrolment->classes($to->getKey()) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_session_id' => ['required', 'string'],
            'to_session_id' => ['required', 'string', 'different:from_session_id'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->enrolment->promote($data['from_session_id'], $data['to_session_id'], $data['mapping'] ?? []),
            fn ($r) => "Promoted {$r['created']} student(s)".($r['skipped'] ? ', skipped '.count($r['skipped']) : '').'.',
            function ($r) use ($data) {
                $redirect = redirect()->route('enrolment', ['session' => $data['to_session_id']]);

                return $r['skipped'] ? $redirect->with('promotion_skipped', $r['skipped']) : $redirect;
            },
        );
    }
}
