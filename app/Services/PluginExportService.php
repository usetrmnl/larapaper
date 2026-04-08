<?php

namespace App\Services;

use App\Models\Plugin;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

/**
 * PluginExportService
 *
 * Exports plugins to ZIP files in the same format that can be imported by PluginImportService.
 *
 * The exported ZIP file contains:
 * - settings.yml: Plugin configuration including custom fields, polling settings, etc.
 * - full.liquid or full.blade.php: The main template file
 * - shared.liquid: Optional shared template (for liquid templates)
 *
 * This format is compatible with the PluginImportService and can be used to:
 * - Backup plugins
 * - Share plugins between users
 * - Migrate plugins between environments
 * - Create plugin templates
 */
class PluginExportService
{
    /**
     * Export a plugin to a ZIP file in the same format that can be imported
     *
     * @param  Plugin  $plugin  The plugin to export
     * @param  User  $user  The user exporting the plugin
     * @return BinaryFileResponse The ZIP file response
     *
     * @throws Exception If the ZIP file cannot be created
     */
    public function exportToZip(Plugin $plugin, User $user): BinaryFileResponse
    {
        $temporaryDirectory = (new TemporaryDirectory)->deleteWhenDestroyed()->create();
        $tempDir = $temporaryDirectory->path();

        app()->terminating(function () use ($temporaryDirectory): void {
            $temporaryDirectory->delete();
        });
        $settings = $this->generateSettingsYaml($plugin);
        $settingsYaml = Yaml::dump($settings, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        File::put($tempDir.'/settings.yml', $settingsYaml);

        $extension = $plugin->markup_language === 'liquid' ? 'liquid' : 'blade.php';

        // Export full template if it exists
        if ($plugin->render_markup) {
            $fullTemplate = $this->generateLayoutTemplate($plugin->render_markup);
            File::put($tempDir.'/full.'.$extension, $fullTemplate);
        }

        // Export layout-specific templates if they exist
        if ($plugin->render_markup_half_horizontal) {
            $halfHorizontalTemplate = $this->generateLayoutTemplate($plugin->render_markup_half_horizontal);
            File::put($tempDir.'/half_horizontal.'.$extension, $halfHorizontalTemplate);
        }

        if ($plugin->render_markup_half_vertical) {
            $halfVerticalTemplate = $this->generateLayoutTemplate($plugin->render_markup_half_vertical);
            File::put($tempDir.'/half_vertical.'.$extension, $halfVerticalTemplate);
        }

        if ($plugin->render_markup_quadrant) {
            $quadrantTemplate = $this->generateLayoutTemplate($plugin->render_markup_quadrant);
            File::put($tempDir.'/quadrant.'.$extension, $quadrantTemplate);
        }

        // Export shared template if it exists
        if ($plugin->render_markup_shared) {
            $sharedTemplate = $this->generateLayoutTemplate($plugin->render_markup_shared);
            File::put($tempDir.'/shared.'.$extension, $sharedTemplate);
        }
        $zipPath = $tempDir.'/plugin_'.$plugin->trmnlp_id.'.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new Exception('Could not create ZIP file.');
        }
        $this->addDirectoryToZip($zip, $tempDir, '');
        $zip->close();

        return response()->download($zipPath, 'plugin_'.$plugin->trmnlp_id.'.zip');
    }

    /**
     * Generate the settings.yml content for the plugin
     */
    private function generateSettingsYaml(Plugin $plugin): array
    {
        $settings = [];

        // Add fields in the specific order requested
        $settings['name'] = $plugin->name;
        $settings['no_screen_padding'] = 'no'; // Default value
        $settings['dark_mode'] = 'no'; // Default value
        $settings['strategy'] = $plugin->data_strategy;

        // Add static data if available
        if ($plugin->data_payload) {
            $settings['static_data'] = json_encode($plugin->data_payload, JSON_PRETTY_PRINT);
        }

        // Add polling configuration if applicable
        if ($plugin->data_strategy === 'polling') {
            if ($plugin->polling_verb) {
                $settings['polling_verb'] = $plugin->polling_verb;
            }
            if ($plugin->polling_url) {
                $settings['polling_url'] = $plugin->polling_url;
            }
            if ($plugin->polling_header) {
                // Convert header format from "key: value" to "key=value"
                $settings['polling_headers'] = str_replace(':', '=', $plugin->polling_header);
            }
            if ($plugin->polling_body) {
                $settings['polling_body'] = $plugin->polling_body;
            }
        }

        $settings['refresh_interval'] = $plugin->data_stale_minutes;
        $settings['id'] = $plugin->trmnlp_id;

        // Add custom fields from configuration template
        if (isset($plugin->configuration_template['custom_fields'])) {
            $settings['custom_fields'] = $plugin->configuration_template['custom_fields'];
        }

        return $settings;
    }

    /**
     * Generate template content from markup, removing wrapper divs if present
     */
    private function generateLayoutTemplate(?string $markup): string
    {
        if (! $markup) {
            return '';
        }

        // Remove the wrapper div if it exists (it will be added during import for liquid)
        $markup = preg_replace('/^<div class="view view--\{\{ size \}\}">\s*/', '', $markup);
        $markup = preg_replace('/\s*<\/div>\s*$/', '', $markup);

        return mb_trim($markup);
    }

    /**
     * Add a directory and its contents to a ZIP file
     */
    private function addDirectoryToZip(ZipArchive $zip, string $dirPath, string $zipPath): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (! $file->isDir()) {
                $filePath = $file->getRealPath();
                $fileName = basename((string) $filePath);

                // For root directory, just use the filename
                $relativePath = $zipPath === '' ? $fileName : $zipPath.'/'.mb_substr((string) $filePath, mb_strlen($dirPath) + 1);

                $zip->addFile($filePath, $relativePath);
            }
        }
    }
}
