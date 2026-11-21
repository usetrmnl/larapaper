<x-trmnl::layout>
    <x-trmnl::table>
        <thead>
            <tr>
                <th>
                    <x-trmnl::title>Abfahrt</x-trmnl::title>
                </th>
                <th>
                    <x-trmnl::title>Aktuell</x-trmnl::title>
                </th>
                <th>
                    <x-trmnl::title>Zug</x-trmnl::title>
                </th>
                <th>
                    <x-trmnl::title>Ziel</x-trmnl::title>
                </th>
                <th>
                    <x-trmnl::title>Steig</x-trmnl::title>
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['departures'] as $journey)
                <tr>
                    <td>
                        <x-trmnl::label>{{ \Carbon\Carbon::parse($journey['scheduledTime'])->setTimezone(config('app.timezone'))->format('H:i') }}</x-trmnl::label>
                    </td>
                    @if ($journey['isCancelled'])
                        <td>
                            <x-trmnl::label variant="inverted">Ausfall</x-trmnl::label>
                        </td>
                    @else
                        <td>
                            <x-trmnl::label>{{ \Carbon\Carbon::parse($journey['time'])->setTimezone(config('app.timezone'))->format('H:i') }}</x-trmnl::label>
                        </td>
                    @endif
                    <td>
                        <x-trmnl::label variant="{{ $journey['isCancelled'] ? 'gray-out' : '' }}">{{ $journey['train'] }}</x-trmnl::label>
                    </td>
                    <td>
                        <x-trmnl::label variant="{{ $journey['isCancelled'] ? 'gray-out' : '' }}">{{ $journey['destination'] }}</x-trmnl::label>
                    </td>
                    <td>
                        <x-trmnl::label variant="{{ $journey['isCancelled'] ? 'gray-out' : '' }}">{{ $journey['platform'] }}</x-trmnl::label>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-trmnl::table>
</x-trmnl::layout>
<x-trmnl::title-bar title="{{ config('services.oebb.station_name') }}" instance="aktualisiert: {{ now() }}" />
