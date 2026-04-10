<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StorageS3Init extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:s3:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copies the default-screens to the s3 bucket';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (config('filesystems.disks.public.driver') !== 's3') {
            $this->error('The public disk is not configured to use the S3 driver.');

            return;
        }

        $localDisk = \Illuminate\Support\Facades\Storage::disk('local');
        $s3Disk = \Illuminate\Support\Facades\Storage::disk('public');

        $sourcePath = storage_path('app/public');
        $directories = ['images', 'images/default-screens'];
        $copiedCount = 0;

        foreach ($directories as $directory) {
            $dirPath = $sourcePath.'/'.$directory;
            if (! is_dir($dirPath)) {
                $this->warn("Directory not found: {$dirPath}");

                continue;
            }

            $files = scandir($dirPath);
            $this->info("Checking directory: {$dirPath}");

            foreach ($files as $filename) {
                if ($filename === '.' || $filename === '..') {
                    continue;
                }

                $fullPath = $dirPath.'/'.$filename;
                if (is_dir($fullPath)) {
                    continue;
                }

                if (
                    str_starts_with($filename, 'setup') ||
                    str_starts_with($filename, 'sleep') ||
                    $directory === 'images/default-screens'
                ) {
                    $s3Path = $directory.'/'.$filename;
                    $this->info("Copying {$filename} to S3: {$s3Path}");

                    $content = file_get_contents($fullPath);
                    $s3Disk->put($s3Path, $content, 'public');
                    ++$copiedCount;
                }
            }
        }

        $this->info("Storage S3 initialization complete. Copied {$copiedCount} files.");
    }
}
