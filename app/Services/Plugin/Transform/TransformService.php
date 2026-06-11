<?php

declare(strict_types=1);

namespace App\Services\Plugin\Transform;

use App\Models\Plugin;
use Throwable;

/**
 * Single entry point for transforms: builds the TRMNL-compatible input,
 * runs it on the configured provider and normalises the result.
 */
final class TransformService
{
    /** Maximum length of runner/interpreter diagnostics surfaced to the recipe owner. */
    private const MAX_DIAGNOSTICS_LENGTH = 4096;

    public function __construct(
        private readonly TransformProvider $provider,
    ) {}

    /**
     * Transforms are enabled once an external runner is configured (`TRANSFORM_PROVIDER=http`).
     */
    public static function isEnabled(): bool
    {
        return config('services.transform.provider') === 'http';
    }

    /**
     * Data strategies whose data a transform can reshape. Webhook data is not transformed.
     *
     * @return list<string>
     */
    public static function strategies(): array
    {
        return ['polling', 'static'];
    }

    /**
     * Language keys the configured provider can execute.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        return $this->provider->languages();
    }

    /**
     * Run the plugin's stored transform against a freshly fetched polling payload or saved static data.
     *
     * @param  array<int|string, mixed>  $payload
     * @return array<int|string, mixed>
     *
     * @throws TransformException
     */
    public function transform(Plugin $plugin, array $payload): array
    {
        return $this->run($plugin, (string) $plugin->transform_language, (string) $plugin->transform_code, $payload)->output ?? [];
    }

    /**
     * Run transform code on behalf of a plugin.
     *
     * The input mirrors TRMNL serverless: top-level keys of the polled data
     * (or `IDX_n` keys for multiple URLs) plus `trmnl` (user, device, plugin_settings).
     * A top-level `trmnl` key in the result is discarded, since render injects it again.
     * The returned result carries the normalised output and the script's stderr.
     *
     * @param  array<int|string, mixed>  $payload
     *
     * @throws TransformException
     */
    public function run(Plugin $plugin, string $language, string $code, array $payload): TransformResult
    {
        $code = mb_trim($code);
        $language = mb_strtolower(mb_trim($language));

        if ($code === '' || $language === '') {
            throw new TransformException('Transform code and language are required');
        }

        if (! in_array($language, $this->languages(), true)) {
            throw new TransformException("Transform language [{$language}] is not available on this server");
        }

        try {
            $result = $this->provider->execute($language, $code, self::buildInput($payload, $plugin->transformContext()));
        } catch (Throwable) {
            throw new TransformException('Transform execution failed');
        }

        $diagnostics = $result->stderr !== '' ? mb_substr($result->stderr, 0, self::MAX_DIAGNOSTICS_LENGTH) : '';

        if (! $result->succeeded()) {
            // User output may contain credentials or household data: surface it to the owner, never log it.
            throw new TransformException($result->error ?? 'Transform execution failed', $diagnostics !== '' ? $diagnostics : null);
        }

        $output = $result->output ?? [];
        unset($output['trmnl']);

        if (! Plugin::dataPayloadWithinWireLimit($output)) {
            throw new TransformException('Transform output exceeds maximum allowed size');
        }

        return new TransformResult(
            output: $output,
            stdout: '',
            stderr: $diagnostics,
            exitCode: 0,
            durationMs: $result->durationMs,
        );
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<int|string, mixed>
     */
    public static function buildInput(array $payload, array $context): array
    {
        $input = $payload;
        $input['trmnl'] = $context;

        return $input;
    }
}
