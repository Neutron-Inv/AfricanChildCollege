<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\RunsAcademicsActions;
use App\Http\Controllers\Controller;
use App\Services\AcademicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Simple catalogue lists: subjects, departments, rooms. */
class CatalogueController extends Controller
{
    use RunsAcademicsActions;

    public function __construct(private readonly AcademicsService $academics) {}

    public function storeSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'department_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->createSubject($data),
            'Subject created.',
            fn ($subject) => ['tab' => 'subjects', 'subject' => $subject->getKey()],
        );
    }

    public function updateSubject(Request $request, string $subject): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'department_id' => ['nullable', 'string'],
            'session_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->academics->updateSubject($subject, $data, $data['session_id'] ?? null),
            'Subject saved.',
            json: fn (array $row) => ['subject' => $row],
        );
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hod_id' => ['nullable', 'string'],
        ]);

        return $this->attempt(fn () => $this->academics->createDepartment($data), 'Department created.');
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'between:0,10000'],
        ]);

        return $this->attempt(fn () => $this->academics->createRoom($data), 'Room created.');
    }
}
