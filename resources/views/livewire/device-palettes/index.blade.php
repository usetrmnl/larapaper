<?php

use App\Jobs\FetchDeviceModelsJob;
use App\Models\DevicePalette;
use Livewire\Component;

new class extends Component
{
    public $devicePalettes;

    public $name;

    public $description;

    public $grays = 2;

    public $colors = [];

    public $framework_class = '';

    public $colorInput = '';

    protected $rules = [
        'name' => 'required|string|max:255|unique:device_palettes,name',
        'description' => 'nullable|string|max:255',
        'grays' => 'required|integer|min:1|max:256',
        'colors' => 'nullable|array',
        'colors.*' => 'string|regex:/^#[0-9A-Fa-f]{6}$/',
        'framework_class' => 'nullable|string|max:255',
    ];

    public function mount()
    {
        $this->devicePalettes = DevicePalette::all();

        return view('livewire.device-palettes.index');
    }

    public function addColor(): void
    {
        $this->validate(['colorInput' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/'], [
            'colorInput.regex' => 'Color must be a valid hex color (e.g., #FF0000)',
        ]);

        if (! in_array($this->colorInput, $this->colors)) {
            $this->colors[] = $this->colorInput;
        }

        $this->colorInput = '';
    }

    public function removeColor(int $index): void
    {
        unset($this->colors[$index]);
        $this->colors = array_values($this->colors);
    }

    public $editingDevicePaletteId;

    public $viewingDevicePaletteId;

    public function updateFromApi(): void
    {
        FetchDeviceModelsJob::dispatchSync();
        $this->devicePalettes = DevicePalette::all();
        Flux::toast(variant: 'success', text: 'Device palettes updated from API.');
    }

    public function openDevicePaletteModal(?string $devicePaletteId = null, bool $viewOnly = false): void
    {
        if ($devicePaletteId) {
            $devicePalette = DevicePalette::findOrFail($devicePaletteId);

            if ($viewOnly) {
                $this->viewingDevicePaletteId = $devicePalette->id;
                $this->editingDevicePaletteId = null;
            } else {
                $this->editingDevicePaletteId = $devicePalette->id;
                $this->viewingDevicePaletteId = null;
            }

            $this->name = $devicePalette->name;
            $this->description = $devicePalette->description;
            $this->grays = $devicePalette->grays;

            // Ensure colors is always an array and properly decoded
            // The model cast should handle JSON decoding, but we'll be explicit
            $colors = $devicePalette->getAttribute('colors');

            if ($colors === null) {
                $this->colors = [];
            } elseif (is_string($colors)) {
                $decoded = json_decode($colors, true);
                $this->colors = is_array($decoded) ? array_values($decoded) : [];
            } elseif (is_array($colors)) {
                $this->colors = array_values($colors); // Re-index array
            } else {
                $this->colors = [];
            }

            $this->framework_class = $devicePalette->framework_class;
        } else {
            $this->editingDevicePaletteId = null;
            $this->viewingDevicePaletteId = null;
            $this->reset(['name', 'description', 'grays', 'colors', 'framework_class']);
        }

        $this->colorInput = '';
    }

    public function saveDevicePalette(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'grays' => 'required|integer|min:1|max:256',
            'colors' => 'nullable|array',
            'colors.*' => 'string|regex:/^#[0-9A-Fa-f]{6}$/',
            'framework_class' => 'nullable|string|max:255',
        ];

        if ($this->editingDevicePaletteId) {
            $rules['name'] = 'required|string|max:255|unique:device_palettes,name,'.$this->editingDevicePaletteId;
        } else {
            $rules['name'] = 'required|string|max:255|unique:device_palettes,name';
        }

        $this->validate($rules);

        if ($this->editingDevicePaletteId) {
            $devicePalette = DevicePalette::findOrFail($this->editingDevicePaletteId);
            $devicePalette->update([
                'name' => $this->name,
                'description' => $this->description,
                'grays' => $this->grays,
                'colors' => ! empty($this->colors) ? $this->colors : null,
                'framework_class' => $this->framework_class,
            ]);
            $message = 'Device palette updated successfully.';
        } else {
            DevicePalette::create([
                'name' => $this->name,
                'description' => $this->description,
                'grays' => $this->grays,
                'colors' => ! empty($this->colors) ? $this->colors : null,
                'framework_class' => $this->framework_class,
                'source' => 'manual',
            ]);
            $message = 'Device palette created successfully.';
        }

        $this->reset(['name', 'description', 'grays', 'colors', 'framework_class', 'colorInput', 'editingDevicePaletteId', 'viewingDevicePaletteId']);
        Flux::modal('device-palette-modal')->close();

        $this->devicePalettes = DevicePalette::all();
        Flux::toast(variant: 'success', text: $message);
    }

    public function deleteDevicePalette(string $devicePaletteId): void
    {
        $devicePalette = DevicePalette::findOrFail($devicePaletteId);
        $devicePalette->delete();

        $this->devicePalettes = DevicePalette::all();
        Flux::toast(variant: 'success', text: 'Device palette deleted successfully.');
    }

    public function duplicateDevicePalette(string $devicePaletteId): void
    {
        $devicePalette = DevicePalette::findOrFail($devicePaletteId);

        $this->editingDevicePaletteId = null;
        $this->viewingDevicePaletteId = null;
        $this->name = $devicePalette->name.'_copy';
        $this->description = $devicePalette->description.' (Copy)';
        $this->grays = $devicePalette->grays;

        $colors = $devicePalette->getAttribute('colors');
        if ($colors === null) {
            $this->colors = [];
        } elseif (is_string($colors)) {
            $decoded = json_decode($colors, true);
            $this->colors = is_array($decoded) ? array_values($decoded) : [];
        } elseif (is_array($colors)) {
            $this->colors = array_values($colors);
        } else {
            $this->colors = [];
        }

        $this->framework_class = $devicePalette->framework_class;
        $this->colorInput = '';

        $this->js('Flux.modal("device-palette-modal").show()');
    }
}

?>

<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-6">
                <livewire:device-resource-nav />
                <flux:button.group>
                    <flux:modal.trigger name="device-palette-modal">
                        <flux:button wire:click="openDevicePaletteModal()" icon="plus" variant="primary">Add Device Palette</flux:button>
                    </flux:modal.trigger>
                    <flux:dropdown>
                        <flux:button icon="chevron-down" variant="primary"></flux:button>
                        <flux:menu>
                            <flux:menu.item icon="arrow-path" wire:click="updateFromApi">Update from API</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:button.group>
            </div>
            <flux:modal name="device-palette-modal" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">
                            @if ($viewingDevicePaletteId)
                                View Device Palette
                            @elseif ($editingDevicePaletteId)
                                Edit Device Palette
                            @else
                                Add Device Palette
                            @endif
                        </flux:heading>
                    </div>

                    <form wire:submit="saveDevicePalette">
                        <div class="mb-4">
                            <flux:input label="Name (Identifier)" wire:model="name" id="name" class="block mt-1 w-full" type="text"
                                        name="name" autofocus :disabled="$viewingDevicePaletteId"/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Description" wire:model="description" id="description" class="block mt-1 w-full" type="text"
                                        name="description" :disabled="$viewingDevicePaletteId"/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Grays" wire:model="grays" id="grays" class="block mt-1 w-full"
                                        type="number"
                                        name="grays" min="1" max="256" :disabled="$viewingDevicePaletteId"/>
                        </div>

                        <div class="mb-4">
                            <flux:input label="Framework Class" wire:model="framework_class" id="framework_class"
                                        class="block mt-1 w-full" type="text"
                                        name="framework_class" :disabled="$viewingDevicePaletteId"/>
                        </div>

                        <div class="mb-4">
                            <flux:label>Colors</flux:label>
                            @if (!$viewingDevicePaletteId)
                                <div class="flex gap-2 mb-2">
                                    <flux:input wire:model="colorInput" placeholder="#FF0000" class="flex-1"/>
                                    <flux:button type="button" wire:click="addColor" variant="ghost">Add</flux:button>
                                </div>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                @if (!empty($colors) && is_array($colors) && count($colors) > 0)
                                    @foreach ($colors as $index => $color)
                                        @if (!empty($color))
                                            <div wire:key="color-{{ $editingDevicePaletteId ?? $viewingDevicePaletteId ?? 'new' }}-{{ $index }}-{{ $color }}" class="flex items-center gap-2 px-3 py-1 bg-zinc-100 dark:bg-zinc-800 rounded">
                                                <div class="w-4 h-4 rounded border border-zinc-300 dark:border-zinc-600" style="background-color: {{ $color }}"></div>
                                                <span class="text-sm">{{ $color }}</span>
                                                @if (!$viewingDevicePaletteId)
                                                    <flux:button type="button" wire:click="removeColor({{ $index }})" icon="x-mark" variant="ghost" size="sm"></flux:button>
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                            @if (!$viewingDevicePaletteId)
                                <p class="mt-1 text-xs text-zinc-500">Leave empty for grayscale-only palette</p>
                            @endif
                        </div>

                        @if (!$viewingDevicePaletteId)
                            <div class="flex">
                                <flux:spacer/>
                                <flux:button type="submit" variant="primary">{{ $editingDevicePaletteId ? 'Update' : 'Create' }} Device Palette</flux:button>
                            </div>
                        @else
                            <div class="flex">
                                <flux:spacer/>
                                <flux:button type="button" wire:click="duplicateDevicePalette('{{ $viewingDevicePaletteId }}')" variant="primary">Duplicate</flux:button>
                            </div>
                        @endif
                    </form>
                </div>
            </flux:modal>

            <table
                class="min-w-full table-fixed text-zinc-800 divide-y divide-zinc-800/10 dark:divide-white/20 text-zinc-800"
                data-flux-table>
                <thead data-flux-columns>
                <tr>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column>
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Description</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column>
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Grays</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column>
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Colors</div>
                    </th>
                    <th class="py-3 px-3 first:pl-0 last:pr-0 text-left text-sm font-medium text-zinc-800 dark:text-white"
                        data-flux-column>
                        <div class="whitespace-nowrap flex group-[]/right-align:justify-end">Actions</div>
                    </th>
                </tr>
                </thead>

                <tbody class="divide-y divide-zinc-800/10 dark:divide-white/20" data-flux-rows>
                @foreach ($devicePalettes as $devicePalette)
                    <tr data-flux-row>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap text-zinc-500 dark:text-zinc-300"
                        >
                            <div>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $devicePalette->description ?? $devicePalette->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $devicePalette->name }}</div>
                            </div>
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap text-zinc-500 dark:text-zinc-300"
                        >
                            {{ $devicePalette->grays }}
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap text-zinc-500 dark:text-zinc-300"
                        >
                            @if ($devicePalette->colors)
                                <div class="flex gap-1">
                                    @foreach ($devicePalette->colors as $color)
                                        <div class="w-4 h-4 rounded border border-zinc-300 dark:border-zinc-600" style="background-color: {{ $color }}"></div>
                                    @endforeach
                                    <span class="ml-2">({{ count($devicePalette->colors) }})</span>
                                </div>
                            @else
                                <span class="text-zinc-400">Grayscale only</span>
                            @endif
                        </td>
                        <td class="py-3 px-3 first:pl-0 last:pr-0 text-sm whitespace-nowrap font-medium text-zinc-800 dark:text-white"
                        >
                            <div class="flex items-center gap-4">
                                <flux:button.group>
                                    @if ($devicePalette->source === 'api')
                                        <flux:modal.trigger name="device-palette-modal">
                                            <flux:button wire:click="openDevicePaletteModal('{{ $devicePalette->id }}', true)" icon="eye"
                                                         iconVariant="outline">
                                            </flux:button>
                                        </flux:modal.trigger>
                                        <flux:button wire:click="duplicateDevicePalette('{{ $devicePalette->id }}')" icon="document-duplicate"
                                                     iconVariant="outline">
                                        </flux:button>
                                    @else
                                        <flux:modal.trigger name="device-palette-modal">
                                            <flux:button wire:click="openDevicePaletteModal('{{ $devicePalette->id }}')" icon="pencil"
                                                         iconVariant="outline">
                                            </flux:button>
                                        </flux:modal.trigger>
                                        <flux:button wire:click="deleteDevicePalette('{{ $devicePalette->id }}')" icon="trash"
                                                     iconVariant="outline">
                                        </flux:button>
                                    @endif
                                </flux:button.group>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

