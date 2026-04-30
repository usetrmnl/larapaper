<?php

declare(strict_types=1);

test('storage s3 init errors when public disk is not s3', function (): void {
    config(['filesystems.disks.public.driver' => 'local']);

    $this->artisan('storage:s3:init')
        ->expectsOutput('The public disk is not configured to use the S3 driver.');
});
