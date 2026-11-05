<div class="grid gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <h3 class="mb-3 text-base font-semibold">Rooms <span class="text-sm font-normal text-gray-500">({{ $rooms->count() }})</span></h3>
        @if ($rooms->isEmpty())
            <p class="text-sm text-gray-500">No rooms yet.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-black/10 text-xs uppercase tracking-wide text-gray-500">
                    <tr><th class="py-2 pr-3 font-medium">Room</th><th class="py-2 font-medium">Capacity</th></tr>
                </thead>
                <tbody class="divide-y divide-black/5">
                    @foreach ($rooms as $r)
                        <tr>
                            <td class="py-2 pr-3 font-medium">{{ $r->name }}</td>
                            <td class="py-2 text-gray-600">{{ $r->capacity ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canEdit)
        <form method="POST" action="{{ route('academics.rooms.store') }}" class="card space-y-3 self-start">
            @csrf
            <h3 class="text-base font-semibold">Add room</h3>
            <div>
                <label for="room_name" class="block text-xs font-medium text-gray-600">Name</label>
                <input id="room_name" name="name" value="{{ old('name') }}" class="form-input mt-1" required maxlength="120">
            </div>
            <div>
                <label for="room_capacity" class="block text-xs font-medium text-gray-600">Capacity</label>
                <input id="room_capacity" type="number" min="0" name="capacity" value="{{ old('capacity', 0) }}" class="form-input mt-1">
            </div>
            <button class="btn-primary w-full">Add room</button>
        </form>
    @endif
</div>
