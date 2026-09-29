<?php

use App\Jobs\GenerateScreenJob;
use App\Models\Device;
use App\Models\Plugin;
use App\Support\UserBladeExecution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Livewire\Component;

new class extends Component
{
    public string $markup_code = '';

    public string $markup_language = 'blade';

    public bool $isLoading = false;

    public Collection $devices;

    public array $checked_devices = [];

    public function mount(): void
    {
        $this->devices = auth()->user()->devices->pluck('id', 'name');
    }

    public function userBladeIsAllowed(): bool
    {
        return UserBladeExecution::allowed();
    }

    public function submit(): void
    {
        $this->isLoading = true;

        $this->validate([
            'checked_devices' => 'required|array',
            'markup_code' => 'required|string',
            'markup_language' => 'required|string|in:blade,liquid',
        ]);

        $this->checked_devices = array_intersect(
            $this->checked_devices,
            auth()->user()->devices->pluck('id')->toArray()
        );

        if ($this->markup_language !== 'liquid' && ! UserBladeExecution::allowed()) {
            $this->addError('generate_screen', UserBladeExecution::DISABLED_MESSAGE);
            $this->isLoading = false;

            return;
        }

        try {
            foreach ($this->checked_devices as $deviceId) {
                $device = Device::query()->with(['deviceModel', 'deviceModel.palette'])->find($deviceId);

                if ($device === null) {
                    continue;
                }

                $rendered = $this->markup_language === 'liquid'
                    ? $this->renderLiquidMarkup($device)
                    : Blade::render($this->markup_code);

                GenerateScreenJob::dispatchSync($deviceId, null, $rendered);
            }
        } catch (LiquidException $e) {
            $this->addError('generate_screen', $e->toLiquidErrorMessage());
        } catch (Exception $e) {
            $this->addError('generate_screen', $e->getMessage());
        }

        $this->isLoading = false;
    }

    private function renderLiquidMarkup(Device $device): string
    {
        $plugin = new Plugin([
            'plugin_type' => 'recipe',
            'markup_language' => 'liquid',
            'render_markup' => $this->markup_code,
        ]);
        $plugin->setRelation('user', auth()->user());

        return $plugin->render('full', true, $device);
    }

    public function renderExample(string $example): void
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
        $this->markup_code = $markup;
    }

    public function renderHelloWorld(): string
    {
        if ($this->markup_language === 'liquid') {
            return <<<'HTML'
<div class="view view--{{ size }}">
    <div class="layout">
        <div class="richtext richtext--center gap--large">
            <span class="title">LaraPaper</span>
            <div class="content">“This screen was rendered by BYOS LaraPaper”</div>
            <span class="label label--underline">Benjamin Nussbaum</span>
        </div>
    </div>
    <div class="title_bar">
        <span class="title">LaraPaper</span>
    </div>
</div>
HTML;
        }

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
        if ($this->markup_language === 'liquid') {
            return <<<'HTML'
<div class="view view--{{ size }}">
    <div class="layout">
        <div class="richtext richtext--center gap--large">
            <span class="title">Motivational Quote</span>
            <div class="content">“I love inside jokes. I hope to be a part of one someday.”</div>
            <span class="label label--underline">Michael Scott</span>
        </div>
    </div>
    <div class="title_bar">
        <span class="title">Motivational Quote</span>
    </div>
</div>
HTML;
        }

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

    public function renderTrainMonitor(): string
    {
        if ($this->markup_language === 'liquid') {
            return <<<'HTML'
<div class="view view--{{ size }}">
    <div class="layout">
        <table class="table">
            <thead>
                <tr>
                    <th><span class="title">Abfahrt</span></th>
                    <th><span class="title">Aktuell</span></th>
                    <th><span class="title">Zug</span></th>
                    <th><span class="title">Ziel</span></th>
                    <th><span class="title">Steig</span></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="label">08:51</span></td>
                    <td><span class="label">08:52</span></td>
                    <td><span class="label">REX 1</span></td>
                    <td><span class="label">Vienna Main Station</span></td>
                    <td><span class="label">3</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="title_bar">
        <span class="title">Train Monitor</span>
    </div>
</div>
HTML;
        }

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

    public function renderHomeAssistant(): string
    {
        if ($this->markup_language === 'liquid') {
            return <<<'HTML'
<div class="view view--{{ size }}">
    <div class="layout layout--col gap--space-between">
        <div class="grid grid--cols-4">
            <div class="col col--center">
                <div class="item">
                    <div class="meta"></div>
                    <div class="content">
                        <span class="value value--large">23.3°</span>
                        <span class="label w--full flex">47.52 %</span>
                        <span class="label w--full flex">Sensor 1</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="title_bar">
        <span class="title">Home Assistant</span>
    </div>
</div>
HTML;
        }

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
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <h2 class="text-2xl font-semibold dark:text-gray-100">
            Markup
            <flux:badge size="sm" class="ml-2">Plugin</flux:badge>
        </h2>

        @if (! $this->userBladeIsAllowed())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4 mb-4">
                <flux:callout.heading>Blade markup is disabled</flux:callout.heading>
                <flux:callout.text>
                    You can still write Blade markup here. Generating a Blade screen is disabled because Blade executes
                    PHP. Liquid templates can still be generated. To compile Blade, set
                    <code>INSECURE_BLADE_ALLOW_PHP_EXECUTION=true</code>
                    in the environment. That flag is remote code execution for every registered user — leave it false on
                    a shared instance.
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="mt-5 mb-5">
            <div class="mb-4 flex items-center gap-4">
                <span>Template language</span>
                <flux:radio.group wire:model.live="markup_language" variant="segmented">
                    <flux:radio value="blade" label="Blade" />
                    <flux:radio value="liquid" label="Liquid" />
                </flux:radio.group>
            </div>
            <span>Examples</span>
            <div class="text-accent">
                <a href="#" wire:click.prevent="renderExample('helloWorld')" class="text-xl">Hello World</a> |
                <a href="#" wire:click.prevent="renderExample('quote')" class="text-xl">Quote</a> |
                <a href="#" wire:click.prevent="renderExample('trainMonitor')" class="text-xl">Train Monitor</a> |
                <a href="#" wire:click.prevent="renderExample('homeAssistant')" class="text-xl">Temperature Sensors</a>
            </div>
        </div>
        <form wire:submit="submit">
            <div class="mb-4">
                <flux:textarea
                    :label="$markup_language === 'liquid' ? 'Liquid Markup' : 'Blade Code'"
                    class="font-mono"
                    wire:model="markup_code"
                    id="markup_code"
                    name="markup_code"
                    rows="15"
                    :placeholder="$markup_language === 'liquid' ? 'Enter your liquid markup here...' : 'Enter your blade code here...'"
                />
            </div>

            <div class="flex">
                <flux:checkbox.group wire:model="checked_devices" label="Devices">
                    @foreach ($devices as $name => $id)
                        <flux:checkbox label="{{ $name }}" value="{{ $id }}" />
                    @endforeach
                </flux:checkbox.group>

                <flux:spacer />

                <flux:button
                    type="submit"
                    variant="primary"
                    :disabled="$markup_language !== 'liquid' && ! $this->userBladeIsAllowed()"
                >
                    Generate Screen
                </flux:button>
            </div>
        </form>

        @error('generate_screen')
            <div class="mt-4">
                <span class="font-mono text-red-700">{{ $message }}</span>
            </div>
        @enderror
    </div>
</div>
