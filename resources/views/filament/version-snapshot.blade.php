<div class="space-y-3">
    <p class="text-sm text-gray-600">
        Snapshot for version <strong>{{ $version->version_number }}</strong>
        @if($version->created_at)
            from {{ $version->created_at->toDayDateTimeString() }}
        @endif
    </p>

    <pre class="max-h-[70vh] overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-5 text-gray-800">{{ json_encode($version->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
