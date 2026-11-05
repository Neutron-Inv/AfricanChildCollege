@php
    $badge = [
        'ALL' => 'bg-gray-100 text-gray-700',
        'STAFF' => 'bg-blue-100 text-blue-800',
        'STUDENT' => 'bg-green-100 text-green-800',
        'PARENT' => 'bg-amber-100 text-amber-800',
    ];
    $range = function ($e) {
        if ($e->all_day) {
            return $e->starts_at->isSameDay($e->ends_at)
                ? $e->starts_at->format('D, j M Y').' · All day'
                : $e->starts_at->format('j M').' – '.$e->ends_at->format('j M Y').' · All day';
        }

        return $e->starts_at->isSameDay($e->ends_at)
            ? $e->starts_at->format('D, j M Y, H:i').' – '.$e->ends_at->format('H:i')
            : $e->starts_at->format('j M, H:i').' – '.$e->ends_at->format('j M, H:i');
    };
@endphp
<x-school-layout title="Notices">
    <div class="mb-5">
        <h2 class="text-xl font-semibold">Notices &amp; calendar</h2>
        <p class="text-sm text-gray-500">Announcements and upcoming school events.</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            @if ($canManage)
                <div class="card">
                    <h3 class="mb-3 text-base font-semibold">Post a notice</h3>
                    <form method="POST" action="{{ route('notices.store') }}" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        <div class="sm:col-span-2">
                            <label for="n_title" class="block text-xs font-medium text-gray-600">Title</label>
                            <input id="n_title" name="title" value="{{ old('title') }}" class="form-input mt-1" required maxlength="255">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="n_body" class="block text-xs font-medium text-gray-600">Message</label>
                            <textarea id="n_body" name="body" rows="3" class="form-input mt-1" required>{{ old('body') }}</textarea>
                        </div>
                        <div>
                            <label for="n_aud" class="block text-xs font-medium text-gray-600">Audience</label>
                            <select id="n_aud" name="audience" class="form-input mt-1">
                                @foreach ($audiences as $value => $label)
                                    <option value="{{ $value }}" @selected(old('audience', 'ALL') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="n_pub" class="block text-xs font-medium text-gray-600">Publish date (blank = now)</label>
                            <input id="n_pub" type="date" name="publish_at" value="{{ old('publish_at') }}" class="form-input mt-1">
                        </div>
                        <div class="sm:col-span-2"><button class="btn-primary">Post notice</button></div>
                    </form>
                </div>
            @endif

            <form method="GET" action="{{ route('notices') }}" class="flex items-end gap-3">
                <div>
                    <label for="f_aud" class="block text-xs font-medium text-gray-600">Audience</label>
                    <select id="f_aud" name="audience" class="form-input mt-1" onchange="this.form.submit()">
                        <option value="">All notices</option>
                        @foreach ($audiences as $value => $label)
                            <option value="{{ $value }}" @selected($audience === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <noscript><button class="btn-secondary">Filter</button></noscript>
            </form>

            <div class="card">
                @if ($notices->isEmpty())
                    <p class="text-sm text-gray-500">No notices to show.</p>
                @else
                    <div class="divide-y divide-black/5">
                        @foreach ($notices as $notice)
                            @php $published = $notice->publish_at ?? $notice->created_at; @endphp
                            <article class="py-3 first:pt-0 last:pb-0">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 class="font-medium">{{ $notice->title }}</h3>
                                        <div class="text-xs text-gray-500">
                                            {{ $published->format('j M Y') }}
                                            @if ($published->isFuture()) · <span class="font-medium text-amber-700">Scheduled</span> @endif
                                            @if ($notice->createdBy) · {{ $notice->createdBy->name }} @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$notice->audience ?? 'ALL'] ?? 'bg-gray-100 text-gray-700' }}">{{ $audiences[$notice->audience ?? 'ALL'] ?? $notice->audience }}</span>
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('notices.destroy', $notice->id) }}" onsubmit="return confirm('Delete this notice?')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-700 hover:underline">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                                <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $notice->body }}</p>
                            </article>
                        @endforeach
                    </div>
                    <div class="mt-4">{{ $notices->links() }}</div>
                @endif
            </div>
        </div>

        <div class="space-y-5">
            @if ($canManage)
                <div class="card" x-data="{ allDay: {{ old('all_day', true) ? 'true' : 'false' }} }">
                    <h3 class="mb-3 text-base font-semibold">Add an event</h3>
                    <form method="POST" action="{{ route('notices.events.store') }}" class="space-y-3">
                        @csrf
                        <div>
                            <label for="e_title" class="block text-xs font-medium text-gray-600">Title</label>
                            <input id="e_title" name="title" value="{{ old('title') }}" class="form-input mt-1" required maxlength="255">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="e_start" class="block text-xs font-medium text-gray-600">Starts</label>
                                <input id="e_start" type="date" name="start_date" value="{{ old('start_date') }}" class="form-input mt-1" required>
                            </div>
                            <div>
                                <label for="e_end" class="block text-xs font-medium text-gray-600">Ends</label>
                                <input id="e_end" type="date" name="end_date" value="{{ old('end_date') }}" class="form-input mt-1">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="all_day" value="1" x-model="allDay" @checked(old('all_day', true))> All day
                        </label>
                        <div class="grid grid-cols-2 gap-3" x-show="!allDay" x-cloak>
                            <div>
                                <label for="e_st" class="block text-xs font-medium text-gray-600">Start time</label>
                                <input id="e_st" type="time" name="start_time" value="{{ old('start_time') }}" class="form-input mt-1">
                            </div>
                            <div>
                                <label for="e_et" class="block text-xs font-medium text-gray-600">End time</label>
                                <input id="e_et" type="time" name="end_time" value="{{ old('end_time') }}" class="form-input mt-1">
                            </div>
                        </div>
                        <div>
                            <label for="e_desc" class="block text-xs font-medium text-gray-600">Description</label>
                            <textarea id="e_desc" name="description" rows="2" class="form-input mt-1">{{ old('description') }}</textarea>
                        </div>
                        <button class="btn-primary">Add event</button>
                    </form>
                </div>
            @endif

            <div class="card">
                <h3 class="mb-3 text-base font-semibold">Upcoming events</h3>
                @if ($events->isEmpty())
                    <p class="text-sm text-gray-500">No upcoming events.</p>
                @else
                    <ul class="divide-y divide-black/5 text-sm">
                        @foreach ($events as $event)
                            <li class="flex items-start justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                <div>
                                    <div class="font-medium">{{ $event->title }}</div>
                                    <div class="text-xs text-gray-500">{{ $range($event) }}</div>
                                    @if ($event->description)<div class="mt-0.5 text-xs text-gray-600">{{ $event->description }}</div>@endif
                                </div>
                                @if ($canManage)
                                    <form method="POST" action="{{ route('notices.events.destroy', $event->id) }}" onsubmit="return confirm('Remove this event?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-700 hover:underline">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-school-layout>
