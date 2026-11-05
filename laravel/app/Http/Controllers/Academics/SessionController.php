<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\RunsAcademicsActions;
use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    use RunsAcademicsActions;

    public function __construct(private readonly AcademicsService $academics) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'start_year' => ['required', 'integer', 'between:1990,2100'],
            'end_year' => ['required', 'integer', 'between:1990,2100'],
            'is_current' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(
            fn () => $this->academics->createSession($data + ['is_current' => $request->boolean('is_current')]),
            'Session created.',
        );
    }

    public function current(string $session): RedirectResponse
    {
        return $this->attempt(fn () => $this->academics->markCurrent($session), 'Current session updated.');
    }

    public function storeTerm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'sequence' => ['required', 'integer', 'between:1,3'],
            'name' => ['nullable', 'string', 'max:100'],
            'days_school_open' => ['nullable', 'integer', 'between:0,366'],
            'result_date' => ['nullable', 'date'],
        ]);

        return $this->attempt(fn () => $this->academics->createTerm($data), 'Term created.');
    }
}
