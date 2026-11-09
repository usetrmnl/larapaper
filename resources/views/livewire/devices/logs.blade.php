<?php

use App\Models\Device;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

new class extends Component
{
    public Device $device;

    public string $search = '';

    public $logs;

    public function mount(Device $device): void
    {
        abort_unless(auth()->user()->devices->contains($device), 403);
        $this->device = $device;
        $this->loadLogs();
    }

    public function updatedSearch(): void
    {
        $this->loadLogs();
    }

    public function clearLogs(): void
    {
        abort_unless(auth()->user()->devices->contains($this->device), 403);

        $this->device->logs()->delete();
        $this->loadLogs();

        Flux::modal('clear-device-logs')->close();
    }

    private function loadLogs(): void
    {
        $search = mb_trim($this->search);

        $this->logs = $this->device->logs()
            ->latest('device_timestamp')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $grammar = $query->getQuery()->getGrammar();

                $query->where(function (Builder $query) use ($term, $operator, $grammar): void {
                    foreach (['message', 'log_message'] as $key) {
                        $query->orWhereRaw(
                            $grammar->wrap('log_entry->'.$key).' '.$operator.' ? escape ?',
                            [$term, '\\'],
                        );
                    }
                });
            })
            ->take(50)
            ->get();
    }
}

?>

<div>
    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-2xl font-semibold dark:text-gray-100">Device Logs - {{ $device->name }}</h2>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search messages"
                        icon="magnifying-glass"
                        clearable
                        class="w-full sm:w-72"
                    />

                    <flux:modal.trigger name="clear-device-logs">
                        <flux:button icon="trash">Clear logs</flux:button>
                    </flux:modal.trigger>
                </div>
            </div>

            @if ($logs->isEmpty())
                <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-300">No logs found.</p>
            @endif

            <flux:modal name="clear-device-logs" class="min-w-[22rem] space-y-6">
                <div>
                    <flux:heading size="lg">Clear logs for {{ $device->name }}?</flux:heading>

                    <flux:text class="mt-2">
                        Every log for this device will be permanently deleted. This cannot be undone.
                    </flux:text>
                </div>

                <div class="flex gap-2">
                    <flux:spacer />

                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>

                    <flux:button wire:click="clearLogs" variant="danger">Clear logs</flux:button>
                </div>
            </flux:modal>

            <table
                class="min-w-full table-fixed divide-y divide-zinc-800/10 text-zinc-800 dark:divide-white/20"
                data-flux-table=""
            >
                <thead data-flux-columns="">
                    <tr>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Device Time</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Log Level</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Device Status</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column=""
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Message</div>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-800/10 dark:divide-white/20" data-flux-rows="">
                    @foreach ($logs as $log)
                        @php
                            $message = $log->log_entry['message'] ?? $log->log_entry['log_message'] ?? null;
                        @endphp
                        @if (! $message)
                            @continue
                        @endif
                        @php
                            // Support both previous and revised log formats
                            $timestamp = $log->log_entry['created_at'] ?? $log->log_entry['creation_timestamp'] ?? null;
                            $wifiStatus = $log->log_entry['wifi_status'] ?? $log->log_entry['device_status_stamp']['wifi_status'] ?? 'Unknown';
                            $wifiRssi = $log->log_entry['wifi_signal'] ?? $log->log_entry['device_status_stamp']['wifi_rssi_level'] ?? null;
                            $hasDeviceStatus = isset($log->log_entry['device_status_stamp']) ||
                                              (isset($log->log_entry['wifi_status']) && $log->log_entry['wifi_status'] !== 'Unknown');
                        @endphp
                        <tr data-flux-row="">
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                @if ($timestamp)
                                    {{ \Carbon\Carbon::createFromTimestamp($timestamp)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') }}
                                @endif
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                <div
                                    class="inline-flex items-center font-medium whitespace-nowrap -mt-1 -mb-1 text-xs py-1 px-2 rounded-md
                                    @if(str_contains(mb_strtolower($message), 'error'))
                                        bg-red-400/15 text-red-700 dark:bg-red-400/40 dark:text-red-200
                                    @elseif(str_contains(mb_strtolower($message), 'warning'))
                                        bg-yellow-400/15 text-yellow-700 dark:bg-yellow-400/40 dark:text-yellow-200
                                    @else
                                        bg-zinc-400/15 text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200
                                    @endif"
                                >
                                    {{
                                        str_contains(mb_strtolower($message), 'error') ? 'Error' :
                                        (str_contains(mb_strtolower($message), 'warning') ? 'Warning' : 'Info')
                                    }}
                                </div>
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                <div class="flex items-center gap-2">
                                    <div class="-mt-1 -mb-1 inline-flex items-center rounded-md bg-zinc-400/15 px-2 py-1 text-xs font-medium whitespace-nowrap text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200">
                                        {{ $wifiStatus }}
                                        @if ($wifiRssi)
                                            ({{ $wifiRssi }}dBm)
                                        @endif
                                    </div>
                                    @if ($hasDeviceStatus)
                                        <flux:modal.trigger name="device-status-{{ $log->id }}">
                                            <flux:button icon="information-circle" variant="ghost" size="xs" />
                                        </flux:modal.trigger>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                <div class="flex items-center gap-2">
                                    <span>{{ $message }}</span>
                                    <flux:modal.trigger name="log-details-{{ $log->id }}">
                                        <flux:button icon="information-circle" variant="ghost" size="xs" />
                                    </flux:modal.trigger>
                                </div>
                            </td>
                        </tr>

                        @if ($hasDeviceStatus)
                            <flux:modal name="device-status-{{ $log->id }}" class="md:w-96">
                                <div class="space-y-6">
                                    <div>
                                        <flux:heading size="lg">Device Status Details</flux:heading>
                                    </div>

                                    <dl class="space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">WiFi Status:</dt>
                                            <dd>{{ $wifiStatus }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">WiFi RSSI:</dt>
                                            <dd>{{ $wifiRssi ?? 'Unknown' }} dBm</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Refresh Rate:</dt>
                                            <dd>
                                                {{ $log->log_entry['refresh_rate'] ?? $log->log_entry['device_status_stamp']['refresh_rate'] ?? 'Unknown' }}s
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Time Since Sleep:</dt>
                                            <dd>
                                                {{ $log->log_entry['sleep_duration'] ?? $log->log_entry['device_status_stamp']['time_since_last_sleep_start'] ?? 'Unknown' }}s
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Firmware Version:</dt>
                                            <dd>
                                                {{ $log->log_entry['firmware_version'] ?? $log->log_entry['device_status_stamp']['current_fw_version'] ?? 'Unknown' }}
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Special Function:</dt>
                                            <dd>
                                                {{ $log->log_entry['special_function'] ?? $log->log_entry['device_status_stamp']['special_function'] ?? 'None' }}
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Battery Voltage:</dt>
                                            <dd>
                                                {{ $log->log_entry['battery_voltage'] ?? $log->log_entry['device_status_stamp']['battery_voltage'] ?? 'Unknown' }}V
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Wakeup Reason:</dt>
                                            <dd>
                                                {{ $log->log_entry['wake_reason'] ?? $log->log_entry['device_status_stamp']['wakeup_reason'] ?? 'Unknown' }}
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-zinc-500">Free Heap:</dt>
                                            <dd>
                                                {{ $log->log_entry['free_heap_size'] ?? $log->log_entry['device_status_stamp']['free_heap_size'] ?? 'Unknown' }} bytes
                                            </dd>
                                        </div>
                                        @if (isset($log->log_entry['device_status_stamp']['max_alloc_size']))
                                            <div class="flex justify-between">
                                                <dt class="text-zinc-500">Max Alloc Size:</dt>
                                                <dd>
                                                    {{ $log->log_entry['device_status_stamp']['max_alloc_size'] }} bytes
                                                </dd>
                                            </div>
                                        @endif
                                    </dl>

                                    <div class="flex">
                                        <flux:spacer />
                                        <flux:modal.close>
                                            <flux:button variant="ghost">Close</flux:button>
                                        </flux:modal.close>
                                    </div>
                                </div>
                            </flux:modal>
                        @endif

                        <flux:modal name="log-details-{{ $log->id }}" class="md:w-192">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">Log Details</flux:heading>
                                </div>

                                <dl class="space-y-1 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-zinc-500">Source File:</dt>
                                        <dd>
                                            {{ $log->log_entry['source_path'] ?? $log->log_entry['log_sourcefile'] ?? 'Unknown' }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-zinc-500">Line Number:</dt>
                                        <dd>
                                            {{ $log->log_entry['source_line'] ?? $log->log_entry['log_codeline'] ?? 'Unknown' }}
                                        </dd>
                                    </div>
                                    @if (isset($log->log_entry['additional_info']))
                                        <div class="mt-2">
                                            <dt class="mb-1 text-zinc-500">Additional Info</dt>
                                            <dd class="space-y-1">
                                                @foreach ($log->log_entry['additional_info'] as $key => $value)
                                                    <div class="flex justify-between">
                                                        <span class="text-zinc-500">{{ str_replace('_', ' ', ucfirst($key)) }}:</span>
                                                        <span>{{ is_null($value) ? 'None' : $value }}</span>
                                                    </div>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif
                                </dl>

                                <div class="flex">
                                    <flux:spacer />
                                    <flux:modal.close>
                                        <flux:button variant="ghost">Close</flux:button>
                                    </flux:modal.close>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
