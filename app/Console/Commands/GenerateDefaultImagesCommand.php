<?php

namespace App\Console\Commands;

use App\Models\DeviceModel;
use Bnussbau\EpaperPipeline\Stages\BrowserStage;
use Bnussbau\EpaperPipeline\Stages\ImageStage;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Wnx\SidecarBrowsershot\BrowsershotLambda;

class GenerateDefaultImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:generate-defaults {--force : Force regeneration of existing images}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate default images (setup-logo and sleep) for all device models from Blade templates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting generation of default images for all device models...');

        $deviceModels = DeviceModel::all();

        if ($deviceModels->isEmpty()) {
            $this->warn('No device models found in the database.');

            return self::SUCCESS;
        }

        $this->info("Found {$deviceModels->count()} device models to process.");

        // Create the target directory
        $targetDir = 'images/default-screens';
        if (! Storage::disk('public')->exists($targetDir)) {
            Storage::disk('public')->makeDirectory($targetDir);
            $this->info("Created directory: {$targetDir}");
        }

        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;

        foreach ($deviceModels as $deviceModel) {
            $this->info("Processing device model: {$deviceModel->label} (ID: {$deviceModel->id})");

            try {
                // Process setup-logo
                $setupResult = $this->transformImage('setup-logo', $deviceModel, $targetDir);
                if ($setupResult) {
                    ++$successCount;
                } else {
                    ++$skipCount;
                }

                // Process sleep
                $sleepResult = $this->transformImage('sleep', $deviceModel, $targetDir);
                if ($sleepResult) {
                    ++$successCount;
                } else {
                    ++$skipCount;
                }

            } catch (Exception $e) {
                $this->error("Error processing device model {$deviceModel->label}: ".$e->getMessage());
                ++$errorCount;
            }
        }

        $this->info("\nGeneration completed!");
        $this->info("Successfully processed: {$successCount} images");
        $this->info("Skipped (already exist): {$skipCount} images");
        $this->info("Errors: {$errorCount} images");

        return self::SUCCESS;
    }

    /**
     * Transform a single image for a device model using Blade templates
     */
    private function transformImage(string $imageType, DeviceModel $deviceModel, string $targetDir): bool
    {
        // Generate filename: {width}_{height}_{bit_depth}_{rotation}.{extension}
        $extension = $deviceModel->mime_type === 'image/bmp' ? 'bmp' : 'png';
        $filename = "{$deviceModel->width}_{$deviceModel->height}_{$deviceModel->bit_depth}_{$deviceModel->rotation}.{$extension}";
        $targetPath = "{$targetDir}/{$imageType}_{$filename}";

        // Check if target already exists and force is not set
        if (Storage::disk('public')->exists($targetPath) && ! $this->option('force')) {
            $this->line("  Skipping {$imageType} - already exists: {$filename}");

            return false;
        }

        try {
            // Create custom Browsershot instance if using AWS Lambda
            $browsershotInstance = null;
            if (config('app.puppeteer_mode') === 'sidecar-aws') {
                $browsershotInstance = new BrowsershotLambda();
            }

            // Generate HTML from Blade template
            $html = $this->generateHtmlFromTemplate($imageType, $deviceModel);
            // dump($html);

            $browserStage = new BrowserStage($browsershotInstance);
            $browserStage->html($html);

            // Set timezone from app config (no user context in this command)
            $browserStage->timezone(config('app.timezone'));

            $browserStage
                ->width($deviceModel->width)
                ->height($deviceModel->height);

            $browserStage->setBrowsershotOption('waitUntil', 'networkidle0');

            if (config('app.puppeteer_docker')) {
                $browserStage->setBrowsershotOption('args', ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu']);
            }

            $outputPath = Storage::disk('public')->path($targetPath);

            $imageStage = new ImageStage();
            $imageStage->format($extension)
                ->width($deviceModel->width)
                ->height($deviceModel->height)
                ->colors($deviceModel->colors)
                ->bitDepth($deviceModel->bit_depth)
                ->rotation($deviceModel->rotation)
                // ->offsetX($deviceModel->offset_x)
                // ->offsetY($deviceModel->offset_y)
                ->outputPath($outputPath);

            (new EpaperPipeline())->pipe($browserStage)
                ->pipe($imageStage)
                ->process();

            if (! file_exists($outputPath)) {
                throw new RuntimeException('Image file was not created: '.$outputPath);
            }

            if (filesize($outputPath) === 0) {
                throw new RuntimeException('Image file is empty: '.$outputPath);
            }

            $this->line("  ✓ Generated {$imageType}: {$filename}");

            return true;

        } catch (Exception $e) {
            $this->error("  ✗ Failed to generate {$imageType} for {$deviceModel->label}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Generate HTML from Blade template for the given image type and device model
     */
    private function generateHtmlFromTemplate(string $imageType, DeviceModel $deviceModel): string
    {
        // Map image type to template name
        $templateName = match ($imageType) {
            'setup-logo' => 'default-screens.setup',
            'sleep' => 'default-screens.sleep',
            default => throw new InvalidArgumentException("Invalid image type: {$imageType}")
        };

        // Determine device properties from DeviceModel
        $deviceVariant = $deviceModel->css_name ?? $deviceModel->name ?? 'og';
        $colorDepth = $deviceModel->color_depth ?? '1bit'; // Use the accessor method
        $scaleLevel = $deviceModel->scale_level; // Use the accessor method
        $darkMode = $imageType === 'sleep'; // Sleep mode uses dark mode, setup uses light mode

        // Render the Blade template
        return view($templateName, [
            'noBleed' => false,
            'darkMode' => $darkMode,
            'deviceVariant' => $deviceVariant,
            'colorDepth' => $colorDepth,
            'scaleLevel' => $scaleLevel,
            'cssVariables' => $deviceModel->css_variables,
        ])->render();
    }
}
