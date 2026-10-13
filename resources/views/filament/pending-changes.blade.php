@php
    $diff = $record ? app(\ForumKit\Cms\Services\PageDraftDiff::class)->forPage($record) : null;
    $badgeColors = ['Added' => 'text-green-700 bg-green-50', 'Edited' => 'text-amber-700 bg-amber-50', 'Removed' => 'text-red-700 bg-red-50'];
@endphp

@if ($diff)
    <x-filament::section
        heading="Pending changes"
        :description="$diff['count'] === 0 ? 'No unpublished changes. The draft matches the live page.' : $diff['count'].' unpublished '.\Illuminate\Support\Str::plural('change', $diff['count']).' will go live when you publish.'"
        icon="heroicon-o-clipboard-document-list"
        compact
    >
        @if ($diff['count'] > 0)
            <ul class="space-y-2 text-sm">
                @foreach ($diff['fields'] as $field)
                    <li>
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badgeColors['Edited'] }}">Edited</span>
                        <span class="font-medium">{{ $field['label'] }}</span>
                        <span class="text-gray-500">{{ $field['before'] }}</span>
                        <span aria-hidden="true">→</span>
                        <span>{{ $field['after'] }}</span>
                    </li>
                @endforeach

                @foreach ($diff['tags']['added'] as $tag)
                    <li><span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badgeColors['Added'] }}">Added</span> Tag <span class="font-medium">{{ $tag }}</span></li>
                @endforeach
                @foreach ($diff['tags']['removed'] as $tag)
                    <li><span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badgeColors['Removed'] }}">Removed</span> Tag <span class="font-medium">{{ $tag }}</span></li>
                @endforeach

                @foreach ($diff['blocks'] as $block)
                    <li>
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badgeColors[$block['status']] }}">{{ $block['status'] }}</span>
                        Block <span class="font-medium">{{ $block['label'] }}</span>
                        @if ($block['details'] !== [])
                            <span class="text-gray-500">— {{ implode(', ', $block['details']) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
@endif
