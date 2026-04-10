<?php

use App\Models\Plugin;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/*
 * This component contains the TRMNL Plugin Settings modal.
 */
new class extends Component
{
    public Plugin $plugin;

    public ?string $trmnlp_id = null;

    public ?string $uuid = null;

    public bool $alias = false;

    public bool $use_trmnl_liquid_renderer = false;

    public string $configurationTemplateYaml = '';

    public int $resetIndex = 0;

    public function mount(): void
    {
        $this->resetErrorBag();
        $this->plugin = $this->plugin->fresh();
        $this->trmnlp_id = $this->plugin->trmnlp_id;
        $this->uuid = $this->plugin->uuid;
        $this->alias = $this->plugin->alias ?? false;
        $this->use_trmnl_liquid_renderer = $this->plugin->preferred_renderer === 'trmnl-liquid';
        $this->configurationTemplateYaml = $this->plugin->getCustomFieldsEditorYaml();
    }

    public function saveTrmnlpId(): void
    {
        abort_unless(auth()->user()->plugins->contains($this->plugin), 403);

        $this->validate([
            'trmnlp_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('plugins', 'trmnlp_id')
                    ->where('user_id', auth()->id())
                    ->ignore($this->plugin->id),
            ],
            'alias' => 'boolean',
            'use_trmnl_liquid_renderer' => 'boolean',
            'configurationTemplateYaml' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === '') {
                        return;
                    }
                    try {
                        $parsed = Yaml::parse($value);
                        if (! is_array($parsed)) {
                            $fail('The configuration must be valid YAML and evaluate to an object/array.');
                            return;
                        }
                        Plugin::validateCustomFieldsList($parsed);
                    } catch (ParseException) {
                        $fail('The configuration must be valid YAML.');
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        foreach ($e->errors() as $messages) {
                            foreach ($messages as $message) {
                                $fail($message);
                            }
                        }
                    }
                },
            ],
        ]);

        $configurationTemplate = Plugin::configurationTemplateFromCustomFieldsYaml(
            $this->configurationTemplateYaml,
            $this->plugin->configuration_template
        );

        $this->plugin->update([
            'trmnlp_id' => empty($this->trmnlp_id) ? null : $this->trmnlp_id,
            'alias' => $this->alias,
            'preferred_renderer' => $this->use_trmnl_liquid_renderer ? 'trmnl-liquid' : null,
            'configuration_template' => $configurationTemplate,
        ]);

        $this->dispatch('config-updated');
        Flux::modal('trmnlp-settings')->close();
    }

    public function getAliasUrlProperty(): string
    {
        return url("/api/display/{$this->uuid}/alias");
    }
}; ?>

<flux:modal name="trmnlp-settings" class="min-w-[600px] max-w-2xl space-y-6">
    <div wire:key="trmnlp-settings-form-{{ $resetIndex }}" class="space-y-6">
        <div>
            <flux:heading size="lg">Recipe Settings</flux:heading>
        </div>

        <form wire:submit="saveTrmnlpId">
            <div class="grid gap-6">
                {{-- <flux:input label="UUID" wire:model="uuid" readonly copyable /> --}}
                <flux:field>
                    <flux:label>TRMNLP Recipe ID</flux:label>
                    <flux:input
                        wire:model="trmnlp_id"
                        placeholder="TRMNL Recipe ID"
                    />
                    <flux:error name="trmnlp_id" />
                    <flux:description>Recipe ID in the TRMNL Recipe Catalog. If set, it can be used with <code>trmnlp</code>. </flux:description>
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model.live="alias" label="Enable Alias" />
                    <flux:description>Enable an Alias URL for this recipe. Your server does not need to be exposed to the internet, but your device must be able to reach the URL. <a href="https://help.usetrmnl.com/en/articles/10701448-alias-plugin">Docs</a></flux:description>
                </flux:field>

                @if($alias)
                    <flux:field>
                        <flux:label>Alias URL</flux:label>
                        <flux:input
                            value="{{ $this->aliasUrl }}"
                            readonly
                            copyable
                        />
                        <flux:description>Copy this URL to your TRMNL Dashboard. By default, image is created for TRMNL OG; use parameter <code>?device-model=</code> to specify a device model.</flux:description>
                    </flux:field>
                @endif

                @if(config('services.trmnl.liquid_enabled') && $plugin->markup_language === 'liquid')
                    <flux:field>
                        <flux:checkbox
                            wire:model.live="use_trmnl_liquid_renderer"
                            label="Use trmnl-liquid renderer"
                        />
                        <flux:description>trmnl-liquid is a Ruby-based renderer that matches the Core service’s Liquid behavior for better compatibility.</flux:description>
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Configuration template</flux:label>
                    <flux:description>
                        Build forms visually in the <a href="https://usetrmnl.github.io/trmnl-form-builder/" target="_blank" rel="noopener noreferrer">TRMNL YML Form Builder</a>.
                        Check the <a href="https://help.trmnl.com/en/articles/10513740-custom-plugin-form-builder" target="_blank" rel="noopener noreferrer">docs</a> for more information.
                    </flux:description>
                    @php
                        $configTemplateTextareaId = 'config-template-' . uniqid();
                    @endphp
                    <flux:textarea
                        wire:model="configurationTemplateYaml"
                        id="{{ $configTemplateTextareaId }}"
                        placeholder="[]"
                        rows="12"
                        hidden
                    />
                    <div
                        x-data="codeEditorFormComponent({
                            isDisabled: false,
                            language: 'yaml',
                            state: $wire.entangle('configurationTemplateYaml'),
                            textareaId: @js($configTemplateTextareaId)
                        })"
                        wire:ignore
                        wire:key="cm-{{ $configTemplateTextareaId }}"
                        class="min-h-[200px] h-[300px] overflow-hidden resize-y"
                    >
                        <div x-show="isLoading" class="flex items-center justify-center h-full">
                            <div class="flex items-center space-x-2">
                                <flux:icon.loading />
                            </div>
                        </div>
                        <div x-show="!isLoading" x-ref="editor" class="h-full"></div>
                    </div>
                    <flux:error name="configurationTemplateYaml" />
                </flux:field>
            </div>

            <div class="flex gap-2 mt-4">
                <flux:spacer/>
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </div>
</flux:modal>
