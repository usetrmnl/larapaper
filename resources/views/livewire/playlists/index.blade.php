<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public $devices;

    public $playlists;

    // Playlist form properties
    public $playlist_name;

    public $selected_weekdays = null;

    public $active_from;

    public $active_until;

    public $refresh_time = null;

    public ?int $item_playlist_id = null;

    public ?int $editing_item_id = null;

    public string $mashup_layout = 'full';

    public array $mashup_plugins = [];

    public function mount()
    {
        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();

        return view('livewire.playlists.index');
    }

    public function togglePlaylistActive(Playlist $playlist)
    {
        abort_unless(auth()->user()->devices->contains($playlist->device), 403);
        $playlist->update(['is_active' => ! $playlist->is_active]);
        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
    }

    public function sortPlaylistItem(int $id, int $position): void
    {
        $item = PlaylistItem::query()->with('playlist.device')->findOrFail($id);
        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);

        $items = $item->playlist->items()->orderBy('order')->orderBy('id')->get();
        $ids = $items->pluck('id')->all();
        $currentIndex = array_search($id, $ids, true);

        if ($currentIndex === false) {
            return;
        }

        $ids = array_values(array_diff($ids, [$id]));
        array_splice($ids, $position, 0, [$id]);

        foreach ($ids as $index => $itemId) {
            PlaylistItem::query()->whereKey($itemId)->update(['order' => $index]);
        }

        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
    }

    public function togglePlaylistItemActive(PlaylistItem $item)
    {
        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);
        $item->update(['is_active' => ! $item->is_active]);
        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
    }

    public function deletePlaylist(Playlist $playlist)
    {
        abort_unless(auth()->user()->devices->contains($playlist->device), 403);
        $playlist->delete();
        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
        Flux::modal('delete-playlist-'.$playlist->id)->close();
    }

    public function deletePlaylistItem(PlaylistItem $item)
    {
        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);
        $item->delete();
        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
        Flux::modal('delete-playlist-item-'.$item->id)->close();
    }

    public function clearPluginImageCache(PlaylistItem $item): void
    {
        $item->loadMissing(['playlist.device', 'plugin']);

        abort_unless(auth()->user()->devices->contains($item->playlist->device), 403);

        if (! $item->supportsImageCache()) {
            return;
        }

        abort_unless($item->plugin->user_id === auth()->id(), 403);

        $item->plugin->clearCurrentImage();

        Flux::toast(variant: 'success', text: 'Image cache cleared.');

        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
    }

    public function editPlaylist(Playlist $playlist)
    {
        abort_unless(auth()->user()->devices->contains($playlist->device), 403);

        $this->validate([
            'playlist_name' => 'required|string|max:255',
            'selected_weekdays' => 'nullable|array',
            'active_from' => 'nullable|date_format:H:i',
            'active_until' => 'nullable|date_format:H:i',
            'refresh_time' => 'nullable|integer|min:60',
        ]);

        if (empty($this->active_from)) {
            $this->active_from = null;
        }
        if (empty($this->active_until)) {
            $this->active_until = null;
        }
        if ($this->refresh_time < 60) {
            $this->refresh_time = null;
        }

        if (empty($this->selected_weekdays)) {
            $this->selected_weekdays = null;
        }

        $playlist->update([
            'name' => $this->playlist_name,
            'weekdays' => $this->selected_weekdays,
            'active_from' => $this->active_from,
            'active_until' => $this->active_until,
            'refresh_time' => $this->refresh_time,
        ]);

        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
        $this->reset(['playlist_name', 'selected_weekdays', 'active_from', 'active_until', 'refresh_time']);
        Flux::modal('edit-playlist-'.$playlist->id)->close();
    }

    public function preparePlaylistEdit(Playlist $playlist)
    {
        $this->playlist_name = $playlist->name;
        $this->selected_weekdays = $playlist->weekdays ?? null;
        $this->active_from = optional($playlist->active_from)->format('H:i');
        $this->active_until = optional($playlist->active_until)->format('H:i');
        $this->refresh_time = $playlist->refresh_time;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Plugin>
     */
    public function recipePlugins(): \Illuminate\Database\Eloquent\Collection
    {
        return auth()->user()->plugins()
            ->where('plugin_type', 'recipe')
            ->orderBy('name')
            ->get();
    }

    public function prepareAddPlaylistItem(Playlist $playlist): void
    {
        $this->authorizePlaylist($playlist);

        $this->resetErrorBag();
        $this->item_playlist_id = $playlist->id;
        $this->editing_item_id = null;
        $this->mashup_layout = 'full';
        $this->mashup_plugins = [];
    }

    public function prepareEditPlaylistItem(PlaylistItem $item): void
    {
        $item->loadMissing(['playlist.device', 'plugin']);
        $this->authorizePlaylist($item->playlist);
        abort_unless($item->isMashup() || $item->plugin?->plugin_type === 'recipe', 403);

        $this->resetErrorBag();
        $this->item_playlist_id = $item->playlist_id;
        $this->editing_item_id = $item->id;
        $this->mashup_layout = $item->isMashup() ? ($item->getMashupLayoutType() ?? 'full') : 'full';
        $this->mashup_plugins = $item->isMashup()
            ? array_map(strval(...), $item->getMashupPluginIds())
            : [(string) $item->plugin_id];
    }

    public function savePlaylistItem(): void
    {
        $playlist = Playlist::query()->with('device')->findOrFail($this->item_playlist_id);
        $this->authorizePlaylist($playlist);

        $allowedLayouts = array_merge(['full'], array_keys(PlaylistItem::getAvailableLayouts()));

        $this->validate([
            'mashup_layout' => ['required', 'string', Rule::in($allowedLayouts)],
        ]);

        $this->mashup_plugins = PlaylistItem::takePluginIdsForLayout($this->mashup_layout, $this->mashup_plugins);
        $required = PlaylistItem::getRequiredPluginCountForLayout($this->mashup_layout);

        $this->validate([
            'mashup_plugins' => ['required', 'array', 'size:'.$required],
            'mashup_plugins.*' => [
                'required',
                Rule::exists('plugins', 'id')->where('user_id', auth()->id())->where('plugin_type', 'recipe'),
            ],
        ]);

        $pluginIds = array_map(intval(...), $this->mashup_plugins);

        if ($this->editing_item_id !== null) {
            $item = PlaylistItem::query()
                ->where('playlist_id', $playlist->id)
                ->findOrFail($this->editing_item_id);
            $item->applyLayout($this->mashup_layout, $pluginIds);
            $message = 'Layout updated.';
        } else {
            $maxOrder = $playlist->items()->max('order') ?? 0;

            if ($this->mashup_layout === 'full') {
                $playlist->items()->create([
                    'plugin_id' => $pluginIds[0],
                    'order' => $maxOrder + 1,
                ]);
            } else {
                $primary = Plugin::query()->findOrFail($pluginIds[0]);
                PlaylistItem::createMashup(
                    $playlist,
                    $this->mashup_layout,
                    $pluginIds,
                    $primary->name.' Mashup',
                    $maxOrder + 1,
                );
            }

            $message = 'Item added';
        }

        $this->devices = auth()->user()->devices()->with(['playlists.items.plugin'])->get();
        $this->reset(['item_playlist_id', 'editing_item_id', 'mashup_layout', 'mashup_plugins']);
        Flux::modal('playlist-item-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    protected function authorizePlaylist(Playlist $playlist): void
    {
        abort_unless(auth()->user()->devices->contains($playlist->device), 403);
    }
}; ?>

<div>
    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center justify-between">
                <h2 class="text-2xl font-semibold dark:text-gray-100">Playlists</h2>
            </div>

            @foreach ($devices as $device)
                @if ($device->playlists->isNotEmpty())
                    <div class="mb-8">
                        <div class="mb-4 flex items-center justify-between">
                            <a
                                href="{{ route('devices.configure', $device) }}"
                                wire:navigate
                                class="text-lg font-medium hover:underline dark:text-zinc-200"
                            >{{ $device->name }}</a>
                            <flux:tooltip content="View device" position="bottom">
                                <flux:button
                                    href="{{ route('devices.configure', $device) }}"
                                    wire:navigate
                                    icon="eye"
                                />
                            </flux:tooltip>
                        </div>

                        <div class="flex flex-col gap-4">
                            @foreach ($device->playlists as $playlist)
                                <flux:card body="divided" wire:key="playlist-{{ $playlist->id }}">
                                    <flux:card.header class="items-start sm:items-center">
                                        <flux:card.heading>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                <flux:switch
                                                    wire:model.live="playlist.is_active"
                                                    wire:click="togglePlaylistActive({{ $playlist->id }})"
                                                    :checked="$playlist->is_active"
                                                />
                                                <span>{{ $playlist->name }}</span>
                                            </div>
                                        </flux:card.heading>

                                        @if ($playlist->weekdays || ($playlist->active_from && $playlist->active_until))
                                            <flux:card.subheading>
                                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    @if ($playlist->weekdays)
                                                        <span>{{ implode(', ', collect($playlist->weekdays)->map(fn ($day) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][$day])->toArray()) }}</span>
                                                    @endif
                                                    @if ($playlist->active_from && $playlist->active_until)
                                                        @if ($playlist->weekdays)
                                                            <span aria-hidden="true">·</span>
                                                        @endif
                                                        <span>{{ $playlist->active_from->format('H:i') }} - {{ $playlist->active_until->format('H:i') }}</span>
                                                    @endif
                                                </span>
                                            </flux:card.subheading>
                                        @endif

                                        <flux:card.actions>
                                            <div class="flex gap-1">
                                                <flux:modal.trigger name="playlist-item-form">
                                                    <flux:tooltip content="Add item" position="bottom">
                                                        <flux:button
                                                            icon="plus"
                                                            variant="subtle"
                                                            size="sm"
                                                            wire:click="prepareAddPlaylistItem({{ $playlist->id }})"
                                                            aria-label="Add item"
                                                        />
                                                    </flux:tooltip>
                                                </flux:modal.trigger>
                                                <flux:modal.trigger name="edit-playlist-{{ $playlist->id }}">
                                                    <flux:tooltip content="Edit playlist settings" position="bottom">
                                                        <flux:button
                                                            icon="pencil-square"
                                                            variant="subtle"
                                                            size="sm"
                                                            wire:click="preparePlaylistEdit({{ $playlist->id }})"
                                                            aria-label="Edit playlist"
                                                        />
                                                    </flux:tooltip>
                                                </flux:modal.trigger>
                                                <flux:modal.trigger name="delete-playlist-{{ $playlist->id }}">
                                                    <flux:button icon="trash" size="sm" aria-label="Delete playlist" />
                                                </flux:modal.trigger>
                                            </div>
                                        </flux:card.actions>
                                    </flux:card.header>

                                    <flux:modal name="edit-playlist-{{ $playlist->id }}" class="md:w-96">
                                        <div class="space-y-6">
                                            <div>
                                                <flux:heading size="lg">Edit Playlist</flux:heading>
                                            </div>

                                            <form wire:submit="editPlaylist({{ $playlist->id }})">
                                                <div class="mb-4">
                                                    <flux:input
                                                        label="Playlist Name"
                                                        wire:model="playlist_name"
                                                        required
                                                    />
                                                </div>

                                                <div class="mb-4">
                                                    <flux:checkbox.group
                                                        wire:model="selected_weekdays"
                                                        label="Active Days (optional)"
                                                    >
                                                        <flux:checkbox label="Monday" value="1" />
                                                        <flux:checkbox label="Tuesday" value="2" />
                                                        <flux:checkbox label="Wednesday" value="3" />
                                                        <flux:checkbox label="Thursday" value="4" />
                                                        <flux:checkbox label="Friday" value="5" />
                                                        <flux:checkbox label="Saturday" value="6" />
                                                        <flux:checkbox label="Sunday" value="0" />
                                                    </flux:checkbox.group>
                                                </div>

                                                <div class="mb-4">
                                                    <flux:input
                                                        type="time"
                                                        label="Active From (optional)"
                                                        wire:model="active_from"
                                                    />
                                                </div>

                                                <div class="mb-4">
                                                    <flux:input
                                                        type="time"
                                                        label="Active Until (optional)"
                                                        wire:model="active_until"
                                                    />
                                                </div>

                                                <div class="mb-4">
                                                    <flux:input
                                                        type="number"
                                                        label="Refresh Time (seconds)"
                                                        wire:model="refresh_time"
                                                        min="1"
                                                        placeholder="Leave empty to use device default"
                                                    />
                                                </div>

                                                <div class="flex">
                                                    <flux:spacer />
                                                    <flux:button type="submit" variant="primary"
                                                        >Save Changes</flux:button>
                                                </div>
                                            </form>
                                        </div>
                                    </flux:modal>

                                    <flux:modal
                                        name="delete-playlist-{{ $playlist->id }}"
                                        class="min-w-[22rem] space-y-6"
                                    >
                                        <div>
                                            <flux:heading size="lg">Delete {{ $playlist->name }}?</flux:heading>
                                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                                This will permanently delete this playlist and all its items.
                                            </p>
                                        </div>

                                        <div class="flex gap-2">
                                            <flux:spacer />
                                            <flux:modal.close>
                                                <flux:button variant="ghost">Cancel</flux:button>
                                            </flux:modal.close>
                                            <flux:button
                                                wire:click="deletePlaylist({{ $playlist->id }})"
                                                variant="danger"
                                            >Delete playlist</flux:button>
                                        </div>
                                    </flux:modal>

                                    <flux:card.body>
                                        @if ($playlist->items->isEmpty())
                                            <x-playlist-empty-callout />
                                        @else
                                            <table class="w-full" data-flux-table>
                                                <thead data-flux-columns>
                                                    <tr>
                                                        <th
                                                            class="w-10 px-2 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 dark:text-white"
                                                            data-flux-column
                                                        >
                                                            <span class="sr-only">Reorder</span>
                                                        </th>
                                                        <th
                                                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 last:pr-0 dark:text-white"
                                                            data-flux-column
                                                        >
                                                            <div class="flex whitespace-nowrap">Plugin / Recipe</div>
                                                        </th>
                                                        <th
                                                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                                                            data-flux-column
                                                        >
                                                            <div class="flex whitespace-nowrap">Status</div>
                                                        </th>
                                                        <th
                                                            class="px-3 py-3 text-right text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                                                            data-flux-column
                                                        >
                                                            <div class="flex justify-end whitespace-nowrap">
                                                                Actions
                                                            </div>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody
                                                    class="divide-y divide-zinc-800/10 dark:divide-white/20"
                                                    data-flux-rows
                                                    @if ($playlist->items->count() > 1) wire:sort="sortPlaylistItem" @endif
                                                >
                                                    @foreach ($playlist->items->sortBy('order') as $item)
                                                        <tr
                                                            data-flux-row
                                                            wire:key="playlist-item-{{ $item->id }}"
                                                            @if ($playlist->items->count() > 1) wire:sort:item="{{ $item->id }}" @endif
                                                        >
                                                            <td class="w-10 px-2 py-3 align-middle text-zinc-400 first:pl-0 dark:text-zinc-500">
                                                                @if ($playlist->items->count() > 1)
                                                                    <div
                                                                        wire:sort:handle
                                                                        class="flex cursor-grab touch-none justify-center active:cursor-grabbing"
                                                                        title="Drag to reorder"
                                                                    >
                                                                        <flux:icon
                                                                            name="bars-3"
                                                                            variant="mini"
                                                                            class="size-5"
                                                                        />
                                                                    </div>
                                                                @endif
                                                            </td>
                                                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 last:pr-0 dark:text-zinc-300">
                                                                <x-playlist-plugin-name :item="$item" />
                                                            </td>
                                                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 dark:text-zinc-300">
                                                                <flux:switch
                                                                    wire:click="togglePlaylistItemActive({{ $item->id }})"
                                                                    :checked="$item->is_active"
                                                                />
                                                            </td>
                                                            <td class="px-3 py-3 text-sm whitespace-nowrap first:pl-0 last:pr-0">
                                                                <div class="flex items-center justify-end gap-2">
                                                                    @if ($item->isMashup() || $item->plugin?->plugin_type === 'recipe')
                                                                        <flux:dropdown>
                                                                            <flux:button
                                                                                icon="ellipsis-horizontal"
                                                                                variant="ghost"
                                                                                size="xs"
                                                                                aria-label="Item actions"
                                                                            />
                                                                            <flux:menu>
                                                                                <flux:modal.trigger name="playlist-item-form">
                                                                                    <flux:menu.item
                                                                                        icon="mashup-2x2"
                                                                                        wire:click="prepareEditPlaylistItem({{ $item->id }})"
                                                                                    >
                                                                                        Edit layout
                                                                                    </flux:menu.item>
                                                                                </flux:modal.trigger>
                                                                                @if (! $item->isMashup())
                                                                                    <flux:menu.item
                                                                                        icon="x-mark"
                                                                                        wire:click="clearPluginImageCache({{ $item->id }})"
                                                                                    >
                                                                                        Clear image
                                                                                        cache</flux:menu.item>
                                                                                @endif
                                                                            </flux:menu>
                                                                        </flux:dropdown>
                                                                    @endif
                                                                    <flux:modal.trigger name="delete-playlist-item-{{ $item->id }}">
                                                                        <flux:button
                                                                            icon="trash"
                                                                            variant="ghost"
                                                                            size="xs"
                                                                        />
                                                                    </flux:modal.trigger>
                                                                </div>

                                                                <flux:modal
                                                                    name="delete-playlist-item-{{ $item->id }}"
                                                                    class="min-w-[22rem] space-y-6"
                                                                >
                                                                    <div>
                                                                        <flux:heading size="lg">
                                                                            @if ($item->isMashup())
                                                                                Delete {{ $item->getMashupName() }}?
                                                                            @else
                                                                                Delete {{ $item->plugin?->name ?? 'missing item' }}?
                                                                            @endif
                                                                        </flux:heading>
                                                                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                                                            @if ($item->isMashup())
                                                                                This will remove this mashup from the
                                                                                playlist.
                                                                            @else
                                                                                This will remove this item from the
                                                                                playlist.
                                                                            @endif
                                                                        </p>
                                                                    </div>

                                                                    <div class="flex gap-2">
                                                                        <flux:spacer />
                                                                        <flux:modal.close>
                                                                            <flux:button variant="ghost">Cancel</flux:button>
                                                                        </flux:modal.close>
                                                                        <flux:button
                                                                            wire:click="deletePlaylistItem({{ $item->id }})"
                                                                            variant="danger"
                                                                        >Delete item</flux:button>
                                                                    </div>
                                                                </flux:modal>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                    </flux:card.body>
                                </flux:card>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            <flux:modal name="playlist-item-form" class="min-w-2xl">
                <div class="space-y-6">
                    <flux:heading size="lg">{{ $editing_item_id ? 'Edit layout' : 'Add item' }}</flux:heading>

                    <form wire:submit="savePlaylistItem">
                        <x-mashup-layout-fields :layout="$mashup_layout" :plugins="$this->recipePlugins()" />

                        <div class="flex">
                            <flux:spacer />
                            <flux:button type="submit" variant="primary">
                                {{ $editing_item_id ? 'Save changes' : 'Add item' }}
                            </flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>

            @if ($devices->isEmpty() || $devices->every(fn ($device) => $device->playlists->isEmpty()))
                <flux:card>
                    <flux:card.header>
                        <flux:card.heading>No playlists found</flux:card.heading>
                        <flux:card.subheading>Add playlists to your devices to see them here.</flux:card.subheading>
                    </flux:card.header>

                    <flux:card.body>
                        @if ($devices->isNotEmpty())
                            <flux:button
                                href="{{ route('devices') }}"
                                wire:navigate
                                icon="square-chart-gantt"
                                class="w-full sm:w-auto"
                            >
                                Go to Devices
                            </flux:button>
                        @else
                            <flux:button
                                href="{{ route('devices') }}"
                                wire:navigate
                                icon="plus-circle"
                                variant="primary"
                                class="w-full sm:w-auto"
                            >
                                Add Device
                            </flux:button>
                        @endif
                    </flux:card.body>
                </flux:card>
            @endif
        </div>
    </div>
</div>
