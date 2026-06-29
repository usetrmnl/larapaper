<?php

use App\Jobs\GenerateScreenJob;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component
{
    public string $blade_code = '';

    public bool $isLoading = false;

    public Collection $devices;

    public array $checked_devices;

    public function mount()
    {
        $this->devices = auth()->user()->devices->pluck('id', 'name');
    }

    public function submit()
    {
        $this->isLoading = true;

        $this->validate([
            'checked_devices' => 'required|array',
            'blade_code' => 'required|string',
        ]);

        // only devices that are owned by the user
        $this->checked_devices = array_intersect($this->checked_devices, auth()->user()->devices->pluck('id')->toArray());

        $isAdminOrAllowed = auth()->user()->isAdmin() || config('app.dangerously_allow_blade_for_non_admins');
        abort_unless($isAdminOrAllowed, 403, 'Blade rendering is restricted to administrators.');

        try {
            $rendered = Blade::render($this->blade_code);
            foreach ($this->checked_devices as $device) {
                GenerateScreenJob::dispatchSync($device, null, $rendered);
            }
        } catch (Exception $e) {
            $this->addError('generate_screen', $e->getMessage());
        }

        $this->isLoading = false;
    }

    public function renderExample(string $example)
    {
        switch ($example) {
            case 'helloWorld':
                $markup = $this->renderHelloWorld();
                break;
            case 'quote':
                $markup = $this->renderQuote();
                break;
            case 'trainMonitor':
                $markup = $this->renderTrainMonitor();
                break;
            case 'homeAssistant':
                $markup = $this->renderHomeAssistant();
                break;
            default:
                $markup = '<h1>Hello World!</h1>';
                break;
        }
        $this->blade_code = $markup;
    }

    public function renderHelloWorld(): string
    {
        return <<<'HTML'
<x-trmnl::screen>
    <x-trmnl::view>
        <x-trmnl::layout>
            <x-trmnl::richtext gapSize="large" align="center">
                <x-trmnl::title>LaraPaper</x-trmnl::title>
                <x-trmnl::content>“This screen was rendered by BYOS LaraPaper”</x-trmnl::content>
                <x-trmnl::label variant="underline">Benjamin Nussbaum</x-trmnl::label>
            </x-trmnl::richtext>
        </x-trmnl::layout>
        <x-trmnl::title-bar/>
    </x-trmnl::view>
</x-trmnl::screen>
HTML;
    }

    public function renderQuote(): string
    {
        return <<<'HTML'
<x-trmnl::screen>
    <x-trmnl::view>
        <x-trmnl::layout>
            <x-trmnl::richtext gapSize="large" align="center">
                <x-trmnl::title>Motivational Quote</x-trmnl::title>
                <x-trmnl::content>“I love inside jokes. I hope to be a part of one someday.”</x-trmnl::content>
                <x-trmnl::label variant="underline">Michael Scott</x-trmnl::label>
            </x-trmnl::richtext>
        </x-trmnl::layout>
        <x-trmnl::title-bar/>
    </x-trmnl::view>
</x-trmnl::screen>
HTML;
    }

    public function renderTrainMonitor()
    {
        return <<<'HTML'
<x-trmnl::screen>
    <x-trmnl::view>
        <x-trmnl::layout>
            <x-trmnl::table>
                <thead>
                <tr>
                    <th><x-trmnl::title>Abfahrt</x-trmnl::title></th>
                    <th><x-trmnl::title>Aktuell</x-trmnl::title></th>
                    <th><x-trmnl::title>Zug</x-trmnl::title></th>
                    <th><x-trmnl::title>Ziel</x-trmnl::title></th>
                    <th><x-trmnl::title>Steig</x-trmnl::title></th>
                </tr>
                </thead>
                <tbody>
                    <tr>
                      <td><x-trmnl::label>08:51</x-trmnl::label></td>
                      <td><x-trmnl::label>08:52</x-trmnl::label></td>
                      <td><x-trmnl::label>REX 1</x-trmnl::label></td>
                      <td><x-trmnl::label>Vienna Main Station</x-trmnl::label></td>
                      <td><x-trmnl::label>3</x-trmnl::label></td>
                    </tr>
                </tbody>
            </x-trmnl::table>
        </x-trmnl::layout>
        <x-trmnl::title-bar title="Train Monitor"/>
    </x-trmnl::view>
</x-trmnl::screen>
HTML;

    }

    public function renderHomeAssistant()
    {
        return <<<'HTML'
<x-trmnl::screen>
    <x-trmnl::view>
        <x-trmnl::layout class="layout--col gap--space-between">
            <x-trmnl::grid cols="4">
                <x-trmnl::col position="center">
                    <x-trmnl::item>
                        <x-trmnl::meta/>
                        <x-trmnl::content>
                            <x-trmnl::value size="large">23.3°</x-trmnl::value>
                            <x-trmnl::label class="w--full flex">
                                <flux:icon icon="droplet" style="max-height: 24px;"/>
                                47.52 %
                            </x-trmnl::label>
                            <x-trmnl::label class="w--full flex">Sensor 1</x-trmnl::label>
                        </x-trmnl::content>
                    </x-trmnl::item>
                </x-trmnl::col>
            </x-trmnl::grid>
        </x-trmnl::layout>
        <x-trmnl::title-bar title="Home Assistant"/>
    </x-trmnl::view>
</x-trmnl::screen>
HTML;

    }
};
?>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <h2 class="text-2xl font-semibold dark:text-gray-100">Markup
            <flux:badge size="sm" class="ml-2">Plugin</flux:badge>
        </h2>

        {{--        <div class="flex justify-between items-center mb-6">--}}

        <div class="mt-5 mb-5 ">
            <span>Examples</span>
            <div class="text-accent">
                <a href="#" wire:click="renderExample('helloWorld')" class="text-xl">Hello World</a> |
                <a href="#" wire:click="renderExample('quote')" class="text-xl">Quote</a> |
                <a href="#" wire:click="renderExample('trainMonitor')" class="text-xl">Train Monitor</a> |
                <a href="#" wire:click="renderExample('homeAssistant')" class="text-xl">Temperature Sensors</a>
            </div>
        </div>
        <form wire:submit="submit">
            <div class="mb-4">
                <flux:textarea
                    label="Blade Code"
                    class="font-mono"
                    wire:model="blade_code"
                    id="blade_code"
                    name="blade_code"
                    rows="15"
                    placeholder="Enter your blade code here..."
                />
            </div>

            <div class="flex">
                <flux:checkbox.group wire:model="checked_devices" label="Devices">
                    @foreach($devices as $name => $id)
                        <flux:checkbox label="{{ $name }}" value="{{ $id }}"/>
                    @endforeach
                </flux:checkbox.group>

                <flux:spacer/>

                <flux:button type="submit" variant="primary">
                    Generate Screen
                </flux:button>
            </div>
        </form>

        @error('generate_screen')
        <div class="mt-4">
            <span class="font-mono text-red-700">{{ $message }}</span>
        </div>
        @enderror

        {{--        </div>--}}
    </div>
</div>
