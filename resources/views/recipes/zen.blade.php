@props(['size' => 'full'])
<x-trmnl::view size="{{ $size }}">
    <x-trmnl::layout class="layout--col">
        <div class="b-h-gray-1">{{$data['data'][0]['a'] ?? ''}}</div>
        @if (strlen($data['data'][0]['q'] ?? '') < 300 && $size != 'quadrant')
            <p class="value">{{ $data['data'][0]['q'] ?? '' }}</p>
        @else
            <p class="value--small">{{ $data['data'][0]['q'] ?? '' }}</p>
        @endif
    </x-trmnl::layout>

    <div class="title_bar">
        <flux:icon name="book-open" class="image" />
        <span class="title">Zen Quotes</span>
    </div>
</x-trmnl::view>
