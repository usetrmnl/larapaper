@php
    use Carbon\Carbon;

    $timezone = $trmnl['user']['time_zone_iana'];
    $today = Carbon::today($timezone);

    $events = collect($data['ical'] ?? [])
        ->map(function (array $event) use ($timezone): array {
            try {
                $start = isset($event['DTSTART'])
                    ? Carbon::parse($event['DTSTART'])->setTimezone($timezone)
                    : null;
            } catch (Exception $e) {
                $start = null;
            }

            try {
                $end = isset($event['DTEND'])
                    ? Carbon::parse($event['DTEND'])->setTimezone($timezone)
                    : null;
            } catch (Exception $e) {
                $end = null;
            }

            return [
                'summary' => $event['SUMMARY'] ?? 'Untitled event',
                'location' => $event['LOCATION'] ?? '—',
                'start' => $start,
                'end' => $end,
            ];
        })
        ->filter(fn ($event) => $event['start'] &&
            (
                $event['start']->greaterThanOrEqualTo($today) ||
                ($event['end'] && $event['end']->greaterThanOrEqualTo($today))
            )
        )
        ->sortBy('start')
        ->take($size === 'quadrant' ? 5 : 8)
        ->values();
@endphp

<x-trmnl::layout class="layout--col gap--small">
    <x-trmnl::table>
        <colgroup>
            <col span="3" style="width: 33%" />
        </colgroup>
        <thead>
            <tr>
                <th>
                    <x-trmnl::title>Date</x-trmnl::title>
                </th>
                <th>
                    <x-trmnl::title>Event</x-trmnl::title>
                </th>
                <th>
                    <x-trmnl::title>Location</x-trmnl::title>
                </th>
            </tr>
        </thead>
        <tbody>
            @forelse ($events as $event)
                <tr>
                    <td>
                        <x-trmnl::label>{{ $event['start']?->format('D, M j') }}</x-trmnl::label>
                    </td>
                    <td>
                        <x-trmnl::label variant="inverted">{{ $event['summary'] }}</x-trmnl::label>
                    </td>
                    <td>
                        <x-trmnl::label>{{ Str::limit($event['location'] ?? '—',50) }}</x-trmnl::label>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        <x-trmnl::label>No events available</x-trmnl::label>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-trmnl::table>
</x-trmnl::layout>

<x-trmnl::title-bar title="Public Holidays" instance="updated: {{ now()->format('M j, H:i') }}" />
