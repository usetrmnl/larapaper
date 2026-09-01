<div {{ $attributes }}>
    <flux:callout variant="info">
        <flux:callout.heading>This playlist is empty</flux:callout.heading>
        <flux:callout.text>
            Add recipes from
            <flux:callout.link href="{{ route('plugins.index') }}" wire:navigate>Plugins & Recipes</flux:callout.link>.
        </flux:callout.text>
    </flux:callout>
</div>
