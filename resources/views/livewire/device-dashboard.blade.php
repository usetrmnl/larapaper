<?php

use Livewire\Component;

new class extends Component
{
    public function mount()
    {
        return view('livewire.device-dashboard', ['devices' => auth()->user()->devices()->paginate(10)]);
    }
}
?>

<div>
    <div class="bg-muted flex flex-col items-center justify-center gap-6 p-6 md:p-10">
        <div class="flex w-full max-w-3xl flex-col gap-6">
            @if($devices->isEmpty())
                <div class="flex flex-col gap-6">
                    <div
                        class="styled-container">
                        <div class="px-10 py-8">
                            <h1 class="text-xl font-medium dark:text-zinc-200">Add your first device</h1>
                            <flux:button href="{{ route('devices') }}" class="mt-4" icon="plus-circle" variant="primary"
                                         class="w-full mt-4">Add Device
                            </flux:button>
                        </div>
                    </div>
                </div>
            @endif

            @foreach($devices as $device)
                <div class="flex flex-col gap-6">
                    <div
                        class="styled-container">
                        <div class="px-10 py-8">
                            @php
                                $current_image_uuid =$device->current_screen_image;
                                if($current_image_uuid) {
                                    $file_extension = Storage::disk('public')->exists('images/generated/' . $current_image_uuid . '.png') ? 'png' : 'bmp';
                                    $current_image_url = Storage::disk('public')->url('images/generated/' . $current_image_uuid . '.' . $file_extension);
                                } else {
                                    $current_image_url = asset('storage/images/setup-logo.bmp');
                                }
                            @endphp

                            <div class="flex items-center justify-between gap-4">
                                <flux:tooltip content="Friendly ID: {{$device->friendly_id}}" position="bottom">
                                    <h1 class="text-xl font-medium dark:text-zinc-200">{{ $device->name }}</h1>
                                </flux:tooltip>
                                <div class="flex gap-2">
                                    <flux:tooltip content="Last refresh" position="bottom">
                                        <span class="dark:text-zinc-200">{{$device->last_refreshed_at?->diffForHumans()}}</span>
                                    </flux:tooltip>
                                    <flux:separator vertical class="hidden md:block"/>
                                    <flux:tooltip content="MAC Address" position="bottom" class="hidden md:block">
                                        <span class="dark:text-zinc-200">{{$device->mac_address}}</span>
                                    </flux:tooltip>
                                    @if($device->last_firmware_version)
                                        <flux:separator vertical class="hidden md:block"/>
                                        <flux:tooltip content="Firmware Version" position="bottom" class="hidden md:block">
                                            <span class="dark:text-zinc-200">{{$device->last_firmware_version}}</span>
                                        </flux:tooltip>
                                    @endif
                                    @if($device->wifiStrength)
                                        <flux:separator vertical class="hidden md:block"/>
                                        <x-responsive-icons.wifi :strength="$device->wifiStrength" :rssi="$device->last_rssi_level"
                                                             class="dark:text-zinc-200 hidden md:block"/>
                                    @endif
                                    @if($device->batteryPercent)
                                        <flux:separator vertical class="hidden md:block"/>
                                        <x-responsive-icons.battery :percent="$device->batteryPercent" class="hidden md:block"/>
                                    @endif
                                </div>
                                <div>
                                    <flux:dropdown>
                                        <flux:button icon="ellipsis-horizontal" variant="subtle"></flux:button>
                                        <flux:menu>
                                            <flux:menu.item icon="eye" href="{{ route('devices.configure', $device) }}">View</flux:menu.item>
                                            <flux:menu.item icon="bars-3" href="{{ route('devices.logs', $device) }}" wire:navigate>Show Logs</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
                            @if($device->mirror_device_id)
                                <flux:separator class="mt-2 mb-4"/>
                                <flux:callout variant="info">
                                    <div class="flex items-center gap-2">
                                        <flux:icon.link class="dark:text-zinc-200"/>
                                        <flux:text>
                                            This device is mirrored from
                                            <a href="{{ route('devices.configure', $device->mirrorDevice) }}" class="font-medium hover:underline">
                                                {{ $device->mirrorDevice->name }}
                                            </a>
                                        </flux:text>
                                    </div>
                                </flux:callout>
                            @elseif($current_image_url)
                                <flux:separator class="mt-2 mb-4"/>
                                <div class="flex justify-center">
                                    <div class="relative origin-center -rotate-[{{ $device->rotate ?? 0 }}deg]">
                                        <img src="{{ $current_image_url }}" class="max-h-[480px]" alt="Current Image"/>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{--    @php--}}
    {{--        $current_image_uuid = auth()->user()?->devices()?->first()?->current_screen_image;--}}
    {{--        $current_image_path = 'images/generated/' . $current_image_uuid . '.png';--}}
    {{--    @endphp--}}
    {{--    @if($current_image_uuid)--}}
    {{--        <h1 class="text-xl font-medium dark:text-zinc-200">TRMNL Giveaway</h1>--}}
    {{--        <p class="text-sm dark:text-zinc-400">D8:3B:DA:F3:C1:DC</p>--}}
    {{--        <flux:separator class="mt-2 mb-4"/>--}}
    {{--        <img src="{{ asset($current_image_path) }}" alt="Current Image"/>--}}
    {{--    @else--}}
    {{--        <h1 class="text-xl font-medium dark:text-zinc-200">Add your first device</h1>--}}
    {{--        <flux:button href="{{ route('devices') }}" class="mt-4"  icon="plus-circle" variant="primary">Add Device</flux:button>--}}
    {{--    @endif--}}
</div>
