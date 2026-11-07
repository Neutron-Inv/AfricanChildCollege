@if (filled(config('app.powered_by')))
    <footer class="px-4 py-4 text-center text-xs text-gray-400">
        Powered by {{ config('app.powered_by') }}
    </footer>
@endif
