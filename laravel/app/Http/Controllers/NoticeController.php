<?php

namespace App\Http\Controllers;

use App\Services\NoticesService;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NoticeController extends Controller
{
    public function __construct(private readonly NoticesService $notices) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('notices.index', [
            'notices' => $this->notices->list($user, $request->query('audience')),
            'events' => $this->notices->upcomingEvents(),
            'audiences' => NoticesService::AUDIENCES,
            'audience' => strtoupper((string) $request->query('audience')),
            'canManage' => $this->notices->canManage($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', Rule::in(array_keys(NoticesService::AUDIENCES))],
            'publish_at' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->attempt(fn () => $this->notices->createNotice($request->user(), $data), 'Notice posted.');
    }

    public function destroy(Request $request, string $notice): RedirectResponse
    {
        return $this->attempt(fn () => $this->notices->deleteNotice($request->user(), $notice), 'Notice deleted.');
    }

    public function storeEvent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'all_day' => ['nullable', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);
        $data['all_day'] = $request->boolean('all_day');

        return $this->attempt(fn () => $this->notices->createEvent($request->user(), $data), 'Event added to the calendar.');
    }

    public function destroyEvent(Request $request, string $event): RedirectResponse
    {
        return $this->attempt(fn () => $this->notices->deleteEvent($request->user(), $event), 'Event removed.');
    }

    private function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('notices')->with('success', $success);
    }
}
