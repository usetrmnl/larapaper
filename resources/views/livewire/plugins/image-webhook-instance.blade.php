<?php

use App\Models\Plugin;
use Livewire\Component;

new class extends Component
{
    public Plugin $plugin;

    public string $name;

    public array $checked_devices = [];

    public array $device_playlists = [];

    public array $device_playlist_names = [];

    public array $device_weekdays = [];

    public array $device_active_from = [];

    public array $device_active_until = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        abort_unless($this->plugin->plugin_type === 'image_webhook', 404);

        $this->name = $this->plugin->name;
    }

    protected array $rules = [
        'name' => 'required|string|max:255',
        'checked_devices' => 'array',
        'device_playlist_names' => 'array',
        'device_playlists' => 'array',
        'device_weekdays' => 'array',
        'device_active_from' => 'array',
        'device_active_until' => 'array',
    ];

    public function updateName(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        $this->validate(['name' => 'required|string|max:255']);
        $this->plugin->update(['name' => $this->name]);
    }

    public function addToPlaylist()
    {
        $this->validate([
            'checked_devices' => 'required|array|min:1',
        ]);

        foreach ($this->checked_devices as $deviceId) {
            if (! isset($this->device_playlists[$deviceId]) || empty($this->device_playlists[$deviceId])) {
                $this->addError('device_playlists.'.$deviceId, 'Please select a playlist for each device.');

                return;
            }

            if ($this->device_playlists[$deviceId] === 'new') {
                if (! isset($this->device_playlist_names[$deviceId]) || empty($this->device_playlist_names[$deviceId])) {
                    $this->addError('device_playlist_names.'.$deviceId, 'Playlist name is required when creating a new playlist.');

                    return;
                }
            }
        }

        foreach ($this->checked_devices as $deviceId) {
            $playlist = null;

            if ($this->device_playlists[$deviceId] === 'new') {
                $playlist = App\Models\Playlist::create([
                    'device_id' => $deviceId,
                    'name' => $this->device_playlist_names[$deviceId],
                    'weekdays' => ! empty($this->device_weekdays[$deviceId] ?? null) ? $this->device_weekdays[$deviceId] : null,
                    'active_from' => $this->device_active_from[$deviceId] ?? null,
                    'active_until' => $this->device_active_until[$deviceId] ?? null,
                ]);
            } else {
                $playlist = App\Models\Playlist::findOrFail($this->device_playlists[$deviceId]);
            }

            $maxOrder = $playlist->items()->max('order') ?? 0;

            // Image webhook plugins only support full layout
            $playlist->items()->create([
                'plugin_id' => $this->plugin->id,
                'order' => $maxOrder + 1,
            ]);
        }

        $this->reset([
            'checked_devices',
            'device_playlists',
            'device_playlist_names',
            'device_weekdays',
            'device_active_from',
            'device_active_until',
        ]);
        Flux::modal('add-to-playlist')->close();
    }

    public function getDevicePlaylists($deviceId)
    {
        return App\Models\Playlist::where('device_id', $deviceId)->get();
    }

    public function hasAnyPlaylistSelected(): bool
    {
        foreach ($this->checked_devices as $deviceId) {
            if (isset($this->device_playlists[$deviceId]) && ! empty($this->device_playlists[$deviceId])) {
                return true;
            }
        }

        return false;
    }

    public function deletePlugin(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);
        $this->plugin->delete();
        $this->redirect(route('plugins.image-webhook'));
    }

    public function getImagePath(): ?string
    {
        if (! $this->plugin->current_image) {
            return null;
        }

        $extensions = ['png', 'bmp'];
        foreach ($extensions as $ext) {
            $path = 'images/generated/'.$this->plugin->current_image.'.'.$ext;
            if (Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }
};
?>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-semibold dark:text-gray-100">Image Webhook – {{$plugin->name}}</h2>

            <flux:button.group>
                <flux:modal.trigger name="add-to-playlist">
                    <flux:button icon="play" variant="primary">Add to Playlist</flux:button>
                </flux:modal.trigger>

                <flux:dropdown>
                    <flux:button icon="chevron-down" variant="primary"></flux:button>
                    <flux:menu>
                        <flux:modal.trigger name="delete-plugin">
                            <flux:menu.item icon="trash" variant="danger">Delete Instance</flux:menu.item>
                        </flux:modal.trigger>
                    </flux:menu>
                </flux:dropdown>
            </flux:button.group>
        </div>

        <flux:modal name="add-to-playlist" class="min-w-2xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add to Playlist</flux:heading>
                </div>

                <form wire:submit="addToPlaylist">
                    <flux:separator text="Device(s)" />
                    <div class="mt-4 mb-4">
                        <flux:checkbox.group wire:model.live="checked_devices">
                            @foreach(auth()->user()->devices as $device)
                                <flux:checkbox label="{{ $device->name }}" value="{{ $device->id }}"/>
                            @endforeach
                        </flux:checkbox.group>
                    </div>

                    @if(count($checked_devices) > 0)
                        <flux:separator text="Playlist Selection" />
                        <div class="mt-4 mb-4 space-y-6">
                            @foreach($checked_devices as $deviceId)
                                @php
                                    $device = auth()->user()->devices->find($deviceId);
                                @endphp
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                                        {{ $device->name }}
                                    </div>

                                    <div class="mb-4">
                                        <flux:select wire:model.live.debounce="device_playlists.{{ $deviceId }}">
                                            <option value="">Select Playlist or Create New</option>
                                            @foreach($this->getDevicePlaylists($deviceId) as $playlist)
                                                <option value="{{ $playlist->id }}">{{ $playlist->name }}</option>
                                            @endforeach
                                            <option value="new">Create New Playlist</option>
                                        </flux:select>
                                    </div>

                                    @if(isset($device_playlists[$deviceId]) && $device_playlists[$deviceId] === 'new')
                                        <div class="space-y-4">
                                            <div>
                                                <flux:input label="Playlist Name" wire:model="device_playlist_names.{{ $deviceId }}"/>
                                            </div>
                                            <div>
                                                <flux:checkbox.group wire:model="device_weekdays.{{ $deviceId }}" label="Active Days (optional)">
                                                    <flux:checkbox label="Monday" value="1"/>
                                                    <flux:checkbox label="Tuesday" value="2"/>
                                                    <flux:checkbox label="Wednesday" value="3"/>
                                                    <flux:checkbox label="Thursday" value="4"/>
                                                    <flux:checkbox label="Friday" value="5"/>
                                                    <flux:checkbox label="Saturday" value="6"/>
                                                    <flux:checkbox label="Sunday" value="0"/>
                                                </flux:checkbox.group>
                                            </div>
                                            <div class="grid grid-cols-2 gap-4">
                                                <div>
                                                    <flux:input type="time" label="Active From (optional)" wire:model="device_active_from.{{ $deviceId }}"/>
                                                </div>
                                                <div>
                                                    <flux:input type="time" label="Active Until (optional)" wire:model="device_active_until.{{ $deviceId }}"/>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif


                    <div class="flex">
                        <flux:spacer/>
                        <flux:button type="submit" variant="primary">Add to Playlist</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        <flux:modal name="delete-plugin" class="min-w-[22rem] space-y-6">
            <div>
                <flux:heading size="lg">Delete {{ $plugin->name }}?</flux:heading>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">This will also remove this instance from your playlists.</p>
            </div>

            <div class="flex gap-2">
                <flux:spacer/>
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="deletePlugin" variant="danger">Delete instance</flux:button>
            </div>
        </flux:modal>

        <div class="grid lg:grid-cols-2 lg:gap-8">
            <div>
                <form wire:submit="updateName" class="mb-6">
                    <div class="mb-4">
                        <flux:input label="Name" wire:model="name" id="name" class="block mt-1 w-full" type="text"
                                    name="name" autofocus/>
                    </div>

                    <div class="flex">
                        <flux:spacer/>
                        <flux:button type="submit" variant="primary" class="w-full">Save</flux:button>
                    </div>
                </form>

                <div class="mb-6">
                    <flux:label>Webhook URL</flux:label>
                    <flux:input
                        :value="route('api.plugin_settings.image', ['uuid' => $plugin->uuid])"
                        class="font-mono text-sm"
                        readonly
                        copyable
                    />
                    <flux:description class="mt-2">POST an image (PNG or BMP) to this URL to update the displayed image.</flux:description>

                    <flux:callout variant="warning" icon="exclamation-circle" class="mt-4">
                        <flux:callout.text>Images must be posted in a format that can directly be read by the device. You need to take care of image format, dithering, and bit-depth. Check device logs if the image is not shown.</flux:callout.text>
                    </flux:callout>

                </div>
            </div>

            <div>
                <div class="mb-4">
                    <flux:label>Current Image</flux:label>
                    @if($this->getImagePath())
                        <img src="{{ Storage::disk('public')->url($this->getImagePath()) }}" alt="{{ $plugin->name }}" class="w-full h-auto rounded-lg border border-zinc-200 dark:border-zinc-700 mt-2" />
                    @else
                        <flux:callout variant="warning" class="mt-2">
                            <flux:text>No image uploaded yet. POST an image to the webhook URL to get started.</flux:text>
                        </flux:callout>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

