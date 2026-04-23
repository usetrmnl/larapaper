<div class="mb-6">
    <flux:label>Webhook URL</flux:label>
    <flux:input
        :value="route('api.plugins.webhook', ['plugin' => $plugin])"
        class="font-mono text-sm"
        readonly
        copyable
    />
    <flux:description class="mt-2">POST an image (PNG or BMP) to this URL to update the displayed image.</flux:description>

    <flux:callout variant="warning" icon="exclamation-circle" class="mt-4">
        <flux:callout.text>Images must be posted in a format that can directly be read by the device. You need to take care of image format, dithering, and bit-depth. Check device logs if the image is not shown.</flux:callout.text>
    </flux:callout>
</div>
